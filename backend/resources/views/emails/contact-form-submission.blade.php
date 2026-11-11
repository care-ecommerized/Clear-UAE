<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @include('emails._dark-mode')
    @php $brandColor = \App\Support\Brand::color(); @endphp
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #f8f5f0; line-height: 1.6; color: #2c2c2c; }
        .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .header { background: {{ $brandColor }}{!! strtolower($brandColor) === '#015056' ? " url('".e(\App\Support\Brand::url())."/email/teal.png') repeat" : '' !!}; padding: 30px; text-align: center; color: #fff; }
        .header h1 { font-size: 20px; font-weight: 600; letter-spacing: 0.5px; }
        .content { padding: 30px; }
        .row { margin-bottom: 18px; padding-bottom: 14px; border-bottom: 1px solid #f0ebe5; }
        .row:last-child { border-bottom: 0; }
        .label { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .value { font-size: 15px; color: #2c2c2c; word-break: break-word; }
        .value a { color: {{ $brandColor }}; text-decoration: none; }
        .message { background: #f8f5f0; padding: 16px; border-radius: 8px; white-space: pre-wrap; font-size: 14px; line-height: 1.55; }
        .row.product { background: #f8f5f0; padding: 14px 16px; border-radius: 8px; border-bottom: 0; }
        .sku { font-size: 12px; color: #888; }
        .footer { padding: 18px 30px; background: #f8f5f0; font-size: 12px; color: #888; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $contactMessage->product_id ? 'New Product Inquiry' : 'New Contact Form Submission' }}</h1>
        </div>
        <div class="content">
            @if ($contactMessage->product_id)
            {{-- Product first: it is the whole point of the enquiry, and it
                 saves the team searching for which watch was being asked about. --}}
            <div class="row product">
                <div class="label">Product</div>
                <div class="value">
                    @if ($contactMessage->product_url)
                        <a href="{{ $contactMessage->product_url }}">{{ $contactMessage->product_name ?: 'View product' }}</a>
                    @else
                        {{ $contactMessage->product_name ?: '—' }}
                    @endif
                    @if ($contactMessage->product_sku)
                        <br><span class="sku">SKU {{ $contactMessage->product_sku }}</span>
                    @endif
                </div>
            </div>
            @endif
            <div class="row">
                <div class="label">From</div>
                <div class="value">{{ $contactMessage->name }}</div>
            </div>
            <div class="row">
                <div class="label">Email</div>
                <div class="value"><a href="mailto:{{ $contactMessage->email }}">{{ $contactMessage->email }}</a></div>
            </div>
            @if ($contactMessage->phone)
            <div class="row">
                <div class="label">Phone</div>
                <div class="value"><a href="tel:{{ $contactMessage->phone }}">{{ $contactMessage->phone }}</a></div>
            </div>
            @endif
            <div class="row">
                <div class="label">Subject</div>
                <div class="value">{{ $contactMessage->subject }}</div>
            </div>
            <div class="row">
                <div class="label">Message</div>
                <div class="message">{{ $contactMessage->message }}</div>
            </div>
        </div>
        <div class="footer">
            Submitted {{ $contactMessage->created_at->copy()->setTimezone(config('app.display_timezone'))->format('M j, Y g:i A T') }} · Reply directly to this email to respond to {{ $contactMessage->name }}.
        </div>
    </div>
</body>
</html>
