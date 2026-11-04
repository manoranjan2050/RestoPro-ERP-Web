<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/** Everything a printed receipt, KOT, PDF or the customer's bill page needs — one shape for all of them. */
class BillView
{
    public const WIDTHS = ['58' => '58 mm (2 inch)', '80' => '80 mm (3 inch)'];

    public static function data(Order $order): array
    {
        $order->loadMissing(['items', 'payments', 'creator']);
        $logoPath = Setting::get('business_logo');
        $logoOk = $logoPath && Storage::disk('public')->exists($logoPath);

        return [
            'o' => $order,
            'biz' => [
                'name' => (string) Setting::get('business_name'),
                'tagline' => Setting::get('business_tagline'),
                'address' => Setting::get('business_address'),
                'phone' => Setting::get('business_phone'),
                'email' => Setting::get('business_email'),
                'gstin' => Setting::get('business_gstin'),
                'fssai' => Setting::get('business_fssai'),
                'logo_url' => $logoOk ? route('media', $logoPath) : null,
                // dompdf can't fetch our own URL behind auth/cookies — give it the bytes.
                'logo_data' => $logoOk ? 'data:'.Storage::disk('public')->mimeType($logoPath).';base64,'.base64_encode(Storage::disk('public')->get($logoPath)) : null,
            ],
            'byRate' => $order->items->groupBy(fn ($l) => (string) (float) $l->gst_rate)
                ->map(fn ($g) => ['taxable' => round($g->sum('taxable'), 2), 'tax' => round($g->sum('tax'), 2)])->sortKeys(),
            'pays' => $order->payments->groupBy('mode')->map(fn ($g, $m) => ['label' => OrderPayment::MODES[$m][0] ?? $m, 'amount' => round($g->sum('amount'), 2)])->values(),
            'print' => self::printSettings(),
            'title' => $order->gst_enabled ? 'TAX INVOICE' : 'BILL',
        ];
    }

    public static function printSettings(): array
    {
        $w = Setting::get('print_width', '80');

        return [
            'width' => array_key_exists($w, self::WIDTHS) ? $w : '80',
            'footer' => (string) Setting::get('print_footer', 'Thank you! Please visit again.'),
            'logo' => Setting::get('print_logo', '1') === '1',
            'auto' => Setting::get('print_auto', '1') === '1',
            'kot' => Setting::get('print_kot', '0') === '1',
        ];
    }

    /** Customer's private bill link (no login). */
    public static function publicUrl(Order $order): ?string
    {
        return $order->share_token ? route('bill.public', $order->share_token) : null;
    }

    /** wa.me link with a short message + the bill link; opens the customer's chat when their mobile is known. */
    public static function whatsappUrl(Order $order): ?string
    {
        $url = self::publicUrl($order);
        if (! $url) {
            return null;
        }
        $name = trim((string) $order->customer_name);
        $text = ($name !== '' && $name !== 'Customer' ? "Hi {$name}, t" : 'T').'hank you for shopping at '.Setting::get('business_name').'!'
            ."\nBill {$order->number} · ".money($order->total)
            ."\nView / download: {$url}";

        return 'https://wa.me/'.($order->customer_mobile ? '91'.$order->customer_mobile : '').'?text='.rawurlencode($text);
    }
}
