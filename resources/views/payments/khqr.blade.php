@extends('layouts.app')

@section('content')

<style>
    .khqr-card {
        max-width: 340px;
        margin: 0 auto;
        border-radius: 16px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, .12);
    }

    /* The red KHQR banner, with the notched corner the scheme's cards use. */
    .khqr-head {
        background: #E1232B;
        padding: 18px 0;
        text-align: center;
        clip-path: polygon(0 0, 100% 0, 100% 72%, 0 100%);
    }

    .khqr-head span {
        color: #fff;
        font-weight: 800;
        font-size: 22px;
        letter-spacing: 4px;
    }

    .khqr-merchant {
        font-size: 15px;
        color: #222;
    }

    .khqr-amount {
        font-size: 34px;
        font-weight: 700;
        line-height: 1.1;
        color: #111;
    }

    .khqr-amount small {
        font-size: 15px;
        font-weight: 600;
        color: #555;
        margin-left: 4px;
    }

    .khqr-divider {
        border-top: 2px dashed #e3e3e3;
        margin: 16px 0 0;
    }

    .khqr-qr-wrap {
        position: relative;
        display: inline-block;
        line-height: 0;
    }

    /* The dollar badge sits over the QR's centre — the code is generated at
       error-correction level H so covering this much of it still scans. */
    .khqr-badge {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 46px;
        height: 46px;
        border-radius: 50%;
        background: #111;
        color: #fff;
        font-size: 22px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 3px solid #fff;
    }

    .khqr-timer {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #fff;
        border-radius: 999px;
        padding: 8px 18px;
        font-variant-numeric: tabular-nums;
        font-weight: 600;
        box-shadow: 0 4px 14px rgba(0, 0, 0, .1);
    }

    .khqr-timer.is-low {
        color: #E1232B;
    }
</style>

<div class="container py-5">

    @if(session('error'))
        <div class="alert alert-warning" style="max-width: 340px; margin: 0 auto 16px;">
            {{ session('error') }}
        </div>
    @endif

    <div class="khqr-card">

        <div class="khqr-head">
            <span>KHQR</span>
        </div>

        <div class="px-4 pt-3">
            <div class="khqr-merchant">{{ config('services.cutluy.merchant_name') }}</div>
            <div class="khqr-amount">
                {{ number_format($order->total, 2) }}<small>{{ $payment->currency ?? 'USD' }}</small>
            </div>
            <div class="khqr-divider"></div>
        </div>

        <div class="text-center px-4 py-4">
            <div class="khqr-qr-wrap">
                <canvas id="khqrCanvas" width="264" height="264"></canvas>
                <div class="khqr-badge" id="khqrBadge">$</div>
            </div>

            <div id="qrError" class="d-none text-muted small py-5">
                The QR code could not be displayed.
                Please refresh the page, or choose Cash on Delivery instead.
            </div>
        </div>

    </div>

    <div class="text-center mt-3">
        <div class="text-muted mb-3" id="paymentState">
            @if($payment->cutluy_status === 'scanned')
                Opened in your banking app — confirm the payment there.
            @else
                Scan with any KHQR-enabled banking app
            @endif
        </div>

        @if($payment->expires_at)
            <div class="khqr-timer" id="khqrTimer">
                <span>&#128337;</span>
                <span id="khqrTimerValue">--:--</span>
            </div>
        @endif
    </div>

    <div class="mx-auto mt-4" style="max-width: 340px;">
        <div class="d-grid gap-2">

            {{-- Shown by the countdown when the code lapses, so an expired QR
                 is a button away from a working one rather than a dead end. --}}
            <form method="POST" action="{{ route('payments.renew', $order) }}"
                  id="renewForm" class="d-none">
                @csrf
                <button type="submit" class="btn btn-success rounded-pill w-100" id="renewButton">
                    Get a new QR code
                </button>
            </form>

            <form method="POST" action="{{ route('payments.refresh', $order) }}" id="checkForm">
                @csrf
                <button type="submit" class="btn btn-success rounded-pill w-100">
                    Already paid? Check now
                </button>
            </form>

            <a href="{{ route('orders.show', $order) }}" class="btn btn-link text-muted btn-sm">
                {{ $order->order_number }} details
            </a>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('khqrCanvas');
    const badge = document.getElementById('khqrBadge');
    const qrError = document.getElementById('qrError');
    const state = document.getElementById('paymentState');

    // The EMV payload CutLuy generated for this payment.
    const qrString = @json($payment->qr_string);

    // If the library did not load, say so rather than leaving a blank box.
    if (typeof QRious === 'undefined') {
        canvas.classList.add('d-none');
        badge.classList.add('d-none');
        qrError.classList.remove('d-none');
    } else {
        try {
            new QRious({
                element: canvas,
                value: qrString,
                // 264 lets the code draw at 255px for a 256-character KHQR
                // payload, so it fills the card instead of sitting small in
                // one corner. Padding is left to QRious: forcing it to 0
                // pins the code to the top-left AND removes the quiet zone
                // that scanners need.
                size: 264,
                // High correction, because the dollar badge covers the centre.
                level: 'H',
            });
        } catch (e) {
            canvas.classList.add('d-none');
            badge.classList.add('d-none');
            qrError.classList.remove('d-none');
        }
    }

    // ---- What a lapsed code looks like -----------------------------------
    const renewForm = document.getElementById('renewForm');
    const checkForm = document.getElementById('checkForm');
    let canRenew = @json($payment->canRenewQr());

    function expire(message) {
        canvas.style.opacity = '.25';
        badge.style.opacity = '.25';

        if (canRenew && renewForm) {
            state.textContent = message + ' Get a new one to finish paying.';
            renewForm.classList.remove('d-none');
            checkForm.classList.add('d-none');
        } else {
            state.textContent = message + ' Please place the order again.';
        }
    }

    // ---- Countdown to the payment's expiry -------------------------------
    const expiresAt = @json(optional($payment->expires_at)->toIso8601String());
    const timer = document.getElementById('khqrTimer');
    const timerValue = document.getElementById('khqrTimerValue');
    let countdown = null;

    if (expiresAt && timerValue) {
        const deadline = new Date(expiresAt).getTime();

        const tick = function () {
            const left = Math.max(0, Math.floor((deadline - Date.now()) / 1000));
            const mm = String(Math.floor(left / 60)).padStart(2, '0');
            const ss = String(left % 60).padStart(2, '0');

            timerValue.textContent = mm + ':' + ss;
            timer.classList.toggle('is-low', left <= 60);

            if (left === 0) {
                clearInterval(countdown);
                expire('This QR code has expired.');
            }
        };

        tick();
        countdown = setInterval(tick, 1000);
    }

    // ---- Watch for the payment landing -----------------------------------
    // Polls our own database, never CutLuy: the webhook is what flips the
    // payment to Paid, this page only watches for it to happen.
    const statusUrl = @json(route('payments.status', $order));
    let attempts = 0;

    const poll = setInterval(async function () {
        // Stop after ~15 minutes so an abandoned tab goes quiet.
        if (++attempts > 300) {
            clearInterval(poll);
            return;
        }

        try {
            const response = await fetch(statusUrl, { headers: { 'Accept': 'application/json' } });

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            if (typeof data.can_renew === 'boolean') {
                canRenew = data.can_renew;
            }

            if (data.paid && data.redirect) {
                clearInterval(poll);
                clearInterval(countdown);
                state.textContent = 'Payment received — taking you to your order…';
                window.location.href = data.redirect;
                return;
            }

            if (data.status === 'Expired' || data.status === 'Failed') {
                clearInterval(poll);
                clearInterval(countdown);
                expire('This payment ' + data.status.toLowerCase() + '.');
                return;
            }

            if (data.provider_status === 'scanned') {
                state.textContent = 'Opened in your banking app — confirm the payment there.';
            }
        } catch (e) {
            // Transient network blip; the next tick tries again.
        }
    }, 3000);
});
</script>

@endsection
