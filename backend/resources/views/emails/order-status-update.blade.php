{{--
  ============================================================
  ORDER STATUS UPDATE — Your Order Status Has Changed
  ============================================================
  What this email does:
    Notifies the customer that their order status changed
    (e.g., processing, shipped, delivered, cancelled).
    Shows the new status, tracking number (if shipped),
    and order item summary.

  Sent when: An admin updates the order status
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
        .status-box { text-align: center; padding: 20px; margin: 20px 0; border-radius: 8px; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-confirmed { background: #fef9c3; color: #854d0e; }
        .status-processing { background: #dbeafe; color: #1e40af; }
        .status-shipped { background: #e0e7ff; color: #3730a3; }
        .status-delivered { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .status-label { font-size: 14px; color: #666; margin-bottom: 5px; }
        .status-value { font-size: 22px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #f5f0e8; padding: 10px; text-align: left; font-size: 13px; color: #666; }
        td { padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ \App\Support\Brand::logoUrl(true) }}" alt="{{ \App\Support\Brand::name() }}" style="max-width:200px;height:auto;">
        </div>
        <div class="content">
            <h2 style="margin-top:0;">Order Status Update</h2>
            <p>Dear {{ $order->customer_name }},</p>

            <div class="order-number">
                <div style="font-size:12px;color:#666;">Order Number</div>
                <span>{{ $order->order_number }}</span>
            </div>

            <div class="status-box status-{{ $newStatus }}">
                <div class="status-label">Your order status has been updated to</div>
                <div class="status-value">{{ ucfirst($newStatus) }}</div>
            </div>

            @if($newStatus === 'shipped' && $order->tracking_number)
            <div style="background:#f5f0e8;padding:20px;border-radius:6px;margin:20px 0;text-align:center;">
                <div style="font-size:12px;color:#666;margin-bottom:5px;">{{ $order->shipping_provider === 'aramex' ? 'Aramex AWB (tracking number)' : 'Tracking Number' }}</div>
                <div style="font-size:18px;font-weight:bold;margin-bottom:15px;">{{ $order->tracking_number }}</div>
                <a href="{{ \App\Support\Brand::url() }}/track-order?order={{ $order->order_number }}"
                   style="display:inline-block;background:{{ $brandColor }};color:#ffffff;padding:12px 28px;text-decoration:none;border-radius:5px;font-weight:bold;font-size:14px;">
                    Track Your Order
                </a>
                @if($order->shipping_provider)
                <div style="font-size:11px;color:#999;margin-top:10px;">
                    Shipped via {{ ucfirst($order->shipping_provider) }}
                </div>
                @endif
            </div>
            @endif

            @if($newStatus === 'confirmed')
            <p>Thanks — we've received your payment. Your order will be prepared by our team shortly. We'll email you again once it moves to processing.</p>
            @elseif($newStatus === 'processing')
            <p>We're preparing your order for shipment. You'll receive another email once it's on its way!</p>
            @elseif($newStatus === 'shipped')
            <p>Great news! Your order has been shipped and is on its way to you.</p>
            @elseif($newStatus === 'delivered')
            <p>Your order has been delivered. We hope you enjoy your purchase!</p>
            @elseif($newStatus === 'cancelled')
            <p>Your order has been cancelled. If you have any questions, please don't hesitate to contact us.</p>
            @endif

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

            <p style="text-align:right;font-weight:bold;font-size:16px;">
                Total: {{ \App\Support\Brand::currency() }} {{ number_format($order->total, 2) }}
            </p>

            @if (\App\Support\Brand::email() !== '')
            <p style="margin-top:25px;font-size:14px;color:#666;">
                If you have any questions, please contact us at
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
