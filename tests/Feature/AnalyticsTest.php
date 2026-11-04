<?php

namespace Tests\Feature;

use App\Livewire\AnnualSummary;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Analytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 20:00:00'); // FY 2026-27, 15 Nov
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $gas = ExpenseCategory::where('name', 'Gas')->value('id');
        $milk = ExpenseCategory::where('name', 'Milk')->value('id');
        // This FY
        CashCollection::create(['date' => '2026-09-05', 'cash' => 8000]);                // Sat
        CashCollection::create(['date' => '2026-10-04', 'cash' => 6000, 'upi' => 4000]); // Sun
        CashCollection::create(['date' => '2026-11-02', 'upi' => 5000]);                 // Mon
        Expense::create(['date' => '2026-10-10', 'category_id' => $gas, 'amount' => 3000, 'direct_paid' => 3000, 'payment_mode' => 'cash']);
        Expense::create(['date' => '2026-11-03', 'category_id' => $milk, 'amount' => 1000, 'direct_paid' => 0]);
        // Last FY: one day before 15 Nov 2025 (counts) and one after (doesn't count for like-for-like)
        CashCollection::create(['date' => '2025-10-01', 'cash' => 10000]);
        CashCollection::create(['date' => '2025-12-01', 'cash' => 99999]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_years_and_labels(): void
    {
        $this->assertSame([2026 => 'FY 2026-27', 2025 => 'FY 2025-26'], Analytics::years());
    }

    public function test_totals_months_and_like_for_like_growth(): void
    {
        $a = Analytics::build(2026);
        $this->assertCount(12, $a['months']);
        $this->assertSame('Apr', $a['months'][0]['label']);
        $this->assertTrue($a['months'][8]['future']);   // Dec 2026
        $this->assertFalse($a['months'][7]['future']);  // Nov 2026

        $this->assertSame(23000.0, $a['totals']['sales']);
        $this->assertSame(4000.0, $a['totals']['expenses']);
        $this->assertSame(19000.0, $a['totals']['net']);

        // 23000 vs 10000 (Dec 2025 is beyond the same point last year)
        $this->assertSame(10000.0, $a['compare']['sales_prev']);
        $this->assertSame(130.0, $a['compare']['sales_growth']);
        $this->assertSame(99999.0, $a['prev_months'][8]);
    }

    public function test_growth_cumulative_weekday_and_days(): void
    {
        $a = Analytics::build(2026);
        // Sep 8000 → Oct 10000 (+25%) → Nov 5000 (−50%); Apr–Aug empty → null; future → null
        $this->assertNull($a['mom'][0]);
        $this->assertSame(25.0, $a['mom'][6]);
        $this->assertSame(-50.0, $a['mom'][7]);
        $this->assertNull($a['mom'][9]);

        $this->assertSame(8000.0, $a['cumulative'][5]);     // Sep
        $this->assertSame(15000.0, $a['cumulative'][6]);    // Oct: +10000 − 3000
        $this->assertSame(19000.0, $a['cumulative'][7]);    // Nov: +5000 − 1000
        $this->assertNull($a['cumulative'][8]);

        $this->assertSame(10000.0, $a['weekday'][6]['avg']);  // Sunday
        $this->assertSame('Sun', $a['weekday'][6]['label']);
        $this->assertSame('2026-10-04', $a['best_days'][0]->date->toDateString());
        $this->assertSame('2026-11-02', $a['worst_days'][0]->date->toDateString());
    }

    public function test_insights(): void
    {
        $text = implode(' ', Analytics::build(2026)['insights']);
        $this->assertStringContainsString('Most profitable month: Sep 2026', $text);
        $this->assertStringContainsString('Sales are up 130%', $text);
        $this->assertStringContainsString('Biggest expense: Gas', $text);
        $this->assertStringContainsString('Busiest day: Sun', $text);
        $this->assertSame(['No sales recorded in this year yet.'], Analytics::build(2027)['insights']);
    }

    public function test_page_renders_and_switches_year(): void
    {
        $this->get('/analytics')->assertOk()->assertSee('FY 2026-27')->assertSee('Month by month')->assertSee('Insights');

        Livewire::test(AnnualSummary::class)
            ->assertSet('fy', 2026)
            ->set('fy', 2025)->assertViewHas('a', fn ($a) => $a['totals']['sales'] === 109999.0)
            ->set('fy', 1990)->assertSet('fy', 2026);
    }
}
