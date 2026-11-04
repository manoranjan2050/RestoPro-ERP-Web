<?php

namespace App\Livewire\Sales;

use App\Models\Order;
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

        return view('livewire.sales.orders', [
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
