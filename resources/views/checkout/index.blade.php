@extends('layouts.app')

@section('content')

<style>
    /* Whole-card payment choices: the label fills the box so anywhere in it
       is clickable, and the selected one is obvious at a glance. */
    .payment-option {
        border: 1px solid #dee2e6;
        border-radius: .75rem;
        padding: 1rem 1rem 1rem 2.75rem;
        transition: border-color .15s ease, background-color .15s ease;
        cursor: pointer;
    }

    .payment-option:hover {
        border-color: #8fcdaa;
        background: #f6fbf8;
    }

    .payment-option:has(input:checked) {
        border-color: #198754;
        background: #f1f9f4;
        box-shadow: 0 0 0 1px #198754 inset;
    }

    .payment-option .form-check-input {
        margin-left: -1.75rem;
        margin-top: .3rem;
    }

    .payment-option .form-check-label {
        cursor: pointer;
    }

    .payment-option-icon {
        font-size: 1.15rem;
        line-height: 1;
    }

    /* Keep the summary in view while the delivery form is filled in. */
    @media (min-width: 992px) {
        .checkout-summary {
            position: sticky;
            top: 1.5rem;
        }
    }
</style>

<div class="container py-5">

    <div class="mb-5">
        <h1 class="fw-bold">@lang('site.checkout.title')</h1>
        <p class="text-muted">@lang('site.checkout.intro')</p>
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

    <form method="POST" action="{{ route('checkout.store') }}">
        @csrf

        <div class="row g-4">

            <!-- DELIVERY + PAYMENT -->
            <div class="col-lg-8">

                <div class="bg-white shadow-sm rounded-4 p-4 mb-4">

                    <h5 class="fw-bold mb-4">@lang('site.checkout.delivery_information')</h5>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">@lang('site.checkout.full_name')</label>
                        <input
                            type="text"
                            name="name"
                            class="form-control form-control-lg"
                            value="{{ old('name', auth()->user()->name) }}"
                            required
                        >
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                @lang('site.checkout.phone')
                            </label>
                            <input
                                type="tel"
                                name="phone"
                                inputmode="tel"
                                placeholder="{{ __('site.checkout.phone_placeholder') }}"
                                class="form-control form-control-lg @error('phone') is-invalid @enderror"
                                value="{{ old('phone', $user->phone) }}"
                                required
                            >
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                @lang('site.checkout.telegram') <span class="text-muted fw-normal">(@lang('site.checkout.optional'))</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">@</span>
                                <input
                                    type="text"
                                    name="telegram"
                                    placeholder="username"
                                    class="form-control form-control-lg @error('telegram') is-invalid @enderror"
                                    value="{{ old('telegram', $user->telegram) }}"
                                >
                                @error('telegram')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-text">@lang('site.checkout.telegram_hint')</div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                @lang('site.checkout.province')
                            </label>
                            <select name="province" id="provinceSelect"
                                    class="form-select form-select-lg @error('province') is-invalid @enderror" required>
                                <option value="">@lang('site.checkout.choose')</option>
                                {{-- A plain A-Z list: the delivery charge is
                                     shown in the summary, so the dropdown does
                                     not need to talk about distance. --}}
                                @foreach($provinces as $value => $label)
                                    <option value="{{ $value }}" @selected(old('province', $user->province) === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('province')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                @lang('site.checkout.district')
                            </label>
                            <input type="text" name="district" placeholder="{{ __('site.checkout.district_placeholder') }}"
                                   class="form-control form-control-lg @error('district') is-invalid @enderror"
                                   value="{{ old('district', $user->district) }}" required>
                            @error('district')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                @lang('site.checkout.commune')
                            </label>
                            <input type="text" name="commune" placeholder="{{ __('site.checkout.commune_placeholder') }}"
                                   class="form-control form-control-lg @error('commune') is-invalid @enderror"
                                   value="{{ old('commune', $user->commune) }}" required>
                            @error('commune')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            @lang('site.checkout.street')
                        </label>
                        <textarea
                            name="address"
                            class="form-control @error('address') is-invalid @enderror"
                            rows="2"
                            placeholder="{{ __('site.checkout.street_placeholder') }}"
                            required
                        >{{ old('address', $user->address) }}</textarea>
                        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">@lang('site.checkout.street_hint')</div>
                    </div>

                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="save_address" value="1"
                               id="saveAddress" {{ old('save_address', ! $user->hasSavedAddress()) ? 'checked' : '' }}>
                        <label class="form-check-label" for="saveAddress">
                            @lang('site.checkout.save_details')
                        </label>
                    </div>

                    <div class="mb-0">
                        <label class="form-label fw-semibold">@lang('site.checkout.note') <span class="text-muted fw-normal">(@lang('site.checkout.optional'))</span></label>
                        <textarea
                            name="note"
                            class="form-control"
                            rows="2"
                        >{{ old('note') }}</textarea>
                    </div>

                </div>

                <div class="bg-white shadow-sm rounded-4 p-4">

                    <h5 class="fw-bold mb-4">@lang('site.checkout.payment_method')</h5>

                    <div class="form-check payment-option mb-3">
                        <input
                            class="form-check-input"
                            type="radio"
                            name="payment_method"
                            value="cod"
                            id="cod"
                            required
                            {{ old('payment_method') == 'cod' ? 'checked' : '' }}
                        >
                        <label class="form-check-label w-100" for="cod">
                            <span class="d-flex align-items-center gap-2">
                                <span class="payment-option-icon">💵</span>
                                <strong>@lang('site.checkout.cod')</strong>
                            </span>
                            <span class="text-muted small d-block mt-1">
                                @lang('site.checkout.cod_hint')
                            </span>
                        </label>
                    </div>

                    <div class="form-check payment-option mb-0">
                        <input
                            class="form-check-input"
                            type="radio"
                            name="payment_method"
                            value="khqr"
                            id="khqr"
                            required
                            {{ old('payment_method') == 'khqr' ? 'checked' : '' }}
                        >
                        <label class="form-check-label w-100" for="khqr">
                            <span class="d-flex align-items-center gap-2">
                                <span class="payment-option-icon">📱</span>
                                <strong>KHQR</strong>
                                <span class="badge rounded-pill text-bg-light border ms-1">@lang('site.checkout.khqr_badge')</span>
                            </span>
                            <span class="text-muted small d-block mt-1">
                                @lang('site.checkout.khqr_hint')
                            </span>
                        </label>
                    </div>

                </div>

            </div>

            <!-- ORDER SUMMARY -->
            <div class="col-lg-4">

                <div class="bg-white shadow-sm rounded-4 p-4 checkout-summary">

                    <h5 class="fw-bold mb-4">@lang('site.checkout.order_summary')</h5>

                    @foreach($cart->items as $item)
                        <div class="d-flex justify-content-between align-items-start mb-2 small">
                            <span class="text-muted pe-2">
                                {{ $item->product->name }}
                                <span class="text-nowrap">× {{ $item->quantity }}</span>
                            </span>
                            <span class="text-nowrap">${{ number_format($item->subtotal(), 2) }}</span>
                        </div>
                    @endforeach

                    <div class="d-flex justify-content-between small text-muted mt-3 pt-3 border-top">
                        <span>@lang('site.checkout.subtotal')</span>
                        <span>${{ number_format($subtotal, 2) }}</span>
                    </div>

                    {{-- Always rendered so applying a code can fill it in
                         without reloading the page. --}}
                    <div class="d-flex justify-content-between small mt-1 text-success {{ $coupon ? '' : 'd-none' }}"
                         id="discountRow">
                        <span class="d-inline-flex align-items-center gap-1">
                            @lang('site.checkout.discount')
                            <span class="badge bg-success-subtle text-success border border-success-subtle"
                                  id="discountCode">{{ $coupon?->code }}</span>
                        </span>
                        <span id="discountAmount">-${{ number_format($discount, 2) }}</span>
                    </div>

                    <div class="d-flex justify-content-between small text-muted mt-1">
                        <span>
                            @lang('site.checkout.delivery')
                            <span class="text-muted" id="deliveryZone"></span>
                        </span>
                        <span id="deliveryFee">—</span>
                    </div>

                    {{-- How long it takes matters as much as what it costs. --}}
                    <div class="d-flex align-items-center gap-2 mt-2 p-2 rounded-3"
                         id="etaRow" style="background: var(--surface-2); display: none !important;">
                        <span aria-hidden="true">&#128666;</span>
                        <div class="small">
                            <div class="fw-semibold" id="etaText"></div>
                            <div class="text-muted" id="etaNote"></div>
                        </div>
                    </div>

                    @if($toFreeDelivery !== null)
                        <div class="alert alert-light border small py-2 px-3 mt-2 mb-0" id="freeDeliveryHint">
                            @lang('site.checkout.add_more_free', ['amount' => '$' . number_format($toFreeDelivery, 2)])
                        </div>
                    @endif

                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top mb-4">
                        <span class="fw-bold">@lang('site.checkout.total')</span>
                        <span class="fw-bold fs-4 text-success" id="orderTotal">
                            ${{ number_format($subtotal, 2) }}
                        </span>
                    </div>

                    {{-- Outside the main form: a nested <form> is invalid HTML,
                         so this posts via its own form element rendered after
                         the summary and linked with the form attribute. --}}
                    {{-- Both states are always in the page so the script can
                         switch between them after applying or removing a code,
                         instead of reloading and losing the form. --}}
                    <div class="mb-3">
                        <div id="couponMessage">
                            @if(session('coupon_error'))
                                <div class="alert alert-warning py-2 px-3 small mb-2">
                                    {{ session('coupon_error') }}
                                </div>
                            @endif

                            @if(session('coupon_success'))
                                <div class="alert alert-success py-2 px-3 small mb-2">
                                    {{ session('coupon_success') }}
                                </div>
                            @endif
                        </div>

                        <div id="couponApplied" class="{{ $coupon ? '' : 'd-none' }}">
                            <button type="submit" form="removeCouponForm"
                                    class="btn btn-link btn-sm text-muted p-0 text-decoration-none">
                                @lang('site.checkout.coupon_remove') "<span id="appliedCode">{{ $coupon?->code }}</span>"
                            </button>
                        </div>

                        <div id="couponEntry" class="{{ $coupon ? 'd-none' : '' }}">
                            <label for="couponCode" class="form-label small text-muted mb-1">
                                @lang('site.checkout.have_coupon')
                            </label>
                            <div class="input-group">
                                <input type="text" id="couponCode" name="code" form="applyCouponForm"
                                       class="form-control text-uppercase"
                                       placeholder="{{ __('site.checkout.coupon_placeholder') }}"
                                       autocomplete="off">
                                <button type="submit" form="applyCouponForm" class="btn btn-outline-success"
                                        id="applyCouponBtn">
                                    @lang('site.checkout.coupon_apply')
                                </button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill" id="placeOrderBtn">
                        @lang('site.checkout.place_order')
                    </button>

                    {{-- Shown only while no payment method is chosen, so the
                         disabled button never looks like a fault. --}}
                    <p class="text-center small text-muted mt-2 mb-0 d-none" id="paymentHint">
                        @lang('site.checkout.choose_payment_hint')
                    </p>

                    <p class="text-muted small text-center mt-3 mb-0">
                        @lang('site.checkout.choose_province_hint')
                    </p>

                </div>

            </div>

        </div>

    </form>

    {{-- Kept out of the checkout form so the browser does not nest forms. --}}
    <form method="POST" action="{{ route('coupon.apply') }}" id="applyCouponForm" class="d-none">
        @csrf
    </form>

    <form method="POST" action="{{ route('coupon.remove') }}" id="removeCouponForm" class="d-none">
        @csrf
        @method('DELETE')
    </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // The same rules the server uses. This only previews them — the order is
    // always priced again server-side from the chosen province.
    const zoneOf = @json($zoneOf);
    const rules = @json($zoneRules);
    const subtotal = {{ $subtotal }};

    // Not const: applying or removing a code changes it without a page load.
    let discount = {{ $discount }};

    const select = document.getElementById('provinceSelect');
    const feeEl = document.getElementById('deliveryFee');
    const zoneEl = document.getElementById('deliveryZone');
    const totalEl = document.getElementById('orderTotal');
    const hint = document.getElementById('freeDeliveryHint');

    function money(n) {
        return '$' + n.toFixed(2);
    }

    const etaRow = document.getElementById('etaRow');
    const etaText = document.getElementById('etaText');
    const etaNote = document.getElementById('etaNote');

    function update() {
        const province = select.value;

        if (!province) {
            feeEl.textContent = '—';
            zoneEl.textContent = '';
            totalEl.textContent = money(subtotal - discount);
            etaRow.style.setProperty('display', 'none', 'important');
            return;
        }

        const rule = rules[zoneOf[province]];
        if (!rule) return;

        // A zone ships free only if it has a threshold and the order clears it.
        const free = rule.free_over !== null && subtotal >= rule.free_over;
        const fee = free ? 0 : Number(rule.fee);

        feeEl.innerHTML = free ? '<span class="text-success">Free</span>' : money(fee);
        zoneEl.textContent = '· ' + rule.label;
        totalEl.textContent = money(subtotal - discount + fee);

        etaRow.style.setProperty('display', 'flex', 'important');
        etaText.textContent = 'Delivery: ' + rule.eta;
        etaNote.textContent = free
            ? 'Free delivery on this order.'
            : (rule.free_over !== null
                ? 'Spend ' + money(rule.free_over) + ' or more for free delivery.'
                : 'Delivery to the provinces is ' + money(rule.fee) + '.');

        if (hint) {
            // The banner only makes sense where free delivery is possible.
            hint.style.display = (!free && rule.free_over !== null) ? '' : 'none';
        }
    }

    select.addEventListener('change', update);
    update();

    // ---- Paying for it is a choice, not a default ------------------------
    //
    // Nothing is pre-selected, so the customer has to say how they want to
    // pay rather than discovering afterwards that the shop picked for them.
    // The radios carry `required`, which is what stops a submission when
    // JavaScript is off; this only makes the state visible beforehand.

    const payRadios = document.querySelectorAll('input[name="payment_method"]');
    const placeOrder = document.getElementById('placeOrderBtn');
    const payHint = document.getElementById('paymentHint');

    function reflectPayment() {
        const chosen = [...payRadios].some(r => r.checked);

        placeOrder.disabled = !chosen;
        if (payHint) payHint.classList.toggle('d-none', chosen);
    }

    payRadios.forEach(r => r.addEventListener('change', reflectPayment));
    reflectPayment();

    // ---- Coupons, without throwing away the form ------------------------
    //
    // These two forms used to post normally, which meant a redirect back to a
    // freshly rendered checkout: every delivery field typed but not submitted
    // was wiped, the required province with it. Posting them in the background
    // leaves the page exactly as the customer left it.

    const couponMessage = document.getElementById('couponMessage');
    const couponEntry = document.getElementById('couponEntry');
    const couponApplied = document.getElementById('couponApplied');
    const appliedCode = document.getElementById('appliedCode');
    const discountRow = document.getElementById('discountRow');
    const discountCode = document.getElementById('discountCode');
    const discountAmount = document.getElementById('discountAmount');

    function say(text, ok) {
        couponMessage.innerHTML = '';
        if (!text) return;

        const box = document.createElement('div');
        box.className = 'alert py-2 px-3 small mb-2 ' + (ok ? 'alert-success' : 'alert-warning');
        box.textContent = text;
        couponMessage.appendChild(box);
    }

    function showCoupon(code, amount) {
        discount = amount;

        if (code) {
            appliedCode.textContent = code;
            discountCode.textContent = code;
            discountAmount.textContent = '-' + money(amount);
            discountRow.classList.remove('d-none');
            couponEntry.classList.add('d-none');
            couponApplied.classList.remove('d-none');
        } else {
            discountRow.classList.add('d-none');
            couponApplied.classList.add('d-none');
            couponEntry.classList.remove('d-none');
            const field = document.getElementById('couponCode');
            if (field) field.value = '';
        }

        update();
    }

    async function post(form, button) {
        if (button) button.disabled = true;

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
            });

            // A session that timed out answers with the login page, not JSON.
            if (res.status === 419 || res.redirected) {
                window.location.reload();
                return;
            }

            const data = await res.json();
            say(data.message, data.ok === true);

            if (data.ok) showCoupon(data.code, Number(data.discount) || 0);
        } catch (e) {
            // Fall back to the ordinary post rather than leaving them stuck.
            form.submit();
        } finally {
            if (button) button.disabled = false;
        }
    }

    const applyForm = document.getElementById('applyCouponForm');
    const removeForm = document.getElementById('removeCouponForm');

    if (applyForm) {
        applyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            post(applyForm, document.getElementById('applyCouponBtn'));
        });
    }

    if (removeForm) {
        removeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            post(removeForm, null);
        });
    }
});
</script>

@endsection
