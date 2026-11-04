<?php

namespace Tests\Feature;

use App\Livewire\Collections;
use App\Livewire\Dashboard;
use App\Livewire\RevenueLog;
use App\Models\CashCollection;
use App\Models\User;
use App\Services\RevenueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CollectionsTest extends TestCase
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

    public function test_pages_render(): void
    {
        $this->get('/collections')->assertOk()->assertSee('Add collection');
        $this->get('/revenue')->assertOk()->assertSee('Sales trend');
    }

    public function test_add_collection_computes_total_and_stores_plain_date(): void
    {
        Livewire::test(Collections::class)
            ->call('create')
            ->assertSet('date', '2026-11-15')
            ->set('cash', '4500.50')->set('upi', '3200')->set('online', '')
            ->set('note', 'Busy Sunday')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $c = CashCollection::sole();
        $this->assertSame('7700.50', $c->total);
        $this->assertSame('0.00', $c->online);
        $this->assertSame('2026-11-15', $c->getRawOriginal('date'));
        $this->assertSame(auth()->id(), $c->created_by);
    }

    public function test_duplicate_date_is_rejected_and_offered_for_edit(): void
    {
        $existing = CashCollection::create(['date' => '2026-11-10', 'cash' => 100]);

        Livewire::test(Collections::class)
            ->call('create', '2026-11-10')
            ->assertSet('duplicateId', $existing->id)
            ->set('cash', 50)
            ->call('save')
            ->assertHasErrors(['date' => 'unique'])
            ->call('editDuplicate')
            ->assertSet('editingId', $existing->id)
            ->assertSet('cash', '100');
    }

    public function test_future_date_and_negative_amounts_rejected(): void
    {
        Livewire::test(Collections::class)
            ->call('create', '2026-11-16')
            ->set('cash', -5)
            ->call('save')
            ->assertHasErrors(['date', 'cash']);
        $this->assertSame(0, CashCollection::count());
    }

    public function test_edit_updates_total_and_can_move_date(): void
    {
        $c = CashCollection::create(['date' => '2026-11-10', 'cash' => 100, 'upi' => 50]);

        Livewire::test(Collections::class)
            ->call('edit', $c->id)
            ->set('upi', 400)
            ->set('date', '2026-10-31')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('month', '2026-10');

        $c->refresh();
        $this->assertSame('500.00', $c->total);
        $this->assertSame('2026-10-31', $c->date->toDateString());
    }

    public function test_owner_can_delete_manager_cannot(): void
    {
        $c = CashCollection::create(['date' => '2026-11-10', 'cash' => 100]);

        $this->actingAs(User::factory()->create(['role' => 'manager']));
        Livewire::test(Collections::class)->call('confirmDelete', $c->id)->assertForbidden();
        $this->assertModelExists($c);

        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(Collections::class)->call('confirmDelete', $c->id)->call('delete');
        $this->assertModelMissing($c);
    }

    public function test_month_view_lists_missing_days(): void
    {
        CashCollection::create(['date' => '2026-11-01', 'cash' => 100]);
        CashCollection::create(['date' => '2026-10-20', 'cash' => 999]);

        Livewire::test(Collections::class)
            ->assertViewHas('missing', fn ($m) => count($m) === 14) // 2nd..15th
            ->assertViewHas('totals', fn ($t) => $t['total'] === 100.0 && $t['days'] === 1)
            ->call('shiftMonth', -1)
            ->assertSet('month', '2026-10')
            ->assertViewHas('totals', fn ($t) => $t['total'] === 999.0);
    }

    public function test_revenue_grouping_by_week_and_month(): void
    {
        foreach (['2026-11-02' => 100, '2026-11-03' => 200, '2026-11-10' => 50, '2026-10-30' => 1000] as $d => $cash) {
            CashCollection::create(['date' => $d, 'cash' => $cash]);
        }

        $week = (new RevenueReport(Carbon::parse('2026-10-01'), Carbon::parse('2026-11-15'), 'week'))->rows();
        $this->assertCount(3, $week); // weeks of 26 Oct, 2 Nov, 9 Nov
        $this->assertSame(300.0, $week->firstWhere('key', '2026-11-02')['total']);

        $month = (new RevenueReport(Carbon::parse('2026-10-01'), Carbon::parse('2026-11-15'), 'month'))->rows();
        $this->assertSame(['2026-11', '2026-10'], $month->pluck('key')->all());
        $this->assertSame(350.0, $month[0]['total']);
    }

    public function test_presets_and_financial_year(): void
    {
        [$f, $t] = RevenueReport::presetRange('fy', 4);
        $this->assertSame('2026-04-01', $f->toDateString());
        $this->assertSame('2026-11-15', $t->toDateString());

        [$f] = RevenueReport::presetRange('last_month');
        $this->assertSame('2026-10-01', $f->toDateString());

        Livewire::test(RevenueLog::class)
            ->set('preset', '30d')
            ->assertSet('from', '2026-10-17')
            ->set('from', '2026-11-01')
            ->assertSet('preset', 'custom');
    }

    public function test_csv_export(): void
    {
        CashCollection::create(['date' => '2026-11-02', 'cash' => 100, 'upi' => 20]);
        CashCollection::create(['date' => '2026-11-03', 'online' => 30]);

        $res = $this->get('/revenue/export?from=2026-11-01&to=2026-11-15&group=day');
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('Period,Days,Cash,UPI,Online,Total', $csv);
        $this->assertStringContainsString('"02 Nov 2026",1,100,20,0,120', $csv);
        $this->assertStringContainsString('TOTAL,2,100,20,30,150', $csv);

        $this->get('/revenue/export?from=bad&to=2026-11-15')->assertSessionHasErrors('from');
    }

    public function test_dashboard_shows_real_numbers(): void
    {
        CashCollection::create(['date' => '2026-11-15', 'cash' => 1234, 'upi' => 1000]);
        CashCollection::create(['date' => '2026-11-01', 'cash' => 100000]);

        Livewire::test(Dashboard::class)
            ->assertSee('₹2,234')
            ->assertSee('₹1,02,234');
    }

    public function test_money_helper_formats_indian_style(): void
    {
        $this->assertSame('₹12,34,567.50', money(1234567.5));
        $this->assertSame('₹1,000', money(1000));
        $this->assertSame('-₹500', money(-500));
    }
}
