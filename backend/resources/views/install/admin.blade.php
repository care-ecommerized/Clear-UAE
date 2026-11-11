@extends('install.layout')

@section('title', 'Admin account')

@section('content')
    <h2>Admin account</h2>
    <p class="lead">You will use this to sign in to the admin panel of your store.</p>

    <form method="POST" action="{{ url('/install/admin') }}">
        @csrf

        <label for="name">Your name</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" required>

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required>

        <div class="row">
            <div>
                <label for="password">Password</label>
                <input id="password" type="password" name="password" minlength="8" required autocomplete="new-password">
                <p class="hint">At least 8 characters.</p>
            </div>
            <div>
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" minlength="8" required autocomplete="new-password">
            </div>
        </div>

        <button class="btn" type="submit">Create admin</button>
    </form>
@endsection
