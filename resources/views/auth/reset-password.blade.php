@extends('layouts.app')

@section('title', 'Choose a new password')

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
                    <h3 class="fw-bold mt-2">Choose a new password</h3>
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

                <form method="POST" action="{{ route('password.update') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Email address</label>
                        <input type="email" name="email" id="email" class="form-control form-control-lg"
                               value="{{ old('email', $email) }}" required>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">New password</label>
                        <input type="password" name="password" id="password"
                               class="form-control form-control-lg" required autocomplete="new-password">
                    </div>

                    <div class="mb-4">
                        <label for="password_confirmation" class="form-label fw-semibold">Repeat it</label>
                        <input type="password" name="password_confirmation" id="password_confirmation"
                               class="form-control form-control-lg" required autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill">
                        Save the new password
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

@endsection
