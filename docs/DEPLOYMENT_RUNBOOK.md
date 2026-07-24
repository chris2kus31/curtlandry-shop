# Curt Landry Ministries Shop — Deployment Runbook

Headless commerce platform: **Bagisto 2.4 (Laravel 12) backend/API** + **Next.js storefront**, deployed on **AWS EC2 + RDS**, fronted by **Cloudflare**.

This runbook is the single source of truth for standing up an environment from scratch. It was written while building **staging**; the same steps produce **production** (see "Production differences" at the bottom for the deltas).

> Legend: `[x]` done on staging · `[ ]` not done yet · values in `«angle brackets»` are environment-specific.

---

## 1. Architecture overview

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
                            │                                           │
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

- **Backend** (`curtlandry-shop`, Bagisto): serves the admin panel + GraphQL/REST API. nginx → PHP-FPM.
- **Storefront** (`curtlandry-storefront`, Next.js): the customer-facing UI. nginx → PM2 (Node).
- **Database**: dedicated RDS MySQL instance (separate from the DW's RDS).
- **Cloudflare**: DNS, TLS (origin cert, Full strict), and team-only access to staging via Zero Trust Access.

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
| RDS security group | `clm-shop-db-sg` (`sg-04731922754ebab98`) |
| EC2 instance | `clm-shop-staging` (`i-0fe4d7f17b63da9eb`, t3.large) |
| EC2 security group | `clm-shop-ec2-sg` (`sg-0f6173b85645feba7`) |
| Elastic IP | `18.225.253.203` |
| Backend hostname | `shop-api-staging.curtlandry.com` |
| Storefront hostname | `shop-staging.curtlandry.com` |
| Backend path | `/var/www/curtlandry-shop` |
| Storefront path | `/var/www/curtlandry-storefront` |
| Storefront API key | `«pk_storefront_...»` (in `storefront_keys` table; rotate before go-live) |

> Secrets (RDS password, storefront key, NEXTAUTH_SECRET) live only in server `.env` files — **never committed**.

---

## 3. AWS RDS — create the database  `[x]`

RDS → Create database → **Standard create**:
- Engine **MySQL**, template **Dev/Test**, **Single-AZ** (staging).
- DB instance identifier: `clm-shop-staging-db`
- Master username `admin`, **Self managed** password (save it).
- Instance class **db.t4g.small**, storage **20 GiB gp3**.
- Connectivity: **same VPC as EC2** (Default VPC), **Public access = No**.
- **VPC security group → Create new → `clm-shop-db-sg`** (do NOT reuse the DW's `curtlandry-db-sg`).
- **Additional configuration → Initial database name: `curtlandry_shop`** ← creates the DB so no `CREATE DATABASE` is ever run.
- Performance Insights on (7-day free); Enhanced Monitoring off (staging).

---

## 4. AWS EC2 — launch the server  `[x]`

EC2 → Launch instance:
- Name `clm-shop-staging`, AMI **Ubuntu 24.04 LTS (x86)**, type **t3.large**, storage **40 GiB gp3**.
- Key pair: `curtlandry-api.pem` (reused).
- Network: **same Default VPC**, auto-assign public IP **enabled**.
- Security group **`clm-shop-ec2-sg`** — inbound: SSH 22 (my IP), HTTP 80 (anywhere), HTTPS 443 (anywhere); outbound: all traffic.
- Allocate an **Elastic IP** and associate it → `18.225.253.203`.

> Gotcha encountered: the instance initially got the RDS's `clm-shop-db-sg` attached (SSH hung). Fix: EC2 → Instance → Actions → Security → Change security groups → set **only `clm-shop-ec2-sg`**.

---

## 5. Wire EC2 → RDS  `[x]`

RDS security group `clm-shop-db-sg` → **Inbound rules**:
- Delete any IP-based rule, then **Add rule**: Type **MySQL/Aurora**, Port **3306**, Source = **security group `clm-shop-ec2-sg`**.

> Gotcha: AWS won't convert an existing CIDR rule to a security-group rule ("You may not specify a referenced group id for an existing IPv4 CIDR rule"). **Delete** the old rule and **add a new** one.

Test from the server:
```bash
mysqladmin -h clm-shop-staging-db.c7gmy24ymfm4.us-east-2.rds.amazonaws.com -u admin -p status
# expect: Uptime/Threads/Queries stats
```

---

## 6. Server stack install  `[x]`

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 + extensions (Bagisto 2.4 requires PHP 8.3/8.4)
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-intl php8.3-gd \
  php8.3-bcmath php8.3-zip php8.3-mbstring php8.3-curl php8.3-xml php8.3-soap php8.3-redis

# Composer 2, Node 22, PM2, nginx, Redis, MySQL client, git
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
sudo npm i -g pm2
sudo apt install -y nginx redis-server mysql-client git unzip

# Redis service + bigger CLI memory for Bagisto
sudo systemctl enable --now redis-server
sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /etc/php/8.3/cli/php.ini
```
Requirement check (all must pass): PHP 8.3.x, Composer ≥2.5, Node ≥22.13, nginx, extensions `calendar curl intl mbstring openssl pdo_mysql tokenizer gd bcmath zip redis`.

> Gotcha: `php8.3-redis` is required because `.env` uses `CACHE_STORE=redis`/`SESSION_DRIVER=redis` with the phpredis client. Without it: `Class "Redis" not found`.

---

## 7. App directory  `[x]`

```bash
sudo mkdir -p /var/www
sudo chown -R ubuntu:www-data /var/www
sudo chmod -R 2775 /var/www          # setgid → new files inherit www-data group
```

---

## 8. GitHub deploy keys (private repos)  `[x]`

Per-repo **read-only deploy keys** with SSH config aliases (one key per repo):
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

cat ~/.ssh/clm_shop.pub          # → GitHub repo curtlandry-shop → Settings → Deploy keys (read-only)
cat ~/.ssh/clm_storefront.pub    # → GitHub repo curtlandry-storefront → Deploy keys (read-only)

ssh -T git@github-shop           # expect "Hi chris2kus31/curtlandry-shop! ..."
ssh -T git@github-storefront
```

---

## 9. Backend deploy — Bagisto  `[x]`

```bash
git clone git@github-shop:chris2kus31/curtlandry-shop.git /var/www/curtlandry-shop
cd /var/www/curtlandry-shop
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader
cp .env.example .env
nano .env       # values below
```

`.env` key settings:
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

```bash
php artisan key:generate
php artisan migrate:fresh --seed      # builds schema + default data in RDS (default admin admin@example.com / admin123)
php artisan storage:link
php artisan optimize:clear

# Admin panel assets (Vite build output is git-ignored → build per environment)
cd packages/Webkul/Admin && npm install && npm run build
cd /var/www/curtlandry-shop
```

---

## 10. Headless API layer — `bagisto/bagisto-api`  `[x]`

**Dependency belongs in Git; environment setup runs on each server.**

**A. LOCAL (commit the dependency):**
```bash
composer require bagisto/bagisto-api
php artisan bagisto-api-platform:install   # publishes config/code
# commit ONLY: composer.json, composer.lock, config/api-platform.php,
#              bootstrap/app.php, packages/Webkul/Core/src/Eloquent/TranslatableModel.php, .gitignore
git commit -m "Add Bagisto headless API (bagisto/bagisto-api)" && git push origin main
```
> Do NOT commit generated/published files: `public/themes/*/build/`, `public/themes/admin/default/assets/*`, `.DS_Store`.

**B. SERVER (deploy):**
```bash
cd /var/www/curtlandry-shop
git pull
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader
php -d memory_limit=512M artisan bagisto-api-platform:install   # publishes assets + migrates RDS
php artisan bagisto-api:generate-key --name="CLM Storefront" --rate-limit=null   # SAVE the pk_storefront_... key
php artisan optimize
```
> Gotcha: `bagisto-api-platform:install` reports "Database migrations failed" even when migrations actually succeed. Workaround: run `php artisan migrate` directly (applies the 6 API tables: `cart_tokens`, `storefront_keys`, `admin_personal_access_tokens`, `admin_api_audits`, …), then proceed to `generate-key` + `optimize`. The install's other steps (assets/providers/config) complete fine.

---

## 11. Runtime permissions  `[x]`

```bash
cd /var/www/curtlandry-shop
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 2775 storage bootstrap/cache
sudo usermod -aG www-data ubuntu && newgrp www-data
```

---

## 12. DNS — Cloudflare  `[ ]` (in progress)

`curtlandry.com` → DNS → add two **A records**, **Proxied (orange)**, → Elastic IP `18.225.253.203`:
- `shop-api-staging`
- `shop-staging`

---

## 13. TLS + nginx  `[ ]`

1. Cloudflare → SSL/TLS → Origin Server → **Create Certificate** for `*.curtlandry.com`; save cert/key to the server:
   - `/etc/ssl/cloudflare/origin.pem`
   - `/etc/ssl/cloudflare/origin.key`
2. nginx vhosts (backend → PHP-FPM; storefront → PM2 proxy). *(config to be added when done)*
3. Cloudflare SSL/TLS mode → **Full (strict)**.

---

## 14. Cloudflare Access (team-only staging)  `[ ]`

Zero Trust → Access → Applications:
- `shop-staging.curtlandry.com` (all paths) → allow `@curtlandry.com`.
- `shop-api-staging.curtlandry.com` **path `/admin` only** → allow `@curtlandry.com`.
- ⚠️ Do NOT gate the whole API host — `/api/*` and `/graphql` must stay reachable for the storefront (protected by the storefront key).

---

## 15. Storefront deploy — Next.js  `[ ]`

```bash
git clone git@github-storefront:chris2kus31/curtlandry-storefront.git /var/www/curtlandry-storefront
cd /var/www/curtlandry-storefront
npm ci
# .env.local:
#   NEXT_PUBLIC_BAGISTO_ENDPOINT=https://shop-api-staging.curtlandry.com
#   NEXT_PUBLIC_BAGISTO_STOREFRONT_KEY=«pk_storefront_...»
#   NEXTAUTH_URL=https://shop-staging.curtlandry.com
#   NEXTAUTH_SECRET=«openssl rand -base64 32»
npm run build
pm2 start npm --name clm-storefront -- start
pm2 save && pm2 startup
```

---

## 16. Queue worker + scheduler  `[ ]`

- systemd service `clm-queue` → `php artisan queue:work redis --sleep=3 --tries=3`
- cron → `* * * * * cd /var/www/curtlandry-shop && php artisan schedule:run >/dev/null 2>&1`

---

## 17. Verification  `[ ]`

- `https://shop-api-staging.curtlandry.com/admin` → login → **change default admin password**.
- `https://shop-staging.curtlandry.com` → storefront renders with products.

---

## 18. Data migration — WooCommerce → Bagisto  `[ ]`

- [ ] Product export from Woo → transform to Bagisto import CSV → import.
- [ ] Customer migration (transparent password upgrade: Woo phpass → Bagisto bcrypt on first login).
- [ ] Category/media mapping.
- [ ] Order history (decision: migrate vs. keep read-only in Woo/DW).

## 19. Payments  `[ ]`

- [ ] Stripe (sandbox → live): keys in `.env`, webhook endpoints.
- [ ] PayPal (sandbox → live).

## 20. Integrations  `[ ]`

- [ ] Keap / DW sync of orders + customers (backend event listeners → existing DW API).
- [ ] QuickBooks path (via DW).

---

## Production differences (when we repeat this for `shop.curtlandry.com`)

| Area | Staging | Production |
|------|---------|-----------|
| RDS | Single-AZ, db.t4g.small, 20 GB | Multi-AZ, larger class, automated backups + longer retention |
| EC2 | single t3.large | right-size; consider separate nodes for API vs. storefront |
| Hostnames | `shop-staging` / `shop-api-staging` | `shop.curtlandry.com` (storefront) + a non-implementation-revealing API host |
| Cloudflare Access | gate everything (team only) | gate `/admin` only; storefront public |
| Secrets | self-managed in `.env` | consider AWS Secrets Manager |
| Payments | Stripe/PayPal sandbox | live keys |
| Deploy | manual `git pull` | CI/CD deploy pipeline |

---

_Last updated while completing section 12 (DNS). Update the `[ ]`/`[x]` markers and add nginx/vhost configs as each section is finished._
