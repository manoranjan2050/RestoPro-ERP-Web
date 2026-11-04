<?php

namespace Tests\Feature;

use App\Livewire\Reports;
use App\Models\CashCollection;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing;
use App\Services\ReportBuilder;
use App\Services\SalesReports;
use App\Services\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SalesReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Item $cake;
    private Item $puff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 14:30:00');
        $this->owner = User::factory()->create(['role' => 'owner']);
        Setting::put('app_mode', 'advanced');
        Setting::put('business_gstin', '21ABCDE1234F1Z5');
        Setting::put('gst_enabled', '1');
        Setting::put('pos_round_off', '0');
        $cakes = ItemCategory::create(['name' => 'Cakes']);
        $snacks = ItemCategory::create(['name' => 'Snacks']);
        $this->cake = Item::create(['name' => 'Black Forest', 'price' => 525, 'gst_rate' => 5, 'hsn' => '1905', 'category_id' => $cakes->id]);
        $this->puff = Item::create(['name' => 'Veg Puff', 'price' => 118, 'gst_rate' => 18, 'hsn' => '2106', 'category_id' => $snacks->id, 'track_stock' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bill(array $lines, array $pays, array $opts = []): Order
    {
        $cart = array_map(fn ($l) => ['item_id' => $l[0]->id, 'variant_id' => null, 'qty' => $l[1], 'note' => ''], $lines);

        return Billing::save($cart, $opts, $pays, $this->owner);
    }

    public function test_reports_only_in_advanced_mode(): void
    {
        $this->assertArrayHasKey('day_end', ReportBuilder::types());
        Setting::put('app_mode', 'simple');
        $this->assertArrayNotHasKey('day_end', ReportBuilder::types());
        $this->actingAs($this->owner)->get(route('reports.download', ['type' => 'day_end', 'format' => 'pdf', 'date' => '2026-11-10']))->assertNotFound();
    }

    public function test_item_hourly_and_bill_register(): void
    {
        $a = $this->bill([[$this->cake, 2], [$this->puff, 3]], [['mode' => 'cash', 'amount' => 1404]]);          // 1050 + 354
        Carbon::setTestNow('2026-11-10 18:05:00');
        $b = $this->bill([[$this->puff, 1]], [['mode' => 'upi', 'amount' => 106.2]], ['discount_value' => 10, 'discount_type' => 'pct']);
        $c = $this->bill([[$this->cake, 1]], [['mode' => 'cash', 'amount' => 525]]);
        Billing::cancel($c, 'wrong item', $this->owner);

        $items = SalesReports::items('2026-11-10', '2026-11-10');
        $this->assertSame([2, 6.0, 1510.2, 2], array_map(fn ($k) => $k[1], $items['kpis']));
        $byItem = collect($items['sections'][0]['rows'])->keyBy(0);
        $this->assertEquals([2, 1050], [$byItem['Black Forest'][3], $byItem['Black Forest'][7]]);
        $this->assertEquals([4, 460.2, 11.8], [$byItem['Veg Puff'][3], $byItem['Veg Puff'][7], $byItem['Veg Puff'][6]]);
        $this->assertSame(['Cakes', 'Snacks'], array_column($items['sections'][1]['rows'], 0));

        $hourly = SalesReports::hourly('2026-11-10', '2026-11-10');
        $this->assertSame(['2 PM – 3 PM', '6 PM – 7 PM'], array_column($hourly['sections'][0]['rows'], 0));
        $this->assertSame('2 PM–3 PM', $hourly['kpis'][2][1]);

        $reg = SalesReports::bills('2026-11-10', '2026-11-10');
        $this->assertSame([2, 1510.2, 11.8, 1], array_map(fn ($k) => $k[1], $reg['kpis']));
        $this->assertCount(3, $reg['sections'][0]['rows']);
        $this->assertSame([[$b->number, 118.0, 11.8, 10.0]], $reg['sections'][1]['rows']);
        $this->assertSame('wrong item', $reg['sections'][2]['rows'][0][3]);
    }

    public function test_gst_summary(): void
    {
        $this->bill([[$this->cake, 2], [$this->puff, 1]], [['mode' => 'cash', 'amount' => 1168]]);
        Setting::put('gst_enabled', '0');
        $this->bill([[$this->cake, 1]], [['mode' => 'cash', 'amount' => 525]]); // plain bill → not in GST report

        $g = SalesReports::gst('2026-11-01', '2026-11-30');
        $rates = collect($g['sections'][0]['rows'])->keyBy(0);
        $this->assertEquals([1000, 25, 25, 50, 1050], array_slice($rates['5%'], 1));
        $this->assertEquals([100, 9, 9, 18, 118], array_slice($rates['18%'], 1));
        $this->assertSame([1, 1100.0, 34.0, 34.0, 68.0, 1168.0], array_map(fn ($k) => $k[1], $g['kpis']));
        $this->assertSame(['1905', '2106'], array_column($g['sections'][1]['rows'], 0));
    }

    public function test_day_end_counts_money_by_payment_date(): void
    {
        Setting::put('gst_enabled', '0');
        CashCollection::create(['date' => '2026-11-10', 'cash' => 200]); // typed by hand
        $this->bill([[$this->cake, 1]], [['mode' => 'cash', 'amount' => 300], ['mode' => 'card', 'amount' => 225]]);
        // advance booked today for tomorrow: money in today, not a sale yet
        Billing::save([['item_id' => $this->cake->id, 'qty' => 2]], ['mode' => 'open', 'type' => 'advance', 'due_at' => '2026-11-11 17:00',
            'customer_name' => 'Meena', 'customer_mobile' => '9000000033'], [['mode' => 'upi', 'amount' => 400]], $this->owner);
        Stock::move($this->puff, null, 10, 'made');
        Stock::move($this->puff, null, -2, 'wastage', null, 'stale');

        $r = SalesReports::dayEnd('2026-11-10');
        $this->assertSame([1, 525.0, 925.0], [$r['kpis'][0][1], $r['kpis'][1][1], $r['kpis'][2][1]]);
        $modes = collect($r['sections'][0]['rows'])->keyBy(0);
        $this->assertEquals([300, 400, 225], [$modes['Cash'][2], $modes['UPI'][2], $modes['Card'][2]]);
        $titles = array_column($r['sections'], 'title');
        $this->assertContains('Sales typed in Cash Collection (not billed)', $titles);
        $this->assertContains('Advance orders due tomorrow', $titles);
        $this->assertContains('Wastage today', $titles);
        $this->assertStringContainsString('Advances taken today', implode(' ', $r['notes']));

        $this->actingAs($this->owner)->get(route('reports.day-end.print', ['date' => '2026-11-10']))->assertOk()
            ->assertSee('DAY-END REPORT')->assertSee('Collected today')->assertSee('window.print()', false);
        $this->get(route('reports.download', ['type' => 'day_end', 'format' => 'pdf', 'date' => '2026-11-10']))->assertOk();
        $this->get(route('reports.download', ['type' => 'gst_summary', 'format' => 'xlsx', 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk();
    }

    public function test_reports_page_and_dashboard(): void
    {
        $this->bill([[$this->puff, 2]], [['mode' => 'cash', 'amount' => 236]]);
        Livewire::actingAs($this->owner)->test(Reports::class)->set('type', 'day_end')
            ->assertSet('date', '2026-11-10')->assertSee('Day-end report')->assertSee('Thermal')
            ->call('shiftDay', -1)->assertSet('date', '2026-11-09')->call('shiftDay', 5)->assertSet('date', '2026-11-10');
        $this->get(route('reports', ['type' => 'item_sales']))->assertOk()->assertSee('Veg Puff');

        $this->get('/')->assertOk()->assertSee('Counter today')->assertSee('Top items today')->assertSee('Veg Puff')->assertSee('Low stock');

        Setting::put('app_mode', 'simple');
        $this->get('/')->assertDontSee('Counter today');
    }
}
