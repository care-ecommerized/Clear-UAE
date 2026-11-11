@extends('install.layout')

@section('title', 'Store basics')

@section('content')
    <h2>Store basics</h2>
    <p class="lead">You can change these, the logo, the colours and everything else later in Admin → Settings.</p>

    <form method="POST" action="{{ url('/install/store') }}">
        @csrf

        <label for="store_name">Store name</label>
        <input id="store_name" type="text" name="store_name" value="{{ old('store_name', $defaults['store_name']) }}" required>

        <label for="store_email">Store email</label>
        <input id="store_email" type="email" name="store_email" value="{{ old('store_email', $defaults['store_email']) }}" required>
        <p class="hint">Shown to customers and used for order notifications.</p>

        <label for="default_currency">Currency</label>
        <select id="default_currency" name="default_currency">
            @foreach ($currencies as $code)
                <option value="{{ $code }}" @selected(old('default_currency', $defaults['default_currency']) === $code)>{{ $code }}</option>
            @endforeach
        </select>

        <button class="btn" type="submit">Finish installation</button>
    </form>
@endsection
