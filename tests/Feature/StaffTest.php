<?php

namespace Tests\Feature;

use App\Livewire\AttendanceRegister;
use App\Livewire\StaffList;
use App\Livewire\StaffProfile;
use App\Models\Attendance;
use App\Models\Staff;
use App\Models\StaffPayment;
use App\Models\User;
use App\Services\StaffLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 20:00:00');
        $this->actingAs(User::factory()->create(['role' => 'owner']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $a = []): Staff
    {
        return Staff::create($a + ['name' => 'Raju Das', 'joining_date' => '2026-09-01', 'salary_type' => 'monthly', 'salary_amount' => 9000]);
    }

    private function att(Staff $s, string $date, string $status): void
    {
        Attendance::create(['staff_id' => $s->id, 'date' => $date, 'status' => $status]);
    }

    public function test_monthly_salary_deducts_absent_and_half_days(): void
    {
        $s = $this->staff(); // Sep has 30 days → ₹300/day
        $this->att($s, '2026-09-03', 'A');
        $this->att($s, '2026-09-04', 'A');
        $this->att($s, '2026-09-10', 'H');
        $this->att($s, '2026-09-11', 'L'); // paid leave
        $this->att($s, '2026-09-14', 'O'); // week off, paid

        $sep = StaffLedger::month($s, '2026-09');
        $this->assertSame(300.0, $sep['per_day']);
        $this->assertSame(27.5, $sep['paid_days']);
        $this->assertSame(8250.0, $sep['work_value']);
        $this->assertSame(25, $sep['unmarked']);
    }

    public function test_joining_mid_month_and_current_month_accrue_until_today(): void
    {
        $s = $this->staff(['joining_date' => '2026-10-21']); // Oct: 31 days, 11 days worked
        $oct = StaffLedger::month($s, '2026-10');
        $this->assertSame(11, $oct['period_days']);
        $this->assertSame(3194.0, $oct['work_value']); // 9000 / 31 × 11 = 3193.55 → whole rupees

        $nov = StaffLedger::month($s, '2026-11'); // today is 15 Nov → 15 of 30 days
        $this->assertSame(15, $nov['period_days']);
        $this->assertSame(4500.0, $nov['work_value']);
        $this->assertTrue($nov['in_progress']);
    }

    public function test_daily_wage_counts_only_present_and_half(): void
    {
        $s = $this->staff(['salary_type' => 'daily', 'salary_amount' => 400]);
        foreach (['01', '02', '03'] as $d) {
            $this->att($s, "2026-09-$d", 'P');
        }
        $this->att($s, '2026-09-04', 'H');
        $this->att($s, '2026-09-05', 'A');
        $this->att($s, '2026-09-06', 'L');

        $sep = StaffLedger::month($s, '2026-09');
        $this->assertSame(3.5, $sep['paid_days']);
        $this->assertSame(1400.0, $sep['work_value']);
    }

    public function test_carry_forward_with_advance_bonus_deduction(): void
    {
        $s = $this->staff(['opening_balance' => -1000]); // they owed us 1000 when we started
        $s->payments()->createMany([
            ['date' => '2026-09-10', 'type' => 'advance', 'amount' => 2000, 'payment_mode' => 'cash'],
            ['date' => '2026-10-05', 'type' => 'salary', 'amount' => 5000, 'payment_mode' => 'upi', 'for_month' => '2026-09'],
            ['date' => '2026-10-20', 'type' => 'bonus', 'amount' => 1500, 'payment_mode' => 'cash'],
            ['date' => '2026-10-25', 'type' => 'deduction', 'amount' => 300],
        ]);

        $rows = StaffLedger::months($s)->keyBy('month');
        // Sep: −1000 + 9000 − 2000 = 6000
        $this->assertSame(-1000.0, $rows['2026-09']['opening']);
        $this->assertSame(6000.0, $rows['2026-09']['closing']);
        // Oct: 6000 + 9000 + 1500 − 300 − (5000 + 1500) = 9700
        $this->assertSame(6000.0, $rows['2026-10']['opening']);
        $this->assertSame(10200.0, $rows['2026-10']['earned']);
        $this->assertSame(6500.0, $rows['2026-10']['paid']);
        $this->assertSame(9700.0, $rows['2026-10']['closing']);
        // Nov (15 of 30 days): 9700 + 4500
        $this->assertSame(14200.0, StaffLedger::balance($s));

        $t = StaffLedger::totals(StaffLedger::months($s));
        $this->assertSame(2000.0, $t['advance']);
        $this->assertSame(7000.0, $t['paid'] - $t['bonus']); // 5000 salary + 2000 advance
    }

    public function test_left_staff_stop_accruing(): void
    {
        $s = $this->staff(['leaving_date' => '2026-10-10']);
        $rows = StaffLedger::months($s);
        $this->assertSame(['2026-09', '2026-10'], $rows->pluck('month')->all());
        $this->assertSame(10, $rows[1]['period_days']);
    }

    public function test_add_staff_with_photo_redirects_to_profile(): void
    {
        Storage::fake('public');

        Livewire::test(StaffList::class)
            ->call('createStaff')
            ->set('sf.name', 'Sita Behera')->set('sf.mobile', '+91 90000 11111')->set('sf.designation', 'Cook')
            ->set('sf.salary_type', 'monthly')->set('sf.salary_amount', '12000')
            ->set('sf.opening_balance', '500')->set('sf.opening_direction', 'staff_owes')
            ->set('staffPhoto', UploadedFile::fake()->image('sita.jpg'))
            ->call('saveStaff')->assertHasNoErrors()
            ->assertRedirect(route('staff.show', Staff::sole()));

        $s = Staff::sole();
        $this->assertSame('9000011111', $s->mobile);
        $this->assertSame('-500.00', $s->opening_balance);
        Storage::disk('public')->assertExists($s->photo_path);
        $this->get(route('media', $s->photo_path))->assertOk();
    }

    public function test_staff_validation_and_leaving_makes_inactive(): void
    {
        Livewire::test(StaffList::class)->call('createStaff')
            ->set('sf.name', '')->set('sf.salary_amount', '0')->set('sf.joining_date', '2026-10-01')->set('sf.leaving_date', '2026-09-01')
            ->call('saveStaff')->assertHasErrors(['sf.name', 'sf.salary_amount', 'sf.leaving_date']);

        $s = $this->staff();
        Livewire::test(StaffProfile::class, ['staff' => $s])
            ->call('editStaff', $s->id)->set('sf.leaving_date', '2026-11-01')->call('saveStaff')->assertHasNoErrors();
        $this->assertFalse($s->fresh()->is_active);
    }

    public function test_daily_register_mark_clear_and_mark_all(): void
    {
        $a = $this->staff();
        $b = $this->staff(['name' => 'Bapi']);
        $c = $this->staff(['name' => 'Future', 'joining_date' => '2026-12-01']);

        Livewire::test(AttendanceRegister::class)
            ->assertSee('Raju Das')->assertDontSee('Future')
            ->call('mark', $a->id, 'A')
            ->call('markAllPresent');

        $this->assertSame('A', Attendance::where('staff_id', $a->id)->value('status'));
        $this->assertSame('P', Attendance::where('staff_id', $b->id)->value('status'));
        $this->assertSame(0, Attendance::where('staff_id', $c->id)->count());

        Livewire::test(AttendanceRegister::class)->call('mark', $a->id, 'A'); // tap again clears
        $this->assertSame(0, Attendance::where('staff_id', $a->id)->count());
    }

    public function test_cycle_and_future_or_pre_joining_dates_are_ignored(): void
    {
        $s = $this->staff();
        foreach (['P', 'H', 'A', 'L', 'O', null, 'P'] as $expected) {
            $this->assertSame($expected, Attendance::cycle($s, '2026-11-02'));
        }
        $this->assertNull(Attendance::cycle($s, '2026-11-20'));
        $this->assertNull(Attendance::cycle($s, '2026-08-20'));
        $this->assertSame(1, Attendance::count());

        Livewire::test(AttendanceRegister::class)->set('view', 'month')->call('cycle', $s->id, '2026-11-03');
        $this->assertSame(2, Attendance::count());
    }

    public function test_profile_payments_crud_and_salary_prefill(): void
    {
        $s = $this->staff(['joining_date' => '2026-11-01']); // 15 days of 9000/30 = 4500 earned

        Livewire::test(StaffProfile::class, ['staff' => $s])
            ->assertSee('Raju Das')
            ->call('newPayment', 'salary')
            ->assertSet('p_amount', '4500')
            ->assertSet('p_for_month', '2026-11')
            ->set('p_amount', '4000')->set('p_mode', 'upi')
            ->call('savePayment')->assertHasNoErrors()
            ->assertViewHas('balance', 500.0)
            ->call('newPayment', 'deduction')->set('p_amount', '200')->call('savePayment')->assertHasNoErrors()
            ->assertViewHas('balance', 300.0);

        $ded = StaffPayment::where('type', 'deduction')->sole();
        $this->assertNull($ded->payment_mode);

        $sal = StaffPayment::where('type', 'salary')->sole();
        Livewire::test(StaffProfile::class, ['staff' => $s])
            ->call('editPayment', $sal->id)->set('p_amount', '4300')->call('savePayment')
            ->assertViewHas('balance', 0.0)
            ->call('confirmDelete', $ded->id)->call('deletePayment')
            ->assertViewHas('balance', 200.0);
    }

    public function test_manager_cannot_delete_payment_and_pages_render(): void
    {
        $s = $this->staff();
        $p = $s->payments()->create(['date' => '2026-11-01', 'type' => 'advance', 'amount' => 100, 'payment_mode' => 'cash']);

        $this->get('/staff')->assertOk()->assertSee('Raju Das');
        $this->get('/attendance')->assertOk();
        $this->get('/attendance?view=month')->assertOk()->assertSee('November 2026');
        $this->get(route('staff.show', $s).'?tab=attendance')->assertOk();
        $this->get(route('staff.show', $s).'?tab=payments')->assertOk()->assertSee('Advance');

        $this->actingAs(User::factory()->create(['role' => 'manager']));
        Livewire::test(StaffProfile::class, ['staff' => $s])->call('confirmDelete', $p->id)->assertForbidden();
    }
}
