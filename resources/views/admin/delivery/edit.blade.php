@extends('layouts.admin')

@section('title', 'Delivery charges')

@section('content')

<div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div>
        <h2 class="fw-bold mb-1">Delivery charges</h2>
        <p class="text-muted mb-0" style="font-size: 14px;">
            What each zone costs and how long it takes. Applies to new orders —
            orders already placed keep the price they were quoted.
        </p>
    </div>

    @if($customised)
        <form method="POST" action="{{ route('admin.delivery.reset') }}"
              onsubmit="return confirm('Put every zone back to the default prices?');">
            @csrf @method('DELETE')
            <button class="chip-btn">Reset to defaults</button>
        </form>
    @endif
</div>

@if(session('success'))
    <div class="alert alert-success rounded-4">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger rounded-4">
        <ul class="mb-0 small">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.delivery.update') }}">
    @csrf
    @method('PUT')

    <div class="row g-4">
        @foreach($zones as $key => $zone)
            @php $default = $defaults[$key] ?? []; @endphp

            <div class="col-lg-4">
                <div class="stat-card h-100">

                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <h5 class="fw-bold mb-0">{{ $zone['label'] }}</h5>
                        <span class="pill pill-mute">{{ count($provincesByZone[$key] ?? []) }} provinces</span>
                    </div>

                    <p class="dz-provinces">{{ implode(', ', $provincesByZone[$key] ?? []) }}</p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Delivery price ($)</label>
                        <input type="number" step="0.01" min="0"
                               name="zones[{{ $key }}][fee]"
                               class="form-control @error('zones.'.$key.'.fee') is-invalid @enderror"
                               value="{{ old('zones.'.$key.'.fee', $zone['fee']) }}" required>
                        @error('zones.'.$key.'.fee')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-muted">Enter 0 to deliver here for nothing.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Free over ($)</label>
                        <input type="number" step="0.01" min="0"
                               name="zones[{{ $key }}][free_over]"
                               class="form-control @error('zones.'.$key.'.free_over') is-invalid @enderror"
                               value="{{ old('zones.'.$key.'.free_over', $zone['free_over']) }}"
                               placeholder="Never free">
                        @error('zones.'.$key.'.free_over')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-muted">
                            Leave empty and this zone always pays.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">How long it takes</label>
                        <input type="text" maxlength="60"
                               name="zones[{{ $key }}][eta]"
                               class="form-control @error('zones.'.$key.'.eta') is-invalid @enderror"
                               value="{{ old('zones.'.$key.'.eta', $zone['eta']) }}"
                               placeholder="e.g. Same day, 2-4 hours" required>
                        @error('zones.'.$key.'.eta')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-muted">Shown to the customer word for word.</small>
                    </div>

                    <div>
                        <label class="form-label fw-semibold">Days to add to the arrival date</label>
                        <input type="number" min="0" max="60"
                               name="zones[{{ $key }}][eta_days]"
                               class="form-control @error('zones.'.$key.'.eta_days') is-invalid @enderror"
                               value="{{ old('zones.'.$key.'.eta_days', $zone['eta_days']) }}" required>
                        @error('zones.'.$key.'.eta_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-muted">
                            0 means same day. Used for the date on the order.
                        </small>
                    </div>

                    @if($default && (
                        (float) $default['fee'] !== (float) $zone['fee']
                        || ($default['free_over'] ?? null) != ($zone['free_over'] ?? null)
                    ))
                        <div class="dz-changed">
                            Default was ${{ number_format($default['fee'], 2) }}@if($default['free_over'] ?? null), free over ${{ number_format($default['free_over'], 2) }}@endif
                        </div>
                    @endif

                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 d-flex align-items-center gap-3 flex-wrap">
        <button type="submit" class="btn btn-success rounded-pill px-4">Save charges</button>
        <span class="text-muted" style="font-size: 13.5px;">
            These reach the checkout quote, the product page, the footer and the assistant at once.
        </span>
    </div>

</form>

@push('styles')
<style>
    .dz-provinces {
        font-size: 12.5px; color: var(--ink-3); line-height: 1.6;
        margin-bottom: 16px; padding-bottom: 14px;
        border-bottom: 1px solid var(--line);
    }

    .dz-changed {
        margin-top: 14px; padding: 8px 11px; border-radius: 10px;
        background: var(--card-2); color: var(--ink-3); font-size: 12.5px;
    }
</style>
@endpush

@endsection
