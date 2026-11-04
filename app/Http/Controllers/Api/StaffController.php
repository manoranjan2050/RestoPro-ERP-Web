<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffPayment;
use App\Models\User;
use App\Services\StaffLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    /** GET /staff?show=active|left|all */
    public function index(Request $request)
    {
        $request->validate(['show' => ['nullable', 'in:active,left,all']]);
        $show = $request->show ?? 'active';
        $today = Attendance::whereDate('date', today()->toDateString())->pluck('status', 'staff_id');
        $list = Staff::query()
            ->when($show === 'active', fn ($q) => $q->where('is_active', true))
            ->when($show === 'left', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->get()
            ->map(fn ($s) => Fmt::staff($s, StaffLedger::balance($s)) + ['today' => $today[$s->id] ?? null]);

        return response()->json(['data' => $list]);
    }

    /** GET /staff/{id}?month=Y-m — profile, balance and that month's breakdown. */
    public function show(Request $request, Staff $staff)
    {
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $ym = $request->month ?? now()->format('Y-m');

        return response()->json([
            'data' => Fmt::staff($staff, StaffLedger::balance($staff)),
            'month' => Fmt::staffMonth(StaffLedger::month($staff, $ym)),
        ]);
    }

    public function store(Request $request)
    {
        $s = new Staff;
        $this->fill($request, $s);

        return response()->json(['data' => Fmt::staff($s->fresh(), StaffLedger::balance($s->fresh()))], 201);
    }

    public function update(Request $request, Staff $staff)
    {
        $this->fill($request, $staff);

        return response()->json(['data' => Fmt::staff($staff->fresh(), StaffLedger::balance($staff->fresh()))]);
    }

    /** GET /staff/{id}/ledger?fy=2026 — monthly carry-forward ledger + full-year totals. */
    public function ledger(Request $request, Staff $staff)
    {
        $sm = (int) Setting::get('fy_start_month', '4');
        $years = StaffLedger::financialYears($staff, $sm);
        $fy = (int) ($request->fy ?? array_key_first($years));
        abort_unless(isset($years[$fy]), 404, 'No such financial year for this staff member.');
        $rows = StaffLedger::yearRows($staff, $fy, $sm);

        return response()->json([
            'fy' => $fy, 'label' => $years[$fy], 'years' => $years,
            'totals' => StaffLedger::totals($rows),
            'months' => $rows->map(fn ($m) => Fmt::staffMonth($m))->values(),
        ]);
    }

    /** GET /staff/{id}/attendance?month=Y-m → {"2026-11-01": "P", …} */
    public function attendance(Request $request, Staff $staff)
    {
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $start = Carbon::parse(($request->month ?? now()->format('Y-m')).'-01');

        return response()->json([
            'month' => $start->format('Y-m'),
            'data' => Attendance::where('staff_id', $staff->id)
                ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
                ->orderBy('date')->get()->mapWithKeys(fn ($a) => [$a->date->toDateString() => $a->status]),
        ]);
    }

    // ---- payments ----------------------------------------------------------

    /** GET /staff/{id}/payments */
    public function payments(Staff $staff)
    {
        return response()->json(['data' => $staff->payments()->orderByDesc('date')->orderByDesc('id')->get()->map(fn ($p) => Fmt::staffPayment($p))]);
    }

    public function storePayment(Request $request, Staff $staff)
    {
        $p = $staff->payments()->create($this->paymentData($request) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => Fmt::staffPayment($p), 'balance' => StaffLedger::balance($staff)], 201);
    }

    public function updatePayment(Request $request, StaffPayment $payment)
    {
        $payment->update($this->paymentData($request));

        return response()->json(['data' => Fmt::staffPayment($payment->fresh()), 'balance' => StaffLedger::balance($payment->staff)]);
    }

    public function destroyPayment(StaffPayment $payment)
    {
        Gate::authorize('delete-records');
        $staff = $payment->staff;
        $payment->delete();

        return response()->json(['ok' => true, 'balance' => StaffLedger::balance($staff)]);
    }

    // ---- share link --------------------------------------------------------

    /** POST /staff/{id}/share {regenerate?, pin?: "1234"|null} — create/refresh the public link, set or clear PIN. */
    public function share(Request $request, Staff $staff)
    {
        $d = $request->validate(['regenerate' => ['nullable', 'boolean'], 'pin' => ['nullable', 'digits:4'], 'remove_pin' => ['nullable', 'boolean']]);
        if (! $staff->share_token || $request->boolean('regenerate')) {
            $staff->share_token = Str::random(32);
        }
        if ($request->boolean('remove_pin')) {
            $staff->share_pin = null;
        } elseif ($request->exists('pin')) {
            $staff->share_pin = $d['pin'] ? Hash::make($d['pin']) : null;
        }
        $staff->save();

        return response()->json(['share_url' => route('staff.share', $staff->share_token), 'has_pin' => (bool) $staff->share_pin]);
    }

    public function unshare(Staff $staff)
    {
        $staff->forceFill(['share_token' => null, 'share_pin' => null])->save();

        return response()->json(['ok' => true]);
    }

    // ---- helpers -----------------------------------------------------------

    private function fill(Request $request, Staff $s): void
    {
        $request->merge(['mobile' => User::normaliseMobile($request->mobile)]);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'mobile' => ['nullable', 'digits:10'],
            'designation' => ['nullable', 'string', 'max:60'],
            'joining_date' => ['required', 'date_format:Y-m-d'],
            'leaving_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:joining_date'],
            'salary_type' => ['required', Rule::in(array_keys(Staff::TYPES))],
            'salary_amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'opening_balance' => ['nullable', 'numeric', 'between:-9999999,9999999'], // − = they owe you
            'id_type' => ['nullable', 'string', 'max:30'],
            'id_number' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);
        $leaving = $d['leaving_date'] ?? null;
        $s->fill([
            'name' => trim($d['name']), 'mobile' => $d['mobile'] ?? null, 'designation' => ($d['designation'] ?? null) ?: null,
            'joining_date' => $d['joining_date'], 'leaving_date' => $leaving,
            'salary_type' => $d['salary_type'], 'salary_amount' => round((float) $d['salary_amount'], 2),
            'opening_balance' => (float) ($d['opening_balance'] ?? 0),
            'id_type' => ($d['id_type'] ?? null) ?: null, 'id_number' => ($d['id_number'] ?? null) ?: null,
            'address' => ($d['address'] ?? null) ?: null, 'emergency_contact' => ($d['emergency_contact'] ?? null) ?: null,
            'note' => ($d['note'] ?? null) ?: null,
            'is_active' => ($request->has('is_active') ? $request->boolean('is_active') : true) && (! $leaving || $leaving > today()->toDateString()),
        ]);
        if ($request->hasFile('photo')) {
            if ($s->photo_path) {
                Storage::disk('public')->delete($s->photo_path);
            }
            $s->photo_path = $request->file('photo')->store('staff', 'public');
        }
        $s->save();
    }

    private function paymentData(Request $request): array
    {
        $d = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'type' => ['required', Rule::in(array_keys(StaffPayment::TYPES))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'payment_mode' => [Rule::requiredIf($request->type !== 'deduction'), 'nullable', Rule::in(array_keys(Expense::MODES))],
            'for_month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'date' => $d['date'], 'type' => $d['type'], 'amount' => round((float) $d['amount'], 2),
            'payment_mode' => $d['type'] === 'deduction' ? null : $d['payment_mode'],
            'for_month' => $d['type'] === 'salary' ? ($d['for_month'] ?? substr($d['date'], 0, 7)) : null,
            'note' => ($d['note'] ?? null) ?: null, 'updated_by' => $request->user()->id,
        ];
    }
}
