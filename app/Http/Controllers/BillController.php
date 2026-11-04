<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\BillView;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Thermal receipt + KOT (browser print), PDF invoice, and the customer's public bill page. */
class BillController extends Controller
{
    public function receipt(Request $request, Order $order)
    {
        abort_unless($order->number, 404);
        $data = BillView::data($order);
        if (in_array((string) $request->query('w'), array_map('strval', array_keys(BillView::WIDTHS)), true)) {
            $data['print']['width'] = $request->query('w');
        }

        return response()->view('bills.receipt', $data + ['auto' => $request->boolean('auto')])
            ->header('Cache-Control', 'no-store');
    }

    public function kot(Request $request, Order $order)
    {
        $data = BillView::data($order);

        return response()->view('bills.kot', $data + ['auto' => $request->boolean('auto')])->header('Cache-Control', 'no-store');
    }

    public function pdf(Request $request, Order $order)
    {
        abort_unless($order->number, 404);

        return $this->pdfResponse($order, $request->boolean('view'));
    }

    // ------------------------------------------------------------------ public (customer)

    public function publicShow(string $token)
    {
        $order = Order::where('share_token', $token)->whereNotNull('number')->firstOrFail();

        return view('bills.public', BillView::data($order));
    }

    public function publicPdf(Request $request, string $token)
    {
        $order = Order::where('share_token', $token)->whereNotNull('number')->firstOrFail();

        return $this->pdfResponse($order, $request->boolean('view'));
    }

    private function pdfResponse(Order $order, bool $inline)
    {
        $pdf = Pdf::loadView('bills.pdf', BillView::data($order))
            ->setPaper('a5', 'portrait')
            ->setOption('isFontSubsettingEnabled', true);
        $file = Str::slug('bill '.$order->number).'.pdf';

        return $inline ? $pdf->stream($file) : $pdf->download($file);
    }
}
