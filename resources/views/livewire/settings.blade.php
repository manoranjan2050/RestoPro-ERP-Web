<div class="space-y-6">
    {{-- Tabs --}}
    <div class="flex max-w-full overflow-x-auto">
        <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            @foreach (['business' => 'Business', 'money' => 'Money & year', 'users' => 'Users & access'] as $k => $l)
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
                            <option value="manager">Manager</option>
                            <option value="owner">Owner</option>
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
