@extends('layouts.admin')

@section('title', 'Edit Category')

@section('content')

<h2 class="fw-bold mb-4">Edit Category</h2>

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
    <form method="POST" action="{{ route('admin.categories.update', $category) }}">
        @include('admin.categories._form')
    </form>
</div>

@endsection