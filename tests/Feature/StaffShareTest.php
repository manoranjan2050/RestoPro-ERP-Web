<?php

namespace Tests\Feature;

use App\Livewire\StaffProfile;
use App\Livewire\StaffShare;
use App\Models\Attendance;
use App\Models\Staff;
use App\Models\User;
use App\Services\StaffLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class StaffShareTest extends TestCase
{
    use RefreshDatabase;

    private Staff $s;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 20:00:00');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->s = Staff::create([
            'name' => 'Raju Das', 'designation' => 'Cook', 'joining_date' => '2026-03-01', 'salary_type' => 'monthly',
            'salary_amount' => 9000, 'mobile' => '9876543210', 'id_number' => 'SECRET-AADHAAR-1234', 'address' => 'Secret Street 9',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function share(?string $pin = null): string
    {
        $this->actingAs($this->owner);
        $c = Livewire::test(StaffProfile::class, ['staff' => $this->s])->call('openShare')->call('createShareLink');
        if ($pin) {
            $c->set('sharePin', $pin)->call('savePin')->assertHasNoErrors();
        }
        auth()->logout();

        return $this->s->fresh()->share_token;
    }

    public function test_year_rows_and_full_year_totals(): void
    {
        $this->s->payments()->createMany([
            ['date' => '2026-03-20', 'type' => 'advance', 'amount' => 1000, 'payment_mode' => 'cash'],
            ['date' => '2026-04-05', 'type' => 'salary', 'amount' => 8000, 'payment_mode' => 'upi', 'for_month' => '2026-03'],
        ]);

        $years = StaffLedger::financialYears($this->s, 4);
        $this->assertSame([2026 => 'FY 2026-27', 2025 => 'FY 2025-26'], $years);

        $fy25 = StaffLedger::yearRows($this->s, 2025);
        $this->assertSame(['2026-03'], $fy25->pluck('month')->all());
        $this->assertSame(8000.0, StaffLedger::totals($fy25)['closing']); // 9000 − 1000 advance

        $fy26 = StaffLedger::yearRows($this->s, 2026);
        $this->assertCount(8, $fy26); // Apr..Nov
        $t = StaffLedger::totals($fy26);
        $this->assertSame(8000.0, $t['opening']);            // carried in from FY 2025-26
        $this->assertSame(9000.0 * 7 + 4500, $t['work_value']); // Apr–Oct full + half of Nov
        $this->assertSame(8000.0, $t['salary']);
        $this->assertSame(8000.0 + 67500 - 8000, $t['closing']);
    }

    public function test_ledger_tab_renders(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('staff.show', $this->s).'?tab=ledger')->assertOk()
            ->assertSee('FY 2026-27')->assertSee('Full year work value')->assertSee('Full year total paid');

        Livewire::test(StaffProfile::class, ['staff' => $this->s])->set('tab', 'ledger')->set('fy', 2025)
            ->assertViewHas('yearRows', fn ($r) => $r->count() === 1);
    }

    public function test_public_link_shows_only_this_staff_without_login(): void
    {
        Attendance::create(['staff_id' => $this->s->id, 'date' => '2026-11-03', 'status' => 'A']);
        $other = Staff::create(['name' => 'Other Person', 'joining_date' => '2026-01-01', 'salary_type' => 'daily', 'salary_amount' => 300]);
        $token = $this->share();

        $this->assertGuest();
        $res = $this->get('/s/'.$token)->assertOk()
            ->assertSee('Raju Das')->assertSee('Salary due to you')->assertSee('November 2026')
            ->assertDontSee('Other Person')->assertDontSee('SECRET-AADHAAR-1234')->assertDontSee('Secret Street 9')
            ->assertDontSee('9876543210');
        $this->assertStringContainsString('noindex', $res->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('noindex', $res->getContent());
    }

    public function test_bad_or_revoked_or_regenerated_tokens_404(): void
    {
        $this->get('/s/'.str_repeat('a', 32))->assertNotFound();
        $this->get('/s/short')->assertNotFound();

        $old = $this->share();
        $this->actingAs($this->owner);
        Livewire::test(StaffProfile::class, ['staff' => $this->s])->call('regenerateShareLink');
        $new = $this->s->fresh()->share_token;
        $this->assertNotSame($old, $new);
        $this->get('/s/'.$old)->assertNotFound();
        $this->get('/s/'.$new)->assertOk();

        Livewire::test(StaffProfile::class, ['staff' => $this->s])->call('revokeShareLink');
        $this->assertNull($this->s->fresh()->share_token);
        $this->get('/s/'.$new)->assertNotFound();
    }

    public function test_pin_gate_and_rate_limit(): void
    {
        $token = $this->share('4821');
        $this->assertNotSame('4821', $this->s->fresh()->share_pin); // stored hashed

        $this->get('/s/'.$token)->assertOk()->assertSee('Enter your 4-digit PIN')->assertDontSee('Salary due to you');

        $c = Livewire::test(StaffShare::class, ['token' => $token]);
        for ($i = 0; $i < 5; $i++) {
            $c->set('pin', '0000')->call('unlock')->assertHasErrors('pin');
        }
        $c->set('pin', '4821')->call('unlock')->assertHasErrors('pin'); // locked out even with right PIN
        $c->assertSee('Enter your 4-digit PIN');

        \Illuminate\Support\Facades\RateLimiter::clear('share-pin:'.$this->s->id.'|127.0.0.1');
        Livewire::test(StaffShare::class, ['token' => $token])
            ->set('pin', '4821')->call('unlock')->assertHasNoErrors()
            ->assertSee('Salary due to you');
    }

    public function test_pin_validation_and_remove(): void
    {
        $this->actingAs($this->owner);
        $c = Livewire::test(StaffProfile::class, ['staff' => $this->s])->call('createShareLink')
            ->set('sharePin', '12a4')->call('savePin')->assertHasErrors('sharePin')
            ->set('sharePin', '123')->call('savePin')->assertHasErrors('sharePin')
            ->set('sharePin', '1234')->call('savePin')->assertHasNoErrors();
        $this->assertNotNull($this->s->fresh()->share_pin);
        $c->call('removePin');
        $this->assertNull($this->s->fresh()->share_pin);
    }

    public function test_public_page_cannot_browse_before_joining_or_into_future(): void
    {
        $token = $this->share();
        Livewire::test(StaffShare::class, ['token' => $token])
            ->call('shiftMonth', 1)->assertSet('month', '2026-11')
            ->set('month', '2026-02')->assertSee('November 2026') // render clamps
            ->call('shiftMonth', -1)->assertSet('month', '2026-10');
    }

    public function test_locked_token_property_cannot_be_swapped(): void
    {
        $token = $this->share();
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(StaffShare::class, ['token' => $token])->set('token', 'x');
    }
}
