# ekdosi — install on a fresh AlmaLinux 9 (or 10) box

End-to-end runbook for standing the app up from an empty server.
Tested mental model: AlmaLinux 9.x, single host, one tenant served at
`ekdosi.myip.gr`. AlmaLinux 10 works the same way — flagged where the
package names differ.

Throughout, replace the placeholders:
- `ekdosi.myip.gr` → your real hostname.
- `/var/www/ekdosi` → wherever you decide to host the app.
- `ekdosi` / `ekdosi-dev` → the DB user and password you'll create
  (generate a real password for production; the dev one in
  `.env.example` is for local sandboxes only).

## 0. What you'll end up with

```
nginx (or httpd) :443  --->  php-fpm  --->  Laravel 13 app at /var/www/ekdosi
                                              |
                                              v
                                          MariaDB 10.11 (one DB: ekdosi)
                                          + Laravel queue worker (systemd)
                                          + Laravel scheduler (cron)
                                          + spatie/laravel-backup (cron, off-site)
```

Outbound HTTPS is needed for: myDATA AADE endpoints, WHMCS API on each
upstream billing host, and any S3-compatible backup target.

## 1. Base system + repositories

AlmaLinux 9 ships PHP 8.1 in its AppStream and that's too old for
Laravel 13 (needs 8.3+). Pull modern PHP from **Remi**, modern Firebird
client from **EPEL**.

```bash
# core toolchain
sudo dnf install -y epel-release dnf-plugins-core
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm   # use -10.rpm on AlmaLinux 10
sudo dnf config-manager --set-enabled crb                                     # CodeReady Builder; provides some -devel pkgs

# pick the Remi PHP 8.4 stream (resets any other php module enabled)
sudo dnf module reset -y php
sudo dnf module enable -y php:remi-8.4

sudo dnf update -y
```

## 2. Packages

One install for the lot. Skip `firebird-utils` / `firebird-devel` /
`php-firebird` if this host won't run the ETL (only the box doing
`migrate:firebird` needs them).

```bash
sudo dnf install -y \
    php php-cli php-fpm php-common \
    php-mbstring php-xml php-intl php-bcmath php-gd php-zip php-curl \
    php-mysqlnd php-pdo php-opcache php-soap php-sodium \
    mariadb-server mariadb \
    nginx \
    git curl unzip tar make gcc \
    nodejs npm \
    certbot python3-certbot-nginx \
    cronie firewalld policycoreutils-python-utils

# only on the host that will run the Firebird ETL:
sudo dnf install -y firebird-utils firebird-devel php-firebird
```

Notes:
- `php-bcmath` — wanted by Laravel for big-number / money math.
- `php-firebird` (Remi) → provides `pdo_firebird`. If your repo
  combination doesn't expose it, the fallback is PECL: `sudo pecl
  install pdo_firebird` after `firebird-devel` is installed.
- `php-mysqlnd` — the native MySQL/MariaDB driver, what Laravel uses.
- `policycoreutils-python-utils` gives you `semanage` for the SELinux
  steps later.

Confirm versions:
```bash
php -v          # expect 8.4.x
composer --version || true   # may be missing; install next
mariadbd --version
nginx -v
```

## 3. Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
composer --version
```

## 4. MariaDB — start, secure, create DB

```bash
sudo systemctl enable --now mariadb
sudo mariadb-secure-installation   # set root password, drop anon users, etc.
```

Create the app's DB + user (run as root, paste a real password):

```bash
sudo mariadb <<'SQL'
CREATE DATABASE ekdosi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ekdosi'@'localhost' IDENTIFIED BY 'REPLACE-WITH-STRONG-PASSWORD';
GRANT ALL PRIVILEGES ON ekdosi.* TO 'ekdosi'@'localhost';
FLUSH PRIVILEGES;
SQL
```

## 5. Pull the code

```bash
sudo mkdir -p /var/www/ekdosi
sudo chown $USER:$USER /var/www/ekdosi
cd /var/www
git clone https://github.com/chrismfz/ekdosi.git ekdosi
cd ekdosi

composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan filament:assets    # publish Filament's CSS/JS/fonts to public/
```

## 6. Configure `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set at least:

```ini
APP_NAME=ekdosi
APP_ENV=production
APP_URL=https://ekdosi.myip.gr
APP_DEBUG=false

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ekdosi
DB_USERNAME=ekdosi
DB_PASSWORD=REPLACE-WITH-STRONG-PASSWORD

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

# mail — adjust to whatever SMTP you use
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="invoices@ekdosi.myip.gr"
MAIL_FROM_NAME="${APP_NAME}"
```

Per-tenant secrets (myDATA AADE credentials, WHMCS API key, etc.)
live on the `companies` table, **not** in `.env`. `.env` only holds
single-process secrets like `APP_KEY` and DB creds.

## 7. Run migrations

```bash
php artisan migrate --force      # --force confirms running in production
php artisan storage:link         # public/storage -> storage/app/public
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

(After deploys, re-run `config:cache`, `route:cache`, `view:cache` —
they need to be rebuilt when `.env` or config files change.)

## 8. Filesystem ownership + SELinux

php-fpm needs to read everything and write to two directories.

```bash
# ownership: app user owned, group readable+writable by web server
sudo chown -R ekdosi-app:nginx /var/www/ekdosi
sudo find /var/www/ekdosi -type d -exec chmod 755 {} \;
sudo find /var/www/ekdosi -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/ekdosi/storage /var/www/ekdosi/bootstrap/cache
```

SELinux is **enforcing by default** on AlmaLinux — without these labels
nginx will get 502s and Laravel will log permission-denied on write:

```bash
# make storage/ and bootstrap/cache/ writable by httpd context
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/ekdosi/storage(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/ekdosi/bootstrap/cache(/.*)?"
sudo restorecon -Rv /var/www/ekdosi/storage /var/www/ekdosi/bootstrap/cache

# the rest stays as plain httpd_sys_content_t (readable, not writable)
sudo restorecon -Rv /var/www/ekdosi

# php-fpm needs to make outbound HTTPS calls (myDATA AADE, WHMCS, S3 backups)
sudo setsebool -P httpd_can_network_connect 1
# and connect to MariaDB if you ever move it off-host:
sudo setsebool -P httpd_can_network_connect_db 1
```

## 9. php-fpm

Default pool config (`/etc/php-fpm.d/www.conf`) is fine for one tenant.
Two things worth checking:

```ini
user = nginx          ; match the web server you're using (apache for httpd)
group = nginx
listen = /run/php-fpm/www.sock
listen.owner = nginx
listen.group = nginx
```

```bash
sudo systemctl enable --now php-fpm
```

## 10. Web server — nginx (recommended)

```bash
sudo mkdir -p /etc/nginx/conf.d
```

Create `/etc/nginx/conf.d/ekdosi.myip.gr.conf`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name ekdosi.myip.gr;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ekdosi.myip.gr;

    root /var/www/ekdosi/public;
    index index.php;

    # certbot will fill these in below
    # ssl_certificate     /etc/letsencrypt/live/ekdosi.myip.gr/fullchain.pem;
    # ssl_certificate_key /etc/letsencrypt/live/ekdosi.myip.gr/privkey.pem;

    client_max_body_size 32M;        # invoice PDFs / scanned attachments

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "no-referrer-when-downgrade";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_read_timeout 120s;     # myDATA submit can be slow
    }

    location ~ /\.(?!well-known) { deny all; }   # block dotfiles
    location ~ ^/(storage|bootstrap)/ { deny all; }   # safety net
}
```

Enable, open the firewall, get a cert:

```bash
sudo nginx -t && sudo systemctl enable --now nginx
sudo firewall-cmd --permanent --add-service={http,https} && sudo firewall-cmd --reload

# Let's Encrypt — point DNS at this host first
sudo certbot --nginx -d ekdosi.myip.gr --redirect --agree-tos -m you@example.com
```

### Apache alternative

If you prefer httpd, replace step 10 with:

```bash
sudo dnf install -y httpd mod_ssl
sudo systemctl enable --now httpd
```

Then `/etc/httpd/conf.d/ekdosi.myip.gr.conf`:

```apache
<VirtualHost *:80>
    ServerName ekdosi.myip.gr
    Redirect permanent / https://ekdosi.myip.gr/
</VirtualHost>

<VirtualHost *:443>
    ServerName ekdosi.myip.gr
    DocumentRoot /var/www/ekdosi/public

    SSLEngine on
    # SSLCertificateFile     /etc/letsencrypt/live/ekdosi.myip.gr/fullchain.pem
    # SSLCertificateKeyFile  /etc/letsencrypt/live/ekdosi.myip.gr/privkey.pem

    <Directory /var/www/ekdosi/public>
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php-fpm/www.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog  /var/log/httpd/ekdosi-error.log
    CustomLog /var/log/httpd/ekdosi-access.log combined
</VirtualHost>
```

Cert: `sudo certbot --apache -d ekdosi.myip.gr --redirect --agree-tos -m you@example.com`

If you go with apache, change `/etc/php-fpm.d/www.conf` user/group from
`nginx` → `apache` and `chown -R ekdosi-app:apache /var/www/ekdosi`.

## 11. Background jobs

### Scheduler (cron) — runs Laravel's `schedule:run` every minute

```bash
sudo crontab -u ekdosi-app -e
```

Add:

```cron
* * * * * cd /var/www/ekdosi && php artisan schedule:run >> /dev/null 2>&1
```

### Queue worker (systemd) — for WHMCS pulls, myDATA retries, mail

Create `/etc/systemd/system/ekdosi-queue.service`:

```ini
[Unit]
Description=ekdosi Laravel queue worker
After=network.target mariadb.service

[Service]
User=ekdosi-app
Group=nginx
Restart=always
RestartSec=5
WorkingDirectory=/var/www/ekdosi
ExecStart=/usr/bin/php artisan queue:work --queue=default --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ekdosi-queue
sudo systemctl status ekdosi-queue
```

(Future: when the WHMCS pull / myDATA-resend backlog warrants it,
switch `QUEUE_CONNECTION=redis` and add `redis` to the install list.
Database driver is fine for ~3 tenants.)

## 12. ETL host extras (Firebird → MariaDB import)

Only needed on whatever box is going to run
`php artisan migrate:firebird ...`. Can be the same host, can be a
one-off VM next to the legacy server.

```bash
sudo dnf install -y firebird-utils firebird-devel php-firebird
# verify the extension loaded:
php -m | grep -i firebird       # expect: pdo_firebird

# restore the legacy gbak into a sandbox .fdb you can iterate against:
gbak -r /path/to/ekdosi.fbk /var/lib/firebird/data/ekdosi-sandbox.fdb \
     -user SYSDBA -password masterkey

# import one tenant
php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb=/var/lib/firebird/data/ekdosi-sandbox.fdb \
    --host=127.0.0.1 --fbuser=SYSDBA --fbpass=masterkey
```

If `php-firebird` isn't in your Remi mirror, fall back to PECL:

```bash
sudo dnf install -y firebird-devel php-pear php-devel
sudo pecl install pdo_firebird
echo "extension=pdo_firebird.so" | sudo tee /etc/php.d/30-pdo_firebird.ini
sudo systemctl restart php-fpm
```

## 13. Re-deploy / update runbook

Save this as `/usr/local/bin/ekdosi-deploy.sh` on the server:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/ekdosi

php artisan down --render="errors::503" --retry=30

git fetch --tags origin
git checkout main
git pull --ff-only

composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
php artisan filament:assets       # republish Filament's CSS/JS/fonts

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan icons:cache
php artisan storage:link || true
sudo systemctl restart php-fpm
sudo systemctl restart ekdosi-queue

php artisan up
```

`chmod +x /usr/local/bin/ekdosi-deploy.sh`. Then deploys are
`sudo /usr/local/bin/ekdosi-deploy.sh`.

## 14. Verification checklist

After install:

- [ ] `curl -I https://ekdosi.myip.gr/` returns 200 (or a login redirect).
- [ ] `php artisan about` shows: PHP 8.4, Laravel 13, mariadb driver,
      cache/session/queue all on `database`.
- [ ] `php artisan migrate:status` shows all 22 migrations as `Ran`.
- [ ] `php -m | grep -E "bcmath|mbstring|pdo_mysql|intl|gd|zip|curl"`
      lists every one of them.
- [ ] `sudo systemctl status ekdosi-queue` is `active (running)`.
- [ ] `sudo crontab -u ekdosi-app -l` shows the scheduler entry.
- [ ] SELinux: `sudo ausearch -m AVC -ts recent | head` is empty after
      hitting the site for a minute.
- [ ] `php artisan tinker` →
      `\App\Models\User::factory()->create();` works (proves DB writes
      from the app user are landing).

## 15. Hardening (do before going live)

- `APP_DEBUG=false` (set above; double-check).
- Rotate the dev DB password.
- Add a per-tenant backup destination: `spatie/laravel-backup` config in
  `config/backup.php`, then schedule its commands in
  `app/Console/Kernel.php` (default: nightly DB dump + storage tarball
  to S3-compatible bucket).
- Restrict MariaDB to localhost: bind-address in
  `/etc/my.cnf.d/mariadb-server.cnf` already defaults to `127.0.0.1` on
  AlmaLinux; verify it didn't get changed.
- Enable fail2ban on sshd at minimum.
- Take a snapshot before the first ETL run against real data.

## 16. Notes specific to this project

- **WIN1253 source DBs**: the legacy Firebird DBs are encoded in
  WIN1253. The ETL connects with `charset=UTF8` so the FB client
  transliterates on read — no extra conversion step needed. If your
  FB client lib chokes, see the charset fallback in CLAUDE.md.
- **myDATA credentials**: per tenant, lived on the legacy
  `CONF_PARAMS` table. In the new app they live on `companies`
  (`mydata_aade_user_id`, `mydata_subscription_key`, etc.). The
  `firebed/aade-mydata` library reads them via its config.
- **WHMCS pull**: decided to be **API-based**, not shared-DB. Each
  tenant with WHMCS gets its WHMCS URL + identifier/secret stored on
  `companies`. The queue worker (step 11) drains the pull jobs.
- **Estonian tenant**: doesn't submit myDATA, but Estonia is moving to
  mandatory PEPPOL e-invoicing. The country profile on `companies`
  selects the right submitter (`gr-mydata`, `ee-peppol`, `none`).

For the full design discussion, see `CLAUDE.md`.
