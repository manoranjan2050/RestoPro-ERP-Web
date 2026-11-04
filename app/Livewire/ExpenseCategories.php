<?php

namespace App\Livewire;

use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

/** Modal for adding, renaming, colouring, hiding and deleting expense categories. */
class ExpenseCategories extends Component
{
    public bool $open = false;
    public string $newName = '';
    public ?int $editingId = null;
    public string $editName = '';
    public string $editColor = '';

    #[On('open-categories')]
    public function show(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->reset();
        $this->resetValidation();
        $this->dispatch('categories-changed');
    }

    public function add(): void
    {
        $this->newName = trim($this->newName);
        $this->validate(['newName' => ['required', 'string', 'max:60', new \App\Rules\UniqueCi('expense_categories')]],
            [], ['newName' => 'category name']);
        ExpenseCategory::create(['name' => $this->newName, 'color' => ExpenseCategory::nextColor(), 'sort' => 100]);
        $this->newName = '';
        $this->dispatch('toast', message: 'Category added');
    }

    public function startEdit(int $id): void
    {
        $c = ExpenseCategory::findOrFail($id);
        $this->editingId = $c->id;
        $this->editName = $c->name;
        $this->editColor = $c->color;
        $this->resetValidation();
    }

    public function saveEdit(): void
    {
        $this->editName = trim($this->editName);
        $this->validate([
            'editName' => ['required', 'string', 'max:60', new \App\Rules\UniqueCi('expense_categories', 'name', $this->editingId)],
            'editColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [], ['editName' => 'name', 'editColor' => 'colour']);
        ExpenseCategory::whereKey($this->editingId)->update(['name' => $this->editName, 'color' => strtolower($this->editColor)]);
        $this->editingId = null;
    }

    public function toggle(int $id): void
    {
        $c = ExpenseCategory::findOrFail($id);
        $c->update(['is_active' => ! $c->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('delete-records');
        $c = ExpenseCategory::withCount('expenses')->findOrFail($id);
        if ($c->expenses_count > 0) {
            $this->addError('delete', "“{$c->name}” has {$c->expenses_count} expense(s). Hide it instead, or move those expenses first.");

            return;
        }
        $c->delete();
        $this->dispatch('toast', message: 'Category deleted');
    }

    public function render()
    {
        return view('livewire.expense-categories', [
            'categories' => $this->open ? ExpenseCategory::ordered()->withCount('expenses')->get() : collect(),
            'palette' => ExpenseCategory::PALETTE,
        ]);
    }
}
