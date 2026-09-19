@extends('layouts.admin')

@section('title', 'Edit Coupon')

@section('content')

<a href="{{ route('admin.coupons.index') }}" class="text-decoration-none text-muted d-inline-block mb-3">
    &larr; Back to Coupons
</a>

<h2 class="fw-bold mb-4">Edit {{ $coupon->code }}</h2>

<div class="stat-card">
    <form method="POST" action="{{ route('admin.coupons.update', $coupon) }}">
        @method('PUT')
        @include('admin.coupons._form')
    </form>
</div>

@endsection
