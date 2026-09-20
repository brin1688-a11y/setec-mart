@extends('layouts.app')

@section('title', 'Forgotten password')

@section('content')

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="bg-white shadow-sm rounded-4 p-5">

                <div class="text-center mb-4">
                    @if($brandLogo)
                        <img src="{{ asset($brandLogo) }}" alt="{{ config('app.name') }}"
                             style="width: 100%; max-width: 190px; height: auto;">
                    @endif
                    <h3 class="fw-bold mt-2">Forgotten your password?</h3>
                    <p class="text-muted mb-0">
                        Give us the address you signed up with and we will send a link
                        to choose a new one.
                    </p>
                </div>

                {{-- The same words whether or not the address is registered:
                     a different answer would tell a stranger who shops here. --}}
                @if (session('status'))
                    <div class="alert alert-success">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Email address</label>
                        <input type="email" name="email" id="email" class="form-control form-control-lg"
                               value="{{ old('email') }}" required autofocus>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill">
                        Send the link
                    </button>
                </form>

                <p class="text-center text-muted mt-4 mb-0">
                    <a href="{{ route('login') }}" class="text-decoration-none">Back to sign in</a>
                </p>

            </div>
        </div>
    </div>
</div>

@endsection
