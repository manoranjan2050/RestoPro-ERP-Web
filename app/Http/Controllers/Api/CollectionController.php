<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CollectionController extends Controller
{
    /** GET /collections?month=Y-m (default this month) */
    public function index(Request $request)
    {
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $start = Carbon::parse(($request->month ?? now()->format('Y-m')).'-01');
        $q = CashCollection::between($start, $start->copy()->endOfMonth());

        return response()->json([
            'month' => $start->format('Y-m'),
            'totals' => CashCollection::totals($q),
            'data' => (clone $q)->orderByDesc('date')->get()->map(fn ($c) => Fmt::collection($c)),
        ]);
    }

    public function store(Request $request)
    {
        $c = CashCollection::create($this->validated($request) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return response()->json(['data' => Fmt::collection($c->fresh())], 201);
    }

    public function update(Request $request, CashCollection $collection)
    {
        $collection->update($this->validated($request, $collection->id) + ['updated_by' => $request->user()->id]);

        return response()->json(['data' => Fmt::collection($collection->fresh())]);
    }

    public function destroy(CashCollection $collection)
    {
        Gate::authorize('delete-records');
        $collection->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', Rule::unique('cash_collections', 'date')->ignore($ignore)],
            'cash' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'upi' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'online' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['date.unique' => 'An entry for this date already exists.']);

        return [
            'date' => $d['date'], 'cash' => (float) ($d['cash'] ?? 0), 'upi' => (float) ($d['upi'] ?? 0),
            'online' => (float) ($d['online'] ?? 0), 'note' => ($d['note'] ?? null) ?: null,
        ];
    }
}
