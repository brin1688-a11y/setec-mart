@extends('layouts.admin')

@section('title', 'Edit Product')

@section('content')

<h2 class="fw-bold mb-4">Edit Product</h2>

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="stat-card">
    <form method="POST" action="{{ route('admin.products.update', $product) }}" enctype="multipart/form-data">
        @include('admin.products._form')
    </form>
</div>

@endsection