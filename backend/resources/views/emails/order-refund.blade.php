{{--
  ============================================================
  ORDER REFUND — Your Refund Has Been Processed
  ============================================================
  What this email does:
    Confirms that a refund has been issued to the customer.
    Shows the refund amount, original order items, and
    estimated time for the refund to appear in their account
    (5-14 business days).

  Sent when: A refund is processed for a cancelled/returned order
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
        .refund-box { text-align: center; padding: 20px; margin: 20px 0; border-radius: 8px; background: #d1fae5; color: #065f46; }
        .refund-label { font-size: 14px; margin-bottom: 5px; }
        .refund-value { font-size: 22px; font-weight: bold; }
        .refund-amount { font-size: 28px; font-weight: bold; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #f5f0e8; padding: 10px; text-align: left; font-size: 13px; color: #666; }
        td { padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; }
        .info-box { background: #f5f0e8; padding: 15px; border-radius: 6px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ \App\Support\Brand::logoUrl(true) }}" alt="{{ \App\Support\Brand::name() }}" style="max-width:200px;height:auto;">
        </div>
        <div class="content">
            <h2 style="margin-top:0;">Refund Processed</h2>
            <p>Dear {{ $order->customer_name }},</p>
            <p>We're writing to confirm that a refund has been processed for your order.</p>

            <div class="order-number">
                <div style="font-size:12px;color:#666;">Order Number</div>
                <span>{{ $order->order_number }}</span>
            </div>

            <div class="refund-box">
                <div class="refund-label">Refund Status</div>
                <div class="refund-value">Refund Processed</div>
                <div class="refund-amount">{{ \App\Support\Brand::currency() }} {{ number_format($order->total, 2) }}</div>
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

            <div class="info-box">
                <strong>Refund Details:</strong>
                <p style="margin:8px 0 0;">
                    The refund of <strong>{{ \App\Support\Brand::currency() }} {{ number_format($order->total, 2) }}</strong> has been initiated to your original payment method
                    @if($order->payment_method)
                        ({{ ucfirst($order->payment_method) }})
                    @endif.
                    Please allow 5-14 business days for the refund to appear in your account.
                </p>
            </div>

            <p style="font-size:14px;color:#666;">
                We're sorry things didn't work out this time. We hope to serve you again in the future.
            </p>

            @if (\App\Support\Brand::email() !== '')
            <p style="margin-top:25px;font-size:14px;color:#666;">
                If you have any questions about your refund, please contact us at
                <a href="mailto:{{ \App\Support\Brand::email() }}" style="color:{{ $brandColor }};">{{ \App\Support\Brand::email() }}</a>
            </p>
            @endif
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
