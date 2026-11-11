{{--
  ============================================================
  ORDER CONFIRMATION — Thank You For Your Order
  ============================================================
  What this email does:
    Shows the customer a summary of their new order including
    the order number, list of items purchased, prices, totals,
    shipping address, and payment method.

  Sent when: A customer successfully places a new order
  ============================================================
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php $brandColor = \App\Support\Brand::color(); @endphp
    @include('emails._dark-mode')
    <style>
        body { font-family: Arial, sans-serif; background: #f5f0e8; margin: 0; padding: 20px; color: {{ $brandColor }}; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; }
        .header { background: {{ $brandColor }}{!! strtolower($brandColor) === '#015056' ? " url('".e(\App\Support\Brand::url())."/email/teal.png') repeat" : '' !!}; color: #ffffff; padding: 30px; text-align: center; }
        .header img { max-width: 200px; height: auto; }
        .header h1 { margin: 0; font-size: 24px; letter-spacing: 2px; }
        .content { padding: 30px; }
        .order-number { background: #f5f0e8; padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 25px; }
        .order-number span { font-size: 20px; font-weight: bold; color: {{ $brandColor }}; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #f5f0e8; padding: 10px; text-align: left; font-size: 13px; color: #666; }
        td { padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; }
        .totals { margin-top: 20px; }
        .totals td { border: none; padding: 5px 10px; }
        .totals .total { font-size: 18px; font-weight: bold; color: {{ $brandColor }}; }
        .shipping-info { background: #f5f0e8; padding: 15px; border-radius: 6px; margin-top: 20px; }
        .shipping-info h3 { margin: 0 0 10px; font-size: 14px; color: #666; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        .badge-paid { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ \App\Support\Brand::logoUrl(true) }}" alt="{{ \App\Support\Brand::name() }}" style="max-width:200px;height:auto;">
        </div>
        <div class="content">
            <h2 style="margin-top:0;">Thank you for your order!</h2>
            <p>Dear {{ $order->customer_name }},</p>
            <p>We've received your order and it's being processed. Here's a summary:</p>

            <div class="order-number">
                <div style="font-size:12px;color:#666;">Order Number</div>
                <span>{{ $order->order_number }}</span>
                <div style="margin-top:8px;">
                    <span class="badge badge-paid">{{ $order->payment_method === 'cod' ? 'Cash on Delivery' : ucfirst($order->payment_method) }}</span>
                </div>
                @if($order->payment_method === 'cod')
                <div style="margin-top:8px;font-size:13px;color:#666;">Please have {{ number_format((float) $order->total, 2) }} AED ready in cash when your order arrives.</div>
                @endif
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                    <tr>
                        <td valign="middle">
                            @if(!empty($item->product_image))
                            <img src="{{ \App\Support\EmailImage::url($item->product_image) }}" alt="" width="48" style="display:inline-block;width:48px;height:auto;border-radius:4px;border:1px solid #e5e5e5;vertical-align:middle;margin-right:10px;">
                            @endif
                            <span style="vertical-align:middle;">{{ $item->product_name }}</span>
                        </td>
                        <td valign="middle" style="text-align:center;">{{ $item->quantity }}</td>
                        <td valign="middle" style="text-align:right;">{{ \App\Support\Brand::currency() }} {{ number_format($item->total_price, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="totals">
                <tr>
                    <td style="text-align:right;color:#666;">Subtotal:</td>
                    <td style="text-align:right;width:120px;">{{ \App\Support\Brand::currency() }} {{ number_format($order->subtotal, 2) }}</td>
                </tr>
                @if($order->discount > 0)
                <tr>
                    <td style="text-align:right;color:#16a34a;">Discount:</td>
                    <td style="text-align:right;color:#16a34a;">-{{ \App\Support\Brand::currency() }} {{ number_format($order->discount, 2) }}</td>
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
                    <td style="text-align:right;color:#666;">{{ $shipLabel }}:</td>
                    <td style="text-align:right;">{{ $order->shipping_cost > 0 ? \App\Support\Brand::currency() . ' ' . number_format($order->shipping_cost, 2) : 'Free' }}</td>
                </tr>
                @if($order->gift_fee > 0)
                <tr>
                    <td style="text-align:right;color:#666;">Gift Message Card:</td>
                    <td style="text-align:right;">{{ \App\Support\Brand::currency() }} {{ number_format($order->gift_fee, 2) }}</td>
                </tr>
                @endif
                <tr class="total">
                    <td style="text-align:right;font-weight:bold;font-size:16px;">Total:</td>
                    <td style="text-align:right;font-weight:bold;font-size:16px;">{{ \App\Support\Brand::currency() }} {{ number_format($order->total, 2) }}</td>
                </tr>
            </table>

            <div class="shipping-info">
                <h3>Shipping Address</h3>
                {{-- Region and postal field were dropped here, so the customer
                     could not check back what they entered. For Saudi orders the
                     postal field is the National Address Code the courier needs. --}}
                @php
                    $isSaudi = (bool) preg_match('/saudi|\bksa\b|^\s*sa\s*$/i', (string) $order->shipping_country);
                    $locality = collect([$order->shipping_city, $order->shipping_state])->filter()->implode(', ');
                @endphp
                <p style="margin:0;">
                    {{ $order->customer_name }}<br>
                    {{ $order->shipping_address }}<br>
                    @if($locality){{ $locality }}<br>@endif
                    {{ $order->shipping_country }}<br>
                    @if($order->shipping_zip)
                        {{ $isSaudi ? 'National Address Code' : 'Postal Code' }}: {{ $order->shipping_zip }}<br>
                    @endif
                    {{ $order->customer_phone }}
                </p>
            </div>

            @if($order->gift_fee > 0 || filled($order->gift_message))
            <div class="shipping-info">
                <h3>Gift Message Card</h3>
                <p style="margin:0;white-space:pre-line;" dir="auto">{{ filled($order->gift_message) ? $order->gift_message : 'A gift message card was added to this order.' }}</p>
            </div>
            @endif

            <p style="margin-top:25px;font-size:14px;color:#666;">
                If you have any questions about your order, please reply to this email
                @if (\App\Support\Brand::email() !== '')
                or contact us at
                <a href="mailto:{{ \App\Support\Brand::email() }}" style="color:{{ $brandColor }};">{{ \App\Support\Brand::email() }}</a>
                @endif
            </p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ \App\Support\Brand::name() }}. All rights reserved.</p>
            <p>{{ \App\Support\Brand::legalName() }}@if (\App\Support\Brand::address() !== '') &middot; {{ \App\Support\Brand::address() }}@endif</p>
            <p>{{ \App\Support\Brand::domain() }}</p>
            @if (\App\Support\Brand::get('invoice_tax_no') !== '')
            <p>TRN: {{ \App\Support\Brand::get('invoice_tax_no') }}</p>
            @endif
        </div>
    </div>
</body>
</html>
