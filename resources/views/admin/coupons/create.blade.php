@extends('layouts.admin')

@section('title', 'New Coupon')

@section('content')

<a href="{{ route('admin.coupons.index') }}" class="text-decoration-none text-muted d-inline-block mb-3">
    &larr; Back to Coupons
</a>

<h2 class="fw-bold mb-4">New Coupon</h2>

<div class="stat-card">
    <form method="POST" action="{{ route('admin.coupons.store') }}">
        @include('admin.coupons._form')
    </form>
</div>

@endsection
