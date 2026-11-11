{{--
  ============================================================
  NEW ORDER ADMIN NOTIFICATION — You Have a New Order!
  ============================================================
  What this email does:
    Notifies the admin that a new order has been placed.
    Shows customer details, items ordered, payment method,
    and total amount so you can take action quickly.

  Sent when: A customer successfully places a new order
  ============================================================
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php $brandColor = \App\Support\Brand::color(); @endphp
    @include('emails._dark-mode')
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f8f5f0; line-height: 1.6; color: #2c2c2c; }
        .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: {{ $brandColor }}{!! strtolower($brandColor) === '#015056' ? " url('".e(\App\Support\Brand::url())."/email/teal.png') repeat" : '' !!}; padding: 35px 30px; text-align: center; }
        .logo { margin-bottom: 20px; }
        .logo img { max-height: 40px; width: auto; display: block; margin: 0 auto; }
        .header-title { color: #fff; font-size: 18px; font-weight: 600; letter-spacing: 1px; margin-top: 12px; }
        .content { padding: 35px 30px; }
        .section-title { font-size: 12px; font-weight: 700; color: {{ $brandColor }}; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; margin-top: 24px; padding-bottom: 8px; border-bottom: 2px solid #f0ebe5; }
        .section-title:first-child { margin-top: 0; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 8px; }
        .info-item { }
        .info-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; font-weight: 600; }
        .info-value { font-size: 15px; color: #2c2c2c; font-weight: 600; word-break: break-word; }
        .info-grid.full { grid-template-columns: 1fr; }
        .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .items-table thead { }
        .items-table th { background: #f8f5f0; padding: 12px 10px; text-align: left; font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5ddd5; }
        .items-table th:last-child { text-align: right; }
        .items-table td { padding: 14px 10px; border-bottom: 1px solid #f0ebe5; }
        .item-image { width: 60px; display: inline-block; margin-right: 12px; vertical-align: middle; }
        .item-image img { width: 100%; height: auto; border-radius: 6px; border: 1px solid #e5ddd5; }
        .item-placeholder { width: 60px; height: 60px; background: #f8f5f0; border: 1px solid #e5ddd5; border-radius: 6px; display: inline-block; margin-right: 12px; vertical-align: middle; }
        .item-name { font-size: 14px; color: #2c2c2c; font-weight: 500; display: inline-block; vertical-align: middle; }
        .item-qty { text-align: center; font-weight: 600; color: #2c2c2c; }
        .item-price { text-align: right; font-weight: 600; color: #2c2c2c; }
        .summary-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .summary-table td { padding: 10px 0; border: none; font-size: 14px; }
        .summary-label { text-align: right; color: #888; padding-right: 20px; }
        .summary-value { text-align: right; font-weight: 600; width: 120px; }
        .summary-table tr.total-row { }
        .summary-table tr.total-row td { padding-top: 12px; border-top: 2px solid #e5ddd5; font-size: 16px; font-weight: 700; color: {{ $brandColor }}; }
        .summary-table tr.discount-row .summary-label { color: #16a34a; }
        .summary-table tr.discount-row .summary-value { color: #16a34a; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-online { background: #d1fae5; color: #065f46; }
        .cta-button { display: inline-block; background: {{ $brandColor }}{!! strtolower($brandColor) === '#015056' ? " url('".e(\App\Support\Brand::url())."/email/teal.png') repeat" : '' !!}; color: #fff; padding: 12px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px; margin-top: 28px; text-align: center; }
        .cta-button:hover { opacity: 0.95; }
        .footer { background: #f8f5f0; padding: 20px 30px; text-align: center; }
        .footer-text { font-size: 12px; color: #999; }
        @media (max-width: 600px) {
            .container { margin: 10px; }
            .header { padding: 25px 20px; }
            .content { padding: 25px 20px; }
            .footer { padding: 15px 20px; }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
                <img src="{{ \App\Support\Brand::logoUrl(true) }}" alt="{{ \App\Support\Brand::name() }}" style="max-width: 120px;">
            </div>
            <div class="header-title">NEW ORDER RECEIVED</div>
        </div>


        <div class="content">
            <div class="section-title">Order Details</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Order Number</div>
                    <div class="info-value">{{ $order->order_number }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Order Date</div>
                    {{-- copy() first: setTimezone mutates the Carbon instance, and
                         $order->created_at is shared with the rest of the request. --}}
                    <div class="info-value">{{ $order->created_at->copy()->setTimezone(config('app.display_timezone'))->format('d M Y, h:i A T') }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Customer Name</div>
                    <div class="info-value">{{ $order->customer_name }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Phone</div>
                    <div class="info-value">{{ $order->customer_phone }}</div>
                </div>
                <div class="info-item full">
                    <div class="info-label">Email Address</div>
                    <div class="info-value">{{ $order->customer_email }}</div>
                </div>
                {{-- The full address, not just city + country: this email is what
                     the team fulfils from, and the street line, region and postal
                     field were all missing. For Saudi orders the postal field is
                     the National Address Code, without which the courier cannot
                     deliver. --}}
                @php
                    $isSaudi = (bool) preg_match('/saudi|\bksa\b|^\s*sa\s*$/i', (string) $order->shipping_country);
                    $postalLabel = $isSaudi ? 'National Address Code' : 'Postal Code';
                    $locality = collect([$order->shipping_city, $order->shipping_state])->filter()->implode(', ');
                @endphp
                @if($order->shipping_address || $order->shipping_city)
                <div class="info-item full">
                    <div class="info-label">Shipping Address</div>
                    <div class="info-value">
                        @if($order->shipping_address){{ $order->shipping_address }}<br>@endif
                        @if($locality){{ $locality }}<br>@endif
                        {{ $order->shipping_country }}
                    </div>
                </div>
                @endif
                @if($order->shipping_zip)
                <div class="info-item full">
                    <div class="info-label">{{ $postalLabel }}</div>
                    <div class="info-value">{{ $order->shipping_zip }}</div>
                </div>
                @endif
                <div class="info-item full">
                    <div class="info-label">Payment Method</div>
                    <div style="margin-top: 4px;">
                        <span class="badge badge-online">{{ $order->payment_method === 'cod' ? 'Cash on Delivery (not paid yet)' : ucfirst($order->payment_method ?? 'Online') }}</span>
                    </div>
                </div>
            </div>

            <div class="section-title" style="margin-top: 32px;">Order Items</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th colspan="2">Product</th>
                        <th style="text-align: center; width: 60px;">Qty</th>
                        <th style="text-align: right; width: 100px;">Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                    <tr>
                        <td width="70" style="padding: 12px 10px 12px 0;">
                            @if(!empty($item->product_image))
                                <div class="item-image">
                                    <img src="{{ \App\Support\EmailImage::url($item->product_image) }}" alt="{{ $item->product_name }}">
                                </div>
                            @else
                                <div class="item-placeholder"></div>
                            @endif
                        </td>
                        <td style="padding: 12px 10px; vertical-align: middle;">
                            <div class="item-name">{{ $item->product_name }}</div>
                        </td>
                        <td style="padding: 12px 10px;">
                            <div class="item-qty">{{ $item->quantity }}</div>
                        </td>
                        <td style="padding: 12px 10px;">
                            <div class="item-price">{{ \App\Support\Brand::currency() }} {{ number_format($item->total_price, 2) }}</div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="section-title" style="margin-top: 32px;">Order Summary</div>
            <table class="summary-table">
                @if($order->discount > 0)
                <tr>
                    <td class="summary-label">Subtotal:</td>
                    <td class="summary-value">{{ \App\Support\Brand::currency() }} {{ number_format($order->subtotal, 2) }}</td>
                </tr>
                <tr class="discount-row">
                    <td class="summary-label">Discount:</td>
                    <td class="summary-value">-{{ \App\Support\Brand::currency() }} {{ number_format($order->discount, 2) }}</td>
                </tr>
                @endif
                @php
                    // e.g. "Shipping (2-3 Days)" in the UAE, "Shipping – International Express (3-6 Days)" abroad.
                    $ship = $order->shippingSummary();
                    $shipLabel = "Shipping";
                    if ($ship) {
                        $shipLabel .= $ship["domestic"] ? "" : " – " . $ship["label"];
                        if ($ship["days"]) $shipLabel .= " (" . $ship["days"] . ")";
                    }
                @endphp
                <tr>
                    <td class="summary-label">{{ $shipLabel }}:</td>
                    <td class="summary-value">{{ $order->shipping_cost > 0 ? \App\Support\Brand::currency() . ' ' . number_format($order->shipping_cost, 2) : 'Free' }}</td>
                </tr>
                @if($order->gift_fee > 0)
                <tr>
                    <td class="summary-label">Gift Message Card:</td>
                    <td class="summary-value">{{ \App\Support\Brand::currency() }} {{ number_format($order->gift_fee, 2) }}</td>
                </tr>
                @endif
                <tr class="total-row">
                    <td class="summary-label">Total:</td>
                    <td class="summary-value">{{ \App\Support\Brand::currency() }} {{ number_format($order->total, 2) }}</td>
                </tr>
            </table>

            @if($order->gift_fee > 0 || filled($order->gift_message))
            <div class="section-title" style="margin-top: 32px;">Gift Message Card</div>
            <div class="info-value" dir="auto" style="white-space: pre-line; background:#f8f5f0; padding:16px; border-radius:8px; font-weight:400;">{{ filled($order->gift_message) ? $order->gift_message : '(card added — no message text provided)' }}</div>
            @endif

            <div style="text-align: center;">
                <a href="{{ \App\Support\Brand::url() }}/admin/orders" class="cta-button">View in Admin Panel</a>
            </div>
        </div>

        <div class="footer">
            <p class="footer-text">This is an automated notification from {{ \App\Support\Brand::name() }}. Please review and process this order promptly.</p>
        </div>
    </div>
</body>
</html>
