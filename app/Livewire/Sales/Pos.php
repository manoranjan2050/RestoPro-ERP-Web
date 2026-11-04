<?php

namespace App\Livewire\Sales;

use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Billing;
use App\Services\BillView;
use App\Support\Sales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * v2 POS. Counter/takeaway: tap items → pay → numbered bill. Dine-in: table bill kept open, Send KOT, settle later.
 * Delivery and advance (cake) orders: saved as running orders with optional advance, settled on hand-over.
 */
#[Title('POS')]
class Pos extends Component
{
    public string $category = '';
    public string $search = '';

    /** @var list<array{key: string, item_id: int, variant_id: ?int, qty: float|string, note: string, kot_no: ?int}> */
    public array $cart = [];
    public string $discount_value = '';
    public string $discount_type = 'amt';
    public string $customer_mobile = '';
    public string $customer_name = '';
    public string $type = 'counter';
    public string $note = '';

    // dine-in / delivery / advance
    public ?int $table_id = null;
    public string $guests = '';
    public string $delivery_address = '';
    public string $delivery_charge = '';
    public string $due_date = '';
    public string $due_time = '';
    public array $details = [];

    /** The held or running order being edited (null = new bill). */
    public ?int $orderId = null;

    #[Url(as: 'table')]
    public ?int $openTable = null;
    #[Url(as: 'order')]
    public ?int $openOrder = null;

    public ?int $pickingItem = null;
    public ?string $noteFor = null;

    public bool $showPay = false;
    public string $payMode = 'complete'; // complete | open (advance / pay later)
    public array $payments = [];
    public string $tendered = '';

    public bool $showHeld = false;
    public ?int $lastOrderId = null;

    public function mount(): void
    {
        if ($this->openOrder && ($o = Order::whereIn('status', ['held', 'open'])->find($this->openOrder))) {
            $this->load($o);
        } elseif ($this->openTable && Sales::on('tables') && ($t = DiningTable::where('is_active', true)->find($this->openTable))) {
            $t->openOrder ? $this->load($t->openOrder) : $this->fill(['type' => 'dine_in', 'table_id' => $t->id]);
        }
        $this->reset('openTable', 'openOrder');
    }

    // ------------------------------------------------------------------ cart

    public function add(int $itemId, ?int $variantId = null): void
    {
        $item = Item::with(['variants' => fn ($q) => $q->where('is_active', true)])->active()->find($itemId);
        if (! $item) {
            return;
        }
        if (! $variantId && $item->variants->isNotEmpty()) {
            $this->pickingItem = $item->id; // ask which size

            return;
        }
        $this->pickingItem = null;
        foreach ($this->cart as $i => $c) {
            // add to a matching line not yet sent to the kitchen
            if ($c['item_id'] === $item->id && $c['variant_id'] === $variantId && $c['note'] === '' && empty($c['kot_no'])) {
                $this->cart[$i]['qty'] = (float) $c['qty'] + 1;
                $this->moveToTop($i);

                return;
            }
        }
        array_unshift($this->cart, ['key' => Str::random(8), 'item_id' => $item->id, 'variant_id' => $variantId, 'qty' => 1, 'note' => '', 'kot_no' => null]);
    }

    /** Enter in the search box: a barcode or short code adds straight away; a single match adds too. */
    public function scan(): void
    {
        $term = trim($this->search);
        if ($term === '') {
            return;
        }
        if ($v = ItemVariant::where('barcode', $term)->where('is_active', true)->first()) {
            $this->add($v->item_id, $v->id);
        } elseif ($i = Item::active()->where(fn ($q) => $q->where('barcode', $term)->orWhere('code', mb_strtoupper($term)))->first()) {
            $this->add($i->id);
        } elseif (($m = Item::active()->search($term)->limit(2)->pluck('id'))->count() === 1) {
            $this->add($m[0]);
        } else {
            return; // several matches: leave them on screen to tap
        }
        $this->search = '';
    }

    public function inc(string $key): void
    {
        $this->each($key, fn (&$c) => $c['qty'] = (float) $c['qty'] + 1);
    }

    public function dec(string $key): void
    {
        $this->each($key, fn (&$c) => $c['qty'] = max(0, (float) $c['qty'] - 1));
        $this->cart = array_values(array_filter($this->cart, fn ($c) => (float) $c['qty'] > 0));
    }

    public function remove(string $key): void
    {
        $this->cart = array_values(array_filter($this->cart, fn ($c) => $c['key'] !== $key));
    }

    /** Typed quantity (0 or empty removes the line). Lines are addressed by key, never by position. */
    public function setQty(string $key, $value): void
    {
        $q = is_numeric($value) ? round((float) $value, 3) : null;
        if ($q !== null && $q <= 0) {
            $this->remove($key);

            return;
        }
        $this->each($key, fn (&$c) => $c['qty'] = $q === null ? $c['qty'] : min($q, 99999));
    }

    public function setNote(string $key, $value): void
    {
        $this->each($key, fn (&$c) => $c['note'] = mb_substr(trim((string) $value), 0, 120));
        $this->noteFor = null;
    }

    public function setType(string $type): void
    {
        if (array_key_exists($type, $this->types())) {
            $this->type = $type;
            if ($type === 'delivery' && $this->delivery_charge === '' && Billing::defaultDeliveryCharge() > 0) {
                $this->delivery_charge = $this->plain(Billing::defaultDeliveryCharge());
            }
            $this->resetValidation();
        }
    }

    public function clear(): void
    {
        $this->reset('cart', 'discount_value', 'discount_type', 'customer_mobile', 'customer_name', 'type', 'note', 'orderId',
            'table_id', 'guests', 'delivery_address', 'delivery_charge', 'due_date', 'due_time', 'details',
            'payments', 'tendered', 'showPay', 'payMode', 'pickingItem', 'noteFor');
        $this->resetValidation();
    }

    public function updatedCustomerMobile(): void
    {
        $m = User::normaliseMobile($this->customer_mobile);
        if ($m && strlen($m) === 10 && ($c = Customer::where('mobile', $m)->first())) {
            $this->customer_name = $c->name;
            if ($this->type === 'delivery' && $this->delivery_address === '' && $c->address) {
                $this->delivery_address = $c->address;
            }
        }
    }

    // ------------------------------------------------------------------ hold / resume / running orders

    public function hold(): void
    {
        $o = $this->persist('hold');
        if ($o) {
            $this->clear();
            $this->dispatch('toast', message: 'Bill put on hold (#'.$o->id.')');
        }
    }

    public function resume(int $id): void
    {
        $this->load(Order::with('items')->where('status', 'held')->findOrFail($id));
        $this->showHeld = false;
    }

    public function discardHeld(int $id): void
    {
        Order::where('status', 'held')->whereKey($id)->delete();
        if ($this->orderId === $id) {
            $this->clear();
        }
    }

    /** Dine-in: save the table bill and print a kitchen ticket for the new lines. */
    public function sendKot(): void
    {
        $o = $this->persist('open');
        if (! $o) {
            return;
        }
        $no = Billing::sendKot($o);
        $this->load($o->fresh(['items']));
        if ($no) {
            $this->dispatch('print-kot', url: route('orders.kot', $o).'?auto=1&kot='.$no);
            $this->dispatch('toast', message: 'KOT '.$no.' sent · '.($o->table?->name ?? 'table'));
        } else {
            $this->dispatch('toast', message: 'Saved — nothing new for the kitchen');
        }
    }

    // ------------------------------------------------------------------ pay

    /** $mode: complete = settle now, open = save as running order with optional advance. */
    public function openPay(string $mode = 'complete'): void
    {
        $this->resetValidation();
        if (! $this->cart) {
            $this->addError('cart', 'Add at least one item.');

            return;
        }
        $this->payMode = $mode === 'open' ? 'open' : 'complete';
        $due = $this->payMode === 'open' ? 0.0 : $this->balance();
        $this->payments = [['mode' => 'cash', 'amount' => $due > 0 ? $this->plain($due) : '']];
        $this->tendered = '';
        $this->showPay = true;
    }

    public function payAll(string $mode): void
    {
        if (array_key_exists($mode, OrderPayment::MODES)) {
            $this->payments = [['mode' => $mode, 'amount' => $this->plain(max(0, $this->balance()))]];
        }
    }

    public function addSplit(): void
    {
        $left = round($this->balance() - array_sum(array_map(fn ($p) => (float) $p['amount'], $this->payments)), 2);
        $used = array_column($this->payments, 'mode');
        $mode = collect(array_keys(OrderPayment::MODES))->first(fn ($m) => ! in_array($m, $used, true)) ?? 'upi';
        $this->payments[] = ['mode' => $mode, 'amount' => $left > 0 ? $this->plain($left) : ''];
    }

    public function removeSplit(int $i): void
    {
        unset($this->payments[$i]);
        $this->payments = array_values($this->payments) ?: [['mode' => 'cash', 'amount' => '']];
    }

    public function complete(): void
    {
        $order = $this->persist($this->payMode, $this->payments);
        if (! $order) {
            return;
        }
        $this->clear();
        $this->lastOrderId = $order->id;
        $print = BillView::printSettings();
        $done = $order->status === 'completed';
        $this->dispatch('toast', message: ($done ? $order->number : $order->ref()).' saved · '.money($order->total));
        $this->dispatch('bill-saved',
            id: $order->id,
            print: $print['auto'] ? route('orders.print', $order).'?auto=1' : null,
            kot: $print['kot'] && $order->type !== 'dine_in' ? route('orders.kot', $order).'?auto=1' : null,
        );
    }

    public function closePicker(): void
    {
        $this->pickingItem = null;
    }

    public function closePay(): void
    {
        $this->showPay = false;
    }

    public function closeHeld(): void
    {
        $this->showHeld = false;
    }

    public function dismissLast(): void
    {
        $this->lastOrderId = null;
    }

    // ------------------------------------------------------------------ render

    public function render()
    {
        $live = $this->live();
        $priced = [];
        if ($this->cart) {
            try {
                $lines = Billing::price($this->cart);
                foreach ($this->cart as $i => $c) {
                    $priced[$c['key']] = $lines[$i] + ['calc' => $live['lines'][$i] ?? null];
                }
            } catch (ValidationException) {
                // an item vanished meanwhile; render() must not fail — saving reports it
            }
        }
        $current = $this->orderId ? Order::find($this->orderId) : null;

        return view('livewire.sales.pos', [
            'categories' => ItemCategory::where('is_active', true)->orderBy('sort')->orderBy('id')->get(),
            'items' => Item::with(['variants' => fn ($q) => $q->where('is_active', true)])->active()
                ->where(fn ($q) => $q->whereNull('category_id')->orWhereHas('category', fn ($c) => $c->where('is_active', true)))
                ->when($this->category !== '', fn ($q) => $q->where('category_id', (int) $this->category))
                ->search($this->search)->orderBy('sort')->orderBy('name')->limit(120)->get(),
            'priced' => $priced,
            'live' => $live,
            'gst' => Sales::gst(),
            'types' => $this->types(),
            'tables' => $this->type === 'dine_in' ? DiningTable::where('is_active', true)->with('openOrder')->orderBy('area')->orderBy('sort')->orderBy('name')->get() : collect(),
            'current' => $current,
            'prior' => $current ? (float) $current->payments()->sum('amount') : 0.0,
            'held' => Order::where('status', 'held')->withCount('items')->latest()->limit(30)->get(),
            'picking' => $this->pickingItem ? Item::with(['variants' => fn ($q) => $q->where('is_active', true)])->find($this->pickingItem) : null,
            'last' => $this->lastOrderId ? Order::find($this->lastOrderId) : null,
            'paidSum' => round(array_sum(array_map(fn ($p) => (float) ($p['amount'] ?: 0), $this->payments)), 2),
            'balance' => $this->balance(),
            'unsent' => collect($this->cart)->contains(fn ($c) => empty($c['kot_no'])),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** Order types offered on this shop's POS (feature switches). */
    private function types(): array
    {
        return array_filter([
            'counter' => 'Counter', 'takeaway' => 'Takeaway',
            'dine_in' => Sales::on('tables') ? 'Dine-in' : null,
            'delivery' => Sales::on('delivery') ? 'Delivery' : null,
            'advance' => Sales::on('advance') ? 'Advance order' : null,
        ]);
    }

    private function load(Order $o): void
    {
        $o->loadMissing('items');
        $this->clear();
        $this->orderId = $o->id;
        $this->cart = $o->items->filter(fn ($l) => $l->item_id)->map(fn ($l) => [
            'key' => Str::random(8), 'item_id' => $l->item_id, 'variant_id' => $l->variant_id, 'qty' => (float) $l->qty,
            'note' => (string) $l->note, 'kot_no' => $l->kot_no,
        ])->values()->all();
        $this->fill([
            'discount_value' => (float) $o->discount_value ? $this->plain((float) $o->discount_value) : '',
            'discount_type' => $o->discount_type, 'customer_mobile' => (string) $o->customer_mobile,
            'customer_name' => (string) $o->customer_name, 'type' => $o->type, 'note' => (string) $o->note,
            'table_id' => $o->table_id, 'guests' => $o->guests ? (string) $o->guests : '',
            'delivery_address' => (string) $o->delivery_address,
            'delivery_charge' => (float) $o->delivery_charge ? $this->plain((float) $o->delivery_charge) : '',
            'due_date' => $o->due_at?->toDateString() ?? '', 'due_time' => $o->due_at?->format('H:i') ?? '',
            'details' => $o->details ?? [],
        ]);
    }

    private function persist(string $mode, array $payments = []): ?Order
    {
        try {
            return Billing::save($this->cart, $this->opts() + ['mode' => $mode], $payments, Auth::user(), $this->orderId ? Order::find($this->orderId) : null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            if ($this->showPay && $e->validator->errors()->hasAny(['table_id', 'delivery_address', 'due_at', 'customer_mobile'])) {
                $this->showPay = false; // the problem is on the bill panel, not in the payment box
            }

            return null;
        }
    }

    /** Totals for what's on screen (0s when the cart is empty or an item vanished). */
    private function live(): array
    {
        $extra = $this->type === 'delivery' ? (float) $this->delivery_charge : 0;
        try {
            return Billing::totals($this->cart ? Billing::price($this->cart) : [], (float) $this->discount_value, $this->discount_type, $extra);
        } catch (ValidationException) {
            return Billing::totals([], 0, 'amt');
        }
    }

    /** What is still to be collected (total minus advances already taken). */
    private function balance(): float
    {
        $prior = $this->orderId ? (float) Order::find($this->orderId)?->payments()->sum('amount') : 0;

        return round($this->live()['total'] - $prior, 2);
    }

    private function opts(): array
    {
        $due = null;
        if ($this->due_date !== '') {
            try {
                $due = Carbon::parse($this->due_date.' '.($this->due_time ?: '18:00'))->toDateTimeString();
            } catch (\Throwable) {
                $due = null;
            }
        }

        return [
            'discount_value' => (float) $this->discount_value, 'discount_type' => $this->discount_type,
            'customer_mobile' => $this->customer_mobile, 'customer_name' => $this->customer_name,
            'type' => array_key_exists($this->type, $this->types()) ? $this->type : 'counter', 'note' => $this->note,
            'table_id' => $this->table_id, 'guests' => $this->guests,
            'delivery_address' => $this->delivery_address, 'delivery_charge' => (float) $this->delivery_charge,
            'due_at' => $due, 'details' => $this->details,
        ];
    }

    private function each(string $key, callable $fn): void
    {
        foreach ($this->cart as $i => $c) {
            if ($c['key'] === $key) {
                $fn($this->cart[$i]);
            }
        }
    }

    private function moveToTop(int $i): void
    {
        $line = $this->cart[$i];
        unset($this->cart[$i]);
        array_unshift($this->cart, $line);
        $this->cart = array_values($this->cart);
    }

    private function plain(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
