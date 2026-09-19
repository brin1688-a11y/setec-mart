# Deploying Setec Mart

Target: an **AWS EC2** instance (Ubuntu 24.04) in **Singapore
(`ap-southeast-1`)**, with **Supabase** (PostgreSQL) as the database.

Why a VM and not a serverless host: the shop writes product photos to a local
disk (`storage/app/public/products`) and runs `queue:work` as a long-lived
process to settle KHQR payments. Both break on Vercel, and on the free tiers of
Railway and Render.

Singapore because it is the closest AWS region to Cambodia — roughly 30–50 ms,
against ~200 ms from a US datacentre.

---

## 0. The project's own repository — done

The project now has its own git repository, rooted at the project folder and
pushed to a private GitHub repo. Before that it was rooted at `C:\Users\SoatB`
— the whole Windows home folder — with no commits and a remote pointing at an
unrelated project.

That mattered for more than deployment: a `git add -A` in the old repository
would have staged `.ssh/`, `.env`, `.claude.json` and browser data, and pushing
it would have published them.

The old repository at `C:\Users\SoatB` still exists. Deleting it is a separate
decision; in the meantime, never run `git add` from the home folder.

Confirm before any push that `.env` and `vendor/` are absent:

```bash
git status --short
```

---

## 1. Set a billing alarm — do this first, not last

AWS does **not** stop your server when the free credits run out. It keeps
running and keeps charging. Set the guard before you create anything.

- Billing console → **Budgets** → create a **Zero spend budget** (or a $1
  budget) with an email alert.
- When signing up, choose the **Free Plan** if offered — it suspends services
  when the credits are gone instead of billing you.

Know what you are getting: accounts created since mid-2025 get roughly **$100
of credit valid for about 6 months**, not the old "12 months free". Verify the
terms shown to you at signup — AWS changes this.

## 2. Launch the instance

EC2 console, region **Singapore (ap-southeast-1)**:

| Setting | Value |
|---|---|
| AMI | Ubuntu Server 24.04 LTS |
| Instance type | `t3.micro` (1 GB RAM — the provisioning script adds 2 GB of swap to compensate) |
| Key pair | create one and keep the `.pem` file |
| Storage | 20 GB gp3 |

**Security group** — inbound rules:

| Type | Port | Source |
|---|---|---|
| SSH | 22 | **your IP only** |
| HTTP | 80 | `0.0.0.0/0` |
| HTTPS | 443 | `0.0.0.0/0` |

Leave SSH open to the world and you will be in someone's brute-force list
within the hour.

**Leave the outbound rules alone** — AWS's default is "All traffic", and that
is what you want. Narrowing egress to HTTP/HTTPS looks tidy and then quietly
breaks things that are not obviously network-related:

| Blocked port | What stops working |
|---|---|
| 5432 | every database query — Supabase is not on the instance |
| 22 | `git clone`/`git pull` over SSH, so deploys fail |

Both surface as "timeout expired" rather than anything mentioning a firewall,
which makes them slow to diagnose. If egress must be restricted, allow at least
TCP 5432 and 443 outbound to `0.0.0.0/0`.

GitHub can be reached over 443 instead of 22, which is worth knowing if you are
ever on a network that blocks it — put this in `/var/www/.ssh/config`:

```
Host github.com
    Hostname ssh.github.com
    Port 443
    User git
```

**Allocate an Elastic IP and associate it with the instance.** Without one the
public IP changes every time the instance stops, and your domain stops
resolving to it. Note that AWS charges for public IPv4 addresses (~$3.60/month)
— it comes out of your credits.

## 3. Give the server read access to the repository

The repository is private, so the server needs its own credential. Use a
**deploy key** — an SSH key that can read this one repository and nothing else.
Not your account key: if the server is ever compromised, the attacker gets
read-only access to one repo rather than everything you own on GitHub.

The key belongs to `www-data`, because that is the user `deploy.sh` runs as and
therefore the user that will `git fetch` on every deploy. `/var/www` is already
that user's home directory.

```bash
sudo mkdir -p /var/www/.ssh
sudo ssh-keygen -t ed25519 -N "" -C "setec-mart deploy" -f /var/www/.ssh/id_ed25519
sudo ssh-keyscan -t ed25519 github.com | sudo tee -a /var/www/.ssh/known_hosts
sudo chown -R www-data:www-data /var/www/.ssh
sudo chmod 700 /var/www/.ssh
sudo cat /var/www/.ssh/id_ed25519.pub
```

Copy that public key, then in the GitHub repository:
**Settings → Deploy keys → Add deploy key** — paste it, and leave
**Allow write access unchecked**.

## 4. Provision

```bash
sudo mkdir -p /var/www/setec-mart
sudo chown www-data:www-data /var/www/setec-mart
sudo -u www-data -H git clone git@github.com:YOUR_USER/setec-mart.git /var/www/setec-mart

sudo DOMAIN=shop.example.com bash /var/www/setec-mart/deploy/setup-server.sh
```

This installs nginx, PHP 8.3 + the PostgreSQL driver, Composer and Supervisor;
adds swap; raises the upload limits to fit a 10-image gallery; and installs the
nginx site, the queue worker and the scheduler cron entry.

The script also inserts iptables rules for 80/443. On AWS that is a no-op — the
security group is the real firewall — but it keeps the script working unchanged
if you ever move to a host that ships a locked-down INPUT chain.

Node.js is deliberately not installed. `@vite` appears only in
`welcome.blade.php`, which no route serves — the storefront loads Bootstrap from
a CDN, so there is no asset build step.

## 5. The environment file

Write `/var/www/setec-mart/.env`. **Never copy your local one** — generate fresh
secrets on the server. Your local `.env` sits inside a OneDrive-synced folder.

```bash
APP_NAME="Setec Mart"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://shop.example.com
APP_TIMEZONE=Asia/Phnom_Penh

# Supabase — the SESSION pooler, not the direct connection.
# The direct host is IPv6-only; the transaction pooler (port 6543) does not
# support prepared statements, which Laravel's PDO relies on.
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-southeast-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres.YOUR_PROJECT_REF
DB_PASSWORD=your-supabase-db-password
DB_SSLMODE=require

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

GEMINI_API_KEY=...
CUTLUY_API_KEY=...
CUTLUY_WEBHOOK_SECRET=...
```

Then:

```bash
cd /var/www/setec-mart
sudo -u www-data -H php artisan key:generate
```

## 6. Move the database to Supabase

Create the Supabase project in **Singapore (ap-southeast-1)** — the same region
as the EC2 instance, so the app and its database are not talking across an
ocean. From your Windows machine:

```bash
pg_dump --no-owner --no-privileges --schema=public \
        -h 127.0.0.1 -U postgres grocery_ecommerce > setec-mart.sql
```

Load it into a **fresh** Supabase project (a used one may already have objects
in `public` that collide):

```bash
psql "postgresql://postgres.YOUR_PROJECT_REF:PASSWORD@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres" \
     -f setec-mart.sql
```

Then, on the server, let Laravel apply anything the dump predates:

```bash
sudo -u www-data -H php artisan migrate --force
```

## 7. Copy the product photos

Uploaded images are **not** in git (by design — `storage/` is generated data).
The snack photos and everything else have to be copied across once:

```bash
rsync -avz --progress -e "ssh -i your-key.pem" \
      "C:/Users/SoatB/OneDrive/Desktop/Projects/E_commerce/grocery-ecommerce/storage/app/public/" \
      ubuntu@YOUR_ELASTIC_IP:/tmp/public-storage/
```

```bash
sudo rsync -a /tmp/public-storage/ /var/www/setec-mart/storage/app/public/
sudo chown -R www-data:www-data /var/www/setec-mart/storage
```

(Two hops because `ubuntu` cannot write into `www-data`'s directory directly.)

From then on, images uploaded through the admin stay on the server's disk.
Take them into your backups — this is the one part of the shop that is not
reproducible from git plus the database.

## 8. First deploy, then HTTPS

```bash
cd /var/www/setec-mart
sudo bash deploy/deploy.sh

sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d shop.example.com
```

Point the domain's A record at the **Elastic IP** before running certbot, and
wait for DNS to propagate — certbot fails if the name does not resolve yet.

## Deploying after that

```bash
cd /var/www/setec-mart && sudo bash deploy/deploy.sh
```

Pulls, installs, migrates, rebuilds the caches, restarts the worker and checks
`/up` before reporting success. It puts the shop in maintenance mode first and
brings it back even if a step fails.

---

## Things worth knowing

**Watch the credits, not the calendar.** Billing console → Cost Explorer. A
`t3.micro` plus its EBS volume plus the IPv4 address runs to roughly $12–13 a
month, so $100 of credit is about six months. Decide before then whether to pay
AWS or move — `deploy/setup-server.sh` works unchanged on any Ubuntu 24.04 host,
so moving is an afternoon, not a rewrite.

**Supabase's free tier pauses a project after ~7 days of no activity.** This
shop will not hit that: `cutluy:reconcile` runs every five minutes from the
scheduler and always queries the payments table, which counts as activity. If
you ever remove that scheduled command, add a keep-alive in its place.

**`config:cache` stops `.env` from being read.** The deploy caches config, and
from that moment Laravel no longer loads `.env` — it reads the cached array.
One call in this codebase reaches for the environment directly:

```php
// bootstrap/app.php:34
if ($proxies = env('TRUSTED_PROXIES')) {
```

With a cached config that returns `null`, so trusted proxies stay off. That is
the right default for the setup above, where nginx talks to PHP-FPM over a
socket on the same box and sets the scheme itself.

It only matters **if you put Cloudflare's proxy or an AWS load balancer in
front**. Then Laravel needs to trust the `X-Forwarded-*` headers, and because of
the caching it has to come from a real environment variable rather than `.env`:

```bash
# /etc/php/8.3/fpm/pool.d/www.conf
env[TRUSTED_PROXIES] = "*"
```

`"*"` is only safe when nothing but that proxy can reach the origin — restrict
ports 80/443 in the security group to the proxy's IP ranges if you do this.

**Backups.** Supabase keeps daily backups of the database on the free tier, but
`storage/app/public` is yours alone. A weekly `rsync` of that directory off the
server is enough.
