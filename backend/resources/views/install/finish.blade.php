@extends('install.layout')

@section('title', 'Finished')

@section('content')
    <h2>{{ $storeName }} is installed</h2>
    <p class="lead">The installer is now switched off and this page will not open again, so copy the cron line below first.</p>

    <div class="alert ok">Installation complete.</div>

    <h3 style="margin:18px 0 6px;font-size:16px">One last step: the cron job</h3>
    <p class="muted" style="margin:0 0 8px">
        Order emails, abandoned-checkout cleanup and delivery tracking run in the background.
        In cPanel → "Cron Jobs", add this command to run <strong>once per minute</strong>:
    </p>
    <pre>{{ $cron }}</pre>
    <p class="muted" style="margin:8px 0 0">
        Use the same PHP version as your website (PHP {{ PHP_MAJOR_VERSION }}.{{ PHP_MINOR_VERSION }}). Plain <code>php</code> in cron uses the server
        default, which may be older. Errors are written to <code>backend/storage/logs/cron.log</code>.
    </p>

    <h3 style="margin:18px 0 6px;font-size:16px">Next</h3>
    <ul class="muted" style="margin:0;padding-left:18px">
        <li>Add your payment gateway keys in Admin → Settings → Integrations, then switch the gateway on.</li>
        <li>Set up outgoing email (SMTP). Until then, emails are only written to <code>backend/storage/logs/mail.log</code>.</li>
        <li>Upload your logo and pick your colours in Admin → Settings.</li>
    </ul>

    <a class="btn" href="{{ $adminUrl }}">Open the admin</a>
    <a class="btn secondary" href="{{ $storeUrl }}">View the store</a>
@endsection
