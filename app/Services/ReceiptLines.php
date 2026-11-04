<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;

/**
 * Plain-text receipt/KOT layout for Bluetooth ESC/POS printers (the Android app prints these lines as they are).
 * One line = ['t' => text, 'a' => L|C|R, 'b' => bold, 's' => 1 normal | 2 double]; ['hr' => '-'] is a full-width rule.
 * Widths: 58 mm paper = 32 characters, 80 mm = 48. ESC/POS code pages have no ₹ glyph, so amounts print as "Rs.".
 */
class ReceiptLines
{
    public static function cols(string $width): int
    {
        return $width === '58' ? 32 : 48;
    }

    public static function receipt(Order $order, ?string $width = null): array
    {
        $d = BillView::data($order);
        $o = $d['o'];
        $w = self::cols($width ?? $d['print']['width']);
        $L = [];
        $L[] = self::t($d['biz']['name'], 'C', true, 2);
        foreach (array_filter([$d['biz']['tagline'], $d['biz']['address'], $d['biz']['phone'] ? 'Ph: '.$d['biz']['phone'] : null]) as $x) {
            foreach (self::wrap($x, $w) as $part) {
                $L[] = self::t($part, 'C');
            }
        }
        if ($o->gst_enabled && $d['biz']['gstin']) {
            $L[] = self::t('GSTIN: '.$d['biz']['gstin'], 'C', true);
        }
        if ($d['biz']['fssai']) {
            $L[] = self::t('FSSAI: '.$d['biz']['fssai'], 'C');
        }
        $L[] = ['hr' => '='];
        $L[] = self::t($d['title'], 'C', true);
        if ($o->isCancelled()) {
            $L[] = self::t('*** CANCELLED ***', 'C', true);
        }
        $L[] = self::t(self::pair(($o->number ? 'Bill: ' : 'Order: ').($o->number ?? '#'.$o->id), Order::TYPES[$o->type] ?? '', $w));
        $L[] = self::t(self::pair(($o->billed_at ?? $o->created_at)->format('d/m/Y h:i A'), (string) $o->creator?->name, $w));
        if ($o->table) {
            $L[] = self::t('Table: '.$o->table->name.($o->guests ? ' ('.$o->guests.' guests)' : ''), 'L', true);
        }
        if ($o->due_at) {
            $L[] = self::t('Pickup: '.$o->due_at->format('D d M Y, h:i A'), 'L', true);
        }
        if ($o->customer_name || $o->customer_mobile) {
            $L[] = self::t('Customer: '.trim($o->customer_name.' '.$o->customer_mobile));
        }
        foreach (self::wrap($o->delivery_address ? 'Deliver to: '.$o->delivery_address : '', $w) as $part) {
            $L[] = self::t($part);
        }
        foreach (Order::CAKE_FIELDS as $k => $label) {
            if (! empty($o->details[$k])) {
                foreach (self::wrap($label.': '.($k === 'eggless' ? 'Yes' : $o->details[$k]), $w) as $part) {
                    $L[] = self::t($part);
                }
            }
        }
        $L[] = ['hr' => '-'];
        $L[] = self::t(self::cols3('Item', 'Qty', 'Amt', $w), 'L', true);
        $L[] = ['hr' => '-'];
        foreach ($o->items as $l) {
            foreach (self::wrap($l->label(), $w) as $part) {
                $L[] = self::t($part, 'L', true);
            }
            $L[] = self::t(self::cols3('  '.self::m($l->rate).($o->gst_enabled ? ' @'.(float) $l->gst_rate.'%' : ''), $l->qtyLabel(), self::m($l->amount), $w));
            if ($l->note) {
                $L[] = self::t('  * '.$l->note);
            }
        }
        $L[] = ['hr' => '-'];
        $L[] = self::t(self::pair('Subtotal', self::m($o->subtotal), $w));
        if ((float) $o->discount > 0) {
            $L[] = self::t(self::pair('Discount', '-'.self::m($o->discount), $w));
        }
        if ($o->gst_enabled) {
            $L[] = self::t(self::pair('Taxable value', self::m($o->taxable), $w));
            $L[] = self::t(self::pair('CGST', self::m($o->cgst), $w));
            $L[] = self::t(self::pair('SGST', self::m($o->sgst), $w));
        }
        if ((float) $o->delivery_charge > 0) {
            $L[] = self::t(self::pair('Delivery', self::m($o->delivery_charge), $w));
        }
        if ((float) $o->round_off != 0) {
            $L[] = self::t(self::pair('Round off', number_format((float) $o->round_off, 2), $w));
        }
        $L[] = ['hr' => '='];
        $L[] = self::t(self::pair('TOTAL', 'Rs.'.self::m($o->total), (int) floor($w / 2)), 'L', true, 2);
        $L[] = ['hr' => '='];
        foreach ($d['pays'] as $p) {
            $L[] = self::t(self::pair('Paid by '.$p['label'], self::m($p['amount']), $w));
        }
        if ($o->balance() > 0.009) {
            $L[] = self::t(self::pair('BALANCE DUE', self::m($o->balance()), $w), 'L', true);
        }
        if ($o->gst_enabled && $d['byRate']->count()) {
            $L[] = ['hr' => '-'];
            foreach ($d['byRate'] as $r => $v) {
                $L[] = self::t(self::pair('GST '.$r.'% on '.self::m($v['taxable']), 'C+S '.self::m($v['tax']), $w));
            }
        }
        if ($d['print']['footer'] !== '') {
            $L[] = ['hr' => '-'];
            foreach (preg_split('/\R/', $d['print']['footer']) as $line) {
                foreach (self::wrap($line, $w) as $part) {
                    $L[] = self::t($part, 'C');
                }
            }
        }

        return $L;
    }

    public static function kot(Order $order, ?int $kotNo = null, ?string $width = null): array
    {
        $order->loadMissing(['items', 'table']);
        $w = self::cols($width ?? BillView::printSettings()['width']);
        $items = $kotNo ? $order->items->where('kot_no', $kotNo) : $order->items;
        $L = [self::t('KITCHEN ORDER', 'C', true, 2)];
        if ($order->table) {
            $L[] = self::t($order->table->name, 'C', true, 2);
        }
        if ($kotNo) {
            $L[] = self::t('KOT '.$kotNo, 'C', true);
        }
        $L[] = self::t($order->ref().' · '.(Order::TYPES[$order->type] ?? ''), 'C');
        $L[] = self::t(now()->format('d/m h:i A').($order->customer_name ? ' · '.$order->customer_name : ''), 'C');
        if ($order->due_at) {
            $L[] = self::t('Pickup '.$order->due_at->format('D d M, h:i A'), 'C', true);
        }
        foreach (Order::CAKE_FIELDS as $k => $label) {
            if (! empty($order->details[$k])) {
                $L[] = self::t($label.': '.($k === 'eggless' ? 'Yes' : $order->details[$k]), 'L', true);
            }
        }
        $L[] = ['hr' => '-'];
        foreach ($items as $l) {
            foreach (self::wrap($l->qtyLabel().' x '.$l->label(), (int) floor($w / 2)) as $part) {
                $L[] = self::t($part, 'L', true, 2);
            }
            if ($l->note) {
                $L[] = self::t('  >> '.$l->note, 'L', true);
            }
        }
        if ($order->note) {
            $L[] = ['hr' => '-'];
            $L[] = self::t('Note: '.$order->note, 'L', true);
        }
        $L[] = ['hr' => '-'];

        return $L;
    }

    /** Any ReportBuilder-shaped report (used for the day-end report). */
    public static function report(array $report, ?string $width = null): array
    {
        $w = self::cols($width ?? BillView::printSettings()['width']);
        $L = [self::t((string) Setting::get('business_name'), 'C', true, 2), self::t(mb_strtoupper($report['title']), 'C', true), self::t($report['subtitle'], 'C'),
            self::t('Printed '.now()->format('d/m/Y h:i A'), 'C'), ['hr' => '=']];
        foreach ($report['kpis'] as [$label, $value, $type]) {
            $L[] = self::t(self::pair($label, self::cell($value, $type), $w));
        }
        foreach ($report['sections'] as $s) {
            $L[] = ['hr' => '-'];
            $L[] = self::t(mb_strtoupper($s['title']), 'L', true);
            foreach ($s['rows'] as $row) {
                $L[] = self::t(self::pair((string) self::cell($row[0], $s['columns'][0][1]), self::cell(end($row), end($s['columns'])[1]), $w));
            }
            if ($s['totals']) {
                $L[] = self::t(self::pair((string) $s['totals'][0], self::cell(end($s['totals']), end($s['columns'])[1]), $w), 'L', true);
            }
        }
        $L[] = ['hr' => '='];
        foreach ($report['notes'] as $n) {
            foreach (self::wrap($n, $w) as $part) {
                $L[] = self::t($part);
            }
        }

        return $L;
    }

    // ------------------------------------------------------------------ helpers

    private static function t(string $text, string $align = 'L', bool $bold = false, int $size = 1): array
    {
        return ['t' => self::ascii($text), 'a' => $align, 'b' => $bold, 's' => $size];
    }

    /** Printers use a 1-byte code page: replace ₹ and typographic marks, drop what can't print. */
    public static function ascii(string $s): string
    {
        $s = strtr($s, ['₹' => 'Rs.', '–' => '-', '—' => '-', '·' => '-', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '…' => '...', '×' => 'x', '→' => '>']);

        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
    }

    private static function m($v): string
    {
        $v = (float) $v;

        return inr_group($v, fmod(abs($v), 1) > 0.004 ? 2 : 0);
    }

    private static function cell($v, string $type): string
    {
        return $type === 'money' ? 'Rs.'.self::m($v) : self::ascii((string) report_cell($v, $type));
    }

    private static function pair(string $left, string $right, int $w): string
    {
        $left = self::ascii($left);
        $right = self::ascii($right);
        $space = $w - mb_strlen($left) - mb_strlen($right);
        if ($space < 1) {
            $left = mb_substr($left, 0, max(1, $w - mb_strlen($right) - 1));
            $space = 1;
        }

        return $left.str_repeat(' ', $space).$right;
    }

    private static function cols3(string $a, string $b, string $c, int $w): string
    {
        $cw = $w >= 48 ? 12 : 9;  // amount column
        $bw = $w >= 48 ? 8 : 6;   // qty column
        $aw = $w - $bw - $cw;
        $a = mb_substr(self::ascii($a), 0, $aw);

        return str_pad($a, $aw).str_pad(self::ascii($b), $bw, ' ', STR_PAD_LEFT).str_pad(self::ascii($c), $cw, ' ', STR_PAD_LEFT);
    }

    /** Word-wrap to the printer width. */
    private static function wrap(string $s, int $w): array
    {
        $s = trim(self::ascii($s));

        return $s === '' ? [] : explode("\n", wordwrap($s, $w, "\n", true));
    }
}
