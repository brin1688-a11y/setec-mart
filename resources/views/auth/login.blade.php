@extends('layouts.app')

@section('content')

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-md-6 col-lg-5">

            <div class="bg-white shadow-sm rounded-4 p-5">

                <div class="text-center mb-4">
                    {{-- Room for the full lockup here, name and all. --}}
                    @if($brandLogo)
                        <img src="{{ asset($brandLogo) }}" alt="{{ config('app.name') }}"
                             style="width: 100%; max-width: 190px; height: auto;">
                    @else
                        <span style="font-size: 50px;">&#129382;</span>
                    @endif
                    <h3 class="fw-bold mt-2">@lang('site.auth.welcome_back')</h3>
                    <p class="text-muted">@lang('site.auth.login_subtitle')</p>
                </div>

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label fw-semibold">@lang('site.auth.email')</label>
                        <input
                            type="email"
                            name="email"
                            class="form-control form-control-lg"
                            value="{{ old('email') }}"
                            required
                            autofocus
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">@lang('site.auth.password')</label>
                        <input
                            type="password"
                            name="password"
                            class="form-control form-control-lg"
                            required
                        >
                    </div>

                    <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
                        <div class="form-check mb-0">
                            <input type="checkbox" name="remember" class="form-check-input" id="remember">
                            <label class="form-check-label" for="remember">@lang('site.auth.remember')</label>
                        </div>

                        {{-- Beside the password field, where someone realises
                             they have forgotten it. --}}
                        <a href="{{ route('password.request') }}" class="small text-decoration-none">
                            Forgotten your password?
                        </a>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill">
                        @lang('site.auth.login')
                    </button>

                </form>

                <div class="d-flex align-items-center gap-3 my-4">
                    <hr class="flex-grow-1 m-0">
                    <span class="text-muted small">@lang('site.auth.or')</span>
                    <hr class="flex-grow-1 m-0">
                </div>

                <a href="{{ route('auth.google') }}"
                   class="btn btn-outline-secondary btn-lg w-100 rounded-pill d-flex align-items-center justify-content-center gap-2">
                    <svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true">
                        <path fill="#4285F4" d="M45.1 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h11.8c-.5 2.7-2 5-4.4 6.6v5.5h7.1c4.1-3.8 6.6-9.4 6.6-16.1z"/>
                        <path fill="#34A853" d="M24 46c5.9 0 10.9-2 14.5-5.4l-7.1-5.5c-2 1.3-4.5 2.1-7.4 2.1-5.7 0-10.5-3.8-12.2-9H4.5v5.7C8.1 41.1 15.4 46 24 46z"/>
                        <path fill="#FBBC05" d="M11.8 28.2c-.4-1.3-.7-2.7-.7-4.2s.3-2.9.7-4.2v-5.7H4.5C3 17.1 2.1 20.4 2.1 24s.9 6.9 2.4 9.9l7.3-5.7z"/>
                        <path fill="#EA4335" d="M24 10.8c3.2 0 6.1 1.1 8.4 3.3l6.3-6.3C34.9 4.2 29.9 2 24 2 15.4 2 8.1 6.9 4.5 14.1l7.3 5.7c1.7-5.2 6.5-9 12.2-9z"/>
                    </svg>
                    <span>@lang('site.auth.continue_google')</span>
                </a>

                <p class="text-center mt-4 mb-0">
                    Don't have an account?
                    <a href="{{ route('register') }}" class="text-success fw-semibold text-decoration-none">
                        Register
                    </a>
                </p>

            </div>

        </div>

    </div>

</div>

@endsection