# KHQR payments via CutLuy

Customers can pay for an order by scanning a KHQR code. CutLuy issues the QR;
a signed webhook tells us when the money actually moved.

## Configuration

Add to `.env` (both values come from your CutLuy dashboard):

```
CUTLUY_BASE_URL=https://cutluy.com
CUTLUY_API_KEY=ck_live_...
CUTLUY_WEBHOOK_SECRET=...
```

Point your CutLuy webhook endpoint at `POST https://your-domain/webhooks/cutluy`
and subscribe to `payment.completed`, `payment.scanned`, `payment.expired` and
`payment.failed`.

**A queue worker must be running** — the webhook endpoint acknowledges the
delivery and hands the work to a job:

```
php artisan queue:work
```

`composer dev` already runs one alongside the dev server.

## The flow

1. Customer picks **KHQR** at checkout. `CheckoutController` creates the order,
   reserves stock, and asks CutLuy for a payment
   (`reference_id` and `idempotency_key` are both `order_<id>`).
2. If CutLuy refuses, the order is rolled back, the stock is handed back, and
   the cart is left untouched so the customer can try again or switch to COD.
3. On success the customer lands on `/orders/{order}/pay`, which draws the
   `qr_string` as a QR code and also links to CutLuy's hosted `checkout_url`.
   That page polls **our own** database every 3s, never CutLuy's API.
4. CutLuy delivers `payment.completed` → `ProcessCutLuyWebhook` marks the
   payment Paid and moves the order from Pending to Confirmed.
5. `payment.expired` / `payment.failed` cancel the order and put the reserved
   stock back on the shelf.

`scanned` only means the customer opened the QR in their banking app. It never
fulfils anything.

## What guards the webhook

`CutLuyWebhookController` + `App\Services\CutLuy\WebhookSignature`:

- The HMAC is checked against `$request->getContent()` — the **raw** bytes.
  Never verify against `$request->all()` or a re-encoded body; the bytes change
  and the signature can never match again.
- `hash_equals` does the comparison in constant time.
- Deliveries whose `t` is more than `CUTLUY_WEBHOOK_TOLERANCE` seconds away
  (default 300) are refused, so a captured delivery cannot be replayed later.
- Verification failures answer 400, not 5xx, so CutLuy stops retrying something
  we will never accept.
- Everything else is answered 202 immediately and processed on the queue, so a
  slow database never turns into a retried delivery.

## Idempotency

`ProcessCutLuyWebhook` keys off the CutLuy payment id (a unique column on
`payments`), takes `lockForUpdate` on the row, and returns early when the
payment has already settled. A redelivered `payment.completed` leaves the
`paid_at` timestamp and the order status alone; a redelivered
`payment.expired` does not restore stock a second time.

The job also refuses to fulfil an order when the webhook's amount does not
match what we charged — it logs an error and leaves the order Pending for a
human to look at.

## API errors

`CutLuyClient` turns every non-2xx into a typed exception:

| HTTP | Exception | Handling |
| --- | --- | --- |
| 401 `unauthorized` | `UnauthorizedException` | logged `critical`, customer told KHQR is unavailable |
| 402 `quota_exceeded` | `QuotaExceededException` | same |
| 403 `account_suspended` | `AccountSuspendedException` | same |
| 429 `rate_limited` | `RateLimitedException` | carries `retryAfter` from the `Retry-After` header; the customer is told how long to wait — nothing retries in a loop |
| other / network | `CutLuyException` | logged, customer asked to try again |

Only transient connection failures are retried automatically (3 attempts,
500ms apart). Rate limits are never retried automatically.

Creates are capped at 60/minute per API key and reads at 600/minute, which is
why the pay page polls our database rather than CutLuy. The one place we do
call `GET /v1/payments/:id` is the "Already paid? Check now" button, throttled
to 4 presses per minute per order.

## Tests

```
php artisan test --filter=CutLuy
```

`tests/Feature/CutLuyWebhookTest.php` covers signature rejection (wrong secret,
missing header, stale timestamp, signature over re-serialised JSON),
idempotent redelivery, and the stock restore. `tests/Feature/CutLuyCheckoutTest.php`
fakes the API and covers payment creation plus each error path.
