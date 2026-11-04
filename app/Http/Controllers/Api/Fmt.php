<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Staff;
use App\Models\StaffPayment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;

/**
 * JSON shapes for the Android app. Money is a number (rupees, 2 decimals), dates are "Y-m-d",
 * timestamps ISO-8601. Keep these stable — the app depends on them (see docs/API.md).
 */
class Fmt
{
    public static function money($v): float
    {
        return round((float) $v, 2);
    }

    public static function user(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'mobile' => $u->mobile,
            'role' => $u->role, 'photo_url' => $u->photoUrl(),
            'can' => ['delete' => $u->isOwner(), 'backups' => $u->isOwner()],
        ];
    }

    public static function collection(CashCollection $c): array
    {
        return [
            'id' => $c->id, 'date' => $c->date->toDateString(),
            'cash' => self::money($c->cash), 'upi' => self::money($c->upi), 'online' => self::money($c->online),
            'total' => self::money($c->total), 'note' => $c->note,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    public static function category(ExpenseCategory $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'is_active' => $c->is_active, 'sort' => $c->sort];
    }

    public static function expense(Expense $e): array
    {
        return [
            'id' => $e->id, 'date' => $e->date->toDateString(),
            'category_id' => $e->category_id, 'category' => $e->relationLoaded('category') ? $e->category?->name : null,
            'vendor_id' => $e->vendor_id, 'vendor' => $e->relationLoaded('vendor') ? $e->vendor?->name : null,
            'amount' => self::money($e->amount),
            'paid_at_purchase' => self::money($e->direct_paid),
            'paid_total' => self::money($e->paid_amount),
            'balance' => $e->balance(),
            'status' => $e->status, 'payment_mode' => $e->payment_mode, 'paid_on' => $e->paid_on?->toDateString(),
            'note' => $e->note,
            'bill_url' => $e->bill_path ? route('api.expenses.bill', $e) : null,
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }

    public static function vendor(Vendor $v): array
    {
        return [
            'id' => $v->id, 'name' => $v->name, 'mobile' => $v->mobile, 'address' => $v->address,
            'default_category_id' => $v->default_category_id, 'opening_balance' => self::money($v->opening_balance),
            'note' => $v->note, 'is_active' => $v->is_active,
            'balance' => $v->due(),
            'total_billed' => array_key_exists('total_bills', $v->getAttributes()) ? self::money($v->total_bills) : null,
        ];
    }

    public static function vendorPayment(VendorPayment $p): array
    {
        return [
            'id' => $p->id, 'vendor_id' => $p->vendor_id, 'date' => $p->date->toDateString(), 'amount' => self::money($p->amount),
            'payment_mode' => $p->payment_mode, 'note' => $p->note,
        ];
    }

    public static function staff(Staff $s, ?float $balance = null): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'mobile' => $s->mobile, 'designation' => $s->designation,
            'photo_url' => $s->photoUrl(),
            'joining_date' => $s->joining_date->toDateString(), 'leaving_date' => $s->leaving_date?->toDateString(),
            'salary_type' => $s->salary_type, 'salary_amount' => self::money($s->salary_amount),
            'opening_balance' => self::money($s->opening_balance),
            'id_type' => $s->id_type, 'id_number' => $s->id_number, 'address' => $s->address,
            'emergency_contact' => $s->emergency_contact, 'note' => $s->note, 'is_active' => $s->is_active,
            'balance' => $balance,
            'share_url' => $s->share_token ? route('staff.share', $s->share_token) : null,
            'share_has_pin' => (bool) $s->share_pin,
        ];
    }

    public static function staffPayment(StaffPayment $p): array
    {
        return [
            'id' => $p->id, 'staff_id' => $p->staff_id, 'date' => $p->date->toDateString(), 'type' => $p->type,
            'amount' => self::money($p->amount), 'payment_mode' => $p->payment_mode, 'for_month' => $p->for_month, 'note' => $p->note,
        ];
    }

    /** StaffLedger month row without the Eloquent payments collection. */
    public static function staffMonth(array $m): array
    {
        $m['payments'] = collect($m['payments'] ?? [])->map(fn ($p) => self::staffPayment($p))->values()->all();

        return $m;
    }

    public static function attendanceCodes(): array
    {
        return collect(Attendance::STATUSES)->map(fn ($v, $k) => ['code' => $k, 'label' => $v[0]])->values()->all();
    }
}
