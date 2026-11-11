{{--
  ============================================================
  ABANDONED CHECKOUT RECOVERY — "Come back and finish"
  ============================================================
  Sent to customers who started a checkout but never paid.
  CTA links to /recover-checkout/{reference} on the storefront,
  which re-hydrates the cart and forwards them to /checkout.
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
        <h2>You left something behind</h2>
        <p>Hi{{ $checkout->customer_name ? ' '.$checkout->customer_name : '' }},</p>
        <p>You started checking out but didn't quite finish. Your selection is still here — pick up right where you left off:</p>

        <p style="text-align:center;">
            <a href="{{ $recoveryUrl }}" class="cta">Complete my order</a>
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
                        {{-- Inline thumbnail so the cart contents are visually recognisable
                             without clicking through. Fallback gray box keeps layout stable. --}}
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
            Stock is limited and these pieces are typically one-of-a-kind. If you have any questions just reply to this email — we read every one.
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
