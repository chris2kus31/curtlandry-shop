# Curt Landry Ministries Shop — Deployment Runbook (granular)

Headless commerce: **Bagisto 2.4 (Laravel 12) backend/API** + **Next.js storefront**, on **AWS EC2 + RDS**, fronted by **Cloudflare**.

This is a **command-by-command** reproduction guide. It was written while building **staging**; the same steps produce **production** (see §"Production differences").

## How to read this
Every command block is tagged with **where** to run it:
- 🖥️ **LOCAL** = your Mac (the local git clones).
- ☁️ **SERVER** = SSH session on the EC2 box (`ubuntu@...`).
- 🟧 **AWS CONSOLE** = clicks in the AWS web console.
- 🔵 **CLOUDFLARE** = clicks in the Cloudflare dashboard.

> Legend: `[x]` done on staging · `[ ]` pending · `«angle brackets»` = environment-specific value/secret.
> Secrets (RDS password, storefront key, `NEXTAUTH_SECRET`) live ONLY in server env files — never committed.

---

## 1. Architecture

```
                          ┌─────────────────── Cloudflare ───────────────────┐
                          │  DNS + proxy (orange) + TLS + Zero Trust Access   │
                          └───────────────┬───────────────────┬──────────────┘
                                          │                   │
             shop-staging.curtlandry.com  │                   │  shop-api-staging.curtlandry.com
                     (storefront)         │                   │      (backend: admin + API)
                                          ▼                   ▼
                            ┌──────────────────────────────────────────┐
                            │        EC2 (Ubuntu 24.04, t3.large)       │
                            │  nginx ── proxy ──▶ PM2 (Next.js :3000)   │  ← storefront
                            │      └─── PHP-FPM ▶ Bagisto (public/)     │  ← backend/API
                            │  Redis (cache/queue/session)              │
                            └───────────────────┬──────────────────────┘
                                                │ 3306 (private, same VPC)
                                                ▼
                            ┌──────────────────────────────────────────┐
                            │   RDS MySQL 8.4 (curtlandry_shop DB)      │
                            └──────────────────────────────────────────┘
```

---

## 2. Environment inventory (staging)

| Item | Value |
|------|-------|
| AWS region | `us-east-2` (Ohio) |
| VPC | Default VPC `vpc-09a1b67ec32a5b512` |
| RDS instance id | `clm-shop-staging-db` |
| RDS engine | MySQL 8.4.x |
| RDS endpoint | `clm-shop-staging-db.c7gmy24ymfm4.us-east-2.rds.amazonaws.com` |
| RDS database | `curtlandry_shop` (created via RDS "Initial database name") |
| RDS master user | `admin` |
| RDS password | `«saved securely»` |
| RDS security group | `clm-shop-db-sg` (`sg-04731922754ebab98`) |
| EC2 instance | `clm-shop-staging` (`i-0fe4d7f17b63da9eb`, t3.large, us-east-2b) |
| EC2 security group | `clm-shop-ec2-sg` (`sg-0f6173b85645feba7`) |
| Elastic IP | `18.225.253.203` |
| SSH key | `curtlandry-api.pem` |
| Backend hostname | `shop-api-staging.curtlandry.com` |
| Storefront hostname | `shop-staging.curtlandry.com` |
| Backend path (server) | `/var/www/curtlandry-shop` |
| Storefront path (server) | `/var/www/curtlandry-storefront` |
| Backend repo | `git@github.com:chris2kus31/curtlandry-shop.git` |
| Storefront repo | `git@github.com:chris2kus31/curtlandry-storefront.git` |
| Storefront API key | `«pk_storefront_...»` (rotate before go-live) |
| Admin URL | `https://shop-api-staging.curtlandry.com/admin` |
| Default admin (from seed) | `admin@example.com` / `admin123` → **change after first login** |

---

## 3. 🟧 AWS RDS — create the database  `[x]`

RDS → **Create database** → **Standard create**:

| Screen | Field | Value |
|--------|-------|-------|
| Engine | Engine type | **MySQL** |
| Method | Creation method | Full configuration |
| Templates | Template | **Dev/Test** |
| Availability | Deployment | **Single-AZ (1 instance)** (staging) |
| Settings | Engine version | MySQL 8.4.x |
| Settings | DB instance identifier | `clm-shop-staging-db` |
| Settings | Master username | `admin` |
| Settings | Credentials mgmt | **Self managed** → set + save password |
| Instance | DB instance class | Burstable → **db.t4g.small** |
| Storage | Type / size | **gp3 / 20 GiB** |
| Connectivity | Compute resource | Don't connect to EC2 (do it later) |
| Connectivity | VPC | **Default VPC** (same one EC2 will use) |
| Connectivity | Public access | **No** |
| Connectivity | VPC security group | **Create new → `clm-shop-db-sg`** (NOT the DW's `curtlandry-db-sg`) |
| Monitoring | Performance Insights | On (7-day free) |
| Monitoring | Enhanced Monitoring | Off (staging) |
| **Additional config** | **Initial database name** | **`curtlandry_shop`** ← creates the DB; no `CREATE DATABASE` ever |

Click **Create database**. Wait for status **Available**, then copy the **Endpoint**.

> ⚠️ VPC vs. security group: keep the **same VPC** as everything else; only the **security group** is new. A new VPC is NOT needed.

---

## 4. 🟧 AWS EC2 — launch the server  `[x]`

EC2 → **Launch instances**:

| Field | Value |
|-------|-------|
| Name | `clm-shop-staging` |
| AMI | **Ubuntu Server 24.04 LTS** (x86) |
| Instance type | **t3.large** (2 vCPU / 8 GB) |
| Key pair | `curtlandry-api.pem` (reuse) |
| Network → VPC | **same Default VPC** `vpc-09a1b67ec32a5b512` |
| Auto-assign public IP | **Enable** |
| Firewall | **Select existing → `clm-shop-ec2-sg`** (create it first, see below) |
| Storage | **40 GiB gp3** |
| File systems | None |

### 4a. Create security group `clm-shop-ec2-sg` (do this before/at launch)
Name: `clm-shop-ec2-sg` · Description: `CLM Shop staging EC2 - web (SSH/HTTP/HTTPS)` · VPC: `vpc-09a1b67ec32a5b512`

**Inbound rules:**
| Type | Port | Source |
|------|------|--------|
| SSH | 22 | My IP |
| HTTP | 80 | 0.0.0.0/0 |
| HTTPS | 443 | 0.0.0.0/0 |

**Outbound rules:** `All traffic → 0.0.0.0/0` (default — required so the server can reach RDS + the internet).

> ⚠️ Gotcha: put the web rules under **Inbound**, not Outbound. And don't delete the default outbound allow-all, or the server can't reach RDS/apt.

### 4b. Elastic IP
EC2 → **Elastic IPs → Allocate** → select it → **Actions → Associate** → Resource type **Instance** → `clm-shop-staging` → Associate. Result: **`18.225.253.203`**.

> ⚠️ Gotcha: the instance ended up with the RDS group `clm-shop-db-sg` attached at first, so SSH **hung** (that group has no port 22). Fix: EC2 → Instances → select instance → **Actions → Security → Change security groups** → remove `clm-shop-db-sg`, add **only `clm-shop-ec2-sg`** → Save.

---

## 5. 🟧 Wire EC2 → RDS (port 3306)  `[x]`

EC2 → Security Groups → **`clm-shop-db-sg`** → **Inbound rules → Edit**:
- If an IP-based `3306` rule exists, **Delete** it.
- **Add rule:** Type **MySQL/Aurora**, Port **3306**, Source **Custom → `clm-shop-ec2-sg`** (`sg-0f6173b85645feba7`).
- **Save rules.**

> ⚠️ Gotcha: AWS error *"You may not specify a referenced group id for an existing IPv4 CIDR rule."* → you cannot edit an IP rule into an SG rule. **Delete** the IP rule and **add a new** SG-sourced rule.

---

## 6. ☁️ SSH in + test DB connectivity  `[x]`

🖥️ LOCAL — connect (in the folder holding the `.pem`):
```bash
chmod 400 curtlandry-api.pem
ssh -i curtlandry-api.pem ubuntu@18.225.253.203
# first time: type "yes" to trust host
```
> ⚠️ Gotcha: if SSH **hangs**, it's the security group. Confirm (a) instance has `clm-shop-ec2-sg` (not the DB SG), and (b) the SSH rule's source matches your **current** public IP (`curl ifconfig.me`).

☁️ SERVER — test RDS reachability:
```bash
mysqladmin -h clm-shop-staging-db.c7gmy24ymfm4.us-east-2.rds.amazonaws.com -u admin -p status
# success = "Uptime: ... Threads: ... Queries per second ..."
# hang/timeout = 3306 rule wrong, or RDS not "Available" yet
```

---

## 7. ☁️ SERVER — install the stack  `[x]`

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 + extensions (Bagisto 2.4 needs PHP 8.3/8.4)
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-intl php8.3-gd \
  php8.3-bcmath php8.3-zip php8.3-mbstring php8.3-curl php8.3-xml php8.3-soap php8.3-redis

# Composer 2, Node 22, PM2, nginx, Redis, MySQL client, git, unzip
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
sudo npm i -g pm2
sudo apt install -y nginx redis-server mysql-client git unzip

# Redis service + larger memory for BOTH php.ini files (CLI *and* FPM)
sudo systemctl enable --now redis-server
sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.3/cli/php.ini
sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.3/fpm/php.ini   # ← web requests use THIS one
sudo systemctl restart php8.3-fpm
```
> ⚠️ Critical: CLI and FPM have **separate** `php.ini` files. The API Platform GraphQL endpoint builds a large schema and exceeds the default 128 MB on **web requests** (PHP-FPM) → HTTP 500 with `Allowed memory size ... exhausted in RedisStore.php`. You MUST raise `memory_limit` in `/etc/php/8.3/fpm/php.ini`, not just the CLI one.

Verify (all must pass):
```bash
php -v && composer --version && node -v && nginx -v
php -m | grep -E "calendar|curl|intl|mbstring|openssl|pdo_mysql|tokenizer|gd|bcmath|zip|redis"
redis-cli ping        # → PONG
```
Confirmed versions on staging: PHP **8.3.32**, Composer **2.10.2**, Node **22.23.1**, nginx **1.24.0**.

> ⚠️ Gotcha: `php8.3-redis` is required (`.env` uses phpredis). Without it every artisan command that touches cache/session throws `Class "Redis" not found`.

---

## 8. ☁️ SERVER — app directory  `[x]`

```bash
sudo mkdir -p /var/www
sudo chown -R ubuntu:www-data /var/www
sudo chmod -R 2775 /var/www          # setgid → new files inherit www-data group
ls -ld /var/www                       # expect drwxrwsr-x ubuntu www-data  (the 's' = setgid active)
```

---

## 9. ☁️ SERVER — GitHub deploy keys (private repos)  `[x]`

Per-repo **read-only** deploy keys + SSH aliases (one key per repo; GitHub deploy keys are single-repo):
```bash
ssh-keygen -t ed25519 -C "clm-shop-staging (shop)" -f ~/.ssh/clm_shop -N ""
ssh-keygen -t ed25519 -C "clm-shop-staging (storefront)" -f ~/.ssh/clm_storefront -N ""

cat > ~/.ssh/config <<'EOF'
Host github-shop
  HostName github.com
  User git
  IdentityFile ~/.ssh/clm_shop
  IdentitiesOnly yes

Host github-storefront
  HostName github.com
  User git
  IdentityFile ~/.ssh/clm_storefront
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

echo "=== SHOP key ===";       cat ~/.ssh/clm_shop.pub
echo "=== STOREFRONT key ==="; cat ~/.ssh/clm_storefront.pub
```

🔵/GitHub — add each **entire** public key line (comment included) as a Deploy Key:
- Repo **curtlandry-shop** → Settings → Deploy keys → Add → paste **SHOP** key → **leave "Allow write access" UNCHECKED** → Add.
- Repo **curtlandry-storefront** → Settings → Deploy keys → Add → paste **STOREFRONT** key → read-only → Add.

☁️ SERVER — test (type `yes` to trust `github.com`, fingerprint `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU`):
```bash
ssh -T git@github-shop          # → "Hi chris2kus31/curtlandry-shop! ..."
ssh -T git@github-storefront    # → "Hi chris2kus31/curtlandry-storefront! ..."
```
> Note the clone URLs use the **alias**: `git@github-shop:...` / `git@github-storefront:...` (not `github.com`).

---

## 10. ☁️ SERVER — clone both repos  `[x]`

```bash
git clone git@github-shop:chris2kus31/curtlandry-shop.git /var/www/curtlandry-shop
git clone git@github-storefront:chris2kus31/curtlandry-storefront.git /var/www/curtlandry-storefront
ls -la /var/www/curtlandry-shop /var/www/curtlandry-storefront
```

---

## 11. ☁️ SERVER — backend dependencies + env  `[x]`

```bash
cd /var/www/curtlandry-shop
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader
cp .env.example .env
nano .env
```

`.env` values:
```env
APP_NAME="Curt Landry Ministries Shop"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://shop-api-staging.curtlandry.com
APP_TIMEZONE=America/Chicago

DB_CONNECTION=mysql
DB_HOST=clm-shop-staging-db.c7gmy24ymfm4.us-east-2.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=curtlandry_shop
DB_USERNAME=admin
DB_PASSWORD=«rds master password»

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```
Save: **Ctrl+O**, Enter, **Ctrl+X**.

---

## 12. ☁️ SERVER — build schema, seed, assets  `[x]`

```bash
php artisan key:generate
php artisan migrate:fresh --seed      # builds all tables + default data in RDS (confirms DB wiring)
php artisan storage:link
php artisan optimize:clear

# Admin panel assets (Vite output public/themes/admin/default/build/ is git-ignored → build per env)
cd packages/Webkul/Admin && npm install && npm run build
cd /var/www/curtlandry-shop
```
> The seed creates default admin `admin@example.com` / `admin123`.
> ⚠️ If `storage:link` errors `Class "Redis" not found`, install `php8.3-redis` (see §7) and re-run.

---

## 13. Headless API — `bagisto/bagisto-api`  `[x]`

**Rule: the dependency goes through Git (local); the environment setup runs on the server.**

### 13a. 🖥️ LOCAL — add dependency + commit
```bash
cd /path/to/curtlandry-shop
git pull
composer require bagisto/bagisto-api          # updates composer.json + composer.lock (+ dev pkgs locally)
php artisan bagisto-api-platform:install       # publishes config/code (local optimize may OOM at 128M — harmless)
composer dump-autoload
git status
```
Commit **only** source/deps/config (leave generated files out):
```bash
git add composer.json composer.lock config/api-platform.php bootstrap/app.php \
        packages/Webkul/Core/src/Eloquent/TranslatableModel.php .gitignore
git commit -m "Add Bagisto headless API (bagisto/bagisto-api)"
git push origin main
```
**Do NOT commit** (generated/published — each env makes its own):
- `public/themes/*/build/` (Vite output)
- `public/themes/admin/default/assets/*` (GraphiQL playground: graphiql.min.*, react*.min.js, *-api.svg …)
- any `.DS_Store` (add `.DS_Store` to `.gitignore`)

### 13b. ☁️ SERVER — pull + install + key
```bash
cd /var/www/curtlandry-shop
git pull
git log --oneline -2                                     # confirm the commit landed
grep bagisto/bagisto-api composer.json                   # confirm dependency present
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader
php -d memory_limit=512M artisan bagisto-api-platform:install
php artisan bagisto-api:generate-key --name="CLM Storefront" --rate-limit=null   # SAVE the pk_storefront_... key
php artisan optimize
```
> ⚠️ Gotcha: `bagisto-api-platform:install` prints `✗ Installation failed: ... Database migrations failed` even though it's fine. Workaround — run the migration directly, then the key + optimize:
> ```bash
> php artisan migrate          # applies: cart_tokens, storefront_keys, admin_personal_access_tokens,
>                              #          align_admin_personal_access_tokens_fks, add_allowed_ips..., admin_api_audits
> php artisan bagisto-api:generate-key --name="CLM Storefront" --rate-limit=null
> php artisan optimize
> ```
> The install's other steps (assets published, provider in `bootstrap/providers.php`, api-platform assets linked, `config/api-platform.php`) complete normally.

**Storefront key generated (staging):** `«pk_storefront_...»` — sent as `X-STOREFRONT-KEY`. Rotate with:
```bash
php artisan bagisto-api:key:manage rotate --key="CLM Storefront"
```

---

## 14. ☁️ SERVER — runtime permissions  `[x]`

```bash
cd /var/www/curtlandry-shop
sudo chown -R www-data:www-data storage bootstrap/cache   # PHP-FPM (www-data) writes logs/cache/sessions/uploads
sudo chmod -R 2775 storage bootstrap/cache
sudo usermod -aG www-data ubuntu                          # let deploy user write too
newgrp www-data                                           # apply group to current shell (no re-login)
```

---

## 15. 🔵 Cloudflare — DNS  `[x]`

`curtlandry.com` → DNS → Records → **Add record** (twice), both **A / Proxied (orange) / Auto TTL**, → `18.225.253.203`:

| Type | Name | Content | Proxy |
|------|------|---------|-------|
| A | `shop-api-staging` | `18.225.253.203` | Proxied |
| A | `shop-staging` | `18.225.253.203` | Proxied |

---

## 16. 🔵/☁️ TLS + nginx (backend)  `[x]`

Zone SSL/TLS mode is **Full (strict)** (verified: SSL/TLS → Overview). Existing servers (DW/portal) use **Let's Encrypt** (verified via `curl --resolve api.curtlandry.com:443:18.221.3.22`). For this Cloudflare-proxied box we used a **Cloudflare Origin Certificate** (simplest, 15-yr, no renewals). Both approaches are equally valid & isolated to the one server.

### 16a. 🔵 Create Origin Certificate
Cloudflare → `curtlandry.com` → SSL/TLS → **Origin Server → Create Certificate**: generate key+CSR with Cloudflare, **RSA 2048**, hostnames `*.curtlandry.com` + `curtlandry.com`, validity **15 years**, format **PEM**. (Isolated — does NOT affect other subdomains; a cert is only presented by the server you install it on.)

### 16b. ☁️ SERVER — install cert
```bash
sudo mkdir -p /etc/ssl/cloudflare
sudo nano /etc/ssl/cloudflare/origin.pem   # paste Origin Certificate block
sudo nano /etc/ssl/cloudflare/origin.key   # paste Private Key block
sudo chmod 600 /etc/ssl/cloudflare/origin.key
sudo chmod 644 /etc/ssl/cloudflare/origin.pem
sudo openssl x509 -in /etc/ssl/cloudflare/origin.pem -noout -subject -issuer -dates
# issuer = CloudFlare Origin CA, valid ~15 years
```

### 16c. ☁️ SERVER — backend vhost
```bash
sudo tee /etc/nginx/sites-available/clm-shop-api > /dev/null <<'EOF'
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name shop-api-staging.curtlandry.com;

    root /var/www/curtlandry-shop/public;
    index index.php;

    ssl_certificate     /etc/ssl/cloudflare/origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/origin.key;

    client_max_body_size 50M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
server {
    listen 80;
    listen [::]:80;
    server_name shop-api-staging.curtlandry.com;
    return 301 https://$host$request_uri;
}
EOF

sudo ln -s /etc/nginx/sites-available/clm-shop-api /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 16d. Verify
```bash
curl -sI https://shop-api-staging.curtlandry.com/admin | head -n 5    # → HTTP/2 302 → /admin/login
```
Browser: `https://shop-api-staging.curtlandry.com/admin` → styled Bagisto login → log in `admin@example.com` / `admin123` → **change password immediately**. ✅ Verified working.

> Zone mode is already Full (strict) — do NOT change it.

---

## 17. 🔵 Cloudflare Access (team-only staging)  `[ ]`

**Why this way (no band-aid):** Bagisto has **no supported way to disable its Blade storefront** in headless mode — removing the `Webkul\Shop` module from `config/concord.php` breaks the module dependency chain (shared `shop` middleware, routes, customer menu) and forfeits clean upgrades. Bagisto's official guidance for headless is to **keep the full backend and separate concerns at the edge**. So we hide the Blade store + lock the admin with **Cloudflare Access**, touching zero Bagisto code (upgrade-safe).

**Scope guardrail:** Access changes nothing until an *application* points at a specific hostname. Every app below uses an explicit `shop-*-staging` subdomain — never a blank subdomain or `*.curtlandry.com` — so **no other subdomain** (DW `api.`, portal, apex) is affected. Verify afterwards in an incognito window.

**Traffic that MUST stay public on the backend host** (server-to-server + browser image loads; protected by the storefront key, not by Access):
- `/api/*` — GraphQL + REST (SSR calls `…/api/graphql`).
- `/storage/*` — product/category images (Next.js `images.unoptimized: true` ⇒ browser loads these directly).
- `/cache/*` — resized image cache (defensive).

### 17.0 One-time: enable Zero Trust
Cloudflare dashboard → **Zero Trust** → pick team name `curtlandry` → Free plan. **Settings → Authentication** → confirm **One-time PIN** is enabled (email 6-digit code; no IdP needed).

### 17.1 Reusable policy `CLM team`
Zero Trust → **Access → Policies → Add a policy**: name `CLM team`, Action **Allow**, Include → **Emails ending in** `@curtlandry.com`. (A policy is inert until attached to an app.)

### 17.2 Backend bypass apps (keep API + images public)
Create **three** Self-hosted apps (Access → Applications → Add → Self-hosted), each with a **Bypass / Everyone** policy:

| App name | Subdomain | Domain | Path |
|---|---|---|---|
| `shop-api bypass /api` | `shop-api-staging` | `curtlandry.com` | `api` |
| `shop-api bypass /storage` | `shop-api-staging` | `curtlandry.com` | `storage` |
| `shop-api bypass /cache` | `shop-api-staging` | `curtlandry.com` | `cache` |

### 17.3 Backend protected app (hides Blade store + locks admin)
Self-hosted → name `shop-api backend (admin + store)`, subdomain `shop-api-staging`, domain `curtlandry.com`, **Path: blank** → attach policy **`CLM team`**. Cloudflare matches the most specific path first, so `/api`,`/storage`,`/cache` hit the Bypass apps and everything else (`/`, `/admin`, all Blade routes) requires login.

### 17.4 Storefront protected app (staging = team-only)
Self-hosted → name `shop storefront (staging)`, subdomain `shop-staging`, domain `curtlandry.com`, **Path: blank** → attach policy **`CLM team`**.
> **At go-live:** delete THIS app only (opens the storefront to the public). The backend host stays gated forever.

### 17.5 Verify
- `https://shop-api-staging.curtlandry.com/` → Access login wall (not the Blade store). ✅
- `https://shop-api-staging.curtlandry.com/admin` → gated, then Bagisto admin. ✅
- `https://shop-staging.curtlandry.com` → gated; once in, **product images load** + pages render (proves `/storage` + `/api` bypass). ✅
- Incognito: other subdomains (`api.`, portal, apex) load with **no** login wall (proves scope). ✅

---

## 18. ☁️ SERVER — storefront deploy (Next.js)  `[x]`

> There is **no `.env.example`** in the repo. Authoritative env vars (from `grep -rhoE "process\.env\.[A-Z0-9_]+" src`): `NEXT_PUBLIC_BAGISTO_ENDPOINT`, `BAGISTO_STORE_DOMAIN` (**required** — build throws without it), `BAGISTO_STOREFRONT_KEY` (server-side, preferred over `NEXT_PUBLIC_...`), `NEXTAUTH_URL`, `NEXTAUTH_SECRET`, `COMPANY_NAME`, `NEXT_SERVER_MAGENTO_PROTOCOL`, `BAGISTO_REVALIDATION_SECRET`, `BAGISTO_SESSION`. Next/image `remotePatterns` are derived from `NEXT_PUBLIC_BAGISTO_ENDPOINT`.

```bash
cd /var/www/curtlandry-storefront
npm ci

NEXTAUTH_SECRET=$(openssl rand -base64 32)
REVAL_SECRET=$(openssl rand -base64 32)
cat > .env.local <<EOF
NEXT_PUBLIC_BAGISTO_ENDPOINT=https://shop-api-staging.curtlandry.com
BAGISTO_STORE_DOMAIN=shop-api-staging.curtlandry.com
BAGISTO_STOREFRONT_KEY=«pk_storefront_...»
NEXTAUTH_URL=https://shop-staging.curtlandry.com
NEXTAUTH_SECRET=$NEXTAUTH_SECRET
COMPANY_NAME=Curt Landry Ministries Shop
NEXT_SERVER_MAGENTO_PROTOCOL=https
BAGISTO_REVALIDATION_SECRET=$REVAL_SECRET
BAGISTO_SESSION=bagisto_session
EOF

npm run build      # prerender calls the backend GraphQL — backend FPM memory MUST be raised first (see §7)
pm2 start npm --name clm-storefront -- start
pm2 save
pm2 startup        # run the exact sudo line it prints (systemd auto-start on reboot)
```

### 18a. ☁️ Storefront nginx vhost
```bash
sudo tee /etc/nginx/sites-available/clm-storefront > /dev/null <<'EOF'
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name shop-staging.curtlandry.com;

    ssl_certificate     /etc/ssl/cloudflare/origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/origin.key;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_cache_bypass $http_upgrade;
    }
}
server {
    listen 80;
    listen [::]:80;
    server_name shop-staging.curtlandry.com;
    return 301 https://$host$request_uri;
}
EOF

sudo ln -s /etc/nginx/sites-available/clm-storefront /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
curl -sI https://shop-staging.curtlandry.com | head -5     # → HTTP/2 200
```
✅ Verified: storefront renders at `https://shop-staging.curtlandry.com`.

---

## 19. ☁️ SERVER — queue worker + scheduler  `[x]`

```bash
sudo tee /etc/systemd/system/clm-queue.service > /dev/null <<'EOF'
[Unit]
Description=CLM Bagisto queue worker
After=network.target redis-server.service

[Service]
User=www-data
Group=www-data
Restart=always
WorkingDirectory=/var/www/curtlandry-shop
ExecStart=/usr/bin/php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now clm-queue
sudo systemctl status clm-queue --no-pager | head -8      # → active (running)

( crontab -l 2>/dev/null; echo "* * * * * cd /var/www/curtlandry-shop && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -
crontab -l
```

---

## 20. Verification  `[ ]`

- `https://shop-api-staging.curtlandry.com/admin` → login → **change default admin password**.
- `https://shop-staging.curtlandry.com` → storefront renders with products.

---

## 21. Data migration — WooCommerce → Bagisto

### 21.0 Master plan — order, join keys & seamless strategy

**Goal:** migrate as seamlessly as possible — returning customers log in (seamless password) and still see their **past orders** and keep access to their **digital/downloadable purchases**.

**Core principle — migrate on stable natural keys so relationships survive:**
- **Products → by SKU** (the join key for order line items + downloads).
- **Customers → by email** (the join key for orders).
- Everything downstream re-links via these keys, so history stays attached to the right people/products.

**Execution order (must follow the dependency chain):**

| # | Step | Source (Woo/WP) | Join key | Status |
|---|------|-----------------|----------|--------|
| 21.1 | Catalog foundation — categories (auto), attributes (+ options), tax classes | Woo product CSV `Categories` column | name/slug | `[~]` categories auto |
| 21.2 | Products — simple/virtual/**downloadable** (SKU, price, stock, images, files). Full **Woo→Bagisto type map** + decommission of tickets/subs/bundles. | Woo product **CSV export** (Products → Export) | **SKU** | `[x]` core built |
| 21.3 | Customers (+ addresses, seamless passwords) | `wp_users` + `wp_usermeta` | **email** | `[x] built` |
| 21.4 | Orders — orders, line items, addresses, payment (purchase history) | `wp_wc_orders` + `wp_wc_order_addresses` + `wp_woocommerce_order_items` (HPOS) | order→email, item→SKU | `[x] local` |
| 21.5 | Digital access — re-grant download links for past digital purchases | built into 21.4 (`downloadable_link_purchased`) | SKU + email | `[x] built · verify on staging` |
| 21.6 | Extras (optional) — wishlists, reviews, coupons | `wp_usermeta`/`wp_comments`/`wp_posts` `shop_coupon` | email/SKU | `[ ]` |

**Notes / gotchas that shape the plan:**
- **SKU is mandatory** on every Woo product before export — any product without a SKU can't be re-linked to its orders/downloads. Audit + backfill missing SKUs in Woo first.
- **Order storage**: modern WooCommerce uses **HPOS** (`wp_wc_orders`, `wp_wc_order_addresses`, `wp_wc_order_operational_data`, `wp_woocommerce_order_items`/`..._itemmeta`); older stores use `wp_posts` (`post_type=shop_order`). Confirm which is active before writing the orders export.
- **Digital files**: Woo stores downloadable files in `wp-content/uploads` (often protected). Files must be copied into Bagisto storage; access is then granted per customer via Bagisto's downloadable-link-purchased records generated from their migrated orders.
- Each step gets its own idempotent `woo:import-*` artisan command mirroring the customer importer (dry-run, skip-existing, batched, direct DB inserts to avoid firing events/emails).

> **✅ STAGING RUN COMPLETE (2026-08-13)** — executed on `clm-shop-staging` (CSVs scp'd to `/home/ubuntu/migration/`, code deployed via git pull):
> 1. **Products:** dry-run matched local exactly (197 import / 375 unpub / 67 excl / 2 no-SKU / 46 deferred) → full import with `--include-bundles`: **205 products / 786 images / 111 re-hosted file links / 0 errors** → `catalog:finish-configurables`: **213 product rows** (2 configurables + 5 variants + Pressing Pause disabled). Large/S3-hosted media kept as **`url`-type links** (see Appendix A). Only failures: **4 Plumbline MP3s** (404 at source — list in `~/migration/products-import.log`; re-source or accept lost).
> 2. **Customers:** **26,284 + 13,868 addresses** (2 invalid skipped) — matches local.
> 3. **Orders:** **9,181 orders** (4,222 customer-linked / 4,959 guest), **15,654 items** (15,210 product-linked), **1,124 downloadable-access rows**, 10,210 failed skipped, **0 errors**. (Downloads 1,124 vs 1,195 dry-run projection = guest orders correctly get no per-customer access rows.)
>
> Storefront `shop-staging.curtlandry.com` confirmed serving the migrated catalog. **Pending verification:** one real-credential login on the Next.js storefront (`/customer/login`) → My Account → Orders + Downloadable Products (proves seamless password end-to-end).

---

### 21.1 Catalog foundation — categories, attributes, tax  `[~] categories auto-handled`
- **Categories**: auto-created by the product importer from the Woo **Categories** column (nested `Parent > Child` preserved), via the official `CategoryRepository`. No separate step needed; a `--dry-run` lists exactly which categories will be created.
- **Attributes + options** (size, color, language, …): only needed for **configurable** products → handled together with the configurable re-export (deferred, see §21.2).
- **Tax**: Woo tax classes (`standard`, `zero-rate`, …) → Bagisto tax categories. v1 importer leaves `tax_category_id` empty (products import untaxed). **TODO:** create Bagisto tax categories + map before go-live if any catalog items are taxable.

### 21.2 Products — full decommission map  `[x] built · [x] run LOCALLY · [x] run on STAGING (213 rows) · [ ] tickets/subs (parked)`

**Goal:** fully decommission WooCommerce — every Woo item gets a Bagisto-native or custom home. Verified against **Bagisto v2.4.3 (Apr 2026)**, which has seven native product types: simple, virtual, downloadable, grouped, **bundle**, configurable, **booking**.

**What's actually in the export** (`wc-product-export-*.csv`, 687 rows incl. variations): 312 published, 331 draft (`-1`), 44 pending. Donations are **already migrated off Woo → GiveWP** (Stripe/PayPal) and are out of scope — only a handful of stale donation/sponsorship product posts remain *published* in Woo and are skipped by category.

| Woo type / item | ~Count | Bagisto home | Status |
|---|---|---|---|
| `simple` | 288 | Simple | ✅ importer v1 |
| `simple, virtual` | 131 | Virtual | ✅ importer v1 |
| `simple, downloadable (+virtual)` | ~112 | Downloadable (files re-hosted) | ✅ importer v1 |
| `variable` + `variation` | 25 + 117 | Configurable | ✅ **resolved** — only 2 real (Shabbat Box, MAPA Hat) built in admin; 1 donation skipped; Pressing Pause → simple (see below) |
| `bundle (+virtual)` | 10 | Bundle (native) | 🔨 deferred (children first) |
| `external` | 2 | none native → simple + link, or recreate manually | 🔨 deferred |
| **Event Tickets / Registration** | 68 (+310 rows w/ ticket meta) | **Booking → Event sub-type** (native) | ⏸ parked (answer below) |
| **Legacy subscriptions** | 13 | custom Stripe-linked module (or official ext.) | ⏸ parked (answer below) |
| Donations / Name-Your-Price | 23 | **GiveWP** (already migrated) | ✅ out of scope |

**Join key: SKU** (must match Woo exactly so orders/downloads re-link in §21.4/§21.5).

---

**Importer (built):** `app/Console/Commands/ImportWooProducts.php` → `php artisan woo:import-products {file}`
- Uses the official **`ProductRepository` + type instances** (not raw DB), so `product_flat`, EAV values, inventories, images and the price/flat **indexers all run** exactly as from the admin panel (no band-aid).
- **Scope v1:** `simple`, `virtual`, `downloadable`. Idempotent (skips SKUs that already exist → safe to re-run).
- **Auto-skips (excluded):** unpublished (`Published != 1`); donations/NYP (`_nyp=yes` or donation categories: Donations, Tree Sponsorships, Memorial Olive Grove, Become a Covenant Partner); **event tickets by `Event Tickets`/`Event Registration` category only**. Real **subscriptions are excluded by product type** (`subscription`/`variable`), *not* by meta.
  - ⚠️ **Two meta signals are deliberately NOT used** — both are plugin noise sprayed onto ordinary products and caused false-positive exclusions of shofars/books/jewelry/tallits: FooEvents/Tribe (`WooCommerceEventsType`, `_tribe_wooticket_for_event`) and WooCommerce Subscriptions (`_subscription_price`, which holds the literal string `"no"` on non-subscription items).
- **Auto-defers (reports counts, doesn't import):** `variable`/`variation`, `bundle`, `external`, `grouped`.
- **Classification (local dry-run, 687 rows):** **197 import** · 375 unpublished · 67 excluded (61 event tickets/registration + 5 sponsorship/olive-tree + 1 NYP donation) · 46 deferred · 2 no-SKU. Deferred = **37 configurable** (4 published parents + 33 variants) + **8 bundle** + **1 subscription-type**.
- **Images:** downloaded from the Woo `Images` URLs and pushed through Bagisto's image pipeline (webp conversion + `product_images` rows).
- **Downloadable files:** downloaded from the `Download N URL` columns and **re-hosted** on Bagisto's `private` disk (so access survives WP shutdown); `Download limit = -1` → `0` (unlimited).
- **Categories:** created/matched from the `Categories` column (nested).
- **Stock:** uses Woo tracked `Stock` when present; otherwise `--default-qty` (1000) when the item is "in stock" so untracked products stay saleable.
- Options: `--dry-run` (classify + list categories to be created, write nothing), `--offset`, `--limit`, `--default-qty=`, `--skip-images`, `--skip-files`.

**Step 1 — Export from WooCommerce.** wp-admin → **Products → All Products → Export** → export **all columns / all products** to CSV (this is the file already provided). No SKU is needed on variations for v1 (they're deferred), but **every simple/downloadable product must have a SKU** or it's skipped (reported).

**Step 2 — Run LOCALLY first** (local `.env` → local MySQL `curtlandry_shop`, `APP_ENV=local`), then repeat the *same* commands on 🖥️ SERVER against RDS. **Always pass `php -d memory_limit=512M`** — webp image encoding blows the 128 MB default:
```bash
# 1) dry run — classification + which categories will be created (writes nothing)
php artisan woo:import-products "/path/to/wc-product-export.csv" --dry-run

# 2) small live smoke test to eyeball the result in admin
php -d memory_limit=512M artisan woo:import-products "/path/to/wc-product-export.csv" --limit=10

# 3a) LOCAL full run — images only, skip the large MP3/MP4 files
php -d memory_limit=512M artisan woo:import-products "/path/to/wc-product-export.csv" --skip-files

# 3b) SERVER/STAGING full run — EVERYTHING incl. downloadable files (re-hosted on private disk)
php -d memory_limit=512M artisan woo:import-products "/path/to/wc-product-export.csv"
```
Re-running is safe (existing SKUs are skipped), so you can resume after fixing data.

**✅ LOCAL RUN DONE (2026-07-29):** 197 products (102 simple / 92 downloadable / 3 virtual), `product_flat` 197, **761 images**, price indices 591 (×3 groups), inventory 197, 12 nested categories, **0 errors**. Only 2 products have no image (blank Woo `Images`). **Server/staging run = ALL (incl. files) — pending.**

**✅ BUNDLES DONE (2026-07-30):** the 8 published WC Product Bundles are **fixed-price** (`priced_individually=no`) → Bagisto's native bundle is additive (would misprice), so they're imported as **simple/virtual products at the fixed bundle price** with a "What's included" list appended to the description (`woo:import-products --include-bundles`; children resolved by SKU from the CSV). Catalog now **205**. Remaining deferred = **configurable only** (29 variation + 4 variable + 4 variation-virtual + 1 sub-virtual — blocked on the clean re-export). Orders re-run then back-filled **+22 bundle-only orders** (idempotent) → 9,082 orders.

**Step 3 — Verify the import (local).** Start the app, then eyeball it in the admin:
```bash
cd /Users/chuy/Desktop/CurtLandry/curtlandry-shop
php artisan serve --port=8004     # local .env → APP_URL http://localhost:8004
```
- **Admin:** `http://localhost:8004/admin/login` (admin `cmoreno@curtlandry.com`) → **Catalog → Products** (197, with price/stock/images; downloadables show the type badge) and **Catalog → Categories** (12, nested).
- **Blade storefront:** `http://localhost:8004/` — quick visual check of products/images (this is the store we later gate; the real customer UI is the headless Next.js app).
- Requires `php artisan storage:link` (already present) so `storage/product/*` images resolve.
- Spot-checks: 2 products intentionally have no image (blank Woo `Images`); MP3 teachings show the Woo price (~$0.50 — confirm intended); downloadable **files** are absent locally (ran `--skip-files`) — they attach on the server run.
- DB sanity (read-only): `SELECT type,COUNT(*) FROM products GROUP BY type;` and `SELECT COUNT(*) FROM product_flat;` (= 197).

---

**✅ Configurable (variable) products — RESOLVED via admin (2026-07-30).**
Re-export (`…30-7-2026…`, Variable + Variations, all cols, meta=Yes) fixed prices but confirmed the SKU/attribute gaps are **source data in Woo**, not the export. Of 25 variable parents only **4 are published**, and after review only **2 are real store products**:
- `CLM4000x0008` "Safe House" — **donation** → skip.
- `CLM2026x2653` **Shabbat Box** (Judaica) — clean: 3 variants Walnut/Ash/Black @ $168 (SKUs `CLM2026x2659/2657/2661`); new attribute `candle_holder_finish` auto-created.
- `CLM2024x0005` **MAPA Fairway Hat** (Apparel) — Color Red/Black @ $24.97/$19.97; variations had **no SKU** → auto-generated `CLM2024x0005-RED/-BLACK` (default `color` attribute; Red+Black options already existed).
- *Pressing Pause* (book) — no parent SKU / no variation rows / no price / no image in Woo → imported as a **SIMPLE, DISABLED** product (`CLM-PRESSING-PAUSE`, placeholder $16.99). **TODO in admin: set real price, add image, enable.**

**How built:** the Bagisto admin's configurable screen needs hundreds of UI clicks per product (browser automation stalled + can't upload images), so instead built via **`catalog:finish-configurables <csv>`** — a one-off command using the **official `ProductRepository` / `Configurable` type** (the exact code path the admin Save runs: `super_attributes` → auto-generated variants → per-variant SKU/price/qty, + index events, + image download). Not a band-aid.

**✅ CONFIGURABLES DONE (2026-07-30):** 2 configurables (Shabbat Box 3 variants + 4 imgs; MAPA 2 variants + 7 imgs) + Pressing Pause (simple, disabled) created; `product_flat` populated (parents price-NULL, variants priced), 0 errors. **Catalog now 213 product rows** (208 sellable parents + 5 variants). Orders re-run back-filled **+99 orders** referencing the new SKUs → **9,181 orders**. Only admin follow-up: finish Pressing Pause (price/image/enable). Full catalog migration is complete except the parked tickets/subscriptions.

**🔨 Bundles (10) & external (2) — deferred.**
- Bundles map to Bagisto's native **Bundle** type. The Woo `Bundled Items (JSON-encoded)` column references **child SKUs** — so bundles must be imported **after** their child simple products exist. Note: Woo bundles here are **fixed-price** (`priced_individually=no`); Bagisto bundle price is normally summed from items, so the importer will need to set item prices to 0 + a fixed price, or these 10 can be built by hand.
- External (2) are Infusionsoft "Menorah Partner" order-form links (partner/donation-adjacent) — recreate as simple products with an external link, or handle manually.

---

**⏸ Event Tickets — parked, but here's the answer.**
Native fit: **Bagisto Booking product, "Event" sub-type** — supports Location, Available From/To, and ticket tiers (General/VIP) with per-tier qty + price; v2.4.3 added Booking CSV import (`booking_options` column). **Headless caveat (important):** Bagisto blocks Booking products from the **GraphQL add-to-cart by design**, so the Next.js storefront can't sell tickets through the normal headless API out of the box. Path when we pick this up: use Bagisto Booking + a **custom GraphQL cart resolver** to unblock booking add-to-cart, or a fully custom ticket module. (No off-the-shelf Bagisto extension removes the headless-cart limitation — expect custom work.)

**⏸ Legacy subscriptions — parked, but here's the answer.**
These 13 are already **billing live in Stripe** (migrated from Authorize.net). **Rule: do not recreate or re-charge them.** Recommended: a **custom lightweight module** that imports the 13 as records **linked to their existing Stripe subscription IDs** and syncs status via **Stripe webhooks** (`invoice.paid`, `customer.subscription.updated/deleted`) so customers can view/cancel in Bagisto while Stripe keeps billing. Alternative: the official **Laravel eCommerce Recurring Payments & Subscription** extension ($199, Bagisto 2.4.x, Stripe+PayPal) — but it's built for *new* subs on the Blade store, so it still needs a custom Stripe-link import and headless wiring. Net: **custom is the cleaner fit** for these legacy subs.

### 21.3 Customers (+ addresses, seamless passwords)  `[x] built · [x] run LOCALLY · [x] run on STAGING (26,284)`

**✅ LOCAL RUN DONE (2026-07-30):** 26,284 customers + 13,868 addresses imported from `wp_users.csv` (2 rows skipped for invalid email). Password formats preserved verbatim: **phpass 20,918 · `$wp$` bcrypt 5,366** (all upgrade to bcrypt on first login). Local dataset now = 197 products + 26,284 customers, ready for §21.4 Orders.


**Source:** WooCommerce store on **WP Engine** (WordPress DB `wp_clmstore2024`, prefix `wp_`, ~26,286 customers).
**Goal:** import customers + billing/shipping addresses, and let every customer keep their **existing password** (no reset email).

**Design (no band-aid):**
- WordPress password hashes are **not convertible** to bcrypt. Instead we store each WP hash verbatim and **transparently upgrade on first login**:
  - `app/Auth/WordPressHasher.php` — verifies all legacy WP formats: `$wp$2y$` (WP 6.8+ bcrypt w/ SHA-384 pre-hash, key `wp-sha384`), `$P$`/`$H$` (phpass portable), and legacy 32-char MD5; native bcrypt falls through. `make()` always emits native bcrypt; `needsRehash()` returns true for any WP format.
  - `app/Auth/WordPressEloquentUserProvider.php` — after a successful `validateCredentials()`, if `needsRehash()` re-saves the password as Bagisto bcrypt. Hooked at the **provider** (not guard) because headless login uses **JWT → `Guard::once()`**, which validates but skips Laravel's built-in guard-level rehash. Covers session + JWT + admin paths.
  - `app/Providers/AppServiceProvider.php` registers `Auth::provider('wordpress', …)`; `config/auth.php` sets `providers.customers.driver = wordpress`. Admin guard untouched (stays native bcrypt).
- Verified against a **real WordPress phpass vector** (`test` → `$P$B55D6LjfHDkINU5wF.v2BuuzO0/XPk/`) plus generated `$wp$`/MD5/bcrypt vectors: 14/14 pass.

**Importer:** `app/Console/Commands/ImportWooCustomers.php` → `php artisan woo:import-customers`.
- Writes with **direct DB inserts** (query builder), intentionally bypassing model events so it does **not** fire 26k welcome emails and stays fast.
- Idempotent: skips emails that already exist (safe to re-run). Options: `--dry-run`, `--offset`, `--limit`, `--chunk=500`.
- Customer: name (falls back account→billing→email local-part), email, WP hash → `password`, group **general**, default channel, `status=1`, `is_verified=1`. `phone` is left on the **address** (customer `phone` is UNIQUE in Bagisto and Woo phones are often dup/blank).
- Addresses: billing (default) + shipping when present/distinct; `address1`+`address2` joined into the single `address` column; `address_type='customer'`.

**Step 1 — Export from WooCommerce.** WP Engine User Portal → Production → **phpMyAdmin** → select DB → **SQL** tab → run (then **Export → CSV**, header row on, "Replace NULL with" cleared):
```sql
SELECT
  u.ID AS wp_user_id, u.user_email AS email, u.user_pass AS password_hash, u.user_registered AS created_at,
  MAX(CASE WHEN m.meta_key='first_name' THEN m.meta_value END) AS first_name,
  MAX(CASE WHEN m.meta_key='last_name'  THEN m.meta_value END) AS last_name,
  MAX(CASE WHEN m.meta_key='billing_first_name' THEN m.meta_value END) AS billing_first_name,
  MAX(CASE WHEN m.meta_key='billing_last_name'  THEN m.meta_value END) AS billing_last_name,
  MAX(CASE WHEN m.meta_key='billing_company'    THEN m.meta_value END) AS billing_company,
  MAX(CASE WHEN m.meta_key='billing_address_1'  THEN m.meta_value END) AS billing_address_1,
  MAX(CASE WHEN m.meta_key='billing_address_2'  THEN m.meta_value END) AS billing_address_2,
  MAX(CASE WHEN m.meta_key='billing_city'       THEN m.meta_value END) AS billing_city,
  MAX(CASE WHEN m.meta_key='billing_state'      THEN m.meta_value END) AS billing_state,
  MAX(CASE WHEN m.meta_key='billing_postcode'   THEN m.meta_value END) AS billing_postcode,
  MAX(CASE WHEN m.meta_key='billing_country'    THEN m.meta_value END) AS billing_country,
  MAX(CASE WHEN m.meta_key='billing_phone'      THEN m.meta_value END) AS billing_phone,
  MAX(CASE WHEN m.meta_key='shipping_first_name' THEN m.meta_value END) AS shipping_first_name,
  MAX(CASE WHEN m.meta_key='shipping_last_name'  THEN m.meta_value END) AS shipping_last_name,
  MAX(CASE WHEN m.meta_key='shipping_company'    THEN m.meta_value END) AS shipping_company,
  MAX(CASE WHEN m.meta_key='shipping_address_1'  THEN m.meta_value END) AS shipping_address_1,
  MAX(CASE WHEN m.meta_key='shipping_address_2'  THEN m.meta_value END) AS shipping_address_2,
  MAX(CASE WHEN m.meta_key='shipping_city'       THEN m.meta_value END) AS shipping_city,
  MAX(CASE WHEN m.meta_key='shipping_state'      THEN m.meta_value END) AS shipping_state,
  MAX(CASE WHEN m.meta_key='shipping_postcode'   THEN m.meta_value END) AS shipping_postcode,
  MAX(CASE WHEN m.meta_key='shipping_country'    THEN m.meta_value END) AS shipping_country
FROM wp_users u
JOIN wp_usermeta m ON u.ID = m.user_id
WHERE u.ID IN (SELECT user_id FROM wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value LIKE '%customer%')
GROUP BY u.ID, u.user_email, u.user_pass, u.user_registered;
```
> Read-only. For zero load on production, run on a WP Engine **Staging** copy instead. Count check: same query with `SELECT COUNT(*)` wrapper on `wp_capabilities LIKE '%customer%'`.

**Step 2 — Deploy backend code** (commit/push; server auto-syncs), then on 🖥️ SERVER:
```bash
php artisan config:clear && php artisan optimize   # load the new 'wordpress' auth provider
```

**Step 3 — Test with known-password sample first** (`migration-test-sample.csv`, in repo root; 3 fake accounts, password `Test1234!`, one per format):
```bash
php artisan woo:import-customers migration-test-sample.csv --dry-run
php artisan woo:import-customers migration-test-sample.csv
```
Log in on `shop-staging.curtlandry.com` with each (`migration-test-wp@`, `migration-test-phpass@`, `migration-test-md5@curtlandry.com` / `Test1234!`) → all succeed, addresses show. Confirm upgrade (read-only):
```sql
SELECT email, LEFT(password,4) FROM customers WHERE email LIKE 'migration-test-%';  -- → $2y$ after login
```
Then delete the 3 test customers (admin or SQL).

**Step 4 — Full run** (copy `wp_users.csv` to server):
```bash
php artisan woo:import-customers /path/to/wp_users.csv --dry-run   # review counts
php artisan woo:import-customers /path/to/wp_users.csv             # ~26k
```

### 21.4 Orders — purchase history (orders, items, addresses, payment)  `[x] built · [x] run LOCALLY · [x] run on STAGING (9,181)`

**Store is on HPOS** (`woocommerce_custom_orders_table_enabled = yes`) → orders live in `wp_wc_orders` (+ `wp_wc_order_addresses`), items in `wp_woocommerce_order_items(+itemmeta)`.

**Export (two read-only CSVs from the Woo DB — see queries below):**
- `wc_orders.csv` — one row/order: number, status, currency, `total_amount`, `date_created_gmt`, `date_updated_gmt`, `customer_id`, `billing_email`, payment method/title, billing + shipping snapshot.
- `wc_order_items.csv` — one row/line: `order_id`, `item_name`, `product_id`, `variation_id`, **`sku` resolved** (variation `_sku` → product `_sku`), `qty`, `line_subtotal`, `line_total`, `line_tax`.

<details><summary><strong>Export SQL (HPOS) — run read-only in phpMyAdmin, export each result as CSV, replace NULL→empty</strong></summary>

```sql
-- wc_orders.csv
SELECT o.id AS order_id, o.status, o.currency, o.total_amount,
       o.date_created_gmt, o.date_updated_gmt, o.customer_id, o.billing_email,
       o.payment_method, o.payment_method_title,
       ba.first_name billing_first_name, ba.last_name billing_last_name, ba.company billing_company,
       ba.address_1 billing_address_1, ba.address_2 billing_address_2, ba.city billing_city,
       ba.state billing_state, ba.postcode billing_postcode, ba.country billing_country, ba.phone billing_phone,
       sa.first_name shipping_first_name, sa.last_name shipping_last_name, sa.company shipping_company,
       sa.address_1 shipping_address_1, sa.address_2 shipping_address_2, sa.city shipping_city,
       sa.state shipping_state, sa.postcode shipping_postcode, sa.country shipping_country
FROM wp_wc_orders o
LEFT JOIN wp_wc_order_addresses ba ON ba.order_id=o.id AND ba.address_type='billing'
LEFT JOIN wp_wc_order_addresses sa ON sa.order_id=o.id AND sa.address_type='shipping'
WHERE o.type='shop_order' AND o.status NOT IN ('trash','auto-draft','draft','checkout-draft')
ORDER BY o.id;

-- wc_order_items.csv (SKU = variation _sku, else product _sku)
SELECT li.order_id, li.order_item_id, li.item_name, li.product_id, li.variation_id,
       COALESCE(NULLIF(vsku.meta_value,''), psku.meta_value) AS sku,
       li.qty, li.line_subtotal, li.line_total, li.line_tax
FROM (
  SELECT oi.order_id, oi.order_item_id, oi.order_item_name AS item_name,
    MAX(CASE WHEN oim.meta_key='_product_id'    THEN oim.meta_value END) product_id,
    MAX(CASE WHEN oim.meta_key='_variation_id'  THEN oim.meta_value END) variation_id,
    MAX(CASE WHEN oim.meta_key='_qty'           THEN oim.meta_value END) qty,
    MAX(CASE WHEN oim.meta_key='_line_subtotal' THEN oim.meta_value END) line_subtotal,
    MAX(CASE WHEN oim.meta_key='_line_total'    THEN oim.meta_value END) line_total,
    MAX(CASE WHEN oim.meta_key='_line_tax'      THEN oim.meta_value END) line_tax
  FROM wp_woocommerce_order_items oi
  JOIN wp_woocommerce_order_itemmeta oim ON oim.order_item_id=oi.order_item_id
  WHERE oi.order_item_type='line_item'
  GROUP BY oi.order_item_id, oi.order_id, oi.order_item_name
) li
LEFT JOIN wp_postmeta vsku ON vsku.post_id=li.variation_id AND vsku.meta_key='_sku'
LEFT JOIN wp_postmeta psku ON psku.post_id=li.product_id  AND psku.meta_key='_sku'
ORDER BY li.order_id, li.order_item_id;
```
</details>

**Command:** `woo:import-orders --orders=<orders.csv> --items=<items.csv> [--dry-run] [--include-failed] [--limit=] [--offset=] [--skip-downloads]`

**Design (no band-aid — historical back-fill, NOT the live-checkout path):** `OrderRepository::create()` is the checkout path — it renumbers orders, **decrements stock**, and fires invoice/e-mail events. Wrong for a back-fill. Instead the command writes the exact rows checkout leaves behind, inside a per-order DB transaction: `orders` · `order_items` · `addresses` (`order_billing`/`order_shipping`) · `order_payment` · `downloadable_link_purchased`. **Original Woo order number is preserved** as `increment_id`. Idempotent by `increment_id` (safe to re-run — e.g. to back-link configurable/bundle lines once those SKUs land).

**Join keys:** order → customer **by email**; line → product **by SKU**. Unmatched lines (a donation added to a product order) are kept as **plain-text history** so totals reconcile.

**Scope decision (chosen — "A"): only orders that contain ≥1 product present in the migrated Bagisto catalog.** Everything else in Woo is donations / legacy subscriptions / no-SKU fees being decommissioned (GiveWP + Stripe). Of 217,735 Woo order rows: only ~101k have any line item, and only these map to real catalog products. **Failed orders are excluded** (never paid — see plan below).

**Woo status → Bagisto:** completed→`completed`, processing→`processing`, on-hold/pending→`pending`, cancelled→`canceled`, refunded→`closed`, failed→(excluded, or `canceled` with `--include-failed`). Legacy `-a`/`-a-a` suffixes (`wc-completed-a`, etc.) are stripped to their base status. Paid statuses (completed/processing/closed) get invoiced totals + `available` downloads.

> **Failed-order plan (the 10,210 `wc-failed*`, incl. 630 that contained a catalog product):** these never completed payment, so they are **not** purchase history and are excluded from Bagisto. The full source stays in the `wc_orders.csv` export, which is retained as the **cold-storage migration archive** for reference/disputes after Woo is decommissioned. If they are ever needed in-store, re-run with `--include-failed` (imported as `canceled`).

> **Guest orders:** ~54% of in-scope orders use a billing email with **no matching customer account** (genuine 10-yr guest checkouts). They import as **guest orders** (`is_guest=1`, `customer_id=NULL`) — visible in admin, preserving history, but not shown in any account and **no** digital-access rows (no customer to grant to). Open decision: optionally auto-create accounts for guest emails so buyers can claim history (would require a password-reset flow — no WP hash exists for guests).

> **⚠ Before go-live — increment_id sequencer:** imported orders reuse Woo numbers (up to ~317k) while Bagisto's sequencer for NEW orders starts low → future orders could collide on the unique `increment_id`. **Bump the order sequencer above `MAX(Woo order id)`** before opening the store (see Appendix A).

**✅ LOCAL RUN DONE (2026-07-30):** 9,060 orders (8,706 completed / 197 canceled / 105 closed / 49 processing / 3 pending), **15,524 line items** (14,437 linked to a product, 1,087 kept as text), 9,060 payment rows, 9,059 billing + 8,747 shipping addresses; **4,154 linked to a customer**, 4,906 guest; 10,210 failed skipped; **0 errors**; re-run = 9,060 skipped (idempotent ✓). Woo order numbers + dates preserved (spot-checked vs source). Digital-access rows = 0 locally **only because the local product run was `--skip-files`** (0 downloadable links to reference); dry-run estimates **~958** download rows on staging. Date span 2023-11 → 2026-07 is genuine (today's catalog products weren't sold before then).

### 21.5 Digital / downloadable access — purchased links  `[x] built into 21.4 · [ ] verify on staging`
- Handled **in the same pass** as §21.4: for each downloadable line of a **linked customer**, `woo:import-orders` creates a `downloadable_link_purchased` row per product link (status `available`, `download_bought = link.downloads × qty`) so customers keep access to past digital purchases.
- Requires **21.2 run with files** (`product_downloadable_links` populated) → so this only produces rows on **staging** (local skipped files). Dry-run projects ~958 rows / ~the downloadable buyers.
- **Verify on staging:** log in as a known downloadable buyer → *Account → Downloadable Products* shows the items; the file downloads.

### 21.6 Extras — wishlists, reviews, coupons (optional)  `[ ]`
- Wishlists (by email + SKU), product reviews (`wp_comments` type `review`), active coupons (`shop_coupon`). Nice-to-have; run after the core migration is verified.

## 22. Payments — Stripe + PayPal (headless)  `[ ] built · [ ] sandbox-tested · [ ] live`

**Decisions (2026-07-30):** (a) **reuse the existing Stripe + PayPal merchant accounts** already used by GiveWP → one payout/reporting home for the ministry; (b) **both Stripe (cards) + PayPal live at launch**; (c) payments are a **separate workstream from the data migration** — they do **not** block the staging data run; (d) **standard one-time checkout only — NO saved cards / NO vaulting** at launch.

> **⚠️ Critical clarification — customers are NOT "connected" to Stripe/PayPal.** Product purchases are **one-time payments**: the shopper enters a card (Stripe) or logs into their own PayPal **at checkout**, every time. There is **no per-customer gateway link** to build or migrate — this applies equally to migrated Woo customers and new shoppers. **Nothing to migrate on the payment side:** the old Woo store ran on **Authorize.net** (not Stripe), so no Stripe tokens ever existed; and stored cards are **not portable** across processors (PCI). The Stripe/PayPal accounts we reuse are the **ministry's merchant** accounts (receive money), never the customers'. A migrated customer simply logs in (password already works) and pays at checkout like anyone else. Because **saved cards are off** (decision d), there is **no `stripe_customer_id` on customer records** and no SetupIntent flow — can be added later as a pure convenience feature without any back-fill.

### 22.0 When
1. **Staging data run first** (products → customers → orders) — no payment dependency.
2. **Build + test payments on staging with SANDBOX/TEST keys** (Stripe test mode, PayPal sandbox). Prove the full checkout flow end-to-end here.
3. **Go-live cutover** = swap to **live keys** + repoint **production webhooks** at the live API host. That's the only payments delta between staging and prod. **Never test with live keys.**

### 22.1 Why this isn't "install a plugin"
The store is **headless** (Next.js storefront ↔ Bagisto GraphQL API). Stock Bagisto payment packages are built for the **Blade** checkout (server-rendered redirects) and won't work as-is. The payment UI must live in **Next.js** and talk to Stripe/PayPal directly, then hand the authorized/captured result to Bagisto. So each method = a **thin custom Bagisto payment method** (records txn id + status on the order; no card data touches our servers) + **Next.js front-end pieces** + a **webhook endpoint** for async reconciliation. This is the no-band-aid fit for headless.

### 22.2 Bagisto side (both methods)
- Register two custom payment methods under `config/paymentmethods.php` (e.g. `stripe_headless`, `paypal_headless`) — each is a minimal `Webkul\Payment\Payment\Payment` subclass whose only job is to be selectable at checkout and to **store the gateway transaction id + status** on the order (`order_payment.additional`).
- Order lands via the headless **`placeOrder`** GraphQL mutation with the chosen method **only after** the gateway authorizes/captures on the client. Order is created `pending`; the **webhook** flips it to `processing`/`completed` (or `canceled` on failure) — same status vocabulary the migration used.
- Reuse-account hygiene: since GiveWP already uses these accounts, tag every store transaction with **metadata `source=bagisto-store`** (Stripe `metadata`, PayPal `custom_id`) so store vs. donation revenue is separable in reporting, and use a **dedicated restricted key / REST app** for the store so GiveWP's credentials are untouched.

### 22.3 Stripe (cards)
- **Frontend (Next.js):** Stripe **Payment Element**. On checkout, call our API to create a **PaymentIntent** (amount = Bagisto cart grand total, currency USD, `metadata.source=bagisto-store`, `metadata.cart_id`), confirm client-side, then call `placeOrder` on success.
- **Server:** endpoint to create/confirm the PaymentIntent using the **store restricted secret key**; verify the amount server-side against the cart (never trust client amount).
- **Webhook** (`/webhooks/stripe`): handle `payment_intent.succeeded` → mark order paid/`processing`; `payment_intent.payment_failed` → `canceled`; `charge.refunded` → `closed`. **Verify signature** (`STRIPE_WEBHOOK_SECRET`); make handlers **idempotent** (Stripe retries). This is a **second, separate** webhook endpoint on the same Stripe account — GiveWP keeps its own; both coexist.
- **Keys** (`.env`): `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY` (restricted), `STRIPE_WEBHOOK_SECRET`. Test keys on staging → live keys at cutover.

### 22.4 PayPal
- **Frontend:** PayPal **JS SDK** smart buttons → create order → **capture**; on capture success call `placeOrder`.
- **Server:** create/capture via PayPal **Orders v2 REST** using a **store-specific REST app** (own client id/secret) under the same PayPal business account; verify capture amount server-side; set `custom_id=bagisto-store`.
- **Webhook** (`/webhooks/paypal`): `PAYMENT.CAPTURE.COMPLETED` → paid; `PAYMENT.CAPTURE.DENIED`/`DECLINED` → canceled; `PAYMENT.CAPTURE.REFUNDED` → closed. **Verify webhook signature**; idempotent handlers.
- **Keys** (`.env`): `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_MODE=sandbox|live`.

### 22.5 Cutover checklist (staging sandbox → production live)
- [ ] Swap `.env` test/sandbox keys → **live** keys (Stripe live restricted key; PayPal live REST app).
- [ ] Create **production webhook endpoints** in Stripe + PayPal dashboards pointing at the **live API host**; store the new signing secrets.
- [ ] `php artisan config:clear && php artisan optimize` so new keys load.
- [ ] Live **smoke test**: 1 real low-value card order + 1 real PayPal order → confirm order flips to `processing`, `order_payment.additional` has the txn id, refund path works, and the row is tagged `source=bagisto-store`.
- [ ] Confirm GiveWP donations/subscriptions **still process normally** (shared account, separate endpoints — verify no interference).

## 23. Integrations  `[ ]`
- [ ] Keap / DW sync (backend event listeners → existing DW API).
- [ ] QuickBooks (via DW).

## 24. Performance — admin/API speed  `[x] diagnosed · [x] OPcache tuned · [x] Octane LIVE (systemd + nginx, 2026-08-14)`

**Symptom (2026-08-13):** admin feels very slow. Measured on the box itself (no Cloudflare/network): warm TTFB for `/admin/login` ≈ **0.65s** under PHP-FPM.

**Diagnosis — measured, not guessed:**

| Layer | Result | Verdict |
|---|---|---|
| `artisan about` | production, debug OFF, config/routes/views cached, Redis for cache/session/queue | ✅ healthy |
| CPU load / credits | idle (0.03) | ✅ |
| OPcache (FPM) | was on stock defaults → tuned (below) | **no TTFB change → not the bottleneck** |
| Profilers (xdebug…) | none loaded | ✅ |
| Redis | local, ~0.3ms | ✅ |
| RDS | ~0.9ms/query (first 1.2ms) | ✅ |
| Composer autoloader | already optimized (13,987 classes) | ✅ |
| **Octane floor** (`/up`) | **6ms** | framework/server are FAST |
| Admin login via Octane, warm | **~0.5s** | ❌ cost = Bagisto's per-page admin render pipeline (CPU-bound) |
| Themed 404 | ~1.3s every hit | same pipeline, uncached |
| Blade storefront home | 6.9s first → 0.2s | response cache works (real storefront is Next.js anyway) |

**Conclusion:** infra + framework are healthy. ~**0.5s/page is Bagisto admin render CPU** on slow t3 cores; FPM adds ~0.15s boot on top. Octane alone ≈ 25% win on admin, bigger win for the GraphQL API + concurrency.

**Applied on staging:**
- **OPcache tuning (FPM)** → `/etc/php/8.3/fpm/conf.d/99-opcache-tuning.ini`: `opcache.enable=1 · memory_consumption=256 · interned_strings_buffer=32 · max_accelerated_files=30000 · validate_timestamps=0 · save_comments=1`. FPM no longer serves this vhost (see cutover below) but stays installed as the rollback path — reload it after deploys if you ever fall back.
- **Octane/RoadRunner completed** — was configured but the binary was never installed/running. `php artisan octane:install --server=roadrunner` → added `spiral/roadrunner-cli` + `spiral/roadrunner-http` to composer (synced to git), downloaded `rr` binary to project root (gitignored).
- **OPcache tuning (CLI)** → same directives **plus `opcache.enable_cli=1`** in `/etc/php/8.3/cli/conf.d/99-opcache-tuning.ini`. Critical: Octane workers run under PHP **CLI**, where OPcache is **off by default** — without this, Octane was *slower* than FPM (~1–2s warm) because every request recompiled all Blade/vendor bytecode. See Appendix A.
- **Octane as systemd service (2026-08-14)** → `/etc/systemd/system/clm-octane.service`: runs `octane:start --server=roadrunner --host=127.0.0.1 --port=8000 --workers=4 --max-requests=500` as `www-data`, `Restart=always`, enabled at boot. Manage with `sudo systemctl {status,restart} clm-octane`.
- **nginx cutover (2026-08-14)** → `clm-shop-api` 443 block: `location / { try_files $uri @octane; }` + `location @octane { proxy_pass http://127.0.0.1:8000; }` (Host/X-Forwarded-* headers, 120s read timeout). The `\.php$` fastcgi block is **gone**; nginx still serves static files directly. **Rollback:** `sudo cp /root/clm-shop-api.fpm.bak /etc/nginx/sites-enabled/clm-shop-api && sudo systemctl reload nginx` (FPM still running underneath).

**Measured result (idle box, warm, same 26 KB page, interleaved):** FPM ≈ **0.64s** → Octane ≈ **0.51–0.56s** TTFB (~20% per request; the saved ~0.13s is the per-request bootstrap). Remaining ~0.5s is Bagisto's admin render CPU → instance-type lever below. *Benchmarking note:* after any Octane restart the first ~4 hits are slow (each of the 4 workers fills its own OPcache) — only trust interleaved warm numbers on an idle box; an evening of "Octane is 2s?!" turned out to be exactly this plus worker recycling noise.

**Second pass (2026-08-14) — browser-perceived speed:**
- **PHP JIT tried & REMOVED (2026-08-14)** (`opcache.jit=1255` + `opcache.jit_buffer_size=128M` were appended to both tuning inis): **no measurable TTFB change** (0.50–0.57s fully warm, same as baseline) — Bagisto's render is thousands of short method calls, not hot loops. Then the tracing JIT started **silently segfaulting Octane workers** on framework-heavy pages (500 with 0-byte body, `worker stopped` in the journal, nothing in Laravel logs). Removed from both inis (`sed -i '/opcache.jit/d' …/cli/…99-opcache-tuning.ini …/fpm/…99-opcache-tuning.ini`) + Octane restart. **Do not re-enable.** See Appendix A.
- **HTTP/2 on nginx** (`listen 443 ssl http2`) — the admin loads dozens of assets per page; HTTP/2 multiplexes them over one connection instead of queueing.
- **Static asset cache headers** — new nginx location for `js/css/img/fonts`: `expires 30d` + `Cache-Control: public, immutable` (Vite fingerprints filenames, so immutable is safe). Repeat admin navigation stops re-downloading assets entirely. Deliberately excludes `.json` (Vite `manifest.json` must stay fresh).
- Note: neither HTTP/2 nor cache headers move the single-page curl TTFB — they cut the browser's *total page load* (asset fetches), which is most of the perceived slowness when clicking around the admin.

**Evaluated and skipped (2026 research, documented so we don't re-litigate):** Elasticsearch (213 products — MySQL search is fine), Full Page Cache/Varnish (accelerates the Blade storefront nobody uses; Next.js + Cloudflare own that layer), OPcache preloading (redundant under Octane, adds a deploy failure mode), Swoole (its win is coroutine I/O; our bottleneck is render CPU). Queue is already async (Redis + worker).

**Pending:**
- [ ] **Production instance type: m7i.large / c7i.large** (≈1.5–2× single-thread vs t3.large) → admin ≈0.25–0.3s/page. The biggest honest lever; zero code changes.
- [ ] Optional deep-cut: profile the admin layout hot spots (menu/ACL/translation payload) and cache per role/locale.
- [ ] `composer audit` — 21 advisories across 4 packages flagged during install; review before go-live.

---

## Appendix A — Gotchas & fixes (all encountered on staging)

| Symptom | Cause | Fix |
|---------|-------|-----|
| SSH to EC2 **hangs** | Instance had RDS SG `clm-shop-db-sg` (no port 22), or SSH rule ≠ current IP | Attach only `clm-shop-ec2-sg`; set SSH source to current `curl ifconfig.me` |
| `mysqladmin ... status` hangs | 3306 rule missing/points at laptop IP; or RDS not Available | Add 3306 inbound on `clm-shop-db-sg` sourced from `clm-shop-ec2-sg` |
| *"You may not specify a referenced group id for an existing IPv4 CIDR rule"* | Editing an IP rule into an SG rule | Delete the IP rule, **add a new** SG-sourced rule |
| `Class "Redis" not found` | Missing phpredis extension | `sudo apt install -y php8.3-redis` |
| `bagisto-api-platform:install` → "Database migrations failed" | Installer's over-strict migration check | Run `php artisan migrate` directly, then `generate-key` + `optimize` |
| `/api/graphql` → HTTP 500, nginx log `Allowed memory size of 134217728 exhausted in RedisStore.php` | PHP-**FPM** php.ini still at 128 MB (separate from CLI); GraphQL schema build needs more | `sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.3/fpm/php.ini && sudo systemctl restart php8.3-fpm` |
| Local `bagisto-api-platform:install` OOM (128 MB) | Mac CLI memory limit on optimize step | Harmless locally; server uses `php -d memory_limit=512M` |
| EC2 not in EIP associate dropdown | EIP not allocated yet / region mismatch | Allocate EIP first; match region top-right |
| Migrated customers can't log in (valid password rejected) | `config/auth.php` still cached with `eloquent` driver | `php artisan config:clear && php artisan optimize` after deploy so the `wordpress` provider loads |
| Migrated customer logs in but password never upgrades to `$2y$` | Rehash relied on guard-level hook; headless JWT uses `Guard::once()` which skips it | By design we rehash in `WordPressEloquentUserProvider::validateCredentials()` (covers JWT) — ensure `providers.customers.driver = wordpress` |
| Import fails on duplicate `phone` | Bagisto `customers.phone` is UNIQUE; Woo phones dup/blank | Importer keeps phone on the **address**, leaves customer `phone` null (already handled) |
| Product import wrongly **skips shofars/books/jewelry** as "event tickets" | FooEvents/Tribe meta (`WooCommerceEventsType`=`single/sequential`, `_tribe_wooticket_for_event`) is written to nearly every product — not a ticket signal | Detect tickets by **category only** (`Event Tickets`/`Event Registration`). Removed the meta checks (`ImportWooProducts::isExcluded`) |
| Product import wrongly **skips products as "subscriptions"** | `_subscription_price` holds the literal string `"no"` on ordinary products | Don't use that meta; exclude real subs by **product type** (`subscription`/`variable`). Fixed 70→187→**197 importable** after both meta fixes |
| Imported products show **empty in admin/storefront** (`product_flat` = 0) | Calling `ProductRepository::create/update` directly doesn't fire the index events; the flat/price/inventory indexers hang off `catalog.product.*.after` | Importer now `Event::dispatch('catalog.product.create.after' / 'update.after')` around the repo calls (as the admin does) |
| Product import **downloads 0 images, no errors** | `shop.curtlandry.com` sits behind a WAF/Cloudflare that 403s header-less requests; PHP `file_get_contents` sends no User-Agent (curl worked, PHP didn't) | Importer fetches media via Laravel `Http` with a browser **User-Agent** + retry, and now **logs** failed downloads instead of swallowing them |
| Product import **OOM** (`Allowed memory size 134217728 exhausted`) | webp image encoding needs >128 MB | Run with `php -d memory_limit=512M artisan woo:import-products …` |
| Product import **FATAL OOM on staging** (`Allowed memory size 536870912 exhausted … guzzle …Utils.php`, tried to alloc 2.5 GB) | Downloadable **files** were fetched with `Http::get()->body()`, which **buffers the whole file in memory**; several teaching **MP4s are 4–5 GB** on S3 (`clm-hod-mp4s`). A fatal OOM isn't catchable → the whole run dies mid-import | Importer now **streams to disk** (`Http::withOptions(['sink'=>…])`, constant memory), checks size via HEAD, and **does not re-host** files that are (a) on a **persistent host (S3/CloudFront)** or (b) larger than **`--max-file-mb` (default 300)** — those stay as **`url`-type** downloadable links to their existing home. `--rehost-remote` overrides. **⚠ Keep the `clm-hod-mp4s` S3 bucket alive + public** post-decommission, since those links point at it |
| A few legacy downloadable **files 404** at source (`curtlandry.com/wp-content/uploads/woocommerce_uploads/2016/…`) | Those specific old files were deleted/moved on WP (neighboring files fetch fine, so not a blanket block) | Importer keeps them as `url` links + logs each failure; **audit `grep "download error" products-import.log`** and re-locate or accept-as-lost. These die when WP is decommissioned unless re-sourced |
| Order import **excluded 0 failed** orders despite `--dry-run` scope | `failed` was mapped to `canceled` in the status map, then the "is failed?" check ran *after* mapping → never matched | `normaliseStatus()` returns the **raw Woo** status; failed-exclusion + Woo→Bagisto mapping happen separately (fixed in `ImportWooOrders`) |
| Order import digital-access rows = **0** locally | Local §21.2 run was `--skip-files` → `product_downloadable_links` empty, nothing to reference | Expected. Runs on **staging** where §21.2 is run with files; dry-run projects the count |
| **⚠ Future new orders collide on `increment_id`** (go-live) | Imported orders reuse Woo numbers (~up to 317k); Bagisto's sequencer for new orders starts at 1 → eventually hits an imported number (unique constraint) | **Before opening the store**, bump the order sequencer above `MAX(Woo id)`. Bagisto stores it in `core_config`-style sequence; simplest: create one throwaway order or set the sequence start > max, then verify a new checkout gets a fresh number |
| Admin dashboard + config pages **500** with `Class "simple" not found` (MorphTo), days after the order import "succeeded" | `order_items.product_type` is a **morph-class column** (must hold `Webkul\Product\Models\Product`); the importer wrote the product *type string* (`simple`/`downloadable`). Nothing crashed until the first admin page lazily loaded an imported item's `product` relation | Importer fixed to write `Product::class`; existing rows repaired with `DB::table('order_items')->whereNotNull('product_id')->update(['product_type' => 'Webkul\Product\Models\Product'])` (idempotent). Lesson: after direct-DB imports, smoke-test the admin **dashboard** too, not just the grids |
| **Octane slower than FPM** (~1–2s warm vs 0.65s) right after install | Octane workers run under PHP **CLI**, where `opcache.enable_cli=0` by default — our tuning ini only targeted `fpm/conf.d`, so every Octane request recompiled all Blade/vendor bytecode from scratch (bootstrap saved, bytecode cache lost = net loss) | Duplicate the tuning ini to `/etc/php/8.3/cli/conf.d/99-opcache-tuning.ini` **with `opcache.enable_cli=1`**, then restart Octane. Warm TTFB dropped to ~0.51s (§24) |
| Octane benchmarks wildly inconsistent (0.4s → 2s → 0.5s across sessions) | Per-worker OPcache: after any restart, the first ~N hits (N = worker count) each land on a cold worker; plus `--max-requests` recycling and concurrent jobs on the box | Only compare **interleaved warm** runs on an idle box, same URL, checking `size_download` matches — that's how the honest FPM 0.64s vs Octane 0.51s numbers were obtained (§24) |
| "Text file busy" when re-downloading the `rr` binary | Linux refuses to overwrite a binary that's currently executing (an Octane server was running) | Harmless if versions already match; otherwise stop `clm-octane`, re-run `./vendor/bin/rr get-binary`, start again |
| Payment-methods config page **500s with a 0-byte body**, `worker stopped` in Octane journal, nothing in Laravel logs | PHP tracing **JIT** (`opcache.jit=1255`) segfaults workers on framework-heavy code paths; it also bought us nothing (§24) | `sudo sed -i '/opcache.jit/d' /etc/php/8.3/{cli,fpm}/conf.d/99-opcache-tuning.ini && sudo systemctl restart clm-octane`. Don't re-enable |
| Payment-methods config page **hangs exactly 30s** then worker is killed (`worker stopped`); other config pages fine; local fine | `prettus/l5-repository` caches 6 core repos (stock Bagisto, `config/repository.php` → `cache.repositories`) and maintains `storage/framework/cache/repository-cache-keys.json`: the trait's `getCacheKey()` embeds the request's **full URL** in every key (so every distinct URL mints new keys forever — 14 MB in 2 weeks of testing) **and rewrites the entire JSON file on every lookup, hit or miss**. The payment page makes ~hundreds of config lookups → hundreds × 14 MB json_encode+write > 30s RoadRunner ceiling. Diagnosed via `pcntl_alarm` stack dump in tinker (strace's "repeated Redis GETs" were the cheap part in between) | `Webkul\Core\Eloquent\Repository::getCacheKey()` **overridden** (packages/Webkul/Core, git-tracked): drops the URL from the key (these repos' results depend only on query args) and only writes the key file when a key is genuinely new. Keys are now a small bounded set; `CleanCacheRepository` invalidation still works (it reads the same file). One-time on server: `rm storage/framework/cache/repository-cache-keys.json` (regenerates bounded). Lesson: this file growing again = red flag |

## Appendix B — Deploy (subsequent code changes)

> **RULE — one-way flow: LOCAL → git → SERVER.** Never run state-changing commands (`composer require`, `php artisan *:install`, `vendor:publish`, edits) **on the server** — that mutates `composer.json`/lock/config there and creates repo drift (happened once with `octane:install`, cost a cleanup — see §24). The server only ever runs: `git pull`, `composer install`, `migrate`, builds, cache reloads.

🖥️ LOCAL: commit + push. ☁️ SERVER:
```bash
cd /var/www/curtlandry-shop && git pull
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader   # if composer.lock changed
php artisan migrate                                                          # if new migrations
cd packages/Webkul/Admin && npm run build && cd /var/www/curtlandry-shop    # if admin assets changed
php artisan optimize
sudo systemctl restart clm-octane      # REQUIRED: Octane serves all traffic (§24) — holds old code in memory until restarted
# sudo systemctl reload php8.3-fpm     # only if rolled back to FPM (OPcache validate_timestamps=0 → reload needed)
# storefront:
cd /var/www/curtlandry-storefront && git pull && npm ci && npm run build && pm2 restart clm-storefront
```

---

## Production differences (when repeating for `shop.curtlandry.com`)

| Area | Staging | Production |
|------|---------|-----------|
| RDS | Single-AZ, db.t4g.small, 20 GB | Multi-AZ, larger class, longer backup retention |
| EC2 | single t3.large | right-size; consider splitting API vs. storefront nodes |
| Hostnames | `shop-staging` / `shop-api-staging` | `shop.curtlandry.com` + non-revealing API host |
| Cloudflare Access | gate everything (team only) | gate `/admin` only; storefront public |
| Secrets | self-managed `.env` | consider AWS Secrets Manager |
| Payments | sandbox | live keys |
| Deploy | manual `git pull` | CI/CD pipeline |

---

_Last updated: after **§21.2 products** work + classification hardening — full **Woo→Bagisto decommission map** verified against Bagisto v2.4.3 (7 native types incl. Booking + Bundle). Built `woo:import-products` (official `ProductRepository`/`CategoryRepository`; simple/virtual/downloadable; images + downloadable files re-hosted; auto-creates categories; idempotent by SKU). **Classification proven locally (dry-run): 197 import** / 375 unpublished / 67 excluded / 46 deferred / 2 no-SKU. Fixed two false-positive exclusions (FooEvents/Tribe meta + `_subscription_price="no"`) that had wrongly dropped ~120 real products — see Appendix A. **Decisions captured:** donations = GiveWP (only stale posts remain, skipped); event tickets → Bagisto **Booking/Event** (parked — headless GraphQL add-to-cart blocked → needs custom cart resolver); legacy subs → **custom Stripe-linked module** (parked — never re-charge). **Blocker:** configurable needs a clean Woo re-export (117 variations: 102 missing attr value, 48 missing SKU; only 4 published parents). §21.3 Customers **RUN + PROVEN LOCALLY** (26,284 customers + 13,868 addresses; phpass/`$wp$` hashes preserved). **§21.2 products RUN + PROVEN LOCALLY** (197 products / 761 images / 0 errors; fixed 4 issues: 2 false-positive exclusions + missing index events + WAF User-Agent + 512M memory). **§21.4 orders + §21.5 digital access BUILT + RUN LOCALLY** (`woo:import-orders`; HPOS export; **9,082** product-orders / 15,547 items / 0 errors; scope = orders w/ a catalog product, failed excluded w/ archive plan; guest orders kept as guest — decided; idempotent; digital-access rows create on staging). **§21.2 BUNDLES DONE** — 8 fixed-price WC bundles imported as **simple products** at the fixed price (`--include-bundles`); catalog **205**. **§21.2 CONFIGURABLES DONE (2026-07-30)** — built via `catalog:finish-configurables` (official `ProductRepository`/`Configurable` code path; admin UI automation abandoned as impractical): Shabbat Box (3 variants, new `candle_holder_finish` attr) + MAPA Hat (2 variants, existing `color`, auto-generated SKUs) + Pressing Pause (simple, **disabled** placeholder — finish price/image/enable in admin). Catalog now **213 rows** (208 parents + 5 variants); orders re-run back-filled **+99 → 9,181 orders**. **Catalog migration complete** (only parked = tickets/subscriptions). **NEXT:** run the whole pipeline on **staging** in dependency order — §21.2 products **with files** → §21.3 customers → §21.4/§21.5 orders. Still to BUILD (parked, custom): tickets → Booking, legacy subs module. Before go-live: **bump order increment_id sequencer** (Appendix A). **§22 payments STRATEGY WRITTEN (2026-07-30):** headless Stripe + PayPal, **reuse GiveWP's existing merchant accounts** (tagged `source=bagisto-store`), both live at launch; thin custom Bagisto payment methods + Next.js Payment Element / PayPal JS SDK + signed idempotent webhooks; build/test on staging with **sandbox keys**, flip to **live only at cutover** (separate workstream — does not block the staging data run). **✅ STAGING DATA RUN COMPLETE (2026-08-13)** — see §21.0 callout: 213 product rows / 26,284 customers / 9,181 orders / 1,124 download grants, 0 errors; only follow-ups = 4 Plumbline MP3s (404 at source), Pressing Pause admin finish, real-credential login test on Next.js. **§24 PERFORMANCE** diagnosed (admin ≈0.5s/page = Bagisto render CPU; framework floor 6ms via Octane) — OPcache tuned + RoadRunner installed. **✅ OCTANE LIVE (2026-08-14):** CLI OPcache ini added (`enable_cli=1` — without it Octane was *slower* than FPM, see Appendix A), `clm-octane` systemd service (www-data, :8000, 4 workers) enabled at boot, nginx `clm-shop-api` cut over to `proxy_pass` (FPM config backed up at `/root/clm-shop-api.fpm.bak` for rollback). Measured: warm admin TTFB **0.64s (FPM) → 0.51–0.56s (Octane)**. **⚠ Deploys now end with `sudo systemctl restart clm-octane`** (Appendix B updated). **Second pass (2026-08-14):** PHP JIT enabled (no measurable gain — kept, documented), nginx **HTTP/2** + **30-day immutable cache headers on static assets** (the browser-perceived win: admin stops re-downloading JS/CSS on every page). Evaluated + skipped with reasons: Elasticsearch, FPC/Varnish, preloading, Swoole (§24). **Pending: prod instance type m7i/c7i**. **PINNED:** staff Bagisto **admin accounts** (separate from customer logins — create in Admin → Settings → Users with same emails, fresh passwords; waiting on staff list). Then §23 integrations._
