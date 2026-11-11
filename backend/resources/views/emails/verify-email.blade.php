{{--
  ============================================================
  VERIFY EMAIL — "Confirm your address"
  ============================================================
  CTA links to the signed backend verification route, which
  marks the address verified and redirects back to the store.
  ============================================================
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('emails._dark-mode')
    @php $brandColor = \App\Support\Brand::color(); @endphp
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
        .muted { color: #666; font-size: 13px; }
        .link { word-break: break-all; color: {{ $brandColor }}; font-size: 12px; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="{{ \App\Support\Brand::logoUrl(true) }}" alt="{{ \App\Support\Brand::name() }}">
    </div>
    <div class="content">
        <h2>Confirm your email</h2>
        <p>Hi{{ $customerName ? ' '.$customerName : '' }},</p>
        <p>Thanks for creating an account with {{ \App\Support\Brand::name() }}. Please confirm this is your email address by clicking the button below:</p>

        <p style="text-align:center;">
            <a href="{{ $verifyUrl }}" class="cta">Verify my email</a>
        </p>

        <p class="muted">This link expires in 48 hours. If the button doesn't work, copy and paste this address into your browser:</p>
        <p class="link">{{ $verifyUrl }}</p>

        <p class="muted" style="margin-top:24px;">If you didn't create this account, you can safely ignore this email.</p>
    </div>
    <div class="footer">
        {{ \App\Support\Brand::legalName() }}@if (\App\Support\Brand::address() !== '') · {{ \App\Support\Brand::address() }}@endif<br>
        <a href="{{ \App\Support\Brand::url() }}" style="color:#999;">{{ \App\Support\Brand::domain() }}</a>
    </div>
</div>
</body>
</html>
