# 10 · Deployment (production)

How to put the POS on a server for a live shop, keep it running, upgrade it and recover it.
Commands are for **Ubuntu 24.04 LTS** with nginx + PHP-FPM; adapt package names for other distributions.

> Before go-live read [11 · Production readiness](11-production-readiness.md) — it lists what was audited and the known limitations.

---

## 1. What runs in production

| Piece | Purpose | Required |
|---|---|---|
| **nginx** | HTTPS, serves `public/`, proxies WebSockets | yes |
| **PHP 8.4-FPM** | The Laravel app | yes |
| **MySQL 8.4** (or 8.0+) | All data | yes |
| **Cron → `php artisan schedule:run`** every minute | Daily alerts 21:45, nightly backup 23:30, loyalty expiry 00:30 | yes |
| **Reverb** (`php artisan reverb:start`, kept alive by Supervisor) | Live notifications over WebSockets | recommended — without it alerts still work, checked every minute |
| `mysqldump` (MySQL client) | Database backups | yes |
| SMTP account | Email alerts, "forgot password" emails | optional |
| Queue worker | Not needed — nothing is queued (notifications and pushes are sent immediately) | no |

**Where to host.** Billing needs the server to be reachable from every counter. Two good options:
- **Cloud VPS** (any provider, Mumbai region for low latency) — every branch connects over the internet. If a shop's internet drops, that counter can't bill until it's back (see limitations).
- **Server in the shop on the LAN** (a small PC/NUC) — counters keep billing when the internet is down; give it a UPS and copy backups off-site.

**Size.** One shop with a few counters: 2 vCPU, 4 GB RAM, 40 GB SSD is plenty. The demo data (~900 bills) runs every report in under 30 ms.

---

## 2. Install the server software

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server mysql-client supervisor git unzip curl \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-bcmath php8.4-intl \
  php8.4-gd php8.4-zip php8.4-curl php8.4-opcache
# (If php8.4 isn't in your Ubuntu release: sudo add-apt-repository ppa:ondrej/php)

# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
# Node 22 (only needed to build the frontend)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs

sudo mysql_secure_installation
```

Database and a dedicated MySQL user (never use `root` for the app):

```sql
CREATE DATABASE pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pos'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON pos.* TO 'pos'@'localhost';
-- mysqldump --single-transaction needs these too (backups):
GRANT PROCESS ON *.* TO 'pos'@'localhost';
FLUSH PRIVILEGES;
```

Set the server timezone to India so logs and cron times match the shop: `sudo timedatectl set-timezone Asia/Kolkata`.

---

## 3. Get the code and build

```bash
sudo mkdir -p /var/www/pos && sudo chown $USER:www-data /var/www/pos
git clone <your-repository-url> /var/www/pos
cd /var/www/pos

composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

### `.env` for production

Edit `.env` — the important values:

```dotenv
APP_NAME="Retail POS"
APP_ENV=production
APP_DEBUG=false                      # never true on a live server (shows secrets on errors)
APP_URL=https://pos.example.com
APP_TIMEZONE=Asia/Kolkata

LOG_CHANNEL=stack
LOG_STACK=daily                      # one log file per day
LOG_DAILY_DAYS=30
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pos
DB_USERNAME=pos
DB_PASSWORD=a-long-random-password

SESSION_DRIVER=database
SESSION_LIFETIME=480                 # minutes; a full shift
SESSION_SECURE_COOKIE=true           # cookies only over HTTPS
CACHE_STORE=database
QUEUE_CONNECTION=sync                # nothing is queued; keeps it simple

TRUSTED_PROXIES=127.0.0.1            # "*" only behind a managed load balancer / Cloudflare

# Live notifications (Reverb) — browsers connect through nginx on 443
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=123456                 # any number
REVERB_APP_KEY=<random 20+ chars>    # e.g. openssl rand -hex 16
REVERB_APP_SECRET=<random 20+ chars>
REVERB_HOST="pos.example.com"
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1         # Reverb itself listens locally; nginx forwards to it
REVERB_SERVER_PORT=8080
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Email (optional — alerts by email, password reset)
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="pos@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Protect it: `chmod 640 .env && sudo chown $USER:www-data .env`. Keep a copy of `.env` (especially `APP_KEY`) somewhere safe — without `APP_KEY` encrypted data and sessions can't be read.

### Build the frontend

The `VITE_REVERB_*` values are baked into the build, so build **after** `.env` is final:

```bash
npm ci
npm run build
```

### Create the shop (first install only)

```bash
php artisan pos:install
```

It asks for the business name, GST state and GSTIN, the first branch (name + code used in invoice numbers, e.g. `BR1`) and the first **Admin** (name, email, password — at least 8 characters with letters and numbers). It creates the tables, GST rates, clothing units and sizes/colours, the walk-in customer, customer groups, expense categories and the Counter 1 register — **no demo data**.
Scripted install: every answer has an option, see `php artisan pos:install --help`.

> ⚠️ Never run `php artisan db:seed` / `migrate:fresh --seed` on a live server: that is the **demo** data (users with the password `password`). It refuses to run when `APP_ENV=production`.

### Permissions and caches

```bash
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan storage:link        # (pos:install already did this)
php artisan optimize            # caches config, routes, events, views
```

After **any** change to `.env`, run `php artisan optimize` again (cached config ignores `.env`).

---

## 4. nginx + HTTPS

`/etc/nginx/sites-available/pos`:

```nginx
server {
    listen 80;
    server_name pos.example.com;
    root /var/www/pos/public;
    index index.php;

    client_max_body_size 10M;          # product images, CSV imports, bill photos

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 300;      # big report exports / backups
    }

    # Reverb: browser WebSocket connections (/app) and the server's publish API (/apps)
    location ~ ^/(app|apps)(/|$) {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:8080;
    }

    location ~ /\.(?!well-known).* { deny all; }   # .env, .git…
    location ~* \.(js|css|png|jpg|jpeg|webp|svg|woff2?)$ { expires 30d; access_log off; try_files $uri =404; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/pos /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

# Free HTTPS certificate (renews automatically)
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d pos.example.com --redirect
```

The app sends security headers itself (`X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, and HSTS over HTTPS).

PHP tuning (`/etc/php/8.4/fpm/php.ini`): `upload_max_filesize = 10M`, `post_max_size = 12M`, `memory_limit = 256M`, `opcache.enable=1`, `opcache.validate_timestamps=0` (then reload PHP-FPM after each deploy). Restart: `sudo systemctl restart php8.4-fpm`.

---

## 5. Background processes

### Cron (required)

```bash
sudo crontab -u www-data -e
```
```cron
* * * * * cd /var/www/pos && php artisan schedule:run >> /dev/null 2>&1
```

Check: `php artisan schedule:list` shows `pos:expire-points` 00:30, `pos:daily-alerts` 21:45, `pos:backup` 23:30.

### Reverb with Supervisor (live notifications)

`/etc/supervisor/conf.d/pos-reverb.conf`:

```ini
[program:pos-reverb]
command=php /var/www/pos/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/pos
user=www-data
autostart=true
autorestart=true
stopwaitsecs=10
stdout_logfile=/var/www/pos/storage/logs/reverb.log
stderr_logfile=/var/www/pos/storage/logs/reverb.log
minfds=10000
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

Check in the app: **Notifications → On this device** shows "Live", and **Send me a test alert** pops up instantly. If Reverb is down, nothing breaks — alerts are saved and the bell checks every minute.

---

## 6. Backups

- **Nightly** at 23:30 (`pos:backup`): gzip `mysqldump --single-transaction` (no locks during billing) into `storage/app/backups/`, older than *Settings → Backups → keep for N days* (default 14) are deleted. Admins also have **Back up now** and download on the Backups page. A failure sends a *Backup problems* alert.
- **Uploaded files** (logo, product images, expense bill photos) live in `storage/app/public` and `storage/app/private` — back these up too.
- **Copy off the server every day.** A backup on the same disk doesn't survive a disk failure, theft or ransomware. Example (another server or NAS reachable over SSH), as a root cron at 23:45:

```bash
rsync -a --delete /var/www/pos/storage/app/backups/ backup@nas:/backups/pos/db/
rsync -a /var/www/pos/storage/app/public/ /var/www/pos/storage/app/private/ backup@nas:/backups/pos/files/
```
(or `rclone` to Google Drive / S3 / Backblaze.)

### Restore (tested procedure)

```bash
php artisan down                                   # stop billing
gunzip -c pos-20261116-233000.sql.gz | mysql -u pos -p pos
rsync -a backup@nas:/backups/pos/files/ /var/www/pos/storage/app/   # if files were lost
php artisan optimize:clear && php artisan optimize
php artisan up
```

**Test a restore** into a scratch database once a month — an untested backup is a guess.

---

## 7. Upgrading to a new version

```bash
cd /var/www/pos
php artisan pos:backup                             # safety copy first
php artisan down --render="errors::503" --retry=60 # short maintenance window (outside billing hours)
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force   # adds new permissions / masters; safe to re-run
php artisan optimize
sudo systemctl reload php8.4-fpm
sudo supervisorctl restart pos-reverb
php artisan up
```

Then check the [changelog](09-changelog.md) for anything to configure. **Rollback:** `git checkout <previous tag>`, `composer install …`, `npm run build`, `php artisan migrate:rollback --force` (every migration has a working `down()`), or restore the backup taken before the upgrade.

---

## 8. Counters: PCs, printers, scanners

- **Browser:** Chrome or Edge, kept updated. One login per counter; cashiers switch with their PIN on the lock screen.
- **Barcode scanners:** USB/Bluetooth in *keyboard* mode with an Enter suffix. The POS keeps the search box focused.
- **Receipt printers (58/80 mm thermal):** install the vendor driver, set it as the **default printer**, paper size 80 mm (or 58 mm) × receipt, margins none. Set the receipt size under *Settings → POS*.
- **Print without the dialog:** start Chrome with `--kiosk-printing` (Windows shortcut: `chrome.exe --kiosk-printing https://pos.example.com/pos`). Receipts then go straight to the default printer.
- **Label printers:** labels print from *Barcode Labels* through the browser; set the label size in the printer driver.
- **Sounds:** the speaker button on the POS; browsers only play sound after the first click on the page.

---

## 9. Monitoring and logs

- **Health check:** `https://pos.example.com/up` returns 200 when the app and database respond — point an uptime monitor (UptimeRobot, Better Stack…) at it.
- **Logs:** `storage/logs/laravel-YYYY-MM-DD.log` (errors, failed pushes, failed backups), `storage/logs/reverb.log`, nginx `/var/log/nginx/error.log`.
- **Watch:** disk space (backups + logs), the nightly backup alert, the cash-difference alerts.
- **Audit log** (*Administration → Audit log*): logins, price/permission changes, cancellations, cash movements, payments, expenses, backups.
- Optional error tracking: Sentry or Flare for Laravel.

---

## 10. Security checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS with redirect, `SESSION_SECURE_COOKIE=true`
- [ ] Own MySQL user (not root), strong passwords, MySQL not reachable from the internet (`bind-address = 127.0.0.1`)
- [ ] Firewall: only 22, 80, 443 open (`sudo ufw allow OpenSSH; sudo ufw allow 'Nginx Full'; sudo ufw enable`); port 8080 stays internal
- [ ] SSH with keys only; automatic security updates (`unattended-upgrades`)
- [ ] `.env` readable only by the app user; never committed
- [ ] Only real staff accounts; each person their own login and PIN; deactivate leavers (*Users*)
- [ ] Admin role only for the owner; managers get *Manager*
- [ ] Off-site backups running and a restore tested
- [ ] GST rates, HSN codes and invoice settings reviewed with your accountant

---

## 11. Troubleshooting

| Symptom | Check |
|---|---|
| 500 error / blank page | `storage/logs/laravel-*.log`; permissions on `storage` and `bootstrap/cache`; after `.env` changes run `php artisan optimize` |
| "419 page expired" on every form | `APP_URL` matches the address in the browser; HTTPS + `SESSION_SECURE_COOKIE`; `TRUSTED_PROXIES` when behind a proxy |
| Bell says "checking every minute", never "live" | `supervisorctl status pos-reverb`; nginx `/app` proxy block; `VITE_REVERB_*` correct **and** `npm run build` re-run |
| No daily summary / backups | Cron line for `www-data`; `php artisan schedule:list`; `php artisan pos:backup` by hand to see the error |
| Backup fails: "mysqldump failed" | `mysql-client` installed; MySQL user has `PROCESS`; disk space |
| Receipt prints with margins / the dialog | Printer paper size & margins in the driver; Chrome `--kiosk-printing` |
| Logo / product images missing | `php artisan storage:link`; nginx serves `public/storage` |
| Times are wrong | `APP_TIMEZONE=Asia/Kolkata` and server timezone; `php artisan optimize` |

---

## 12. Go-live checklist

1. Server set up (sections 2–5), HTTPS working, `/up` is green.
2. `php artisan pos:install` done; Admin can log in.
3. *Settings → Business*: address, GSTIN, logo, invoice prefix/footer/terms, receipt size, alert limits, loyalty rules.
4. *Branches* and *Registers* for every shop/counter; *Users* for every staff member with role, branches and PIN.
5. *Catalog Setup*: check GST rates (garment 5 % ≤ ₹2,500, 18 % above — confirm with your CA), categories, brands, sizes/colours.
6. Products: create or import CSV; enter **opening stock** per branch (Stock adjustments, reason "count") so stock and cost are right from day one.
7. Customers/suppliers: import CSV with opening balances.
8. Counter PCs: printer default + paper size, `--kiosk-printing`, scanner test, one test bill → cancel it in the same shift.
9. Backups: run **Back up now**, download it, confirm the off-site copy, and do one test restore.
10. First day: open each register with the counted float; at closing, close with the counted cash and check the Z-report.
