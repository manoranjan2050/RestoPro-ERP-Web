<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\ExpenseCategories;
use App\Livewire\Expenses;
use App\Livewire\Vendors;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategory $milk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 20:00:00');
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->milk = ExpenseCategory::where('name', 'Milk')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function expense(array $attrs = []): Expense
    {
        return Expense::create($attrs + ['date' => '2026-11-10', 'category_id' => $this->milk->id, 'amount' => 500, 'direct_paid' => 500, 'payment_mode' => 'cash']);
    }

    public function test_default_categories_are_seeded_by_migration(): void
    {
        $this->assertSame(
            ['Chena/Paneer', 'Electricity', 'Gas', 'Grocery', 'Koila', 'Milk', 'PAN/TEA/EXTRA', 'Vegetable', 'Other'],
            ExpenseCategory::ordered()->pluck('name')->all(),
        );
    }

    public function test_pages_render(): void
    {
        $this->get('/expenses')->assertOk()->assertSee('Add expense');
        $this->get('/vendors')->assertOk()->assertSee('Add vendor');
    }

    public function test_add_paid_expense(): void
    {
        Livewire::test(Expenses::class)
            ->call('create')
            ->set('amount', '840')->set('category_id', (string) $this->milk->id)->set('payment_mode', 'upi')->set('note', '20 L')
            ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

        $e = Expense::sole();
        $this->assertSame('paid', $e->status);
        $this->assertSame('840.00', $e->paid_amount);
        $this->assertSame('upi', $e->payment_mode);
        $this->assertSame('2026-11-15', $e->paid_on->toDateString());
        $this->assertSame('2026-11-15', $e->getRawOriginal('date'));
    }

    public function test_add_unpaid_expense_with_new_vendor_and_new_category(): void
    {
        Livewire::test(Expenses::class)
            ->call('create')
            ->set('amount', '1200')
            ->set('category_id', '__new')->set('new_category', 'Oil')
            ->set('vendor_id', '__new')->set('new_vendor', 'Sharma Traders')
            ->set('status', 'unpaid')
            ->call('save')->assertHasNoErrors();

        $e = Expense::with('vendor', 'category')->sole();
        $this->assertSame('unpaid', $e->status);
        $this->assertNull($e->payment_mode);
        $this->assertNull($e->paid_on);
        $this->assertSame('Oil', $e->category->name);
        $this->assertSame('Sharma Traders', $e->vendor->name);
        $this->assertSame($e->category_id, $e->vendor->default_category_id);
        $this->assertSame(1200.0, $e->vendor->due());
    }

    public function test_validation(): void
    {
        Livewire::test(Expenses::class)
            ->call('create')
            ->set('date', '2026-11-20')->set('amount', '0')
            ->call('save')
            ->assertHasErrors(['date', 'amount', 'category_id']);

        Livewire::test(Expenses::class)
            ->call('create')->set('amount', 5)->set('category_id', '__new')->set('new_category', 'Milk')
            ->call('save')->assertHasErrors(['new_category']);

        $this->assertSame(0, Expense::count());
    }

    public function test_save_and_add_another_keeps_date_and_category(): void
    {
        Livewire::test(Expenses::class)
            ->call('create')->set('date', '2026-11-12')->set('amount', 100)->set('category_id', (string) $this->milk->id)
            ->call('save', true)
            ->assertSet('showForm', true)
            ->assertSet('date', '2026-11-12')
            ->assertSet('category_id', (string) $this->milk->id)
            ->assertSet('amount', '');
        $this->assertSame(1, Expense::count());
    }

    public function test_vendor_preselects_usual_category(): void
    {
        $v = Vendor::create(['name' => 'Gopal Dairy', 'default_category_id' => $this->milk->id]);
        Livewire::test(Expenses::class)->call('create')->set('vendor_id', (string) $v->id)
            ->assertSet('category_id', (string) $this->milk->id);
    }

    public function test_mark_paid_and_edit_status(): void
    {
        $e = $this->expense(['direct_paid' => 0]);
        $this->assertSame('unpaid', $e->fresh()->status);

        Livewire::test(Expenses::class)->call('markPaid', $e->id, 'upi');
        $e->refresh();
        $this->assertSame('paid', $e->status);
        $this->assertSame('upi', $e->payment_mode);

        Livewire::test(Expenses::class)->call('edit', $e->id)->set('status', 'unpaid')->call('save')->assertHasNoErrors();
        $this->assertSame('unpaid', $e->fresh()->status);
        $this->assertSame('0.00', $e->fresh()->paid_amount);
    }

    public function test_partial_status_is_preserved_on_edit(): void
    {
        $e = $this->expense(['amount' => 1000, 'direct_paid' => 400]);
        $this->assertSame('partial', $e->status);

        Livewire::test(Expenses::class)->call('edit', $e->id)->assertSet('status', 'partial')
            ->set('note', 'fixed note')->call('save')->assertHasNoErrors();
        $this->assertSame('400.00', $e->fresh()->paid_amount);
        $this->assertSame('partial', $e->fresh()->status);
    }

    public function test_bill_upload_is_private_and_removed_with_expense(): void
    {
        Storage::fake('local');

        Livewire::test(Expenses::class)
            ->call('create')->set('amount', 300)->set('category_id', (string) $this->milk->id)
            ->set('bill', UploadedFile::fake()->image('bill.jpg'))
            ->call('save')->assertHasNoErrors();

        $e = Expense::sole();
        Storage::disk('local')->assertExists($e->bill_path);
        $this->get(route('expenses.bill', $e))->assertOk();

        auth()->logout();
        $this->get(route('expenses.bill', $e))->assertRedirect('/login');

        $e->delete();
        Storage::disk('local')->assertMissing($e->bill_path);
    }

    public function test_filters_and_totals(): void
    {
        $v = Vendor::create(['name' => 'Gopal Dairy']);
        $gas = ExpenseCategory::where('name', 'Gas')->first();
        $this->expense(['amount' => 500, 'vendor_id' => $v->id]);
        $this->expense(['amount' => 1100, 'direct_paid' => 0, 'category_id' => $gas->id, 'note' => 'cylinder']);
        $this->expense(['amount' => 999, 'date' => '2026-10-05']);

        Livewire::test(Expenses::class)
            ->assertViewHas('monthTotals', fn ($t) => $t['amount'] === 1600.0 && $t['unpaid'] === 1100.0)
            ->assertViewHas('byCategory', fn ($c) => $c[0]['name'] === 'Gas' && $c[1]['name'] === 'Milk')
            ->set('filterStatus', 'due')->assertViewHas('entries', fn ($e) => $e->count() === 1)
            ->set('filterStatus', '')->set('search', 'cylind')->assertViewHas('entries', fn ($e) => $e->count() === 1)
            ->set('search', '')->set('filterVendor', (string) $v->id)->assertViewHas('entries', fn ($e) => $e->count() === 1)
            ->set('filterVendor', 'none')->assertViewHas('entries', fn ($e) => $e->count() === 1)
            ->call('clearFilters')->call('shiftMonth', -1)
            ->assertViewHas('monthTotals', fn ($t) => $t['amount'] === 999.0);
    }

    public function test_manager_cannot_delete(): void
    {
        $e = $this->expense();
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        Livewire::test(Expenses::class)->call('confirmDelete', $e->id)->assertForbidden();
        $this->assertModelExists($e);
    }

    public function test_category_manager(): void
    {
        Livewire::test(ExpenseCategories::class)
            ->call('show')
            ->set('newName', 'Oil')->call('add')->assertHasNoErrors()
            ->set('newName', 'oil')->call('add')->assertHasErrors('newName');

        $oil = ExpenseCategory::where('name', 'Oil')->first();
        Livewire::test(ExpenseCategories::class)->call('show')
            ->call('startEdit', $oil->id)->set('editName', 'Cooking Oil')->set('editColor', '#ec4899')->call('saveEdit')->assertHasNoErrors()
            ->call('toggle', $oil->id);
        $oil->refresh();
        $this->assertSame('Cooking Oil', $oil->name);
        $this->assertFalse($oil->is_active);

        $this->expense();
        Livewire::test(ExpenseCategories::class)->call('show')->call('delete', $this->milk->id)->assertHasErrors('delete');
        $this->assertModelExists($this->milk);
        Livewire::test(ExpenseCategories::class)->call('show')->call('delete', $oil->id)->assertHasNoErrors();
        $this->assertModelMissing($oil);
    }

    public function test_vendor_crud_and_dues(): void
    {
        Livewire::test(Vendors::class)
            ->call('create')->set('name', 'Gopal Dairy')->set('mobile', '+91 98765 11111')
            ->set('default_category_id', (string) $this->milk->id)->set('opening_balance', '2500')
            ->call('save')->assertHasNoErrors();

        $v = Vendor::sole();
        $this->assertSame('9876511111', $v->mobile);
        $this->expense(['vendor_id' => $v->id, 'amount' => 1000, 'direct_paid' => 300]);
        $this->assertSame(3200.0, Vendor::withDue()->find($v->id)->due());

        Livewire::test(Vendors::class)
            ->assertViewHas('totalDue', 3200.0)
            ->set('show', 'due')->assertViewHas('vendors', fn ($l) => $l->count() === 1)
            ->call('create')->set('name', 'gopal dairy')->call('save')->assertHasErrors('name');

        // Vendor with bills is deactivated, not deleted.
        Livewire::test(Vendors::class)->call('confirmDelete', $v->id)->call('delete');
        $this->assertFalse($v->fresh()->is_active);

        $empty = Vendor::create(['name' => 'Unused']);
        Livewire::test(Vendors::class)->call('confirmDelete', $empty->id)->call('delete');
        $this->assertModelMissing($empty);
    }

    public function test_dashboard_profit(): void
    {
        CashCollection::create(['date' => '2026-11-14', 'cash' => 10000]);
        $this->expense(['amount' => 2500, 'direct_paid' => 0]);

        Livewire::test(Dashboard::class)
            ->assertSee('₹10,000')
            ->assertSee('₹2,500')
            ->assertSee('₹7,500')
            ->assertSee('Margin 75%');
    }
}
