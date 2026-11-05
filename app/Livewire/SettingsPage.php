<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Owner-only: business details + logo, money & year, and team logins (owner / manager). */
#[Title('Settings')]
class SettingsPage extends Component
{
    use WithFileUploads;

    /** Business keys shown on screens, PDFs, the staff share page and backups. */
    public const BUSINESS_KEYS = [
        'business_name', 'business_tagline', 'business_address', 'business_phone', 'business_email', 'business_gstin', 'business_fssai',
    ];

    #[Url]
    public string $tab = 'business';

    public array $biz = [];
    public $logo;

    // mode + sales (v2)
    public string $mode = 'simple';
    public array $features = [];
    public bool $gst_enabled = false;
    public string $gst_inclusive = '1';
    public string $bill_prefix = '';
    public array $printing = [];
    public bool $round_off = true;
    public string $max_discount = '100';
    public string $delivery_charge = '0';
    public string $weight_unit = 'kg';
    public string $shop_type = 'other';

    // Android app APK
    public $apk;
    public string $apkVersion = '';

    public string $currency_symbol = '₹';
    public string $fy_start_month = '4';

    // user form
    public bool $showUser = false;
    public ?int $userId = null;
    public array $u = [];

    public function mount(): void
    {
        Gate::authorize('manage-settings');
        foreach (self::BUSINESS_KEYS as $k) {
            $this->biz[$k] = (string) Setting::get($k, '');
        }
        $this->currency_symbol = (string) Setting::get('currency_symbol', '₹');
        $this->fy_start_month = (string) Setting::get('fy_start_month', '4');
        $this->mode = Setting::get('app_mode', 'simple') === 'advanced' ? 'advanced' : 'simple';
        foreach (array_keys(\App\Support\Sales::FEATURES) as $f) {
            $this->features[$f] = Setting::get('sales_'.$f, '1') === '1';
        }
        $this->gst_enabled = Setting::get('gst_enabled', '0') === '1';
        $this->bill_prefix = (string) Setting::get('bill_prefix', '');
        $this->printing = \App\Services\BillView::printSettings();
        $this->round_off = Setting::get('pos_round_off', '1') === '1';
        $this->max_discount = (string) Setting::get('pos_max_discount', '100');
        $this->delivery_charge = (string) Setting::get('delivery_charge', '0');
        $this->weight_unit = \App\Support\Sales::weightUnit();
        $this->shop_type = \App\Support\Sales::shopType();
        $this->gst_inclusive = Setting::get('gst_inclusive', '1') === '1' ? '1' : '0';
    }

    /** Type of business: fill in the matching feature switches and cake weights (still editable), then save. */
    public function chooseShopType(string $type): void
    {
        Gate::authorize('manage-settings');
        $preset = \App\Support\Sales::SHOP_TYPES[$type] ?? null;
        if (! $preset) {
            return;
        }
        $this->shop_type = $type;
        Setting::put('shop_type', $type);
        if ($preset[3]) {
            $this->features = $preset[3];
            $this->mode = 'advanced';
        }
        if ($preset[4]) {
            $this->weight_unit = $preset[4];
            Setting::put('weight_unit', $preset[4]);
        }
        $this->saveMode();
    }

    /** Create the suggested menu categories for the shop type (ones that already exist are skipped). */
    public function addSuggestedCategories(): void
    {
        Gate::authorize('manage-settings');
        $made = 0;
        foreach (\App\Support\Sales::SHOP_TYPES[$this->shop_type][5] ?? [] as $name) {
            if (! \App\Models\ItemCategory::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                \App\Models\ItemCategory::create(['name' => $name, 'color' => \App\Models\ItemCategory::nextColor(), 'sort' => (int) \App\Models\ItemCategory::max('sort') + 1]);
                $made++;
            }
        }
        $this->dispatch('toast', message: $made ? "{$made} categories added — add items to them on the Items page" : 'Those categories are already there');
    }

    public function saveMode(): void
    {
        Gate::authorize('manage-settings');
        $this->validate(['mode' => ['required', Rule::in(['simple', 'advanced'])], 'features.*' => ['boolean']]);
        Setting::put('app_mode', $this->mode);
        foreach (array_keys(\App\Support\Sales::FEATURES) as $f) {
            Setting::put('sales_'.$f, ! empty($this->features[$f]) ? '1' : '0');
        }
        $this->dispatch('toast', message: $this->mode === 'advanced' ? 'Advanced mode on — the Sales menu is now in the sidebar' : 'Simple mode on');
        $this->redirectRoute('settings', ['tab' => 'mode'], navigate: true); // rebuild the sidebar
    }

    public function uploadApk(): void
    {
        Gate::authorize('manage-settings');
        $this->apkVersion = trim($this->apkVersion);
        $this->validate([
            'apk' => ['required', 'file', 'max:102400', function ($attr, $file, $fail) {
                if (strtolower($file->getClientOriginalExtension()) !== 'apk') {
                    $fail('Choose the .apk file of the app.');
                }
            }],
            'apkVersion' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/'],
        ], [], ['apk' => 'APK file', 'apkVersion' => 'version']);
        \App\Support\AndroidApp::store($this->apk, $this->apkVersion);
        $this->reset('apk', 'apkVersion');
        $this->dispatch('toast', message: 'App uploaded — staff can download it from the Dashboard');
    }

    public function removeApk(): void
    {
        Gate::authorize('manage-settings');
        \App\Support\AndroidApp::remove();
        $this->dispatch('toast', message: 'App file removed');
    }

    public function saveBilling(): void
    {
        Gate::authorize('manage-settings');
        $this->bill_prefix = mb_strtoupper(trim($this->bill_prefix));
        $this->validate([
            'bill_prefix' => ['nullable', 'regex:/^[A-Z0-9\/-]{0,8}$/'],
            'max_discount' => ['required', 'numeric', 'between:0,100'],
            'delivery_charge' => ['required', 'numeric', 'between:0,99999'],
            'weight_unit' => ['required', Rule::in(array_keys(\App\Support\Sales::WEIGHT_UNITS))],
        ], ['bill_prefix.regex' => 'Up to 8 letters/numbers, e.g. SG/ or MB-.'], ['max_discount' => 'discount limit']);
        Setting::put('bill_prefix', $this->bill_prefix !== '' ? $this->bill_prefix : null);
        Setting::put('pos_round_off', $this->round_off ? '1' : '0');
        Setting::put('pos_max_discount', (string) (float) $this->max_discount);
        Setting::put('delivery_charge', (string) (float) $this->delivery_charge);
        Setting::put('weight_unit', $this->weight_unit);
        $this->dispatch('toast', message: 'Billing settings saved');
    }

    public function savePrinting(): void
    {
        Gate::authorize('manage-settings');
        $this->validate([
            'printing.width' => ['required', Rule::in(array_keys(\App\Services\BillView::WIDTHS))],
            'printing.footer' => ['nullable', 'string', 'max:200'],
        ], [], ['printing.footer' => 'footer']);
        Setting::put('print_width', $this->printing['width']);
        Setting::put('print_footer', trim((string) $this->printing['footer']));
        foreach (['logo', 'auto', 'kot'] as $k) {
            Setting::put('print_'.$k, ! empty($this->printing[$k]) ? '1' : '0');
        }
        $this->dispatch('toast', message: 'Printing settings saved');
    }

    public function saveGst(): void
    {
        Gate::authorize('manage-settings');
        $this->resetErrorBag('gst_enabled');
        if ($this->gst_enabled && ! preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', (string) Setting::get('business_gstin'))) {
            $this->addError('gst_enabled', 'Enter your GSTIN under Business first — it is printed on every tax invoice.');

            return;
        }
        Setting::put('gst_enabled', $this->gst_enabled ? '1' : '0');
        Setting::put('gst_inclusive', $this->gst_inclusive === '0' ? '0' : '1');
        $this->dispatch('toast', message: $this->gst_enabled ? 'GST billing on' : 'GST billing off — normal bills');
    }

    public function saveBusiness(): void
    {
        Gate::authorize('manage-settings');
        $this->biz = array_map(fn ($v) => trim((string) $v), $this->biz);
        $this->biz['business_gstin'] = mb_strtoupper($this->biz['business_gstin'] ?? '');
        $this->validate([
            'biz.business_name' => ['required', 'string', 'max:60'],
            'biz.business_tagline' => ['nullable', 'string', 'max:80'],
            'biz.business_address' => ['nullable', 'string', 'max:200'],
            'biz.business_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s,\/]+$/'],
            'biz.business_email' => ['nullable', 'email', 'max:120'],
            'biz.business_gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/'],
            'biz.business_fssai' => ['nullable', 'digits:14'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            'biz.business_gstin.regex' => 'GSTIN is 15 characters, e.g. 21ABCDE1234F1Z5.',
            'biz.business_fssai.digits' => 'FSSAI licence number is 14 digits.',
        ], ['biz.business_name' => 'business name']);

        foreach (self::BUSINESS_KEYS as $k) {
            Setting::put($k, $this->biz[$k] !== '' ? $this->biz[$k] : null);
        }
        if ($this->logo) {
            $old = Setting::get('business_logo');
            Setting::put('business_logo', $this->logo->store('logos', 'public'));
            if ($old) {
                Storage::disk('public')->delete($old);
            }
            $this->reset('logo');
        }
        $this->dispatch('toast', message: 'Business details saved');
    }

    public function removeLogo(): void
    {
        Gate::authorize('manage-settings');
        if ($old = Setting::get('business_logo')) {
            Storage::disk('public')->delete($old);
            Setting::put('business_logo', null);
        }
        $this->dispatch('toast', message: 'Logo removed');
    }

    public function saveMoney(): void
    {
        Gate::authorize('manage-settings');
        $this->currency_symbol = trim($this->currency_symbol);
        $this->validate([
            'currency_symbol' => ['required', 'string', 'max:4'],
            'fy_start_month' => ['required', 'integer', 'between:1,12'],
        ]);
        Setting::put('currency_symbol', $this->currency_symbol);
        Setting::put('fy_start_month', (string) (int) $this->fy_start_month);
        $this->dispatch('toast', message: 'Saved');
    }

    // ---------------- users

    public function newUser(): void
    {
        $this->resetValidation();
        $this->userId = null;
        $this->u = ['name' => '', 'username' => '', 'mobile' => '', 'email' => '', 'role' => User::ROLE_MANAGER, 'is_active' => true, 'password' => ''];
        $this->showUser = true;
    }

    public function editUser(int $id): void
    {
        $this->resetValidation();
        $user = User::findOrFail($id);
        $this->userId = $user->id;
        $this->u = $user->only(['name', 'username', 'mobile', 'email', 'role', 'is_active']) + ['password' => ''];
        $this->u = array_map(fn ($v) => $v ?? '', $this->u);
        $this->u['is_active'] = (bool) $user->is_active;
        $this->showUser = true;
    }

    public function cancel(): void
    {
        $this->showUser = false;
        $this->resetValidation();
    }

    public function saveUser(): void
    {
        Gate::authorize('manage-settings');
        $id = $this->userId;
        $this->u['username'] = mb_strtolower(trim((string) $this->u['username']));
        $this->u['email'] = trim((string) $this->u['email']) !== '' ? mb_strtolower(trim($this->u['email'])) : null;
        $this->u['mobile'] = User::normaliseMobile($this->u['mobile'] ?: null);
        $this->u['name'] = trim((string) $this->u['name']);

        $this->validate([
            'u.name' => ['required', 'string', 'max:80'],
            'u.username' => ['required', 'alpha_dash', 'min:3', 'max:40', Rule::unique('users', 'username')->ignore($id)],
            'u.mobile' => ['nullable', 'digits:10', Rule::unique('users', 'mobile')->ignore($id)],
            'u.email' => ['nullable', 'email', 'max:120', Rule::unique('users', 'email')->ignore($id)],
            'u.role' => ['required', Rule::in(array_keys(User::ROLES))],
            'u.is_active' => ['boolean'],
            'u.password' => [$id ? 'nullable' : 'required', Password::min(8)],
        ], [], ['u.name' => 'name', 'u.username' => 'username', 'u.mobile' => 'mobile', 'u.email' => 'email', 'u.password' => 'password']);

        $user = $id ? User::findOrFail($id) : new User;
        $removesOwner = $user->exists && $user->isOwner() && $user->is_active
            && ($this->u['role'] !== User::ROLE_OWNER || ! $this->u['is_active']);
        if ($user->exists && $user->id === Auth::id() && ($this->u['role'] !== $user->role || ! $this->u['is_active'])) {
            $this->addError('u.role', 'You can\'t change your own role or turn off your own login.');

            return;
        }
        if ($removesOwner && User::where('role', User::ROLE_OWNER)->where('is_active', true)->count() <= 1) {
            $this->addError('u.role', 'There must always be at least one active owner.');

            return;
        }

        $user->fill(collect($this->u)->only(['name', 'username', 'mobile', 'email', 'role', 'is_active'])->all());
        $signOut = $user->exists && (! $user->is_active || $user->isDirty('role'));
        if ($this->u['password'] !== '') {
            $user->password = $this->u['password'];
            $user->remember_token = Str::random(60);
            $signOut = $user->exists;
        }
        $user->save();

        if ($signOut && $user->id !== Auth::id()) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->showUser = false;
        $this->dispatch('toast', message: $id ? 'Login updated'.($signOut ? ' — they were signed out' : '') : 'Login created');
    }

    public function render()
    {
        return view('livewire.settings', [
            'users' => User::orderByDesc('is_active')->orderByRaw("role = 'owner' desc")->orderBy('name')->get(),
            'logoPath' => Setting::get('business_logo'),
            'months' => collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Illuminate\Support\Carbon::create(2000, $m, 1)->format('F')]),
        ]);
    }
}
