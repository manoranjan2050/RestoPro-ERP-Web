<?php

namespace App\Livewire\Sales;

use App\Models\Order;
use App\Services\Billing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** One bill: lines, GST breakup, payments; owner can cancel it with a reason. */
class OrderShow extends Component
{
    public Order $order;

    public bool $confirmCancel = false;
    public string $reason = '';

    public function mount(Order $order): void
    {
        abort_if($order->status === 'held', 404);
        $this->order = $order;
    }

    public function askCancel(): void
    {
        Gate::authorize('delete-records');
        $this->confirmCancel = true;
    }

    public function closeCancel(): void
    {
        $this->confirmCancel = false;
        $this->resetValidation();
    }

    public function cancelBill(): void
    {
        Gate::authorize('delete-records');
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'reason']);
        Billing::cancel($this->order, $this->reason, Auth::user());
        $this->order->refresh();
        $this->confirmCancel = false;
        $this->dispatch('toast', message: 'Bill cancelled — removed from today\'s sales');
    }

    public function render()
    {
        $this->order->load(['items', 'payments', 'creator']);
        $byRate = $this->order->items->groupBy(fn ($l) => (string) (float) $l->gst_rate)
            ->map(fn ($g) => ['taxable' => round($g->sum('taxable'), 2), 'tax' => round($g->sum('tax'), 2)])->sortKeys();

        return view('livewire.sales.order-show', ['byRate' => $byRate])->title($this->order->title());
    }
}
