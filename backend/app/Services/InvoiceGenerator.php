<?php

namespace App\Services;

use App\Models\Order;
use App\Support\Brand;
use TCPDF;

/**
 * Renders the customer invoice PDF.
 *
 * Uses TCPDF (LGPL), which can ship inside a redistributed product — mPDF
 * (GPL) cannot, and dompdf has no Arabic shaping or bidi at all. TCPDF shapes
 * Arabic into joined presentation forms and runs the Unicode bidi algorithm,
 * so Arabic customer names, addresses and product names print correctly
 * inside the English, left-to-right invoice. The template sets Arabic runs in
 * AlArabiya (see the note at the top of customer-invoice.blade.php).
 *
 * Pinned to tecnickcom/tcpdf 6.x: 7.x is a facade over tc-lib-pdf that ships
 * no font files, so it cannot render out of the box on shared hosting.
 */
class InvoiceGenerator
{
    public function generate(Order $order): string
    {
        @ini_set('memory_limit', '512M');

        // Product names are snapshotted at order time in whatever language the
        // customer was browsing; load the live product so the invoice can show
        // the English name where one still exists.
        $order->loadMissing('items.product:id,name');

        $html = view('invoices.customer-invoice', [
            'order' => $order,
            'tax' => (new InvoiceTax)->for($order),
        ])->render();

        // TCPDF prints the template's indentation: whitespace between two tags
        // or after a <br> becomes a visible leading space on the next line.
        $html = preg_replace(['/>\s+</', '/<br>\s+/'], ['><', '<br>'], $html);

        $this->defineCachePath();

        $pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false) extends TCPDF
        {
            // No "Powered by TCPDF" link: it lands in the text layer, so it
            // shows up in copy/paste and in anything that indexes the invoice.
            protected $tcpdflink = false;
        };

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 11, 12);
        $pdf->SetAutoPageBreak(true, 11);
        $pdf->setFontSubsetting(true);
        $pdf->SetFont('dejavusans', '', 8.5);

        $pdf->SetCreator(Brand::name());
        $pdf->SetAuthor(Brand::name());
        $pdf->SetTitle('Invoice '.$order->order_number);

        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    public function filename(Order $order): string
    {
        return "Invoice-{$order->order_number}.pdf";
    }

    /**
     * TCPDF writes temporary image files to K_PATH_CACHE, which defaults to
     * the system temp dir — often outside open_basedir on shared hosting.
     * Point it at storage/ instead. Must run before TCPDF is first loaded,
     * because its autoconfig defines the constant on load.
     */
    private function defineCachePath(): void
    {
        if (defined('K_PATH_CACHE')) {
            return;
        }

        $dir = storage_path('app/tcpdf');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            define('K_PATH_CACHE', rtrim($dir, '/\\').DIRECTORY_SEPARATOR);
        }
    }
}
