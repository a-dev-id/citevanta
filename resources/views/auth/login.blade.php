@extends('layouts.app', ['title' => 'Sign in'])

@section('content')
    <div class="text-center mb-4"><a href="{{ route('login') }}" class="navbar-brand navbar-brand-autodark"><x-brand :wordmark="true" /></a></div>
    <p class="text-secondary text-center mb-2">{{ config('branding.description') }}</p>
    <p class="text-center mb-4">{{ config('branding.tagline') }}</p>
    <div class="card card-md">
        <div class="card-body">
            <h1 class="h2 text-center mb-4">Sign in</h1>
            <p class="text-secondary text-center mb-4">Sign in to monitor your AI search visibility.</p>
            <form method="post" action="{{ route('login.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="email">Email address</label>
                    <input id="email" class="form-control" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input id="password" class="form-control" name="password" type="password" required autocomplete="current-password">
                </div>
                <div class="form-footer"><button class="btn btn-primary w-100" type="submit"><x-icon name="login" /> Sign in</button></div>
            </form>
        </div>
    </div>
@endsection
