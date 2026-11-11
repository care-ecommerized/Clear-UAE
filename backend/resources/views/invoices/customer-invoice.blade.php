{{--
    Customer invoice — rendered to PDF by App\Services\InvoiceGenerator (TCPDF).

    Written for TCPDF's HTML subset, not a browser:
    - Layout is tables with explicit percentage widths; cell spacing comes
      from the table's cellpadding attribute and spacer rows. CSS padding /
      margin on divs, border-collapse and white-space are ignored.
    - Sizes are in pt (TCPDF reads 1px as 1pt, so px values print 33% larger
      than in a browser).
    - Arabic runs go through $ar(), which sets them in AlArabiya: DejaVu Sans
      has no glyph for the "Allah" ligature (U+FDF2) that TCPDF's Arabic shaper
      emits, so عبدالله printed with a missing-glyph box. TCPDF shapes the
      letters and runs the bidi algorithm itself.
--}}
@php
    // The invoice's date must be the store's LOCAL date of the order
    // (app.display_timezone). Formatting the raw (UTC) timestamp put an
    // order placed just after local midnight on the previous day.
    //
    // Applied only from the cutover onwards: this template also renders
    // reprints of past orders, and renumbering an invoice a customer
    // already holds would be worse than leaving it as issued.
    //
    // An order that recorded its own invoice_date when it was created uses
    // that, so a later Branding timezone change never redates/renumbers it.
    $orderedAt = $order->created_at->copy();
    if (! empty($order->invoice_date)) {
        $orderedAt = \Illuminate\Support\Carbon::parse($order->invoice_date);
    } elseif ($orderedAt->greaterThanOrEqualTo(config('app.invoice_local_dates_from'))) {
        $orderedAt->setTimezone(config('app.display_timezone'));
    }

    // Date of supply for goods = despatch. Only printed when it differs
    // from the issue date (see the header block).
    $suppliedAt = $order->shipped_at
        ? $order->shipped_at->copy()->setTimezone(config('app.display_timezone'))
        : null;

    // Display-only invoice numbering: {YYYYMMDD}-{last 4 of order_number, padded}
    $invoiceDate = $orderedAt->format('Ymd');
    $shortRef = preg_replace('/\D/', '', $order->order_number ?? '');
    $shortRef = substr(str_pad($shortRef, 4, '0', STR_PAD_LEFT), -4);
    $displayInvoiceNumber = $invoiceDate . '-' . $shortRef;

    // Table header follows the store's brand colour (Admin → Theme).
    $brandColor = \App\Support\Brand::color();

    // Store identity from Admin → Theme → Branding, with the
    // config/invoice.php env values as fallbacks.
    $storeName = \App\Support\Brand::name();
    $legalName = \App\Support\Brand::get('legal_name', config('invoice.company.name') ?: $storeName);
    $storeAddress = \App\Support\Brand::address() ?: config('invoice.company.address');
    $storePhone = \App\Support\Brand::phone() ?: config('invoice.company.phone');
    $storeEmail = \App\Support\Brand::get('store_email', config('invoice.company.email') ?: \App\Support\Brand::email());
    $licenceNo = \App\Support\Brand::get('invoice_licence_no', (string) config('invoice.company.licence'));
    $currency = \App\Support\Brand::currency();

    // Home country name for the zero-rated export note.
    $homeCode = \App\Support\Brand::countryCode();
    $homeCountry = class_exists(\Locale::class)
        ? (\Locale::getDisplayRegion('-' . $homeCode, 'en') ?: $homeCode)
        : $homeCode;

    // Escapes $text and, when it contains Arabic, sets it in the Arabic font.
    // Bidi::rtlIfArabic() wraps an Arabic-led line in RLE…PDF so a line such
    // as "… برج اتصالات stc، مبنى 12" keeps right-to-left order inside the
    // left-to-right invoice — see App\Support\Bidi.
    $ar = function ($text) {
        $text = (string) $text;
        if (! preg_match('/\p{Arabic}/u', $text)) {
            return e($text);
        }

        return '<span style="font-family:aealarabiya;font-size:10pt;">'
            . e(\App\Support\Bidi::rtlIfArabic($text)) . '</span>';
    };

    $money = fn ($amount) => e($currency) . ' ' . number_format((float) $amount, 2);

    $logoPath = \App\Support\Brand::logoPath();
    $logoData = $logoPath ? @file_get_contents($logoPath) : false;

    $isSaudi = (bool) preg_match('/saudi|\bksa\b|^\s*sa\s*$/i', (string) $order->shipping_country);
    $localityParts = collect([$order->shipping_city, $order->shipping_state])->filter();
    // Arabic text takes the Arabic comma (،), not the Latin one.
    $localitySep = $localityParts->contains(fn ($p) => preg_match('/\p{Arabic}/u', (string) $p)) ? '، ' : ', ';
    $locality = $localityParts->implode($localitySep);

    // Column widths (%). The description takes whatever the others leave.
    $colQty = 8;
    $colUnit = 16;
    $colVat = $tax['registered'] ? 12 : 0;
    $colAmount = 17;
    $colDesc = 100 - $colQty - $colUnit - $colVat - $colAmount;
@endphp
<style>
    body, td, div, span { font-family: dejavusans; color: #1a3a3a; }
    .title { font-size: 22pt; font-weight: bold; letter-spacing: 2pt; color: #1a3a3a; }
    .meta { font-size: 8.5pt; color: #555555; line-height: 1.6; }
    .block-title { font-size: 7.5pt; font-weight: bold; color: #888888; letter-spacing: 0.8pt; }
    .block-body { font-size: 8.5pt; line-height: 1.7; }
    th { color: #ffffff; font-size: 8.5pt; font-weight: normal; }
    td.item { font-size: 8.5pt; border-bottom: 0.5pt solid #eeeeee; }
    td.label { font-size: 8.5pt; color: #666666; text-align: right; }
    td.value { font-size: 8.5pt; text-align: right; }
    tr.grand td { font-size: 10.5pt; font-weight: bold; color: #1a3a3a; border-top: 1.5pt solid #1a3a3a; }
    tr.discount td { color: #16a34a; }
    tr.net td { border-top: 0.5pt solid #dddddd; }
    .tax-note { font-size: 7.5pt; color: #555555; background-color: #f5f7f7; border-left: 2pt solid #1a3a3a; }
    .gift { font-size: 8.5pt; border: 0.5pt solid #e5ddd5; }
    .footer-note { font-size: 7.5pt; color: #888888; text-align: center; border-top: 0.5pt solid #dddddd; }
</style>

<table cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td width="50%">
            @if($logoData !== false && $logoData !== '')
                {{-- "@" + base64 is TCPDF's inline-image syntax: no file path
                     or URL to resolve, so it works on any host. --}}
                <img src="{{ '@'.base64_encode($logoData) }}" width="150">
            @else
                <span style="font-size:14pt;font-weight:bold;">{!! $ar($storeName) !!}</span>
            @endif
        </td>
        <td width="50%" align="right">
            <span class="title">{{ $tax['title'] }}</span><br>
            <span class="meta">
                <b>Invoice #:</b> INV-{{ $displayInvoiceNumber }}<br>
                @if($tax['registered'])
                    <b>TRN:</b> {{ $tax['trn'] }}<br>
                @endif
                <b>Order #:</b> {{ $order->order_number }}<br>
                <b>Date of Issue:</b> {{ $orderedAt->format('d M Y') }}
                @if($suppliedAt && $suppliedAt->format('Ymd') !== $orderedAt->format('Ymd'))
                    {{-- Article 59 requires the date of supply whenever it
                         differs from the issue date. For goods that is the
                         despatch date, which we have as shipped_at. --}}
                    <br><b>Date of Supply:</b> {{ $suppliedAt->format('d M Y') }}
                @endif
            </span>
        </td>
    </tr>
</table>
<br><br>

<table cellpadding="0" cellspacing="0" width="100%">
    <tr>
        <td width="50%">
            <span class="block-title">FROM</span><br>
            <span class="block-body">
                <b>{!! $ar($legalName) !!}</b><br>
                @if($storeAddress){!! $ar($storeAddress) !!}<br>@endif
                @if($storePhone)Phone: {{ $storePhone }}<br>@endif
                @if($storeEmail)Email: {{ $storeEmail }}<br>@endif
                @if($licenceNo)Licence No: {{ $licenceNo }}<br>@endif
                @if($tax['registered'])
                    <b>TRN: {{ $tax['trn'] }}</b>
                @endif
            </span>
        </td>
        <td width="50%">
            <span class="block-title">BILL TO</span><br>
            <span class="block-body">
                <b>{!! $ar($order->customer_name) !!}</b><br>
                {!! $ar($order->shipping_address) !!}<br>
                @if($locality){!! $ar($locality) !!}<br>@endif
                {!! $ar($order->shipping_country) !!}<br>
                @if($order->shipping_zip){{ $isSaudi ? 'National Address Code' : 'Postal Code' }}: {{ $order->shipping_zip }}<br>@endif
                {{ $order->customer_phone }}<br>
                {{ $order->customer_email }}
            </span>
        </td>
    </tr>
</table>
<br><br>

<table cellpadding="6" cellspacing="0" width="100%">
    <thead>
        <tr>
            <th bgcolor="{{ $brandColor }}" style="color:#ffffff;" width="{{ $colDesc }}%" align="left">Description</th>
            <th bgcolor="{{ $brandColor }}" style="color:#ffffff;" width="{{ $colQty }}%" align="center">Qty</th>
            <th bgcolor="{{ $brandColor }}" style="color:#ffffff;" width="{{ $colUnit }}%" align="right">Unit Price</th>
            @if($tax['registered'])
                <th bgcolor="{{ $brandColor }}" style="color:#ffffff;" width="{{ $colVat }}%" align="center">VAT Rate</th>
            @endif
            <th bgcolor="{{ $brandColor }}" style="color:#ffffff;" width="{{ $colAmount }}%" align="right">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($order->items as $item)
            {{-- The stored product_name is a snapshot in whatever language
                 the customer was browsing. The invoice is issued in English,
                 so prefer the live English name while the product still
                 exists; otherwise the Arabic snapshot is shown. --}}
            @php $desc = $item->product?->name ?: $item->product_name; @endphp
            <tr nobr="true">
                <td class="item" width="{{ $colDesc }}%">{!! $ar($desc) !!}</td>
                <td class="item" width="{{ $colQty }}%" align="center">{{ $item->quantity }}</td>
                <td class="item" width="{{ $colUnit }}%" align="right">{!! $money($item->unit_price ?? ($item->total_price / max($item->quantity, 1))) !!}</td>
                @if($tax['registered'])
                    <td class="item" width="{{ $colVat }}%" align="center">{{ $tax['is_export'] ? '0%' : $tax['rate_label'] }}</td>
                @endif
                <td class="item" width="{{ $colAmount }}%" align="right">{!! $money($item->total_price) !!}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<br>

<table cellpadding="4" cellspacing="0" width="100%" nobr="true">
    <tr>
        <td class="label" width="75%">Subtotal:</td>
        <td class="value" width="25%">{!! $money($order->subtotal) !!}</td>
    </tr>
    @if($order->discount > 0)
        <tr class="discount">
            <td class="label" width="75%">Discount:</td>
            <td class="value" width="25%">-{!! $money($order->discount) !!}</td>
        </tr>
    @endif
    <tr>
        <td class="label" width="75%">Shipping:</td>
        <td class="value" width="25%">{!! $order->shipping_cost > 0 ? $money($order->shipping_cost) : 'Free' !!}</td>
    </tr>
    @if($order->gift_fee > 0)
        <tr>
            <td class="label" width="75%">Gift Message Card:</td>
            <td class="value" width="25%">{!! $money($order->gift_fee) !!}</td>
        </tr>
    @endif
    @if($tax['registered'])
        {{-- Prices charged are VAT-inclusive, so the net and the VAT are
             extracted from the total rather than added to it. Article 59
             requires the tax amount and the gross payable, both in the store currency. --}}
        <tr class="net">
            <td class="label" width="75%">Total excluding VAT:</td>
            <td class="value" width="25%">{!! $money($tax['net']) !!}</td>
        </tr>
        <tr>
            <td class="label" width="75%">VAT {{ $tax['rate_label'] }}:</td>
            <td class="value" width="25%">{!! $money($tax['vat']) !!}</td>
        </tr>
    @endif
    <tr class="grand">
        <td class="label" width="75%">Total{{ $tax['registered'] ? ' including VAT' : '' }}:</td>
        <td class="value" width="25%">{!! $money($order->total) !!}</td>
    </tr>
</table>

@if($tax['registered'] && $tax['is_export'])
    <br>
    <table cellpadding="6" cellspacing="0" width="100%">
        <tr>
            <td class="tax-note">Zero-rated export of goods &mdash; supply delivered to {!! $ar($order->shipping_country) !!}, outside {{ $homeCountry }}.</td>
        </tr>
    </table>
@endif

@if(filled($order->gift_message))
    <br>
    <table cellpadding="7" cellspacing="0" width="100%" nobr="true">
        <tr>
            <td class="gift"><b>Gift Message:</b><br>{{-- Line by line, so only the Arabic lines switch font and direction. --}}{!! collect(preg_split("/\R/u", (string) $order->gift_message))->map($ar)->implode("<br>") !!}</td>
        </tr>
    </table>
@endif

<br><br>
<table cellpadding="5" cellspacing="0" width="100%" nobr="true">
    <tr>
        <td class="footer-note">Thank you for your purchase from {!! $ar($legalName) !!}.
            @if(\App\Support\Brand::domain())<br>{{ \App\Support\Brand::domain() }}@endif
        </td>
    </tr>
</table>
