@extends('install.layout')

@section('title', 'Install')

@section('content')
    <h2>Install the store</h2>
    <p class="lead">Creates the database tables and the default settings. This can take a minute, so keep this tab open.</p>

    @isset($failure)
        <div class="alert error">Installation failed: {{ $failure }}</div>
        @if (! empty($output))
            <pre>{{ $output }}</pre>
        @endif
    @endisset

    @if ($migrated && ! isset($failure))
        <div class="alert ok">The database tables already exist. Running this again only adds anything missing.</div>
    @endif

    <form method="POST" action="{{ url('/install/migrate') }}" onsubmit="var b = this.querySelector('button'); b.disabled = true; b.textContent = 'Installing…';">
        @csrf

        @if ($demoAvailable)
            <label class="check">
                <input type="checkbox" name="demo" value="1" @checked(old('demo'))>
                <span>Install demo data <span class="muted">(sample products, categories and brands so you can see the store working; you can delete them later)</span></span>
            </label>
        @endif

        <button class="btn" type="submit">Install</button>
    </form>
@endsection
