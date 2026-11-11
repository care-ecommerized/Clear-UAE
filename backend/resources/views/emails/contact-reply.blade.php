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
        .greeting { font-size: 15px; margin-bottom: 16px; }
        .reply-body { font-size: 15px; line-height: 1.6; white-space: pre-wrap; margin-bottom: 24px; }
        .signature { font-size: 14px; color: #555; padding-top: 16px; border-top: 1px solid #f0ebe5; margin-top: 24px; }
        .original { background: #f8f5f0; padding: 14px 16px; border-left: 3px solid {{ $brandColor }}; border-radius: 4px; margin-top: 24px; font-size: 13px; color: #666; }
        .original .label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 6px; }
        .original .body { white-space: pre-wrap; }
        .footer { padding: 18px 30px; background: #f8f5f0; font-size: 12px; color: #888; text-align: center; }
        .footer a { color: {{ $brandColor }}; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ \App\Support\Brand::name() }}</h1>
        </div>
        <div class="content">
            <p class="greeting">Hi {{ $contactMessage->name }},</p>
            <div class="reply-body">{{ $replyBody }}</div>
            <div class="signature">
                Best regards,<br>
                {{ \App\Support\Brand::name() }} Team<br>
                <a href="{{ \App\Support\Brand::url() }}">{{ \App\Support\Brand::domain() }}</a>
            </div>
            <div class="original">
                <div class="label">Your original message ({{ $contactMessage->created_at->copy()->setTimezone(config('app.display_timezone'))->format('M j, Y g:i A') }})</div>
                <div class="body">{{ $contactMessage->message }}</div>
            </div>
        </div>
        <div class="footer">
            <a href="{{ \App\Support\Brand::url() }}">{{ \App\Support\Brand::domain() }}</a>@if (\App\Support\Brand::address() !== '') · {{ \App\Support\Brand::address() }}@endif
        </div>
    </div>
</body>
</html>
