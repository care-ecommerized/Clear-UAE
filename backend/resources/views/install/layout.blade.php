<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Install') — Ecomweb Installer</title>
    <style>
        :root {
            --brand: #015056; --brand-dark: #013a3e; --ivory: #fffff0; --ink: #1d2327;
            --muted: #646970; --line: #dcdcde; --ok: #1a7f37; --bad: #b32d2e; --bg: #f4f2ea;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        header { background: var(--brand); color: var(--ivory); padding: 18px 16px; text-align: center; }
        header h1 { margin: 0; font-size: 20px; font-weight: 600; letter-spacing: .04em; }
        header p { margin: 2px 0 0; font-size: 13px; opacity: .8; }
        main { max-width: 680px; margin: 24px auto 48px; padding: 0 16px; }
        ol.steps { list-style: none; display: flex; flex-wrap: wrap; gap: 6px; padding: 0; margin: 0 0 18px; }
        ol.steps li { flex: 1 1 90px; font-size: 12px; text-align: center; padding: 6px 4px; border-radius: 4px; background: #e6e3d8; color: var(--muted); }
        ol.steps li.done { background: #d6e7e4; color: var(--brand-dark); }
        ol.steps li.current { background: var(--brand); color: var(--ivory); font-weight: 600; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 24px; }
        .card h2 { margin: 0 0 6px; font-size: 20px; }
        .card > p.lead { margin: 0 0 18px; color: var(--muted); }
        label { display: block; font-weight: 600; margin: 14px 0 4px; font-size: 14px; }
        input[type=text], input[type=url], input[type=email], input[type=password], input[type=number], select {
            width: 100%; padding: 9px 11px; border: 1px solid #8c8f94; border-radius: 4px; font: inherit; background: #fff;
        }
        input:focus, select:focus { outline: 2px solid var(--brand); outline-offset: 1px; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        .row > div { flex: 1 1 200px; }
        .hint { font-size: 13px; color: var(--muted); margin: 4px 0 0; }
        .btn { display: inline-block; margin-top: 22px; background: var(--brand); color: var(--ivory); border: 0; border-radius: 4px; padding: 11px 22px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: var(--brand-dark); }
        .btn.secondary { background: #fff; color: var(--brand); border: 1px solid var(--brand); }
        .btn[disabled] { opacity: .5; cursor: not-allowed; }
        .alert { border-radius: 4px; padding: 10px 14px; margin: 0 0 16px; font-size: 14px; }
        .alert.error { background: #fcf0f1; border: 1px solid #f0b8b8; color: var(--bad); }
        .alert.ok { background: #edfaef; border: 1px solid #b8e6bf; color: var(--ok); }
        .alert ul { margin: 0; padding-left: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td { padding: 8px 6px; border-bottom: 1px solid #f0f0f1; }
        td.status { text-align: right; white-space: nowrap; font-weight: 600; }
        td.status.ok { color: var(--ok); }
        td.status.bad { color: var(--bad); }
        .check { display: flex; gap: 8px; align-items: flex-start; margin-top: 16px; font-weight: 400; }
        .check input { margin-top: 4px; }
        pre, code { font-family: Consolas, Menlo, monospace; font-size: 13px; }
        pre { background: #1d2327; color: #f0f0f1; padding: 12px; border-radius: 4px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
        .muted { color: var(--muted); }
        footer { text-align: center; font-size: 12px; color: var(--muted); margin-top: 18px; }
    </style>
</head>
<body>
<header>
    <h1>Ecomweb Installer</h1>
    <p>Set up your store in a few steps</p>
</header>
<main>
    @php $keys = array_keys($steps); $currentIndex = array_search($current, $keys, true); @endphp
    <ol class="steps">
        @foreach ($steps as $key => $label)
            @php $i = array_search($key, $keys, true); @endphp
            <li class="{{ $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'current' : '') }}">{{ $i + 1 }}. {{ $label }}</li>
        @endforeach
    </ol>

    <div class="card">
        @if ($errors->any())
            <div class="alert error">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </div>
    <footer>Ecomweb {{ \App\Http\Middleware\EnsureInstalled::VERSION }}</footer>
</main>
</body>
</html>
