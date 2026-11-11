{{--
  ============================================================
  RESET PASSWORD — "Choose a new password"
  ============================================================
  CTA links to the storefront /reset-password page, carrying
  the reset token + email in the query string. The page posts
  them back to /api/auth/reset-password to set the new password.
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
        <h2>Reset your password</h2>
        <p>Hi{{ $customerName ? ' '.$customerName : '' }},</p>
        <p>We received a request to reset the password on your {{ \App\Support\Brand::name() }} account. Click the button below to choose a new one:</p>

        <p style="text-align:center;">
            <a href="{{ $resetUrl }}" class="cta">Reset my password</a>
        </p>

        <p class="muted">This link expires in 60 minutes. If the button doesn't work, copy and paste this address into your browser:</p>
        <p class="link">{{ $resetUrl }}</p>

        <p class="muted" style="margin-top:24px;">If you didn't request a password reset, you can safely ignore this email — your password won't change.</p>
    </div>
    <div class="footer">
        {{ \App\Support\Brand::legalName() }}@if (\App\Support\Brand::address() !== '') · {{ \App\Support\Brand::address() }}@endif<br>
        <a href="{{ \App\Support\Brand::url() }}" style="color:#999;">{{ \App\Support\Brand::domain() }}</a>
    </div>
</div>
</body>
</html>
