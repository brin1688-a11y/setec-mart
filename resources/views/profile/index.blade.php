@extends('layouts.app')

@section('content')

<div class="container py-5">

    <div class="mb-5">
        <h1 class="fw-bold">@lang('site.profile.title')</h1>
        <p class="text-muted">@lang('site.profile.intro')</p>
    </div>

    @if (session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
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

    <div class="row g-4">

        <!-- LEFT: Profile info + picture + password -->
        <div class="col-lg-4">

            <div class="bg-white shadow-sm rounded-4 p-4 text-center mb-4">

                <img
                    src="{{ $user->avatarUrl() }}"
                    onerror="this.onerror=null;this.src='{{ asset('images/default-avatar.svg') }}';"
                    alt="Profile Picture"
                    class="rounded-circle mb-3"
                    style="width: 120px; height: 120px; object-fit: cover;"
                >

                <h5 class="fw-bold mb-1">{{ $user->name }}</h5>
                <p class="text-muted mb-4">{{ $user->email }}</p>

                <form method="POST" action="{{ route('profile.picture') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3 text-start">
                        <label class="form-label fw-semibold small">@lang('site.profile.update_picture')</label>
                        <input type="file" name="profile_picture" class="form-control" accept="image/*" required>
                    </div>
                    <button type="submit" class="btn btn-outline-success rounded-pill w-100">
                        @lang('site.profile.upload_picture')
                    </button>
                </form>

            </div>

            <div class="bg-white shadow-sm rounded-4 p-4">

                <h6 class="fw-bold mb-3">@lang('site.profile.change_password')</h6>

                <form method="POST" action="{{ route('profile.password') }}">
                    @csrf
                    @method('PUT')

                    @if($user->usesGoogleOnly())
                        <p class="text-muted small mb-3">
                            @lang('site.profile.google_no_password')
                        </p>
                    @else
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">@lang('site.profile.current_password')</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">@lang('site.profile.new_password')</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">@lang('site.profile.confirm_new_password')</label>
                        <input type="password" name="password_confirmation" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-success rounded-pill w-100">
                        @lang('site.profile.update_password')
                    </button>
                </form>

            </div>

        </div>

        <!-- RIGHT: Settings + order history -->
        <div class="col-lg-8">

            <!-- ACCOUNT DETAILS -->
            <div class="bg-white shadow-sm rounded-4 p-4 mb-4">

                <h5 class="fw-bold mb-4">@lang('site.profile.account_details')</h5>

                <form method="POST" action="{{ route('profile.account') }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">@lang('site.profile.full_name')</label>
                            <input type="text" name="name" class="form-control"
                                   value="{{ old('name', $user->name) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">@lang('site.profile.email')</label>
                            <input type="email" name="email" class="form-control"
                                   value="{{ old('email', $user->email) }}" required>
                            @if($user->google_id)
                                <div class="form-text">@lang('site.profile.google_linked')</div>
                            @endif
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success rounded-pill mt-3 px-4">
                        @lang('site.profile.save_account')
                    </button>
                </form>

            </div>

            {{-- Where to deliver is only meaningful for an account that
                 can order; staff accounts cannot. --}}
            @shopper
            <!-- DELIVERY DETAILS -->
            <div class="bg-white shadow-sm rounded-4 p-4 mb-4">

                <h5 class="fw-bold mb-1">@lang('site.profile.delivery_details')</h5>
                <p class="text-muted small mb-4">
                    @lang('site.profile.delivery_intro')
                </p>

                <form method="POST" action="{{ route('profile.delivery') }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">
                                @lang('site.checkout.phone')
                            </label>
                            <input type="tel" name="phone" placeholder="{{ __('site.checkout.phone_placeholder') }}"
                                   class="form-control @error('phone') is-invalid @enderror"
                                   value="{{ old('phone', \App\Support\Cambodia::formatPhone($user->phone)) }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">@lang('site.profile.telegram')</label>
                            <div class="input-group">
                                <span class="input-group-text">@</span>
                                <input type="text" name="telegram" placeholder="username"
                                       class="form-control @error('telegram') is-invalid @enderror"
                                       value="{{ old('telegram', $user->telegram) }}">
                                @error('telegram')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">
                                @lang('site.checkout.province')
                            </label>
                            <select name="province" class="form-select @error('province') is-invalid @enderror">
                                <option value="">@lang('site.checkout.choose')</option>
                                @foreach($provinces as $value => $label)
                                    <option value="{{ $value }}" @selected(old('province', $user->province) === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('province')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">
                                @lang('site.checkout.district')
                            </label>
                            <input type="text" name="district" class="form-control"
                                   value="{{ old('district', $user->district) }}">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">
                                @lang('site.checkout.commune')
                            </label>
                            <input type="text" name="commune" class="form-control"
                                   value="{{ old('commune', $user->commune) }}">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold small">
                                @lang('site.checkout.street')
                            </label>
                            <textarea name="address" class="form-control" rows="2"
                                      placeholder="{{ __('site.checkout.street_placeholder') }}">{{ old('address', $user->address) }}</textarea>
                        </div>
                    </div>

                    @if($user->province)
                        <div class="alert alert-light border small mt-3 mb-0">
                            Delivery to <strong>{{ $user->province }}</strong> costs
                            <strong>${{ number_format(\App\Support\Cambodia::deliveryFee($user->province, 0), 2) }}</strong>,
                            @if(\App\Support\Cambodia::shipsFreeAt($user->province))or free over ${{ number_format(\App\Support\Cambodia::shipsFreeAt($user->province), 2) }}@endif, arriving {{ \App\Support\Cambodia::deliveryEta($user->province) }}.
                        </div>
                    @endif

                    <button type="submit" class="btn btn-success rounded-pill mt-3 px-4">
                        @lang('site.profile.save_delivery')
                    </button>
                </form>

            </div>
            @endshopper

        </div>

    </div>

</div>

@endsection