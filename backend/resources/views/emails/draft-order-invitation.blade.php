{{--
  ============================================================
  DRAFT ORDER INVITATION — "Your order is ready to pay"
  ============================================================
  Sent when an admin creates a draft order for a customer.
  CTA links to /pay/{reference} on the storefront, where the
  customer enters a shipping address and pays. On payment the
  draft converts into a real Order.
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
        .header { background: {{ $brandColor }}{!! strtolower($brandColor) === '#015056' ? " url('".e(\App\Support\Brand::url())."/email/teal.png') repeat" : '' !!}; color: #FFFFF0; padding: 30px; text-align: center; }
        .header img { max-width: 200px; height: auto; }
        .content { padding: 30px; }
        h2 { margin-top: 0; }
        .cta {
            display: inline-block; background: {{ $brandColor }}; color: #FFFFF0 !important;
            padding: 14px 32px; text-decoration: none; border-radius: 6px;
            font-weight: bold; letter-spacing: 0.5px; margin: 18px 0;
        }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #f5f0e8; padding: 10px; text-align: left; font-size: 13px; color: #666; }
        td { padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
        .totals { margin-top: 20px; }
        .totals td { border: none; padding: 5px 10px; }
        .totals .total { font-size: 18px; font-weight: bold; color: {{ $brandColor }}; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
        .ref { font-family: monospace; font-size: 11px; color: #888; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="{{ \App\Support\Brand::logoUrl(true) }}" alt="{{ \App\Support\Brand::name() }}">
    </div>
    <div class="content">
        <h2>Your order is ready</h2>
        <p>Hi{{ $checkout->customer_name ? ' '.$checkout->customer_name : '' }},</p>
        <p>We've prepared the following order for you. Click below to confirm your shipping details and complete your secure payment:</p>

        <p style="text-align:center;">
            <a href="{{ $payUrl }}" class="cta">Complete my payment</a>
        </p>

        <table>
            <thead>
                <tr>
                    <th colspan="2">Item</th>
                    <th style="text-align:center;">Qty</th>
                    <th style="text-align:right;">Price</th>
                </tr>
            </thead>
            <tbody>
                @foreach (($checkout->items ?? []) as $item)
                    @php $itemImage = $item['image_url'] ?? $item['product_image'] ?? null; @endphp
                    <tr>
                        <td width="70" valign="top" style="padding:8px 8px 8px 0;">
                            @if(!empty($itemImage))
                                <img src="{{ \App\Support\EmailImage::url($itemImage) }}" alt="" width="60" style="display:block;width:60px;height:auto;border-radius:4px;border:1px solid #e5e5e5;">
                            @else
                                <div style="width:60px;height:60px;background:#f3f3f3;border:1px solid #e5e5e5;border-radius:4px;"></div>
                            @endif
                        </td>
                        <td valign="middle">{{ $item['product_name'] ?? $item['name'] ?? 'Item' }}</td>
                        <td valign="middle" style="text-align:center;">{{ $item['quantity'] ?? 1 }}</td>
                        <td valign="middle" style="text-align:right;">{{ \App\Support\Brand::currency() }} {{ number_format((float)($item['price'] ?? 0), 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td>Subtotal</td>
                <td style="text-align:right;">{{ \App\Support\Brand::currency() }} {{ number_format((float)$checkout->subtotal, 2) }}</td>
            </tr>
            @if ((float)$checkout->discount > 0)
                <tr>
                    <td>Discount</td>
                    <td style="text-align:right;">– {{ \App\Support\Brand::currency() }} {{ number_format((float)$checkout->discount, 2) }}</td>
                </tr>
            @endif
            @if ((float)$checkout->shipping_cost > 0)
                <tr>
                    <td>Shipping</td>
                    <td style="text-align:right;">{{ \App\Support\Brand::currency() }} {{ number_format((float)$checkout->shipping_cost, 2) }}</td>
                </tr>
            @endif
            <tr class="total">
                <td>Total</td>
                <td style="text-align:right;">{{ \App\Support\Brand::currency() }} {{ number_format((float)$checkout->total, 2) }}</td>
            </tr>
        </table>

        <p style="margin-top:30px;color:#666;font-size:13px;">
            These pieces are typically one-of-a-kind and are held for you. If you have any questions just reply to this email — we read every one.
        </p>

        <p class="ref">Reference: {{ $checkout->reference }}</p>
    </div>
    <div class="footer">
        {{ \App\Support\Brand::legalName() }}@if (\App\Support\Brand::address() !== '') &middot; {{ \App\Support\Brand::address() }}@endif<br>
        <a href="{{ \App\Support\Brand::url() }}" style="color:#999;">{{ \App\Support\Brand::domain() }}</a>
    </div>
</div>
</body>
</html>
