<?php

namespace App\Livewire\Concerns;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Add / edit staff form, shared by the staff list and the staff profile page. */
trait EditsStaff
{
    public bool $showStaffForm = false;
    public ?int $staffId = null;
    public array $sf = [];
    public $staffPhoto;

    protected function blankStaff(): array
    {
        return [
            'name' => '', 'mobile' => '', 'designation' => '', 'joining_date' => today()->toDateString(), 'leaving_date' => '',
            'salary_type' => 'monthly', 'salary_amount' => '', 'opening_balance' => '', 'opening_direction' => 'owe_staff',
            'id_type' => '', 'id_number' => '', 'address' => '', 'emergency_contact' => '', 'note' => '', 'is_active' => true,
        ];
    }

    public function createStaff(): void
    {
        $this->staffId = null;
        $this->sf = $this->blankStaff();
        $this->staffPhoto = null;
        $this->resetValidation();
        $this->showStaffForm = true;
    }

    public function editStaff(int $id): void
    {
        $s = Staff::findOrFail($id);
        $ob = (float) $s->opening_balance;
        $this->staffId = $s->id;
        $this->sf = [
            'name' => $s->name, 'mobile' => (string) $s->mobile, 'designation' => (string) $s->designation,
            'joining_date' => $s->joining_date->toDateString(), 'leaving_date' => $s->leaving_date?->toDateString() ?? '',
            'salary_type' => $s->salary_type, 'salary_amount' => $this->plainAmount($s->salary_amount),
            'opening_balance' => $ob == 0 ? '' : $this->plainAmount(abs($ob)), 'opening_direction' => $ob < 0 ? 'staff_owes' : 'owe_staff',
            'id_type' => (string) $s->id_type, 'id_number' => (string) $s->id_number, 'address' => (string) $s->address,
            'emergency_contact' => (string) $s->emergency_contact, 'note' => (string) $s->note, 'is_active' => $s->is_active,
        ];
        $this->staffPhoto = null;
        $this->resetValidation();
        $this->showStaffForm = true;
    }

    public function saveStaff(): void
    {
        $this->sf['name'] = trim($this->sf['name'] ?? '');
        $this->sf['mobile'] = (string) User::normaliseMobile($this->sf['mobile'] ?? '');

        $this->validate([
            'sf.name' => ['required', 'string', 'max:80'],
            'sf.mobile' => ['nullable', 'digits:10'],
            'sf.designation' => ['nullable', 'string', 'max:60'],
            'sf.joining_date' => ['required', 'date'],
            'sf.leaving_date' => ['nullable', 'date', 'after_or_equal:sf.joining_date'],
            'sf.salary_type' => ['required', Rule::in(array_keys(Staff::TYPES))],
            'sf.salary_amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'sf.opening_balance' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'sf.opening_direction' => ['required', Rule::in(['owe_staff', 'staff_owes'])],
            'sf.id_type' => ['nullable', 'string', 'max:30'],
            'sf.id_number' => ['nullable', 'string', 'max:40'],
            'sf.address' => ['nullable', 'string', 'max:255'],
            'sf.emergency_contact' => ['nullable', 'string', 'max:80'],
            'sf.note' => ['nullable', 'string', 'max:255'],
            'staffPhoto' => ['nullable', 'image', 'max:4096'],
        ], [], [
            'sf.name' => 'name', 'sf.mobile' => 'mobile', 'sf.joining_date' => 'joining date', 'sf.leaving_date' => 'leaving date',
            'sf.salary_amount' => 'salary', 'sf.opening_balance' => 'opening balance',
        ]);

        $ob = (float) ($this->sf['opening_balance'] ?: 0);
        $data = [
            'name' => $this->sf['name'],
            'mobile' => $this->sf['mobile'] ?: null,
            'designation' => trim($this->sf['designation']) ?: null,
            'joining_date' => $this->sf['joining_date'],
            'leaving_date' => $this->sf['leaving_date'] ?: null,
            'salary_type' => $this->sf['salary_type'],
            'salary_amount' => round((float) $this->sf['salary_amount'], 2),
            'opening_balance' => $this->sf['opening_direction'] === 'staff_owes' ? -$ob : $ob,
            'id_type' => trim($this->sf['id_type']) ?: null,
            'id_number' => trim($this->sf['id_number']) ?: null,
            'address' => trim($this->sf['address']) ?: null,
            'emergency_contact' => trim($this->sf['emergency_contact']) ?: null,
            'note' => trim($this->sf['note']) ?: null,
            // Someone who has already left is inactive (hidden from the daily register).
            'is_active' => (bool) $this->sf['is_active'] && (! $this->sf['leaving_date'] || $this->sf['leaving_date'] > today()->toDateString()),
        ];

        $staff = $this->staffId ? Staff::findOrFail($this->staffId) : new Staff;
        if ($this->staffPhoto) {
            if ($staff->photo_path) {
                Storage::disk('public')->delete($staff->photo_path);
            }
            $data['photo_path'] = $this->staffPhoto->store('staff', 'public');
        }
        $staff->fill($data)->save();

        $this->dispatch('toast', message: $this->staffId ? 'Staff updated' : 'Staff added');
        $this->showStaffForm = false;
        $this->staffPhoto = null;
        $this->afterStaffSaved($staff);
    }

    public function cancelStaff(): void
    {
        $this->showStaffForm = false;
        $this->staffPhoto = null;
        $this->resetValidation();
    }

    protected function afterStaffSaved(Staff $staff): void {}

    protected function plainAmount($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }
}
