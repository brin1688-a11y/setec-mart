<p align="center">
  <img src="public/images/logo-full.png" width="220" alt="Setec Mart">
</p>

<h1 align="center">Setec Mart</h1>

<p align="center">An online grocery shop for Cambodia — KHQR and cash on delivery, zone-priced delivery, built on Laravel 12.</p>

---
php artisan dev --public=https://gbhg7562-8000.asse.devtunnels.ms

## What it does

- **Storefront** — browse by category, search, product galleries, cart, coupons
- **Checkout** — Cambodian address (province → district → commune), local phone
  validation, optional Telegram handle
- **Delivery pricing** — Phnom Penh $1.50, free over $20; the provinces $2.50.
  Priced on the server from the chosen province, so the form cannot be edited
  to change it
- **Payments** — KHQR through [CutLuy](https://cutluy.com), rendered on our own
  pay page, plus cash on delivery
- **Accounts** — email/password or Google sign-in, saved delivery details
- **Admin** — products, categories, orders, coupons, customers, revenue

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer (8.3 here), with `pdo_pgsql`, `mbstring`, `openssl`, `curl` |
| Composer | 2.x |
| PostgreSQL | 13 or newer |
| Node | only if you touch Tailwind — the shop itself needs no build step |

## Setup

Run these once, in order.

```bash
composer install
```

```bash
cp .env.example .env && php artisan key:generate
```

Open `.env` and fill in the database block:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=grocery_ecommerce
DB_USERNAME=postgres
DB_PASSWORD=your-password
```

While you are in there, point `APP_URL` at the address you actually open, so
generated links and the webhook tester agree with the browser:

```
APP_URL=http://127.0.0.1:8000
```

Create that database in PostgreSQL first, then build the schema and load the
starter categories and products:

```bash
php artisan migrate --seed
```

Uploaded product images are written to `storage/`, so link them into `public/`:

```bash
php artisan storage:link
```

Give yourself an admin account. The command asks for the password on a hidden
prompt, so it never lands in your shell history:

```bash
php artisan user:admin you@example.com --name="Your Name"
```

## Running it

One command starts everything:

```bash
php artisan dev
```

That runs the three processes the shop needs and labels whose output is whose:

```
  Setec Mart is running.

  server     http://127.0.0.1:8000
  queue      waiting for jobs — silent until one arrives
  schedule   running cutluy:reconcile every five minutes

  Press Ctrl+C to stop everything.

server     2026-09-18 02:18:27 /products ....................... ~ 0.23ms
queue      2026-09-18 02:18:30 App\Jobs\ProcessCutLuyWebhook .... RUNNING
queue      2026-09-18 02:18:30 App\Jobs\ProcessCutLuyWebhook ... 71.52ms DONE
```

Ctrl+C stops all three together. If one of them dies on its own, the others are
shut down too and the reason is printed, so you never end up with half a stack
running without noticing.

| | |
|---|---|
| `--port=8001` | serve somewhere else |
| `--host=0.0.0.0` | reachable from your phone on the same wifi |
| `--no-queue` | skip the queue worker |
| `--no-schedule` | skip the scheduler |

`composer dev` does the same thing.

### Sharing it through a tunnel

`php artisan dev` serves locally only. To let CutLuy reach the webhook, or to
show the shop on a phone, open a tunnel yourself — VS Code's **Ports** panel,
or:

```bash
ngrok http 8000
```

Two things then need setting, or the shared URL serves a site whose every link
points back at `127.0.0.1`:

```
APP_URL=https://your-tunnel-address
TRUSTED_PROXIES=*
```

`TRUSTED_PROXIES` is what makes Laravel believe the `X-Forwarded-Proto` and
`X-Forwarded-Host` the tunnel sends, so pages come out on the public address.
Use `*` only for a tunnel you opened yourself — trusting a proxy you do not
control lets a caller forge the host Laravel thinks it is serving.

Then point the CutLuy dashboard at `https://your-tunnel-address/webhooks/cutluy`.

> On Windows a forwarder told to reach "localhost" tries IPv6 `::1` first, while
> `artisan serve` binds one address family only. If the tunnel connects but the
> page just hangs, that is usually why — run a second listener alongside:
>
> ```bash
> php artisan serve --host=[::] --port=8000
> ```

> Anyone with the link reaches the whole shop, admin area included. Close the
> tunnel when you are done.

### Or run them by hand

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

Open **http://127.0.0.1:8000** — not `http://localhost:8000`.

> On Windows, `localhost` resolves to the IPv6 address `::1` first, while
> `artisan serve` only listens on IPv4. Every request then waits for the IPv6
> attempt to fail before retrying — about 500 ms of dead time on each one. Using
> `127.0.0.1` skips that entirely and the site feels roughly five times faster.

The queue worker matters: payment webhooks are handled on the queue, and without
one running a customer can pay and the order will sit on *Pending* until the
reconciler catches it.

> **Run by hand on Windows it prints nothing and looks dead. It is not.**
> Laravel only shows its `Processing jobs from the [default] queue.` banner when
> `stty` is present, and PowerShell has no `stty`. So the worker starts, prints
> nothing, and waits — it writes a line only when a job actually arrives. That is
> why `php artisan dev` prints its own banner instead.

The scheduler is what runs `cutluy:reconcile` every five minutes. In production
it is a cron entry instead: `* * * * * php artisan schedule:run`.

Admin lives at **/admin**; sign in with the account you made above.

## KHQR payments

Add your CutLuy credentials to `.env`. **Never commit these** — `.env` is
already in `.gitignore`:

```
CUTLUY_BASE_URL=https://cutluy.com
CUTLUY_API_KEY=your-api-key
CUTLUY_WEBHOOK_SECRET=your-webhook-secret
```

CutLuy tells us a payment succeeded by calling
`POST /webhooks/cutluy`. It cannot reach `127.0.0.1`, so while developing,
expose the port with a tunnel and register that URL in the CutLuy dashboard:

```bash
ngrok http 8000
```

To check the handler without involving CutLuy at all, send yourself a correctly
signed webhook:

```bash
php artisan cutluy:test-webhook 1 --event=payment.completed
```

That posts to `APP_URL/webhooks/cutluy`; pass `--url=` to send it somewhere
else. Two flags prove the guards work: `--bad-secret` signs with the wrong
key and must be rejected, `--stale` back-dates the timestamp ten minutes and
must be rejected as a replay.

If a webhook is ever lost, nothing is stuck: `cutluy:reconcile` asks CutLuy
about every pending KHQR payment, completes the ones that were paid and returns
the stock held by the ones that expired. Cash-on-delivery orders are never
touched by it.

```bash
php artisan cutluy:reconcile
```

## Google sign-in

Create an OAuth client in the Google Cloud console, add
`http://127.0.0.1:8000/auth/google/callback` as an authorised redirect URI, then:

```
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
```

Signing in with a Google address that already has an account links the two
rather than creating a second one.

## Shop settings

The footer's contact details come from `.env`:

```
SHOP_TELEGRAM=setecmart
SHOP_PHONE="012 345 678"
SHOP_EMAIL=hello@setecmart.com
SHOP_ADDRESS="Phnom Penh, Cambodia"
SHOP_HOURS="Every day, 7:00 - 20:00"
```

Delivery prices, free-delivery thresholds, lead times and the list of provinces
live in `config/cambodia.php`. Change them there and the checkout quote, the
product page, the footer and the order totals all follow.

**Logo files** in `public/`:

| File | Where it shows |
|---|---|
| `images/logo.png` | the original artwork, kept as the source |
| `images/logo-mark.png` | header, footer, admin sidebar — the emblem only |
| `images/logo-full.png` | sign-in and register pages — the full lockup |
| `favicon.ico`, `apple-touch-icon.png` | browser tab, phone home screen |

To change the logo, replace `images/logo.png` and regenerate the rest at the
sizes in that table.

## Tests

```bash
php artisan test
```

150 tests run against an in-memory SQLite database, so they need no setup and
never touch your development data.

## Troubleshooting

**`could not find driver (Connection: pgsql)`** — the PostgreSQL driver is off.
In `php.ini`, uncomment `extension=pdo_pgsql` and `extension=pgsql`, then
restart PHP. On WAMP there are two `php.ini` files (Apache's and the CLI's) and
both need it.

**`cURL error 60: SSL certificate problem`** — PHP has no CA bundle. Download
[cacert.pem](https://curl.se/ca/cacert.pem), then point `php.ini` at it with
`curl.cainfo` and `openssl.cafile`.

**Uploaded images give 404 or 403** — `php artisan storage:link` was not run, or
`public/storage` exists as a real folder instead of a link. Delete it and run
the command again.

**A change to `.env` or a config file does nothing** — clear the caches:

```bash
php artisan config:clear && php artisan view:clear
```
