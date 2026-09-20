@extends('layouts.app')

@section('title', 'Confirm your email')

@section('content')

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="bg-white shadow-sm rounded-4 p-5 text-center">

                <div class="ve-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                         stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2.5" y="5" width="19" height="14" rx="2.5"></rect>
                        <path d="m3 7 9 6 9-6"></path>
                    </svg>
                </div>

                <h3 class="fw-bold mt-3">Confirm your email</h3>

                <p class="text-muted">
                    We sent a link to <strong>{{ auth()->user()->email }}</strong>.
                    Open it and your account is ready.
                </p>

                {{-- Said plainly, so nobody wonders why the shop looks broken:
                     it does not, everything but the till is open. --}}
                <p class="text-muted small">
                    You can keep browsing and filling your cart meanwhile — only
                    placing the order waits for this.
                </p>

                @if (session('status'))
                    <div class="alert alert-success">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn btn-success rounded-pill px-4">
                        Send it again
                    </button>
                </form>

                <p class="text-muted small mt-4 mb-0">
                    Not in your inbox? It may be in spam.
                    <a href="{{ route('products.index') }}" class="text-decoration-none">Keep shopping</a>
                </p>

            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    .ve-mark {
        width: 76px; height: 76px; margin: 0 auto;
        display: grid; place-items: center;
        border-radius: 50%;
        background: var(--brand-wash); color: var(--brand-2);
    }
    .ve-mark svg { width: 34px; height: 34px; }
</style>
@endpush

@endsection
