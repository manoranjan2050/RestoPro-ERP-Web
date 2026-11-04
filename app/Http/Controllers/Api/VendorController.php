<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Rules\UniqueCi;
use App\Services\VendorLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    /** GET /vendors?show=active|due|all&q= */
    public function index(Request $request)
    {
        $request->validate(['show' => ['nullable', 'in:active,due,all'], 'q' => ['nullable', 'string', 'max:80']]);
        $list = Vendor::query()->select('vendors.*')->withDue()
            ->when(($request->show ?? 'active') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('mobile', 'like', "%{$t}%")))
            ->orderBy('name')->get()
            ->when($request->show === 'due', fn ($c) => $c->filter(fn ($v) => $v->due() > 0)->values());

        return response()->json([
            'total_owed' => Fmt::money($list->sum(fn ($v) => max($v->due(), 0))),
            'data' => $list->map(fn ($v) => Fmt::vendor($v)),
        ]);
    }

    public function show(Vendor $vendor)
    {
        $v = Vendor::query()->select('vendors.*')->withDue()->findOrFail($vendor->id);

        return response()->json([
            'data' => Fmt::vendor($v),
            'open_bills' => Expense::where('vendor_id', $v->id)->whereIn('status', ['unpaid', 'partial'])->with('category')
                ->orderBy('date')->get()->map(fn ($e) => Fmt::expense($e)),
        ]);
    }

    public function store(Request $request)
    {
        $v = Vendor::create($this->validated($request));

        return response()->json(['data' => Fmt::vendor($v->fresh())], 201);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $vendor->update($this->validated($request, $vendor->id));

        return response()->json(['data' => Fmt::vendor($vendor->fresh())]);
    }

    /** Vendors with history are deactivated instead of deleted. */
    public function destroy(Vendor $vendor)
    {
        Gate::authorize('delete-records');
        if ($vendor->expenses()->exists() || $vendor->payments()->exists()) {
            $vendor->update(['is_active' => false]);

            return response()->json(['ok' => true, 'deactivated' => true]);
        }
        $vendor->delete();

        return response()->json(['ok' => true, 'deactivated' => false]);
    }

    /** GET /vendors/{id}/statement?from=&to= — khata with running balance. */
    public function statement(Request $request, Vendor $vendor)
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $s = VendorLedger::statement($vendor, $request->from ? Carbon::parse($request->from) : null, $request->to ? Carbon::parse($request->to) : null);
        $s['rows'] = array_map(fn ($r) => array_merge($r, ['date' => $r['date']->toDateString()]), $s['rows']);

        return response()->json(['balance' => $vendor->due()] + $s);
    }

    public function storePayment(Request $request, Vendor $vendor)
    {
        $p = $vendor->payments()->create($this->paymentData($request) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => Fmt::vendorPayment($p), 'balance' => $vendor->fresh()->due()], 201);
    }

    public function updatePayment(Request $request, VendorPayment $payment)
    {
        $payment->update($this->paymentData($request));

        return response()->json(['data' => Fmt::vendorPayment($payment->fresh()), 'balance' => $payment->vendor->fresh()->due()]);
    }

    public function destroyPayment(VendorPayment $payment)
    {
        Gate::authorize('delete-records');
        $vendor = $payment->vendor;
        $payment->delete();

        return response()->json(['ok' => true, 'balance' => $vendor->fresh()->due()]);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $request->merge(['mobile' => User::normaliseMobile($request->mobile)]);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80', new UniqueCi('vendors', 'name', $ignore)],
            'mobile' => ['nullable', 'digits:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'default_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => trim($d['name']), 'mobile' => $d['mobile'] ?? null, 'address' => ($d['address'] ?? null) ?: null,
            'default_category_id' => $d['default_category_id'] ?? null, 'opening_balance' => (float) ($d['opening_balance'] ?? 0),
            'note' => ($d['note'] ?? null) ?: null, 'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
        ];
    }

    private function paymentData(Request $request): array
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payment_mode' => ['required', Rule::in(array_keys(Expense::MODES))],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $d + ['note' => null, 'updated_by' => $request->user()->id];
    }
}
