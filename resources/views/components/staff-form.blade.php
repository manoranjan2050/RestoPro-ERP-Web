{{-- Add / edit staff modal. Used by components that `use EditsStaff`. --}}
@props(['staffId', 'sf', 'staffPhoto'])
<x-modal :title="$staffId ? 'Edit staff' : 'Add staff'" close="cancelStaff">
    <form wire:submit="saveStaff" class="space-y-4">
        <div class="flex items-center gap-4">
            @if ($staffPhoto && method_exists($staffPhoto, 'isPreviewable') && $staffPhoto->isPreviewable())
                <img src="{{ $staffPhoto->temporaryUrl() }}" class="size-16 rounded-2xl object-cover" alt="New photo">
            @elseif ($staffId && ($s = \App\Models\Staff::find($staffId))?->photo_path)
                <img src="{{ $s->photoUrl() }}" class="size-16 rounded-2xl object-cover" alt="{{ $s->name }}">
            @else
                <span class="grid size-16 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800"><x-icon name="camera" class="size-6" /></span>
            @endif
            <label class="btn-ghost cursor-pointer py-2">
                <x-icon name="camera" class="size-4" /> Photo
                <input type="file" wire:model="staffPhoto" accept="image/*" class="sr-only">
            </label>
            <span wire:loading wire:target="staffPhoto" class="text-sm text-slate-500">Uploading…</span>
        </div>
        @error('staffPhoto') <p class="error">{{ $message }}</p> @enderror

        <div class="grid grid-cols-2 gap-3">
            <div class="col-span-2">
                <label class="label" for="s-name">Full name</label>
                <input id="s-name" wire:model="sf.name" class="input" autofocus>
                @error('sf.name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="s-mobile">Mobile</label>
                <input id="s-mobile" wire:model="sf.mobile" inputmode="numeric" class="input" placeholder="10 digits">
                @error('sf.mobile') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="s-des">Role</label>
                <input id="s-des" wire:model="sf.designation" class="input" list="designations" placeholder="Cook, Helper…">
                <datalist id="designations">
                    @foreach (['Cook', 'Head Cook', 'Helper', 'Waiter', 'Cashier', 'Cleaner', 'Delivery', 'Manager'] as $d) <option value="{{ $d }}"> @endforeach
                </datalist>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
            <p class="mb-3 text-sm font-bold">Salary</p>
            <div class="grid grid-cols-2 gap-2">
                @foreach (\App\Models\Staff::TYPES as $k => $label)
                    <button type="button" wire:click="$set('sf.salary_type', '{{ $k }}')" @class([
                        'rounded-xl border px-3 py-2 text-sm font-semibold transition',
                        'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400' => ($sf['salary_type'] ?? '') === $k,
                        'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => ($sf['salary_type'] ?? '') !== $k,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="mt-3">
                <label class="label" for="s-sal">{{ ($sf['salary_type'] ?? '') === 'daily' ? 'Wage per day' : 'Salary per month' }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ \App\Models\Setting::get('currency_symbol') }}</span>
                    <input id="s-sal" type="number" step="0.01" min="0" wire:model="sf.salary_amount" class="input pl-7 tabular-nums">
                </div>
                @error('sf.salary_amount') <p class="error">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-xs text-slate-500">
                    {{ ($sf['salary_type'] ?? '') === 'daily' ? 'Earns this for each Present day, half for a Half day.' : 'Absent days are cut at salary ÷ days in month; half days cut half a day. Unmarked days count as worked.' }}
                </p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label" for="s-join">Joining date</label>
                <input id="s-join" type="date" wire:model="sf.joining_date" class="input dark:[color-scheme:dark]">
                @error('sf.joining_date') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="s-leave">Leaving date <span class="font-normal text-slate-400">(if left)</span></label>
                <input id="s-leave" type="date" wire:model="sf.leaving_date" class="input dark:[color-scheme:dark]">
                @error('sf.leaving_date') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="label" for="s-ob">Opening balance <span class="font-normal text-slate-400">(before using this app)</span></label>
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ \App\Models\Setting::get('currency_symbol') }}</span>
                    <input id="s-ob" type="number" step="0.01" min="0" wire:model="sf.opening_balance" class="input pl-7 tabular-nums" placeholder="0">
                </div>
                <select wire:model="sf.opening_direction" class="input w-auto">
                    <option value="owe_staff">We owe them</option>
                    <option value="staff_owes">They owe us</option>
                </select>
            </div>
            @error('sf.opening_balance') <p class="error">{{ $message }}</p> @enderror
        </div>

        <details class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800" @if ($sf['id_number'] || $sf['address'] || $sf['emergency_contact']) open @endif>
            <summary class="cursor-pointer text-sm font-bold">More details <span class="font-normal text-slate-400">(ID, address, emergency)</span></summary>
            <div class="mt-3 grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="s-idt">ID type</label>
                    <input id="s-idt" wire:model="sf.id_type" class="input" list="idtypes" placeholder="Aadhaar">
                    <datalist id="idtypes">@foreach (['Aadhaar', 'Voter ID', 'PAN', 'Driving Licence'] as $d) <option value="{{ $d }}"> @endforeach</datalist>
                </div>
                <div>
                    <label class="label" for="s-idn">ID number</label>
                    <input id="s-idn" wire:model="sf.id_number" class="input">
                    @error('sf.id_number') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="col-span-2">
                    <label class="label" for="s-addr">Address</label>
                    <input id="s-addr" wire:model="sf.address" class="input">
                </div>
                <div class="col-span-2">
                    <label class="label" for="s-emg">Emergency contact</label>
                    <input id="s-emg" wire:model="sf.emergency_contact" class="input" placeholder="Name & number">
                </div>
                <div class="col-span-2">
                    <label class="label" for="s-note">Note</label>
                    <input id="s-note" wire:model="sf.note" class="input">
                </div>
            </div>
        </details>

        @if ($staffId)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="sf.is_active" class="rounded border-slate-300 text-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                Active (shows in the daily attendance register)
            </label>
        @endif

        <div class="flex justify-end gap-2 pt-1">
            <button type="button" wire:click="cancelStaff" class="btn-ghost">Cancel</button>
            <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveStaff,staffPhoto">{{ $staffId ? 'Save changes' : 'Add staff' }}</button>
        </div>
    </form>
</x-modal>
