<?php

namespace App\Livewire\Sales;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\StockMovement;
use App\Services\Stock;
use App\Support\Sales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Simple stock: what's on hand, made today / received, wastage, counts, and the movement history. */
#[Title('Stock')]
class StockPage extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'stock'; // stock | history

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $lowOnly = false;

    // history filters
    #[Url]
    public string $reason = '';

    // single-row action
    public ?string $actKey = null;
    public string $actType = 'made'; // made | purchase | wastage | count
    public string $actQty = '';
    public string $actNote = '';

    // bulk "made today"
    public bool $showBulk = false;
    public string $bulkReason = 'made';
    public array $bulk = [];

    // start tracking
    public bool $showTrack = false;
    public array $track = [];

    public function mount(): void
    {
        abort_unless(Sales::on('stock'), 404);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'reason', 'tab', 'lowOnly'], true)) {
            $this->resetPage();
        }
    }

    // ------------------------------------------------------------------ single row

    public function act(string $key, string $type): void
    {
        $this->actKey = $key;
        $this->actType = in_array($type, ['made', 'purchase', 'wastage', 'count'], true) ? $type : 'made';
        $this->actQty = '';
        $this->actNote = '';
        $this->resetValidation();
    }

    public function closeAct(): void
    {
        $this->actKey = null;
    }

    public function saveAct(): void
    {
        $this->validate([
            'actQty' => ['required', 'numeric', $this->actType === 'count' ? 'min:0' : 'gt:0', 'max:99999'],
            'actNote' => ['nullable', 'string', 'max:120'],
        ], [], ['actQty' => $this->actType === 'count' ? 'count' : 'quantity']);
        [$item, $variant] = $this->resolve($this->actKey);
        try {
            $this->actType === 'count'
                ? Stock::setCount($item, $variant, (float) $this->actQty, Auth::user(), $this->actNote)
                : Stock::move($item, $variant, ($this->actType === 'wastage' ? -1 : 1) * (float) $this->actQty, $this->actType, Auth::user(), $this->actNote);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->actKey = null;
        $this->dispatch('toast', message: 'Stock updated');
    }

    // ------------------------------------------------------------------ bulk

    public function openBulk(string $reason = 'made'): void
    {
        $this->bulkReason = in_array($reason, ['made', 'purchase', 'wastage'], true) ? $reason : 'made';
        $this->bulk = collect(Stock::rows())->filter(fn ($r) => $r['active'])->mapWithKeys(fn ($r) => [$r['key'] => ''])->all();
        $this->resetValidation();
        $this->showBulk = true;
    }

    public function closeBulk(): void
    {
        $this->showBulk = false;
    }

    public function saveBulk(): void
    {
        $lines = array_filter($this->bulk, fn ($q) => trim((string) $q) !== '' && (float) $q != 0);
        foreach ($lines as $k => $q) {
            if (! is_numeric($q) || (float) $q < 0 || (float) $q > 99999) {
                $this->addError('bulk', 'Check the quantity for every item (positive numbers only).');

                return;
            }
        }
        if (! $lines) {
            $this->addError('bulk', 'Enter a quantity for at least one item.');

            return;
        }
        DB::transaction(function () use ($lines) {
            foreach ($lines as $k => $q) {
                [$item, $variant] = $this->resolve($k);
                Stock::move($item, $variant, ($this->bulkReason === 'wastage' ? -1 : 1) * (float) $q, $this->bulkReason, Auth::user());
            }
        });
        $this->showBulk = false;
        $this->dispatch('toast', message: count($lines).' item(s) updated');
    }

    // ------------------------------------------------------------------ start tracking

    public function openTrack(): void
    {
        $this->track = [];
        $this->showTrack = true;
    }

    public function closeTrack(): void
    {
        $this->showTrack = false;
    }

    public function saveTrack(): void
    {
        $ids = array_keys(array_filter($this->track));
        Item::whereIn('id', $ids)->update(['track_stock' => true]);
        $this->showTrack = false;
        $this->dispatch('toast', message: count($ids).' item(s) now tracked — add their stock with “Made today”');
    }

    // ------------------------------------------------------------------ render

    public function render()
    {
        $rows = collect(Stock::rows($this->search));
        $all = collect(Stock::rows());
        $act = $this->actKey ? $all->firstWhere('key', $this->actKey) : null;

        return view('livewire.sales.stock', [
            'rows' => $this->lowOnly ? $rows->filter(fn ($r) => $r['low'] || $r['qty'] <= 0)->values() : $rows,
            'kpi' => [
                'tracked' => $all->count(),
                'low' => $all->filter(fn ($r) => $r['low'] && $r['qty'] > 0)->count(),
                'out' => $all->filter(fn ($r) => $r['qty'] <= 0)->count(),
                'made' => (float) StockMovement::whereDate('date', today())->where('reason', 'made')->sum('qty'),
                'sold' => -(float) StockMovement::whereDate('date', today())->where('reason', 'sale')->sum('qty'),
                'waste' => -(float) StockMovement::whereDate('date', today())->where('reason', 'wastage')->sum('qty'),
            ],
            'act' => $act,
            'bulkRows' => $this->showBulk ? $all->filter(fn ($r) => $r['active'])->values() : collect(),
            'untracked' => $this->showTrack ? Item::where('track_stock', false)->active()->orderBy('name')->get() : collect(),
            'history' => $this->tab === 'history'
                ? StockMovement::with(['item', 'variant', 'order', 'creator'])
                    ->when($this->reason !== '', fn ($q) => $q->where('reason', $this->reason))
                    ->when(trim($this->search) !== '', fn ($q) => $q->whereHas('item', fn ($i) => $i->search($this->search)))
                    ->latest('id')->paginate(50)
                : null,
        ]);
    }

    /** "itemId-variantId" → [Item, ?ItemVariant] */
    private function resolve(?string $key): array
    {
        [$i, $v] = array_map('intval', explode('-', (string) $key) + [0, 0]);
        $item = Item::findOrFail($i);

        return [$item, $v ? ItemVariant::where('item_id', $item->id)->findOrFail($v) : null];
    }
}
