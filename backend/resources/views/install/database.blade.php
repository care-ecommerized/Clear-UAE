@extends('install.layout')

@section('title', 'Database')

@section('content')
    <h2>Database</h2>
    <p class="lead">Create a database and a user for it in cPanel → "MySQL Databases", then enter them here.</p>

    @if (session('locked'))
        <div class="alert error">
            An installation was already started from another browser. Continue it there, or delete
            <code>backend/storage/framework/installer.json</code> in your hosting file manager to start over.
        </div>
    @endif

    @php $useSqlite = old('driver') === 'sqlite' || ! $mysqlAvailable; @endphp

    <form method="POST" action="{{ url('/install/database') }}">
        @csrf

        @if ($requireInstallKey)
            <label for="install_key">Install key</label>
            <input id="install_key" type="text" name="install_key" value="" autocomplete="off" required>
            <p class="hint">Proves you own this hosting account. Open <code>{{ $keyFile }}</code> in your hosting File Manager and copy the code on its second line.</p>
        @endif

        <label for="site_url">Store address</label>
        <input id="site_url" type="url" name="site_url" value="{{ old('site_url', $siteUrl) }}" placeholder="https://yourstore.com">
        <p class="hint">The address customers will use. Use <code>https://</code> if your hosting has SSL (almost all do).</p>

        <label for="driver">Database type</label>
        <select id="driver" name="driver" onchange="document.getElementById('mysql-fields').style.display = this.value === 'mysql' ? '' : 'none'">
            @if ($mysqlAvailable)
                <option value="mysql" @selected(! $useSqlite)>MySQL / MariaDB (recommended)</option>
            @endif
            @if ($sqliteAvailable)
                <option value="sqlite" @selected($useSqlite)>SQLite (quick trial, no setup)</option>
            @endif
        </select>
        @if ($sqliteAvailable)
            <p class="hint">SQLite keeps everything in one file, <code>{{ $sqlitePath }}</code>. Good for trying the store out; use MySQL for a live shop.</p>
        @endif

        <div id="mysql-fields" @if ($useSqlite) style="display:none" @endif>
            <div class="row">
                <div>
                    <label for="host">Host</label>
                    <input id="host" type="text" name="host" value="{{ old('host', 'localhost') }}">
                </div>
                <div style="flex:0 1 120px">
                    <label for="port">Port</label>
                    <input id="port" type="number" name="port" value="{{ old('port', '3306') }}">
                </div>
            </div>
            <label for="database">Database name</label>
            <input id="database" type="text" name="database" value="{{ old('database') }}" autocomplete="off">
            <p class="hint">On cPanel this usually starts with your account name, e.g. <code>myaccount_shop</code>.</p>
            <div class="row">
                <div>
                    <label for="username">Username</label>
                    <input id="username" type="text" name="username" value="{{ old('username') }}" autocomplete="off">
                </div>
                <div>
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" autocomplete="new-password">
                </div>
            </div>
        </div>

        <button class="btn" type="submit" @disabled(session('locked'))>Test connection &amp; continue</button>
    </form>
@endsection
