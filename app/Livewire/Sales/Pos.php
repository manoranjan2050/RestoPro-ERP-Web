<?php

namespace App\Livewire\Sales;

use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Billing;
use App\Support\Sales;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** v2 counter POS: tap items → cart → pay (split cash/UPI/card) → numbered bill. Held bills can be resumed. */
#[Title('POS')]
class Pos extends Component
{
    public string $category = '';
    public string $search = '';

    /** @var list<array{key: string, item_id: int, variant_id: ?int, qty: float|string, note: string}> */
    public array $cart = [];
    public string $discount_value = '';
    public string $discount_type = 'amt';
    public string $customer_mobile = '';
    public string $customer_name = '';
    public string $type = 'counter';
    public string $note = '';
    public ?int $heldId = null;

    public ?int $pickingItem = null;
    public ?string $noteFor = null;

    public bool $showPay = false;
    public array $payments = [];
    public string $tendered = '';

    public bool $showHeld = false;
    public ?int $lastOrderId = null;

    // ------------------------------------------------------------------ cart

    public function add(int $itemId, ?int $variantId = null): void
    {
        $item = Item::with(['variants' => fn ($q) => $q->where('is_active', true)])->active()->find($itemId);
        if (! $item) {
            return;
        }
        if (! $variantId && $item->variants->isNotEmpty()) {
            $this->pickingItem = $item->id; // ask which size

            return;
        }
        $this->pickingItem = null;
        foreach ($this->cart as $i => $c) {
            if ($c['item_id'] === $item->id && $c['variant_id'] === $variantId && $c['note'] === '') {
                $this->cart[$i]['qty'] = (float) $c['qty'] + 1;
                $this->moveToTop($i);

                return;
            }
        }
        array_unshift($this->cart, ['key' => Str::random(8), 'item_id' => $item->id, 'variant_id' => $variantId, 'qty' => 1, 'note' => '']);
    }

    /** Enter in the search box: a barcode or short code adds straight away; a single match adds too. */
    public function scan(): void
    {
        $term = trim($this->search);
        if ($term === '') {
            return;
        }
        if ($v = ItemVariant::where('barcode', $term)->where('is_active', true)->first()) {
            $this->add($v->item_id, $v->id);
        } elseif ($i = Item::active()->where(fn ($q) => $q->where('barcode', $term)->orWhere('code', mb_strtoupper($term)))->first()) {
            $this->add($i->id);
        } elseif (($m = Item::active()->search($term)->limit(2)->pluck('id'))->count() === 1) {
            $this->add($m[0]);
        } else {
            return; // several matches: leave them on screen to tap
        }
        $this->search = '';
    }

    public function inc(string $key): void
    {
        $this->each($key, fn (&$c) => $c['qty'] = (float) $c['qty'] + 1);
    }

    public function dec(string $key): void
    {
        $this->each($key, fn (&$c) => $c['qty'] = max(0, (float) $c['qty'] - 1));
        $this->cart = array_values(array_filter($this->cart, fn ($c) => (float) $c['qty'] > 0));
    }

    public function remove(string $key): void
    {
        $this->cart = array_values(array_filter($this->cart, fn ($c) => $c['key'] !== $key));
    }

    /** Typed quantity (0 or empty removes the line). Lines are addressed by key, never by position. */
    public function setQty(string $key, $value): void
    {
        $q = is_numeric($value) ? round((float) $value, 3) : null;
        if ($q !== null && $q <= 0) {
            $this->remove($key);

            return;
        }
        $this->each($key, fn (&$c) => $c['qty'] = $q === null ? $c['qty'] : min($q, 99999));
    }

    public function setNote(string $key, $value): void
    {
        $this->each($key, fn (&$c) => $c['note'] = mb_substr(trim((string) $value), 0, 120));
        $this->noteFor = null;
    }

    public function clear(): void
    {
        $this->reset('cart', 'discount_value', 'discount_type', 'customer_mobile', 'customer_name', 'type', 'note', 'heldId',
            'payments', 'tendered', 'showPay', 'pickingItem', 'noteFor');
        $this->resetValidation();
    }

    public function updatedCustomerMobile(): void
    {
        $m = User::normaliseMobile($this->customer_mobile);
        if ($m && strlen($m) === 10 && ($c = Customer::where('mobile', $m)->first())) {
            $this->customer_name = $c->name;
        }
    }

    // ------------------------------------------------------------------ hold / resume

    public function hold(): void
    {
        try {
            $o = Billing::save($this->cart, $this->opts() + ['hold' => true], [], Auth::user(), $this->heldId ? Order::find($this->heldId) : null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->clear();
        $this->dispatch('toast', message: 'Bill put on hold (#'.$o->id.')');
    }

    public function resume(int $id): void
    {
        $o = Order::with('items')->where('status', 'held')->findOrFail($id);
        $this->clear();
        $this->heldId = $o->id;
        $this->cart = $o->items->map(fn ($l) => [
            'key' => Str::random(8), 'item_id' => $l->item_id, 'variant_id' => $l->variant_id, 'qty' => (float) $l->qty, 'note' => (string) $l->note,
        ])->filter(fn ($c) => $c['item_id'])->values()->all();
        $this->fill([
            'discount_value' => (float) $o->discount_value ? rtrim(rtrim((string) $o->discount_value, '0'), '.') : '',
            'discount_type' => $o->discount_type, 'customer_mobile' => (string) $o->customer_mobile,
            'customer_name' => (string) $o->customer_name, 'type' => $o->type, 'note' => (string) $o->note,
        ]);
        $this->showHeld = false;
    }

    public function discardHeld(int $id): void
    {
        Order::where('status', 'held')->whereKey($id)->delete();
        if ($this->heldId === $id) {
            $this->clear();
        }
    }

    // ------------------------------------------------------------------ pay

    public function openPay(): void
    {
        $this->resetValidation();
        if (! $this->cart) {
            $this->addError('cart', 'Add at least one item.');

            return;
        }
        $this->payments = [['mode' => 'cash', 'amount' => $this->plain($this->live()['total'])]];
        $this->tendered = '';
        $this->showPay = true;
    }

    public function payAll(string $mode): void
    {
        if (array_key_exists($mode, OrderPayment::MODES)) {
            $this->payments = [['mode' => $mode, 'amount' => $this->plain($this->live()['total'])]];
        }
    }

    public function addSplit(): void
    {
        $left = round($this->live()['total'] - array_sum(array_map(fn ($p) => (float) $p['amount'], $this->payments)), 2);
        $used = array_column($this->payments, 'mode');
        $mode = collect(array_keys(OrderPayment::MODES))->first(fn ($m) => ! in_array($m, $used, true)) ?? 'upi';
        $this->payments[] = ['mode' => $mode, 'amount' => $left > 0 ? $this->plain($left) : ''];
    }

    public function removeSplit(int $i): void
    {
        unset($this->payments[$i]);
        $this->payments = array_values($this->payments) ?: [['mode' => 'cash', 'amount' => '']];
    }

    public function complete(): void
    {
        try {
            $order = Billing::save($this->cart, $this->opts(), $this->payments, Auth::user(), $this->heldId ? Order::find($this->heldId) : null);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->clear();
        $this->lastOrderId = $order->id;
        $this->dispatch('toast', message: $order->number.' saved · '.money($order->total));
        $print = \App\Services\BillView::printSettings();
        $this->dispatch('bill-saved',
            id: $order->id,
            print: $print['auto'] ? route('orders.print', $order).'?auto=1' : null,
            kot: $print['kot'] ? route('orders.kot', $order).'?auto=1' : null,
        );
    }

    public function closePicker(): void
    {
        $this->pickingItem = null;
    }

    public function closePay(): void
    {
        $this->showPay = false;
    }

    public function closeHeld(): void
    {
        $this->showHeld = false;
    }

    public function dismissLast(): void
    {
        $this->lastOrderId = null;
    }

    // ------------------------------------------------------------------ render

    public function render()
    {
        $live = $this->live();
        $priced = [];
        if ($this->cart) {
            try {
                $lines = Billing::price($this->cart);
                foreach ($this->cart as $i => $c) {
                    $priced[$c['key']] = $lines[$i] + ['calc' => $live['lines'][$i] ?? null];
                }
            } catch (ValidationException) {
                // an item vanished meanwhile; render() must not fail — saving reports it
            }
        }

        return view('livewire.sales.pos', [
            'categories' => ItemCategory::where('is_active', true)->orderBy('sort')->orderBy('id')->get(),
            'items' => Item::with(['variants' => fn ($q) => $q->where('is_active', true)])->active()
                ->where(fn ($q) => $q->whereNull('category_id')->orWhereHas('category', fn ($c) => $c->where('is_active', true)))
                ->when($this->category !== '', fn ($q) => $q->where('category_id', (int) $this->category))
                ->search($this->search)->orderBy('sort')->orderBy('name')->limit(120)->get(),
            'priced' => $priced,
            'live' => $live,
            'gst' => Sales::gst(),
            'held' => Order::where('status', 'held')->withCount('items')->latest()->limit(30)->get(),
            'picking' => $this->pickingItem ? Item::with(['variants' => fn ($q) => $q->where('is_active', true)])->find($this->pickingItem) : null,
            'last' => $this->lastOrderId ? Order::find($this->lastOrderId) : null,
            'paidSum' => round(array_sum(array_map(fn ($p) => (float) ($p['amount'] ?: 0), $this->payments)), 2),
            'maxDiscount' => Billing::maxDiscountPct(Auth::user()),
        ]);
    }

    /** Totals for what's on screen (0s when the cart is empty or an item vanished). */
    private function live(): array
    {
        try {
            return Billing::totals($this->cart ? Billing::price($this->cart) : [], (float) $this->discount_value, $this->discount_type);
        } catch (ValidationException) {
            return Billing::totals([], 0, 'amt');
        }
    }

    private function opts(): array
    {
        return [
            'discount_value' => (float) $this->discount_value, 'discount_type' => $this->discount_type,
            'customer_mobile' => $this->customer_mobile, 'customer_name' => $this->customer_name,
            'type' => in_array($this->type, ['counter', 'takeaway'], true) ? $this->type : 'counter', 'note' => $this->note,
        ];
    }

    private function each(string $key, callable $fn): void
    {
        foreach ($this->cart as $i => $c) {
            if ($c['key'] === $key) {
                $fn($this->cart[$i]);
            }
        }
    }

    private function moveToTop(int $i): void
    {
        $line = $this->cart[$i];
        unset($this->cart[$i]);
        array_unshift($this->cart, $line);
        $this->cart = array_values($this->cart);
    }

    private function plain(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
