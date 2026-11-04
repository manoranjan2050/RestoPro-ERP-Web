<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Rules\UniqueCi;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /** GET /expenses?month=&category_id=&vendor_id=(id|none)&status=(paid|due)&q= */
    public function index(Request $request)
    {
        $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'category_id' => ['nullable', 'integer'], 'vendor_id' => ['nullable'], 'status' => ['nullable', 'in:paid,due'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $start = Carbon::parse(($request->month ?? now()->format('Y-m')).'-01');
        $q = Expense::between($start, $start->copy()->endOfMonth())
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->vendor_id, fn ($q, $v) => $v === 'none' ? $q->whereNull('vendor_id') : $q->where('vendor_id', $v))
            ->when($request->status, fn ($q, $v) => $v === 'due' ? $q->whereIn('status', ['unpaid', 'partial']) : $q->where('status', 'paid'))
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w->where('note', 'like', "%{$term}%")
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$term}%"))
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$term}%"))));

        return response()->json([
            'month' => $start->format('Y-m'),
            'totals' => Expense::totals($q),
            'by_category' => Expense::byCategory(Expense::between($start, $start->copy()->endOfMonth())),
            'data' => (clone $q)->with('category', 'vendor')->orderByDesc('date')->orderByDesc('id')->get()->map(fn ($e) => Fmt::expense($e)),
        ]);
    }

    public function show(Expense $expense)
    {
        return response()->json(['data' => Fmt::expense($expense->load('category', 'vendor'))]);
    }

    /**
     * POST /expenses (multipart if a bill photo is attached).
     * paid=true → paid in full at purchase (payment_mode required); paid=false → udhaar.
     */
    public function store(Request $request)
    {
        $e = new Expense(['created_by' => $request->user()->id]);
        $this->fill($request, $e);

        return response()->json(['data' => Fmt::expense($e->fresh()->load('category', 'vendor'))], 201);
    }

    /** POST /expenses/{id} with _method=PUT for multipart, or PUT with JSON. */
    public function update(Request $request, Expense $expense)
    {
        $this->fill($request, $expense);

        return response()->json(['data' => Fmt::expense($expense->fresh()->load('category', 'vendor'))]);
    }

    public function destroy(Expense $expense)
    {
        Gate::authorize('delete-records');
        $expense->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /expenses/{id}/mark-paid {payment_mode} — pays whatever is still open on the bill itself. */
    public function markPaid(Request $request, Expense $expense)
    {
        $d = $request->validate(['payment_mode' => ['required', Rule::in(array_keys(Expense::MODES))]]);
        $expense->fill([
            'direct_paid' => min((float) $expense->amount, (float) $expense->direct_paid + $expense->balance()),
            'payment_mode' => $d['payment_mode'], 'paid_on' => today(), 'updated_by' => $request->user()->id,
        ])->save();

        return response()->json(['data' => Fmt::expense($expense->fresh()->load('category', 'vendor'))]);
    }

    /** GET /expenses/{id}/bill — the bill photo/PDF (token-authenticated). */
    public function bill(Expense $expense)
    {
        $disk = Storage::disk(Expense::BILL_DISK);
        abort_unless($expense->bill_path && $disk->exists($expense->bill_path), 404);

        return $disk->response($expense->bill_path);
    }

    private function fill(Request $request, Expense $e): void
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'paid' => ['required', 'boolean'],
            'payment_mode' => ['required_if:paid,true,1', 'nullable', Rule::in(array_keys(Expense::MODES))],
            'note' => ['nullable', 'string', 'max:255'],
            'bill' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'remove_bill' => ['nullable', 'boolean'],
        ]);
        $amount = round((float) $d['amount'], 2);
        $paid = filter_var($d['paid'], FILTER_VALIDATE_BOOLEAN);

        $e->fill([
            'date' => $d['date'], 'category_id' => $d['category_id'], 'vendor_id' => $d['vendor_id'] ?? null,
            'amount' => $amount, 'direct_paid' => $paid ? $amount : 0,
            'payment_mode' => $paid ? $d['payment_mode'] : null,
            'paid_on' => $paid ? ($e->paid_on ?? $d['date']) : null,
            'note' => ($d['note'] ?? null) ?: null, 'updated_by' => $request->user()->id,
        ]);

        $disk = Storage::disk(Expense::BILL_DISK);
        if (($request->hasFile('bill') || $request->boolean('remove_bill')) && $e->bill_path) {
            $disk->delete($e->bill_path);
            $e->bill_path = null;
        }
        if ($request->hasFile('bill')) {
            $e->bill_path = $request->file('bill')->store('bills/'.substr($d['date'], 0, 7), Expense::BILL_DISK);
        }
        $e->save();
    }

    // ---- categories --------------------------------------------------------

    public function categories()
    {
        return response()->json(['data' => ExpenseCategory::ordered()->get()->map(fn ($c) => Fmt::category($c))]);
    }

    public function storeCategory(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:60', new UniqueCi('expense_categories')]]);
        $c = ExpenseCategory::create(['name' => trim($d['name']), 'color' => ExpenseCategory::nextColor(), 'sort' => 100]);

        return response()->json(['data' => Fmt::category($c)], 201);
    }

    public function updateCategory(Request $request, ExpenseCategory $category)
    {
        $d = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60', new UniqueCi('expense_categories', 'name', $category->id)],
            'color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $category->update($d);

        return response()->json(['data' => Fmt::category($category->fresh())]);
    }
}
