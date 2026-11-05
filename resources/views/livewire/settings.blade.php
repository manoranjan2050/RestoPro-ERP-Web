<div class="space-y-6">
    {{-- Tabs --}}
    <div class="flex max-w-full overflow-x-auto">
        <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            @foreach (['business' => 'Business', 'mode' => 'Mode & sales', 'gst' => 'GST', 'printing' => 'Printing', 'app' => 'Android app', 'money' => 'Money & year', 'users' => 'Users & access'] as $k => $l)
                <button wire:click="$set('tab', '{{ $k }}')" @class([
                    'whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-semibold',
                    'bg-gradient-to-r from-brand-500 to-rose-500 text-white shadow' => $tab === $k,
                    'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $tab !== $k,
                ])>{{ $l }}</button>
            @endforeach
        </div>
    </div>

    @if ($tab === 'business')
        <form wire:submit="saveBusiness" class="grid gap-6 lg:grid-cols-3">
            <div class="card p-5 sm:p-6 lg:col-span-1">
                <h3 class="text-base font-bold">Logo</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Shown in the menu, on the sign-in page, PDF reports and staff statements. Square PNG/JPG, up to 2 MB.</p>
                <div class="mt-5 flex flex-col items-center gap-4">
                    @if ($logo && method_exists($logo, 'isPreviewable') && $logo->isPreviewable())
                        <img src="{{ $logo->temporaryUrl() }}" class="size-28 rounded-2xl object-cover ring-1 ring-slate-200 dark:ring-slate-700" alt="New logo preview">
                    @elseif ($logoPath)
                        <img src="{{ route('media', $logoPath) }}" class="size-28 rounded-2xl object-cover ring-1 ring-slate-200 dark:ring-slate-700" alt="Business logo">
                    @else
                        <div class="grid size-28 place-items-center rounded-2xl bg-gradient-to-br from-brand-400 to-rose-500 text-4xl font-extrabold text-white">{{ mb_strtoupper(mb_substr($biz['business_name'] ?: 'R', 0, 1)) }}</div>
                    @endif
                    <div class="flex flex-wrap justify-center gap-2">
                        <label class="btn-ghost cursor-pointer">
                            <x-icon name="camera" class="size-4" /> {{ $logoPath ? 'Change logo' : 'Upload logo' }}
                            <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="sr-only">
                        </label>
                        @if ($logoPath && ! $logo)
                            <button type="button" wire:click="removeLogo" wire:confirm="Remove the logo?" class="btn text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">Remove</button>
                        @endif
                    </div>
                    <span wire:loading wire:target="logo" class="text-sm text-slate-500">Uploading…</span>
                    @if ($logo) <p class="text-xs text-amber-600">Press “Save” to keep the new logo.</p> @endif
                    @error('logo') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="card p-5 sm:p-6 lg:col-span-2">
                <h3 class="text-base font-bold">Business details</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Printed on the top of every PDF report.</p>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="bn">Business name</label>
                        <input id="bn" wire:model="biz.business_name" class="input" maxlength="60">
                        @error('biz.business_name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="bt">Tagline <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="bt" wire:model="biz.business_tagline" class="input" maxlength="80" placeholder="Pure veg · Family restaurant">
                        @error('biz.business_tagline') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="ba">Address</label>
                        <textarea id="ba" wire:model="biz.business_address" rows="2" class="input" maxlength="200"></textarea>
                        @error('biz.business_address') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="bp">Phone</label>
                        <input id="bp" wire:model="biz.business_phone" class="input" inputmode="tel" placeholder="98765 43210">
                        @error('biz.business_phone') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="be">Email</label>
                        <input id="be" wire:model="biz.business_email" type="email" class="input">
                        @error('biz.business_email') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="bg">GSTIN <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="bg" wire:model="biz.business_gstin" class="input uppercase" maxlength="15" placeholder="21ABCDE1234F1Z5">
                        @error('biz.business_gstin') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="bf">FSSAI licence <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="bf" wire:model="biz.business_fssai" class="input" inputmode="numeric" maxlength="14">
                        @error('biz.business_fssai') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveBusiness,logo">Save</button>
                </div>
            </div>
        </form>
    @elseif ($tab === 'mode')
        <div class="card mb-6 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-bold">Type of business</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Pick yours and the app sets itself up for it: which features are on, cake weights, and menu categories. You can still change every switch below.</p>
                </div>
                @php $cats = \App\Support\Sales::SHOP_TYPES[$shop_type][5] ?? []; @endphp
                @if ($cats)
                    <button type="button" wire:click="addSuggestedCategories" class="btn-ghost shrink-0" title="{{ implode(', ', $cats) }}">+ Add suggested categories</button>
                @endif
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (\App\Support\Sales::SHOP_TYPES as $k => [$label, $emoji, $desc])
                    <button type="button" wire:key="shop-{{ $k }}" wire:click="chooseShopType('{{ $k }}')" @class(['relative flex gap-3 rounded-2xl border p-3 text-left transition',
                        'border-brand-500 bg-brand-50 ring-1 ring-brand-500 dark:bg-brand-500/10' => $shop_type === $k,
                        'border-slate-200 hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700' => $shop_type !== $k])>
                        <span class="text-2xl leading-none">{{ $emoji }}</span>
                        <span class="min-w-0">
                            <span class="block text-sm font-bold">{{ $label }}</span>
                            <span class="block text-xs text-slate-500">{{ $desc }}</span>
                        </span>
                        @if ($shop_type === $k)<span class="absolute right-2 top-2 text-brand-500"><x-icon name="check" class="size-4" /></span>@endif
                    </button>
                @endforeach
            </div>
            @if ($cats)
                <p class="mt-3 text-xs text-slate-500">Suggested categories for {{ \App\Support\Sales::SHOP_TYPES[$shop_type][0] }}: {{ implode(' · ', $cats) }}</p>
            @endif
        </div>
        <form wire:submit="saveMode" class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2">
                @foreach (['simple' => ['Simple', 'Like v1: type each day\'s cash, UPI and online totals. Expenses, vendors, staff, reports and backups.', 'cash'],
                           'advanced' => ['Advanced', 'Everything in Simple, plus a sales counter (POS) with items, customers, orders, printed bills, GST and stock.', 'spark']] as $k => [$label, $desc, $icon])
                    <label wire:key="mode-{{ $k }}" @class(['card relative flex cursor-pointer gap-4 p-5 transition',
                        'ring-2 ring-brand-500' => $mode === $k, 'hover:ring-1 hover:ring-slate-300 dark:hover:ring-slate-700' => $mode !== $k])>
                        <input type="radio" wire:model.live="mode" value="{{ $k }}" class="sr-only">
                        <span @class(['grid size-12 shrink-0 place-items-center rounded-2xl text-white',
                            'bg-gradient-to-br from-emerald-400 to-teal-500' => $k === 'simple',
                            'bg-gradient-to-br from-brand-400 to-violet-500' => $k === 'advanced'])><x-icon :name="$icon" class="size-6" /></span>
                        <span class="min-w-0 pr-6">
                            <span class="flex items-center gap-2 font-bold">{{ $label }} @if ($k === 'advanced')<span class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">v2</span>@endif</span>
                            <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">{{ $desc }}</span>
                        </span>
                        @if ($mode === $k)<span class="absolute right-4 top-4 text-brand-500"><x-icon name="check" class="size-5" /></span>@endif
                    </label>
                @endforeach
            </div>

            <div @class(['card p-5 sm:p-6', 'opacity-50' => $mode !== 'advanced'])>
                <h3 class="text-base font-bold">Sales features</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Used in Advanced mode. Turn off what your shop doesn't need. Turning one off only hides it; nothing is deleted.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach (\App\Support\Sales::FEATURES as $f => [$flabel, $fdesc])
                        <label wire:key="feat-{{ $f }}" class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                            <input type="checkbox" wire:model="features.{{ $f }}" class="mt-0.5 size-4 rounded border-slate-300 text-brand-500" @disabled($mode !== 'advanced')>
                            <span><span class="block text-sm font-semibold">{{ $flabel }}</span><span class="block text-xs text-slate-500">{{ $fdesc }}</span></span>
                        </label>
                    @endforeach
                </div>
            </div>
            <p class="text-xs text-slate-500">Switching back to Simple keeps every item, customer and bill. In Advanced mode, bills paid at the counter are added to that day's Cash Collection automatically, so all v1 reports include them.</p>
            <div class="flex justify-end"><button class="btn-primary" wire:loading.attr="disabled" wire:target="saveMode">Save</button></div>
        </form>

        @if ($mode === 'advanced')
            <form wire:submit="saveBilling" class="card mt-6 p-5 sm:p-6">
                <h3 class="text-base font-bold">Billing</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Bill numbers restart every financial year: <b>{{ $bill_prefix ?: '' }}{{ \App\Services\Billing::fyLabel() }}/0001</b>, /0002 …</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="label" for="bp">Bill number prefix <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="bp" wire:model.live.debounce.400ms="bill_prefix" class="input uppercase" maxlength="8" placeholder="SG/">
                        @error('bill_prefix') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="md">Max discount for managers & cashiers</label>
                        <div class="relative">
                            <input id="md" wire:model="max_discount" type="number" min="0" max="100" step="0.5" class="input pr-8">
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-400">%</span>
                        </div>
                        @error('max_discount') <p class="error">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-slate-500">Owners can always give any discount.</p>
                    </div>
                    <div>
                        <label class="label" for="dc">Default delivery charge</label>
                        <input id="dc" wire:model="delivery_charge" type="number" min="0" step="1" class="input">
                        @error('delivery_charge') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 self-center text-sm">
                        <input type="checkbox" wire:model="round_off" class="size-4 rounded border-slate-300 text-brand-500"> Round bill totals to the rupee
                    </label>
                </div>
                <div class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <p class="label">Cake weights in</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Support\Sales::WEIGHT_UNITS as $k => $l)
                            <label wire:key="wu-{{ $k }}" @class(['flex cursor-pointer items-center gap-2 rounded-xl border px-4 py-2 text-sm',
                                'border-brand-500 bg-brand-50 dark:bg-brand-500/10' => $weight_unit === $k, 'border-slate-200 dark:border-slate-800' => $weight_unit !== $k])>
                                <input type="radio" wire:model.live="weight_unit" value="{{ $k }}" class="size-4 text-brand-500"> {{ $l }}
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        Quick sizes on the Items page and the weight picker for advance orders:
                        <b>{{ collect(\App\Support\Sales::SIZES)->only($weight_unit === 'both' ? ['kg', 'lb'] : [$weight_unit])->flatten()->implode(' · ') }}</b>.
                        Items sold loose by weight can use the unit <b>Kg</b> or <b>Pound</b>.
                    </p>
                </div>
                <div class="mt-5 flex justify-end"><button class="btn-primary" wire:loading.attr="disabled" wire:target="saveBilling">Save</button></div>
            </form>
        @endif
    @elseif ($tab === 'gst')
        <form wire:submit="saveGst" class="card max-w-2xl space-y-5 p-5 sm:p-6">
            <div>
                <h3 class="text-base font-bold">GST billing</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Used by the sales counter in Advanced mode.</p>
            </div>
            <label class="flex cursor-pointer items-start gap-3">
                <input type="checkbox" wire:model.live="gst_enabled" class="mt-0.5 size-4 rounded border-slate-300 text-brand-500">
                <span><span class="block text-sm font-semibold">Charge GST on bills</span>
                    <span class="block text-xs text-slate-500">On: bills print as <b>Tax Invoice</b> with your GSTIN, CGST + SGST per rate, HSN and a tax summary. Off: a plain <b>Bill</b> with no tax lines.</span></span>
            </label>
            @error('gst_enabled') <p class="error">{{ $message }}</p> @enderror
            <div @class(['space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800', 'opacity-50' => ! $gst_enabled])>
                <p class="text-sm font-semibold">Item prices are…</p>
                <label class="flex items-start gap-2 text-sm"><input type="radio" wire:model="gst_inclusive" value="1" @disabled(! $gst_enabled) class="mt-1 size-4 text-brand-500"> <span><b>Including GST</b>: ₹500 on the menu = ₹500 on the bill (usual for cake & sweet shops)</span></label>
                <label class="flex items-start gap-2 text-sm"><input type="radio" wire:model="gst_inclusive" value="0" @disabled(! $gst_enabled) class="mt-1 size-4 text-brand-500"> <span><b>Before GST</b>: ₹500 + 5% = ₹525 on the bill</span></label>
                <p class="text-xs text-slate-500">Each item's GST rate (0/5/12/18/28 %) and HSN code are set on the Items page. GSTIN in use: <b>{{ \App\Models\Setting::get('business_gstin') ?: 'not set — add it under Business' }}</b></p>
            </div>
            <div class="flex justify-end"><button class="btn-primary" wire:loading.attr="disabled" wire:target="saveGst">Save</button></div>
        </form>
    @elseif ($tab === 'printing')
        <form wire:submit="savePrinting" class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <div class="card space-y-5 p-5 sm:p-6">
                <div>
                    <h3 class="text-base font-bold">Receipt printer</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">For the POS in Advanced mode. Works with any USB/Bluetooth thermal printer installed on the PC; the Android app prints to Bluetooth printers directly.</p>
                </div>
                <div>
                    <p class="label">Paper width</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach (\App\Services\BillView::WIDTHS as $k => $l)
                            <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-sm dark:border-slate-800">
                                <input type="radio" wire:model.live="printing.width" value="{{ $k }}" class="size-4 text-brand-500"> {{ $l }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="label" for="pf">Message at the bottom of the bill</label>
                    <textarea id="pf" wire:model="printing.footer" rows="2" maxlength="200" class="input" placeholder="Thank you! Please visit again."></textarea>
                    @error('printing.footer') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="printing.auto" class="size-4 rounded border-slate-300 text-brand-500"> Print the bill automatically after <b>Save bill</b></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="printing.kot" class="size-4 rounded border-slate-300 text-brand-500"> Also print a kitchen ticket (KOT) for each bill</label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="printing.logo" class="size-4 rounded border-slate-300 text-brand-500"> Print the logo at the top</label>
                </div>
                <div class="rounded-xl bg-sky-50 p-3 text-xs text-sky-800 dark:bg-sky-500/10 dark:text-sky-300">
                    <b>Print without the dialog box:</b> make a Chrome shortcut with <code>--kiosk-printing</code> at the end of “Target” and set the thermal printer as the Windows default. The bill then prints in one click. Step-by-step: docs/SETUP.md → “Thermal printer”.
                </div>
                <div class="flex justify-end"><button class="btn-primary" wire:loading.attr="disabled" wire:target="savePrinting">Save</button></div>
            </div>
            <div class="card p-4">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-500">Preview · {{ $printing['width'] }} mm</p>
                <div class="mx-auto rounded bg-white p-3 font-mono text-[10px] leading-snug text-black shadow-inner ring-1 ring-slate-200" style="width: {{ $printing['width'] === '58' ? '180px' : '250px' }}">
                    <p class="text-center text-xs font-bold">{{ \App\Models\Setting::get('business_name') }}</p>
                    @if (\App\Models\Setting::get('business_address'))<p class="text-center">{{ \App\Models\Setting::get('business_address') }}</p>@endif
                    <p class="my-1 border-t border-dashed border-black"></p>
                    <p class="text-center font-bold">BILL</p>
                    <p>Bill: {{ \App\Models\Setting::get('bill_prefix') }}{{ \App\Services\Billing::fyLabel() }}/0001</p>
                    <p class="my-1 border-t border-dashed border-black"></p>
                    <p class="font-bold">Black Forest Cake (1 kg)</p><p class="flex justify-between"><span>850</span><span>1</span><span>850</span></p>
                    <p class="font-bold">Veg Puff</p><p class="flex justify-between"><span>25</span><span>2</span><span>50</span></p>
                    <p class="my-1 border-t-2 border-black"></p>
                    <p class="flex justify-between text-xs font-bold"><span>TOTAL</span><span>₹900</span></p>
                    <p class="my-1 border-t-2 border-black"></p>
                    <p class="text-center">{{ $printing['footer'] ?: '' }}</p>
                </div>
            </div>
        </form>
    @elseif ($tab === 'app')
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-4">
                @php $apkInfo = \App\Support\AndroidApp::info(); @endphp
                @if ($apkInfo)
                    <x-app-download />
                    <div class="card p-4 text-xs text-slate-500">
                        <p>File: {{ \App\Support\AndroidApp::fileName() }} · {{ \App\Support\AndroidApp::sizeLabel($apkInfo['size']) }}</p>
                        @if ($apkInfo['sha256'])<p class="mt-1 break-all">SHA-256: <span class="font-mono">{{ $apkInfo['sha256'] }}</span></p>@endif
                        <button type="button" wire:click="removeApk" wire:confirm="Remove the app file? The download button disappears until you upload one again." class="mt-3 font-semibold text-rose-600 hover:underline">Remove app file</button>
                    </div>
                @else
                    <div class="card px-6 py-10 text-center text-sm text-slate-500">No app uploaded yet. Upload the .apk and a Download button appears on the Dashboard and here.</div>
                @endif
            </div>
            <form wire:submit="uploadApk" class="card space-y-4 p-5 sm:p-6">
                <div>
                    <h3 class="text-base font-bold">{{ \App\Support\AndroidApp::info() ? 'Upload a new version' : 'Upload the Android app' }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Until the app is on the Play Store, staff install it from here. Signed-in users only — the file isn't public.</p>
                </div>
                <label class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 px-4 py-8 text-center hover:border-brand-400 dark:border-slate-700">
                    <x-icon name="upload" class="size-7 text-slate-400" />
                    <span class="text-sm font-semibold">{{ $apk ? $apk->getClientOriginalName() : 'Choose the .apk file' }}</span>
                    <input type="file" wire:model="apk" accept=".apk,application/vnd.android.package-archive" class="sr-only">
                </label>
                <p wire:loading wire:target="apk" class="text-sm text-slate-500">Uploading…</p>
                @error('apk') <p class="error">{{ $message }}</p> @enderror
                <div>
                    <label class="label" for="apkv">Version</label>
                    <input id="apkv" wire:model="apkVersion" class="input w-40" placeholder="2.0.1">
                    @error('apkVersion') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end"><button class="btn-primary" wire:loading.attr="disabled" wire:target="uploadApk,apk">Upload</button></div>
            </form>
        </div>
    @elseif ($tab === 'money')
        <form wire:submit="saveMoney" class="card max-w-2xl p-5 sm:p-6">
            <h3 class="text-base font-bold">Money & financial year</h3>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="cs">Currency symbol</label>
                    <input id="cs" wire:model="currency_symbol" class="input" maxlength="4">
                    @error('currency_symbol') <p class="error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">Amounts always use Indian grouping (1,00,000).</p>
                </div>
                <div>
                    <label class="label" for="fy">Financial year starts in</label>
                    <select id="fy" wire:model="fy_start_month" class="input">
                        @foreach ($months as $m => $name)
                            <option value="{{ $m }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('fy_start_month') <p class="error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">April for the Indian financial year. Used by yearly reports, Annual Summary and staff ledgers.</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveMoney">Save</button>
            </div>
        </form>
    @else
        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                <div>
                    <h3 class="font-bold">Who can sign in</h3>
                    <p class="text-xs text-slate-500">Owners can do everything. Managers can enter and edit records but can't delete, back up, restore or change settings.</p>
                </div>
                <button wire:click="newUser" class="btn-primary"><x-icon name="plus" class="size-4" /> Add login</button>
            </div>
            @foreach ($users as $usr)
                <div wire:key="u-{{ $usr->id }}" @class(['flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800', 'opacity-60' => ! $usr->is_active])>
                    <x-avatar :user="$usr" class="size-10" />
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ $usr->name }}
                            @if ($usr->id === auth()->id()) <span class="text-xs font-medium text-slate-400">(you)</span> @endif
                            <span @class(['rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase',
                                'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400' => $usr->isOwner(),
                                'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400' => ! $usr->isOwner()])>{{ $usr->role }}</span>
                            @unless ($usr->is_active) <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500 dark:bg-slate-800">off</span> @endunless
                        </p>
                        <p class="truncate text-xs text-slate-500">{{ '@'.$usr->username }}{{ $usr->mobile ? ' · '.$usr->mobile : '' }}{{ $usr->email ? ' · '.$usr->email : '' }}</p>
                        <p class="text-xs text-slate-400">Last sign-in {{ $usr->last_login_at?->diffForHumans() ?? 'never' }}</p>
                    </div>
                    <button wire:click="editUser({{ $usr->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Edit</button>
                </div>
            @endforeach
        </div>
    @endif

    <a href="{{ route('developer') }}" class="group relative block overflow-clip rounded-3xl bg-slate-950 p-5 text-white sm:p-6">
        <div class="pointer-events-none absolute -right-10 -top-16 size-48 rounded-full bg-brand-500/40 blur-3xl transition group-hover:scale-125"></div>
        <div class="pointer-events-none absolute -bottom-16 left-1/3 size-40 rounded-full bg-violet-600/40 blur-3xl transition group-hover:scale-125"></div>
        <div class="relative flex flex-wrap items-center gap-4">
            <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-brand-400 via-rose-500 to-violet-600 text-2xl font-black">M</span>
            <div class="min-w-0 flex-1">
                <p class="text-xs uppercase tracking-[.25em] text-slate-400">About this software</p>
                <p class="text-lg font-bold">OpenRestoPro ERP · developed by <span class="bg-gradient-to-r from-brand-300 to-violet-300 bg-clip-text text-transparent">MANORANJAN</span></p>
                <p class="text-sm text-slate-400">manoranjan.dev · github.com/manoranjan2050 · open source (MIT)</p>
            </div>
            <span class="rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold transition group-hover:bg-white/20">Developer profile →</span>
        </div>
    </a>

    @if ($showUser)
        <x-modal :title="$userId ? 'Edit login' : 'Add login'">
            <form wire:submit="saveUser" class="space-y-4">
                <div>
                    <label class="label" for="un">Full name</label>
                    <input id="un" wire:model="u.name" class="input" autofocus>
                    @error('u.name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="uu">Username</label>
                        <input id="uu" wire:model="u.username" class="input" autocomplete="off">
                        @error('u.username') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="um">Mobile</label>
                        <input id="um" wire:model="u.mobile" class="input" inputmode="numeric" placeholder="10-digit mobile">
                        @error('u.mobile') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="ue">Email <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="ue" wire:model="u.email" type="email" class="input">
                    @error('u.email') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="ur">Role</label>
                        <select id="ur" wire:model="u.role" class="input">
                            @foreach (\App\Models\User::ROLES as $r => $rl)
                                <option value="{{ $r }}">{{ $rl }}</option>
                            @endforeach
                        </select>
                        @error('u.role') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="up">{{ $userId ? 'New password' : 'Password' }}</label>
                        <input id="up" wire:model="u.password" type="password" class="input" autocomplete="new-password" placeholder="{{ $userId ? 'leave blank to keep' : 'at least 8 characters' }}">
                        @error('u.password') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="u.is_active" class="size-4 rounded border-slate-300 text-brand-500">
                    Can sign in
                </label>
                <p class="text-xs text-slate-500">They can sign in with the username, mobile or email. Turning a login off or changing its password or role signs it out of the website and the app.</p>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="cancel" class="btn-ghost">Cancel</button>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveUser">{{ $userId ? 'Save' : 'Create login' }}</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
