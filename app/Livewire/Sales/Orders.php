<?php

namespace App\Livewire\Sales;

use App\Models\Order;
use App\Services\Billing;
use App\Support\Sales;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Bills list (Phase 15). Phase 17 adds the live order board on top. */
#[Title('Orders & bills')]
class Orders extends Component
{
    use WithPagination;

    #[Url]
    public string $date = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    /** bills | running | delivery | advance */
    #[Url]
    public string $tab = 'bills';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $this->date = today()->toDateString();
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['date', 'search', 'status'], true)) {
            $this->resetPage();
        }
    }

    /** Board button: move a delivery / advance order to its next step. */
    public function advance(int $id): void
    {
        $o = Order::whereIn('type', ['delivery', 'advance'])->where('status', '!=', 'cancelled')->findOrFail($id);
        if ($next = $o->nextStage()) {
            Billing::setStage($o, $next);
            $this->dispatch('toast', message: $o->ref().' → '.$o->fresh()->stageLabel());
        }
    }

    public function shiftDay(int $d): void
    {
        $next = Carbon::parse($this->date)->addDays($d);
        $this->date = $next->isFuture() ? today()->toDateString() : $next->toDateString();
    }

    public function render()
    {
        $term = trim($this->search);
        $base = Order::billed()
            ->when($term === '', fn ($q) => $q->whereDate('billed_at', $this->date))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")
                ->orWhere('customer_name', 'like', "%{$term}%")->orWhere('customer_mobile', 'like', '%'.preg_replace('/\D/', '', $term ?: 'x').'%')));

        $day = (clone $base)->where('status', '!=', 'cancelled');
        $live = fn (string $type) => Order::where('type', $type)->whereIn('status', ['open', 'completed'])
            ->where(fn ($q) => $q->where('stage', '!=', 'delivered')->orWhere('status', 'open'));

        $tabs = array_filter([
            'bills' => 'Bills',
            'running' => Sales::on('tables') ? 'Running tables' : null,
            'delivery' => Sales::on('delivery') ? 'Delivery' : null,
            'advance' => Sales::on('advance') ? 'Advance orders' : null,
        ]);
        $counts = [
            'running' => Order::where('type', 'dine_in')->where('status', 'open')->count(),
            'delivery' => Sales::on('delivery') ? $live('delivery')->count() : 0,
            'advance' => Sales::on('advance') ? $live('advance')->count() : 0,
        ];

        return view('livewire.sales.orders', [
            'tabs' => $tabs,
            'counts' => $counts,
            'running' => $this->tab === 'running' ? Order::with('table')->withCount('items')->where('type', 'dine_in')->where('status', 'open')->oldest()->get() : collect(),
            'deliveries' => $this->tab === 'delivery' ? $live('delivery')->oldest()->get()->groupBy('stage') : collect(),
            'advances' => $this->tab === 'advance' ? $live('advance')->with('items')->orderBy('due_at')->get()->groupBy(function ($o) {
                $d = $o->due_at;

                return match (true) {
                    ! $d => 'Later',
                    $d->isPast() && ! $d->isToday() => 'Overdue',
                    $d->isToday() => 'Today',
                    $d->isTomorrow() => 'Tomorrow',
                    default => 'Later',
                };
            })->sortBy(fn ($g, $k) => array_search($k, ['Overdue', 'Today', 'Tomorrow', 'Later'])) : collect(),
            'orders' => (clone $base)->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
                ->withCount('items')->with('payments')->latest('billed_at')->paginate(40),
            'summary' => [
                'count' => (clone $day)->count(),
                'total' => (float) (clone $day)->sum('total'),
                'discount' => (float) (clone $day)->sum('discount'),
                'tax' => (float) (clone $day)->sum('cgst') + (float) (clone $day)->sum('sgst'),
                'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            ],
            'isToday' => $this->date === today()->toDateString(),
        ]);
    }
}
