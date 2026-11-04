<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Staff;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private string $token;
    private int $milk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 20:00:00');
        Storage::fake('local');
        Storage::fake('public');
        $this->owner = User::factory()->create(['role' => 'owner', 'username' => 'owner', 'mobile' => '9876543210', 'password' => 'secret-pass-1']);
        $this->token = $this->postJson('/api/v1/auth/login', ['identifier' => '+91 98765 43210', 'password' => 'secret-pass-1', 'device_name' => 'Pixel 8'])
            ->assertOk()->json('token');
        $this->milk = ExpenseCategory::where('name', 'Milk')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(): static
    {
        return $this->withToken($this->token);
    }

    // ---------------------------------------------------------------- auth

    public function test_login_returns_token_user_and_meta(): void
    {
        $res = $this->postJson('/api/v1/auth/login', ['identifier' => 'OWNER', 'password' => 'secret-pass-1', 'device_name' => 'Phone'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.username', 'owner')
            ->assertJsonPath('user.can.delete', true)
            ->assertJsonPath('meta.currency_symbol', '₹')
            ->assertJsonCount(5, 'meta.attendance_codes');
        $this->assertStringContainsString('|', $res->json('token'));
        $this->assertSame(2, $this->owner->tokens()->count());
    }

    public function test_login_errors_and_rate_limit(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422)->assertJsonValidationErrors(['identifier', 'password', 'device_name']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['identifier' => 'owner', 'password' => 'bad', 'device_name' => 'x'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['identifier' => 'owner', 'password' => 'secret-pass-1', 'device_name' => 'x'])
            ->assertStatus(422)->assertJsonFragment(['Too many attempts. Try again in 15 minute(s).']);
    }

    public function test_unauthenticated_gets_json_401_and_logout_revokes_only_this_device(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
        $this->get('/api/v1/dashboard')->assertStatus(401); // JSON even without Accept header

        $other = $this->owner->createToken('Tablet')->plainTextToken;
        $this->api()->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.mobile', '9876543210');
        $this->api()->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/me')->assertOk();
    }

    public function test_password_change_in_web_revokes_all_app_tokens(): void
    {
        $this->actingAs($this->owner);
        \Livewire\Livewire::test(\App\Livewire\Profile::class)
            ->set('current_password', 'secret-pass-1')->set('new_password', 'new-pass-123')->set('new_password_confirmation', 'new-pass-123')
            ->call('changePassword')->assertHasNoErrors();
        $this->assertSame(0, $this->owner->tokens()->count());
    }

    // ------------------------------------------------------------ dashboard

    public function test_dashboard(): void
    {
        CashCollection::create(['date' => '2026-11-15', 'cash' => 3000, 'upi' => 2000]);
        Expense::create(['date' => '2026-11-15', 'category_id' => $this->milk, 'amount' => 800, 'direct_paid' => 800, 'payment_mode' => 'cash']);
        $this->api()->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('today.collection.total', 5000)
            ->assertJsonPath('month.profit', 4200)
            ->assertJsonCount(14, 'last_14_days.values');
    }

    // ----------------------------------------------------------- collections

    public function test_collections_crud_duplicate_and_manager_cannot_delete(): void
    {
        $id = $this->api()->postJson('/api/v1/collections', ['date' => '2026-11-10', 'cash' => 1000, 'upi' => 500])
            ->assertCreated()->assertJsonPath('data.total', 1500)->json('data.id');
        $this->api()->postJson('/api/v1/collections', ['date' => '2026-11-10', 'cash' => 1])
            ->assertStatus(422)->assertJsonPath('errors.date.0', 'An entry for this date already exists.');
        $this->api()->postJson('/api/v1/collections', ['date' => '2026-11-20'])->assertStatus(422);
        $this->api()->putJson("/api/v1/collections/{$id}", ['date' => '2026-11-10', 'cash' => 2000])->assertOk()->assertJsonPath('data.total', 2000);
        $this->api()->getJson('/api/v1/collections?month=2026-11')->assertOk()->assertJsonPath('totals.total', 2000)->assertJsonCount(1, 'data');

        $manager = User::factory()->create(['role' => 'manager']);
        $this->app['auth']->forgetGuards();
        $this->withToken($manager->createToken('m')->plainTextToken)->deleteJson("/api/v1/collections/{$id}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->api()->deleteJson("/api/v1/collections/{$id}")->assertOk();
        $this->assertSame(0, CashCollection::count());
    }

    // -------------------------------------------------------------- expenses

    public function test_expense_with_bill_photo_filters_mark_paid_and_bill_download(): void
    {
        $v = Vendor::create(['name' => 'Gopal Dairy']);
        $res = $this->api()->post('/api/v1/expenses', [
            'date' => '2026-11-12', 'category_id' => $this->milk, 'vendor_id' => $v->id, 'amount' => '1200', 'paid' => '0', 'note' => '20 L',
            'bill' => UploadedFile::fake()->image('bill.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'unpaid')->assertJsonPath('data.vendor', 'Gopal Dairy');
        $id = $res->json('data.id');
        $this->assertNotNull($res->json('data.bill_url'));
        $this->api()->get("/api/v1/expenses/{$id}/bill")->assertOk();

        $this->api()->postJson('/api/v1/expenses', ['date' => '2026-11-12', 'category_id' => $this->milk, 'amount' => 50, 'paid' => true])
            ->assertStatus(422)->assertJsonValidationErrors('payment_mode');

        $this->api()->getJson('/api/v1/expenses?month=2026-11&status=due')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('totals.unpaid', 1200);
        $this->api()->getJson('/api/v1/expenses?q=gopal')->assertJsonCount(1, 'data');
        $this->api()->postJson("/api/v1/expenses/{$id}/mark-paid", ['payment_mode' => 'upi'])->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_on', '2026-11-15');

        // Multipart edit via _method=PUT, removing the bill.
        $this->api()->post("/api/v1/expenses/{$id}", [
            '_method' => 'PUT', 'date' => '2026-11-12', 'category_id' => $this->milk, 'vendor_id' => $v->id, 'amount' => 1300,
            'paid' => 1, 'payment_mode' => 'cash', 'remove_bill' => 1,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.amount', 1300)->assertJsonPath('data.bill_url', null);
    }

    public function test_categories(): void
    {
        $this->api()->getJson('/api/v1/expense-categories')->assertOk()->assertJsonCount(9, 'data');
        $id = $this->api()->postJson('/api/v1/expense-categories', ['name' => 'Oil'])->assertCreated()->json('data.id');
        $this->api()->postJson('/api/v1/expense-categories', ['name' => 'oil'])->assertStatus(422);
        $this->api()->putJson("/api/v1/expense-categories/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }

    // --------------------------------------------------------------- vendors

    public function test_vendor_khata_flow(): void
    {
        $vid = $this->api()->postJson('/api/v1/vendors', ['name' => 'Gopal Dairy', 'mobile' => '+91 90000 11111', 'opening_balance' => 500])
            ->assertCreated()->assertJsonPath('data.mobile', '9000011111')->assertJsonPath('data.balance', 500)->json('data.id');
        Expense::create(['date' => '2026-11-01', 'category_id' => $this->milk, 'vendor_id' => $vid, 'amount' => 1000, 'direct_paid' => 0]);

        $pid = $this->api()->postJson("/api/v1/vendors/{$vid}/payments", ['date' => '2026-11-10', 'amount' => 700, 'payment_mode' => 'upi'])
            ->assertCreated()->assertJsonPath('balance', 800)->json('data.id');
        $this->api()->getJson("/api/v1/vendors/{$vid}")->assertOk()->assertJsonCount(1, 'open_bills')->assertJsonPath('open_bills.0.balance', 800);
        $this->api()->getJson("/api/v1/vendors/{$vid}/statement")->assertOk()
            ->assertJsonPath('closing', 800)->assertJsonPath('rows.1.type', 'payment')->assertJsonPath('rows.0.date', '2026-11-01');
        $this->api()->putJson("/api/v1/vendor-payments/{$pid}", ['date' => '2026-11-10', 'amount' => 1500, 'payment_mode' => 'cash'])->assertJsonPath('balance', 0);
        $this->api()->getJson('/api/v1/vendors?show=due')->assertJsonCount(0, 'data');
        $this->api()->deleteJson("/api/v1/vendors/{$vid}")->assertJsonPath('deactivated', true);
    }

    // ----------------------------------------------------------------- staff

    public function test_staff_attendance_payments_ledger_and_share(): void
    {
        $sid = $this->api()->postJson('/api/v1/staff', [
            'name' => 'Raju Das', 'designation' => 'Cook', 'joining_date' => '2026-11-01', 'salary_type' => 'monthly', 'salary_amount' => 9000,
        ])->assertCreated()->assertJsonPath('data.balance', 4500)->json('data.id'); // 15 of 30 days

        $this->api()->getJson('/api/v1/attendance?date=2026-11-15')->assertOk()->assertJsonPath('unmarked', 1);
        $this->api()->putJson('/api/v1/attendance', ['staff_id' => $sid, 'date' => '2026-11-14', 'status' => 'A'])->assertOk();
        $this->api()->putJson('/api/v1/attendance', ['staff_id' => $sid, 'date' => '2026-10-01', 'status' => 'P'])->assertStatus(422); // before joining
        $this->api()->postJson('/api/v1/attendance/mark-all', ['date' => '2026-11-15'])->assertJsonPath('marked', 1);
        $this->api()->getJson("/api/v1/staff/{$sid}/attendance?month=2026-11")
            ->assertJsonPath('data.2026-11-14', 'A')->assertJsonPath('data.2026-11-15', 'P');

        $this->api()->postJson("/api/v1/staff/{$sid}/payments", ['date' => '2026-11-15', 'type' => 'advance', 'amount' => 1000, 'payment_mode' => 'cash'])
            ->assertCreated()->assertJsonPath('balance', 3200); // 4500 − 300 (absent) − 1000
        $this->api()->postJson("/api/v1/staff/{$sid}/payments", ['date' => '2026-11-15', 'type' => 'deduction', 'amount' => 200])
            ->assertCreated()->assertJsonPath('data.payment_mode', null)->assertJsonPath('balance', 3000);

        $this->api()->getJson("/api/v1/staff/{$sid}?month=2026-11")->assertOk()
            ->assertJsonPath('month.counts.A', 1)->assertJsonPath('month.work_value', 4200)->assertJsonCount(2, 'month.payments');
        $this->api()->getJson("/api/v1/staff/{$sid}/ledger")->assertOk()
            ->assertJsonPath('label', 'FY 2026-27')->assertJsonPath('totals.closing', 3000);

        $share = $this->api()->postJson("/api/v1/staff/{$sid}/share", ['pin' => '4821'])->assertOk()->assertJsonPath('has_pin', true);
        $this->get(parse_url($share->json('share_url'), PHP_URL_PATH))->assertOk()->assertSee('Enter your 4-digit PIN');
        $this->api()->deleteJson("/api/v1/staff/{$sid}/share")->assertOk();
        $this->assertNull(Staff::find($sid)->share_token);
    }

    public function test_staff_photo_upload_and_validation(): void
    {
        $this->api()->postJson('/api/v1/staff', ['name' => '', 'salary_type' => 'weekly'])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'joining_date', 'salary_type', 'salary_amount']);
        $res = $this->api()->post('/api/v1/staff', [
            'name' => 'Sita', 'joining_date' => '2026-11-01', 'salary_type' => 'daily', 'salary_amount' => 400,
            'photo' => UploadedFile::fake()->image('s.jpg'), 'opening_balance' => -500,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.opening_balance', -500);
        $this->assertStringContainsString('/media/staff/', $res->json('data.photo_url'));
    }

    // -------------------------------------------------------------- insights

    public function test_ledger_reports_analytics(): void
    {
        CashCollection::create(['date' => '2026-11-02', 'cash' => 3000, 'upi' => 1000]);
        $this->api()->getJson('/api/v1/ledger?from=2026-11-01&to=2026-11-15')->assertOk()
            ->assertJsonPath('summary.in', 4000)->assertJsonPath('accounts.cash', 3000)->assertJsonCount(2, 'data');
        $this->api()->getJson('/api/v1/ledger')->assertStatus(422);

        $this->api()->getJson('/api/v1/reports/monthly?month=2026-11')->assertOk()
            ->assertJsonPath('title', 'Monthly summary')->assertJsonPath('kpis.0.1', 4000);
        $this->api()->getJson('/api/v1/reports/nope')->assertNotFound();
        $pdf = $this->api()->get('/api/v1/reports/monthly.pdf?month=2026-11')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->api()->getJson('/api/v1/analytics')->assertOk()->assertJsonPath('label', 'FY 2026-27')->assertJsonPath('totals.sales', 4000);
    }

    public function test_backups_owner_only(): void
    {
        $this->api()->postJson('/api/v1/backups')->assertCreated();
        $this->api()->getJson('/api/v1/backups')->assertOk()->assertJsonCount(1, 'data');

        $manager = User::factory()->create(['role' => 'manager']);
        $this->app['auth']->forgetGuards();
        $this->withToken($manager->createToken('m')->plainTextToken)->getJson('/api/v1/backups')->assertForbidden();
    }

    public function test_not_found_is_json(): void
    {
        $this->api()->getJson('/api/v1/expenses/999999')->assertNotFound()->assertJsonStructure(['message']);
    }
}
