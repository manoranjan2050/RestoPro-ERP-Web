<?php

namespace App\Livewire\Sales;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Rules\UniqueCi;
use App\Services\ItemImport;
use App\Support\Sales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** v2 menu / catalogue: categories, items with optional variants (sizes/weights), photo, GST, barcode, CSV import. */
#[Title('Items')]
class Items extends Component
{
    use WithFileUploads, WithPagination;

    #[Url(as: 'c')]
    public string $category = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'show')]
    public string $show = 'active'; // active | hidden | all

    // item form
    public bool $showForm = false;
    public ?int $editingId = null;
    public array $f = [];
    public array $variants = [];
    public $photo;

    // categories modal
    public bool $showCategories = false;
    public string $newCategory = '';
    public ?int $catEditId = null;
    public string $catEditName = '';
    public string $catEditColor = '';

    // import
    public bool $showImport = false;
    public $importFile;
    public ?array $importPreview = null;

    public ?int $confirmingDelete = null;

    public function updating($name): void
    {
        if (in_array($name, ['category', 'search', 'show'], true)) {
            $this->resetPage();
        }
    }

    // ------------------------------------------------------------------ items

    public function create(): void
    {
        $this->resetForm();
        $this->f['category_id'] = $this->category !== '' ? (int) $this->category : (ItemCategory::orderBy('sort')->value('id') ?? '');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $item = Item::with('variants')->findOrFail($id);
        $this->resetForm();
        $this->editingId = $item->id;
        $this->f = [
            'name' => $item->name, 'category_id' => $item->category_id ?? '', 'code' => (string) $item->code,
            'barcode' => (string) $item->barcode, 'unit' => $item->unit, 'price' => $this->plain($item->price),
            'gst_rate' => (string) (int) $item->gst_rate, 'hsn' => (string) $item->hsn, 'food_type' => (string) $item->food_type,
            'description' => (string) $item->description, 'track_stock' => $item->track_stock,
            'low_stock_at' => $item->low_stock_at === null ? '' : $this->plain($item->low_stock_at), 'is_active' => $item->is_active,
        ];
        $this->variants = $item->variants->map(fn ($v) => [
            'id' => $v->id, 'name' => $v->name, 'price' => $this->plain($v->price), 'barcode' => (string) $v->barcode, 'is_active' => $v->is_active,
        ])->all();
        $this->showForm = true;
    }

    public function addVariant(): void
    {
        $this->variants[] = ['id' => null, 'name' => '', 'price' => '', 'barcode' => '', 'is_active' => true];
    }

    public function removeVariant(int $i): void
    {
        unset($this->variants[$i]);
        $this->variants = array_values($this->variants);
    }

    public function save(): void
    {
        $id = $this->editingId;
        foreach (['name', 'code', 'barcode', 'hsn', 'description'] as $k) {
            $this->f[$k] = trim((string) ($this->f[$k] ?? ''));
        }
        $this->f['code'] = mb_strtoupper($this->f['code']);
        $this->variants = array_values(array_filter($this->variants, fn ($v) => trim((string) $v['name']) !== '' || trim((string) $v['price']) !== ''));
        foreach ($this->variants as &$v) {
            $v['name'] = trim((string) $v['name']);
            $v['barcode'] = trim((string) ($v['barcode'] ?? ''));
        }
        unset($v);
        $hasVariants = count($this->variants) > 0;

        $variantIds = array_filter(array_column($this->variants, 'id'));
        $this->validate([
            'f.name' => ['required', 'string', 'max:100'],
            'f.category_id' => ['nullable', 'exists:item_categories,id'],
            'f.code' => ['nullable', 'alpha_dash', 'max:30', Rule::unique('items', 'code')->ignore($id)],
            'f.barcode' => ['nullable', 'string', 'max:40', Rule::unique('items', 'barcode')->ignore($id),
                Rule::unique('item_variants', 'barcode')],
            'f.unit' => ['required', Rule::in(array_keys(Item::UNITS))],
            'f.price' => [$hasVariants ? 'nullable' : 'required', 'numeric', 'min:0', 'max:9999999'],
            'f.gst_rate' => ['required', Rule::in(array_map('strval', Item::GST_RATES))],
            'f.hsn' => ['nullable', 'regex:/^\d{4,8}$/'],
            'f.food_type' => ['nullable', Rule::in(array_keys(Item::FOOD_TYPES))],
            'f.description' => ['nullable', 'string', 'max:255'],
            'f.low_stock_at' => ['nullable', 'numeric', 'min:0'],
            'photo' => ['nullable', 'image', 'max:4096'],
            'variants.*.name' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'variants.*.barcode' => ['nullable', 'string', 'max:40', 'distinct', Rule::unique('items', 'barcode')->ignore($id),
                Rule::unique('item_variants', 'barcode')->where(fn ($q) => $q->whereNotIn('id', $variantIds ?: [0]))],
        ], [
            'f.hsn.regex' => 'HSN is 4 to 8 digits (cakes & pastries: 1905).',
            'variants.*.name.distinct' => 'Two sizes have the same name.',
        ], [
            'f.name' => 'name', 'f.price' => 'price', 'f.code' => 'code', 'f.barcode' => 'barcode',
            'variants.*.name' => 'size name', 'variants.*.price' => 'size price',
        ]);

        DB::transaction(function () use ($id, $hasVariants) {
            $item = $id ? Item::findOrFail($id) : new Item;
            $item->fill([
                'name' => $this->f['name'], 'category_id' => $this->f['category_id'] ?: null,
                'code' => $this->f['code'] ?: null, 'barcode' => $this->f['barcode'] ?: null, 'unit' => $this->f['unit'],
                'price' => $hasVariants ? (float) min(array_column($this->variants, 'price')) : (float) $this->f['price'],
                'gst_rate' => (float) $this->f['gst_rate'], 'hsn' => $this->f['hsn'] ?: null, 'food_type' => $this->f['food_type'] ?: null,
                'description' => $this->f['description'] ?: null, 'track_stock' => (bool) ($this->f['track_stock'] ?? false),
                'low_stock_at' => ($this->f['low_stock_at'] ?? '') === '' ? null : (float) $this->f['low_stock_at'],
                'is_active' => (bool) ($this->f['is_active'] ?? true),
            ]);
            if ($this->photo) {
                $old = $item->photo_path;
                $item->photo_path = $this->photo->store('items', 'public');
                if ($old) {
                    Storage::disk('public')->delete($old);
                }
            }
            $item->save();

            $keep = [];
            foreach (array_values($this->variants) as $i => $v) {
                $row = ['name' => $v['name'], 'price' => (float) $v['price'], 'barcode' => $v['barcode'] ?: null, 'sort' => $i, 'is_active' => (bool) ($v['is_active'] ?? true)];
                $model = ! empty($v['id']) ? $item->variants()->whereKey($v['id'])->first() : null;
                $model ? $model->update($row) : $model = $item->variants()->create($row);
                $keep[] = $model->id;
            }
            // Variants removed in the form. (Phase 15: ones already on a bill get hidden instead.)
            $item->variants()->whereNotIn('id', $keep ?: [0])->delete();
        });

        $this->showForm = false;
        $this->dispatch('toast', message: $id ? 'Item updated' : 'Item added');
        $this->resetForm();
    }

    public function removePhoto(): void
    {
        $item = Item::findOrFail($this->editingId);
        if ($item->photo_path) {
            Storage::disk('public')->delete($item->photo_path);
            $item->update(['photo_path' => null]);
        }
        $this->dispatch('toast', message: 'Photo removed');
    }

    public function toggle(int $id): void
    {
        $item = Item::findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);
        $this->dispatch('toast', message: $item->is_active ? 'Shown on the POS' : 'Hidden from the POS');
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('delete-records');
        $this->confirmingDelete = $id;
    }

    public function delete(): void
    {
        Gate::authorize('delete-records');
        $item = Item::findOrFail($this->confirmingDelete);
        if ($item->photo_path) {
            Storage::disk('public')->delete($item->photo_path);
        }
        $item->delete();
        $this->confirmingDelete = null;
        $this->dispatch('toast', message: 'Item deleted');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->confirmingDelete = null;
        $this->showImport = false;
        $this->resetForm();
    }

    // ------------------------------------------------------------------ categories

    public function closeCategories(): void
    {
        $this->reset('showCategories', 'newCategory', 'catEditId');
        $this->resetValidation();
    }

    public function addCategory(): void
    {
        $this->newCategory = trim($this->newCategory);
        $this->validate(['newCategory' => ['required', 'string', 'max:60', new UniqueCi('item_categories')]], [], ['newCategory' => 'category name']);
        ItemCategory::create(['name' => $this->newCategory, 'color' => ItemCategory::nextColor(), 'sort' => (int) ItemCategory::max('sort') + 1]);
        $this->newCategory = '';
    }

    public function editCategory(int $id): void
    {
        $c = ItemCategory::findOrFail($id);
        [$this->catEditId, $this->catEditName, $this->catEditColor] = [$c->id, $c->name, $c->color];
        $this->resetValidation();
    }

    public function saveCategory(): void
    {
        $this->catEditName = trim($this->catEditName);
        $this->validate([
            'catEditName' => ['required', 'string', 'max:60', new UniqueCi('item_categories', 'name', $this->catEditId)],
            'catEditColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [], ['catEditName' => 'name', 'catEditColor' => 'colour']);
        ItemCategory::whereKey($this->catEditId)->update(['name' => $this->catEditName, 'color' => strtolower($this->catEditColor)]);
        $this->catEditId = null;
    }

    public function moveCategory(int $id, int $dir): void
    {
        $cats = ItemCategory::orderBy('sort')->orderBy('id')->get()->values();
        $i = $cats->search(fn ($c) => $c->id === $id);
        $j = $i + $dir;
        if ($i === false || $j < 0 || $j >= $cats->count()) {
            return;
        }
        $order = $cats->pluck('id')->all();
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        foreach ($order as $sort => $cid) {
            ItemCategory::whereKey($cid)->update(['sort' => $sort]);
        }
    }

    public function toggleCategory(int $id): void
    {
        $c = ItemCategory::findOrFail($id);
        $c->update(['is_active' => ! $c->is_active]);
    }

    public function deleteCategory(int $id): void
    {
        Gate::authorize('delete-records');
        $c = ItemCategory::withCount('items')->findOrFail($id);
        if ($c->items_count > 0) {
            $this->addError('catDelete', "“{$c->name}” has {$c->items_count} item(s). Move them or hide the category instead.");

            return;
        }
        $c->delete();
        if ($this->category === (string) $id) {
            $this->category = '';
        }
    }

    // ------------------------------------------------------------------ import

    public function openImport(): void
    {
        $this->reset('importFile', 'importPreview');
        $this->resetValidation();
        $this->showImport = true;
    }

    public function updatedImportFile(): void
    {
        $this->validate(['importFile' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120']], [], ['importFile' => 'file']);
        try {
            $this->importPreview = ItemImport::preview($this->importFile->getRealPath(), $this->importFile->getClientOriginalExtension());
        } catch (\Throwable $e) {
            $this->importPreview = null;
            $this->addError('importFile', $e->getMessage());
        }
    }

    public function runImport(): void
    {
        abort_unless($this->importPreview && empty($this->importPreview['errors']), 422);
        $r = ItemImport::run($this->importFile->getRealPath(), $this->importFile->getClientOriginalExtension());
        $this->showImport = false;
        $this->reset('importFile', 'importPreview');
        $this->dispatch('toast', message: "Imported: {$r['created']} new, {$r['updated']} updated");
    }

    // ------------------------------------------------------------------ render

    public function render()
    {
        $cats = ItemCategory::withCount('items')->orderBy('sort')->orderBy('id')->get();
        $q = Item::with(['variants', 'category'])
            ->search($this->search)
            ->when($this->category === 'none', fn ($w) => $w->whereNull('category_id'))
            ->when(ctype_digit($this->category), fn ($w) => $w->where('category_id', (int) $this->category))
            ->when($this->show === 'active', fn ($w) => $w->where('is_active', true))
            ->when($this->show === 'hidden', fn ($w) => $w->where('is_active', false))
            ->orderBy('sort')->orderBy('name');

        return view('livewire.sales.items', [
            'items' => $q->paginate(48),
            'categories' => $cats,
            'counts' => ['all' => Item::count(), 'active' => Item::where('is_active', true)->count(), 'hidden' => Item::where('is_active', false)->count()],
            'gst' => Sales::gst(),
            'stock' => Sales::on('stock'),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'photo', 'variants');
        $this->f = [
            'name' => '', 'category_id' => '', 'code' => '', 'barcode' => '', 'unit' => 'pcs', 'price' => '', 'gst_rate' => '5',
            'hsn' => '', 'food_type' => 'veg', 'description' => '', 'track_stock' => false, 'low_stock_at' => '', 'is_active' => true,
        ];
        $this->resetValidation();
    }

    private function plain($v): string
    {
        $v = (float) $v;

        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }
}
