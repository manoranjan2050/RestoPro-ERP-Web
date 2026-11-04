<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BillController;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing;
use App\Services\BillView;
use App\Services\ReceiptLines;
use App\Services\SalesReports;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * v2 sales API for the Android POS (Advanced mode only). Cashiers may use all of it except cancelling.
 * Saving goes through the same App\Services\Billing as the web POS, so prices, numbers, GST and stock match.
 */
class SalesController extends Controller
{
    public function catalog()
    {
        $cats = ItemCategory::where('is_active', true)->orderBy('sort')->orderBy('id')->get();
        $items = Item::with(['variants' => fn ($q) => $q->where('is_active', true)])->active()
            ->where(fn ($q) => $q->whereNull('category_id')->orWhereIn('category_id', $cats->pluck('id')))
            ->orderBy('sort')->orderBy('name')->get();
        $stock = Sales::on('stock');

        return response()->json([
            'categories' => $cats->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color])->values(),
            'items' => $items->map(fn ($i) => Fmt::catalogItem($i, $stock))->values(),
        ]);
    }

    public function lookupCustomer(Request $request)
    {
        $m = User::normaliseMobile($request->query('mobile'));
        $c = $m && strlen($m) === 10 ? Customer::where('mobile', $m)->first() : null;

        return response()->json(['data' => $c ? ['id' => $c->id, 'name' => $c->name, 'mobile' => $c->mobile, 'address' => $c->address] : null]);
    }

    public function tables()
    {
        abort_unless(Sales::on('tables'), 404);

        return response()->json(['data' => DiningTable::where('is_active', true)->with(['openOrder' => fn ($q) => $q->withCount('items')])
            ->orderBy('area')->orderBy('sort')->orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'area' => $t->area, 'seats' => $t->seats,
                'order' => $t->openOrder ? ['id' => $t->openOrder->id, 'total' => Fmt::money($t->openOrder->total), 'items' => $t->openOrder->items_count,
                    'guests' => $t->openOrder->guests, 'since' => $t->openOrder->created_at->toIso8601String()] : null,
            ])]);
    }

    /** ?tab=bills (date, q) | running | delivery | advance | held */
    public function index(Request $request)
    {
        $tab = $request->query('tab', 'bills');
        $q = match ($tab) {
            'held' => Order::where('status', 'held')->latest(),
            'running' => Order::with('table')->where('type', 'dine_in')->where('status', 'open')->oldest(),
            'delivery', 'advance' => Order::where('type', $tab)->whereIn('status', ['open', 'completed'])
                ->where(fn ($w) => $w->where('stage', '!=', 'delivered')->orWhere('status', 'open'))
                ->when($tab === 'advance', fn ($w) => $w->orderBy('due_at'), fn ($w) => $w->oldest()),
            default => Order::billed()->latest('billed_at')->when(
                trim((string) $request->query('q')) !== '',
                fn ($w) => $w->where(fn ($x) => $x->where('number', 'like', '%'.$request->query('q').'%')->orWhere('customer_name', 'like', '%'.$request->query('q').'%')
                    ->orWhere('customer_mobile', 'like', '%'.preg_replace('/\D/', '', (string) $request->query('q')).'%')),
                fn ($w) => $w->whereDate('billed_at', $request->query('date', today()->toDateString())),
            ),
        };

        return response()->json(['tab' => $tab, 'data' => $q->with(['items', 'payments', 'table'])->limit(200)->get()->map(fn ($o) => Fmt::order($o))->values()]);
    }

    public function show(Order $order)
    {
        return response()->json(['data' => Fmt::order($order->load(['items', 'payments', 'table']), true)]);
    }

    /** Body: cart[{item_id, variant_id?, qty, note?, kot_no?}], mode (complete|open|hold), payments[{mode, amount, reference?}],
     *  type, discount_value, discount_type, customer_name, customer_mobile, note, table_id, guests, delivery_address,
     *  delivery_charge, due_at (Y-m-d H:i), details{flavour, weight, shape, message, eggless, instructions}. */
    public function store(Request $request)
    {
        return $this->save($request, null, 201);
    }

    public function update(Request $request, Order $order)
    {
        return $this->save($request, $order, 200);
    }

    public function kot(Request $request, Order $order)
    {
        abort_unless($order->status === 'open', 422, 'Only a running order can send a KOT.');
        $no = Billing::sendKot($order);

        return response()->json([
            'kot_no' => $no,
            'order' => Fmt::order($order->fresh(['items', 'payments', 'table']), true),
            'print' => $no ? ReceiptLines::kot($order->fresh(['items', 'table']), $no, $request->query('width')) : [],
        ]);
    }

    public function stage(Request $request, Order $order)
    {
        $stage = $request->validate(['stage' => ['required', 'string']])['stage'];
        Billing::setStage($order, $stage);

        return response()->json(['data' => Fmt::order($order->fresh(['items', 'payments', 'table']))]);
    }

    public function move(Request $request, Order $order)
    {
        abort_unless($order->type === 'dine_in' && $order->status === 'open', 422, 'Only a running table bill can move.');
        Billing::moveTable($order, (int) $request->validate(['table_id' => ['required', 'integer']])['table_id']);

        return response()->json(['data' => Fmt::order($order->fresh(['items', 'payments', 'table']))]);
    }

    public function cancel(Request $request, Order $order)
    {
        Gate::authorize('delete-records');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'];
        Billing::cancel($order, $reason, $request->user());

        return response()->json(['ok' => true]);
    }

    /** ESC/POS lines for the app's Bluetooth printer. ?kind=receipt|kot &kot=N &width=58|80 */
    public function printLines(Request $request, Order $order)
    {
        abort_if($order->status === 'held', 404);
        $width = in_array((string) $request->query('width'), ['58', '80'], true) ? (string) $request->query('width') : null;
        $lines = $request->query('kind') === 'kot'
            ? ReceiptLines::kot($order, (int) $request->query('kot') ?: null, $width)
            : ReceiptLines::receipt($order, $width);

        return response()->json(['width' => ReceiptLines::cols($width ?? BillView::printSettings()['width']), 'lines' => $lines]);
    }

    public function pdf(Request $request, Order $order)
    {
        return app(BillController::class)->pdf($request, $order);
    }

    public function dayEnd(Request $request)
    {
        Gate::authorize('back-office');
        $d = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? today()->toDateString();
        $r = SalesReports::dayEnd($d);

        return response()->json($r + ['print' => ReceiptLines::report($r, $request->query('width'))]);
    }

    private function save(Request $request, ?Order $order, int $status)
    {
        $b = $request->validate([
            'cart' => ['required', 'array', 'min:1', 'max:300'],
            'cart.*.item_id' => ['required', 'integer'],
            'cart.*.variant_id' => ['nullable', 'integer'],
            'cart.*.qty' => ['required', 'numeric', 'gt:0'],
            'cart.*.note' => ['nullable', 'string', 'max:120'],
            'cart.*.kot_no' => ['nullable', 'integer'],
            'mode' => ['nullable', 'in:complete,open,hold'],
            'payments' => ['nullable', 'array', 'max:6'],
            'payments.*.mode' => ['required', 'string'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
            'type' => ['nullable', 'string'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:amt,pct'],
            'customer_name' => ['nullable', 'string', 'max:80'],
            'customer_mobile' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
            'table_id' => ['nullable', 'integer'],
            'guests' => ['nullable', 'integer', 'min:0', 'max:99'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'due_at' => ['nullable', 'date'],
            'details' => ['nullable', 'array'],
        ]);
        if ($order && ! in_array($order->status, ['held', 'open'], true)) {
            abort(422, 'This bill is already closed.');
        }
        $saved = Billing::save($b['cart'], collect($b)->except(['cart', 'payments'])->all() + ['mode' => $b['mode'] ?? 'complete'],
            $b['payments'] ?? [], $request->user(), $order);
        $saved->load(['items', 'payments', 'table']);
        $print = BillView::printSettings();

        return response()->json([
            'data' => Fmt::order($saved, true),
            // what the app should print straight away (empty = nothing)
            'print' => $saved->status !== 'held' && $print['auto'] && $saved->type !== 'dine_in' ? ReceiptLines::receipt($saved) : [],
            'kot' => $saved->status !== 'held' && $print['kot'] && $saved->type !== 'dine_in' ? ReceiptLines::kot($saved) : [],
        ], $status);
    }
}
