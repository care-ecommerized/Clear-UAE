@extends('install.layout')

@section('title', 'Requirements')

@section('content')
    <h2>Server requirements</h2>
    <p class="lead">Ecomweb checks that your hosting can run the store.</p>

    <table>
        @foreach ($checks as $check)
            <tr>
                <td>{{ $check['label'] }}</td>
                <td class="status {{ $check['ok'] ? 'ok' : 'bad' }}">{{ $check['ok'] ? '✓' : '✗' }} {{ $check['value'] }}</td>
            </tr>
        @endforeach
    </table>

    @if ($passes)
        <a class="btn" href="{{ url('/install/database') }}">Continue</a>
    @else
        <div class="alert error" style="margin-top:18px">
            Fix the items marked ✗ — PHP version and extensions are set in cPanel → "Select PHP Version";
            folder permissions in the File Manager (755, or 775 if your host needs it) — then reload this page.
        </div>
        <a class="btn secondary" href="{{ url('/install/requirements') }}">Check again</a>
    @endif
@endsection
