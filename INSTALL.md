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

AlmaLinux 9 ships PHP 8.1 in its AppStream and that's too old. Our
`composer.lock` is pinned to PHP 8.4 (Filament 5 pulls in Symfony 8.x
which hard-requires 8.4). Pull modern PHP from **Remi**, modern
Firebird client from **EPEL**.

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

**After §2 finishes installing PHP, verify the version is actually
8.4** — `dnf module reset` doesn't always bump an already-installed
PHP, and `composer install` will fail with a long list of
"requires php >=8.4" errors if you end up on 8.3:

```bash
php -v       # must show "PHP 8.4.x"
```

If it shows 8.3 or older, force the upgrade:
```bash
sudo dnf module reset  -y php
sudo dnf module enable -y php:remi-8.4
sudo dnf distro-sync   -y
php -v       # confirm 8.4
```

## 2. Packages

One install for the lot. **Don't drop any of the `php-*` packages** —
`composer install` will fail with cryptic "missing extension" errors
later if you do. Skip `firebird-utils` / `firebird-devel` /
`php-firebird` only if this host won't run the ETL (only the box doing
`migrate:firebird` needs them).

```bash
sudo dnf install -y \
    php php-cli php-fpm php-common \
    php-mbstring php-xml php-intl php-bcmath php-gd php-zip php-curl \
    php-mysqlnd php-pdo php-opcache php-sodium \
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
- `php-zip` and `php-curl` are required by Filament's exporter
  (`openspout/openspout` → ext-zip) and Laravel's HTTP client (Guzzle
  → ext-curl). Composer refuses to install without them.
- `php-firebird` (Remi) → provides `pdo_firebird`. If your repo
  combination doesn't expose it, the fallback is PECL: `sudo pecl
  install pdo_firebird` after `firebird-devel` is installed.
- `php-mysqlnd` — the native MySQL/MariaDB driver, what Laravel uses.
- `policycoreutils-python-utils` gives you `semanage` for the SELinux
  steps later.

Confirm versions and required extensions:
```bash
php -v          # expect 8.4.x
composer --version || true   # may be missing; install next
mariadbd --version
nginx -v

# verify every required PHP extension is loaded — anything that prints
# means it's MISSING. Empty output = good.
for ext in bcmath ctype curl dom fileinfo filter gd iconv intl json \
           mbstring openssl pcre pdo pdo_mysql phar session simplexml \
           sodium tokenizer xml xmlwriter zip; do
    php -m | grep -qix "$ext" || echo "MISSING: $ext"
done
```

If anything prints `MISSING: foo`, install the matching package
(`sudo dnf install -y php-foo`) and re-run the loop until it's quiet
**before** moving on to §3.

## 3. Composer

Install to `/usr/bin/composer` rather than the conventional
`/usr/local/bin/composer` — AlmaLinux's sudo `secure_path` doesn't
include `/usr/local/bin`, so `sudo -u ekdosi-app composer ...`
(used everywhere below) would fail with "command not found":

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/bin/composer
sudo chmod 755 /usr/bin/composer
composer --version
```

If you prefer keeping composer under `/usr/local/bin`, either symlink
it (`sudo ln -s /usr/local/bin/composer /usr/bin/composer`) or extend
`secure_path` in a sudoers drop-in. The `/usr/bin` install is the
lowest-friction option for an unattended deploy.

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

### 5a. Create the system user

Create the system user the app will run under (referenced by every
later step that says `ekdosi-app`). Default home (`/home/ekdosi-app`)
is left in place so composer / npm have somewhere to write their
caches; the app code itself lives separately under `/var/www/ekdosi`:

```bash
sudo useradd -r -m -s /sbin/nologin ekdosi-app
```

### 5b. Give the system user a GitHub deploy key (private repo)

If the ekdosi repo is private, the HTTPS clone will block on a
username prompt. Use a per-server SSH deploy key:

```bash
sudo -u ekdosi-app mkdir -p /home/ekdosi-app/.ssh
sudo -u ekdosi-app chmod 700 /home/ekdosi-app/.ssh

# generate the deploy key (no passphrase — this key only reads the repo)
sudo -u ekdosi-app ssh-keygen -t ed25519 -N '' \
    -f /home/ekdosi-app/.ssh/id_ed25519 \
    -C "ekdosi-app@$(hostname)"

# trust github.com's host key so the clone doesn't prompt
sudo -u ekdosi-app sh -c 'ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> /home/ekdosi-app/.ssh/known_hosts'

# print the public key — paste this into the repo's
# Settings → Deploy keys → Add deploy key (read-only is fine)
sudo cat /home/ekdosi-app/.ssh/id_ed25519.pub
```

(If the repo is **public**, skip 5b and the clone in 5c can use the
`https://` URL — but for production deploys we want a key anyway so
nothing ever pauses for credentials.)

### 5c. Clone and install dependencies

```bash
sudo mkdir -p /var/www/ekdosi
sudo chown ekdosi-app:ekdosi-app /var/www/ekdosi

# Private repo (SSH, recommended):
sudo -u ekdosi-app git clone git@github.com:chrismfz/ekdosi.git /var/www/ekdosi
# OR Public repo (HTTPS):
# sudo -u ekdosi-app git clone https://github.com/chrismfz/ekdosi.git /var/www/ekdosi

cd /var/www/ekdosi

sudo -u ekdosi-app composer install --no-dev --optimize-autoloader --no-interaction
sudo -u ekdosi-app npm ci
sudo -u ekdosi-app npm run build
sudo -u ekdosi-app php artisan filament:assets   # publish Filament's CSS/JS/fonts to public/
```

If `composer` errors with a list of `requires php >=8.4` failures,
your PHP is too old — go back to the §1 verification block and force
the 8.4 upgrade.

> Filament's published assets (`public/css|js|fonts/filament/`) are
> git-ignored — they're regenerated by `filament:assets` on every clone
> and on every Filament upgrade. **If you skip this step, the admin
> panel loads with no styling.**

## 6. Configure `.env`

**This step is mandatory** — skipping it leaves the app on Laravel's
defaults (`APP_ENV=production`, `DB_CONNECTION=sqlite`), so the very
next step's `php artisan migrate` will silently create a
`database/database.sqlite` file and migrate *there* instead of into
your MariaDB. If you see Laravel prompting "Would you like to create
the SQLite database?", you skipped this step — quit out, do this
section, then redo §7.

```bash
sudo -u ekdosi-app cp .env.example .env
sudo -u ekdosi-app php artisan key:generate
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

All artisan commands below run as the app user. **Never run
`php artisan ...` as root** — every file artisan creates under
`storage/logs/` and `bootstrap/cache/` is then root-owned, and the
real app user (which php-fpm and the queue worker run as) can't
append to those files later. If you accidentally do, repair
ownership before moving on:

```bash
sudo chown -R ekdosi-app:ekdosi-app /var/www/ekdosi/storage /var/www/ekdosi/bootstrap/cache
```

**First, sanity-check that the app is actually pointed at MariaDB** —
if `.env` wasn't created in §6, Laravel falls back to SQLite by
default, and `migrate` will silently create `database/database.sqlite`
and migrate there instead:

```bash
sudo -u ekdosi-app php artisan db:show 2>&1 | head -3
# Expected first line: "MariaDB ............................. <version>"
# If you see "SQLite", go back to §6 — your .env is missing.
```

Then:

```bash
sudo -u ekdosi-app php artisan migrate --force      # --force confirms running in production
sudo -u ekdosi-app php artisan storage:link         # public/storage -> storage/app/public
sudo -u ekdosi-app php artisan config:cache
sudo -u ekdosi-app php artisan route:cache
sudo -u ekdosi-app php artisan view:cache
sudo -u ekdosi-app php artisan icons:cache          # blade-icons (Filament uses them heavily)
```

(After deploys, re-run `config:cache`, `route:cache`, `view:cache`,
`icons:cache` — they need to be rebuilt when `.env` or config files
change.)

## 7b. Create the first tenant + admin user

The app is **multi-tenant**: there's no usable login until at least one
`Company` row + one `User` row attached to it exist. The Filament panel
lives at `https://ekdosi.myip.gr/admin/{tenant-slug}/...` — `/admin`
alone redirects to the user's first tenant.

### Dev / staging — just seed it

The repo ships a seeder that creates three sample tenants (`myip`,
`nixpal`, `sample-ee`) and an `admin@ekdosi.local` user attached to
all three:

```bash
sudo -u ekdosi-app php artisan migrate:fresh --seed --force
```

Login: `admin@ekdosi.local` / `password`. **Never run this on a
production box** — the password is hard-coded and known.

### Production — tinker recipe

Create your real tenant and your first operator interactively:

```bash
sudo -u ekdosi-app php artisan tinker
```

```php
$company = \App\Models\Company::create([
    'name'              => 'My I.P.',
    'slug'              => 'myip',
    'country_code'      => 'GR',
    'einvoice_provider' => 'gr-mydata',
    'afm'               => '999999999',
    'tax_office'        => 'Athens',
    'mydata_production' => false,             // flip to true once myDATA creds are set
]);

$user = \App\Models\User::create([
    'name'              => 'Chris',
    'email'             => 'you@example.com',
    'password'          => bcrypt('REPLACE-WITH-STRONG-PASSWORD'),
    'email_verified_at' => now(),
]);

$user->companies()->attach($company->id);
exit
```

Then browse to `https://ekdosi.myip.gr/admin/login`, sign in, and you
should land on `/admin/myip` (the dashboard scoped to that tenant).

Add more operators by creating more `User` rows and attaching them to
the company. Add more tenants by creating more `Company` rows (each
tenant gets its own URL prefix from its slug). Per-tenant myDATA
credentials live on the `Company` row, not in `.env`.

## 8. Filesystem ownership + SELinux

php-fpm needs to read everything and write to two directories.

```bash
# ownership: app user owned, group readable+writable by web server
sudo chown -R ekdosi-app:nginx /var/www/ekdosi
sudo find /var/www/ekdosi -type d -exec chmod 755 {} \;
sudo find /var/www/ekdosi -type f -exec chmod 644 {} \;

# storage/ and bootstrap/cache/ — Laravel writes here
sudo chmod -R 775 /var/www/ekdosi/storage /var/www/ekdosi/bootstrap/cache

# restore executable bits stripped by the bulk `chmod 644` above
sudo chmod 755 /var/www/ekdosi/artisan
sudo find /var/www/ekdosi/vendor/bin -maxdepth 1 -type f -exec chmod 755 {} \;
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

### 12a. Install Firebird client + PDO driver + server

The PDO driver package name depends on which repo you pull it from
(both work):
- Remi (matches the `php:remi-8.4` module stream): `php-firebird`
- EPEL 10 (SCL-style versioned): `php8.4-pdo-firebird`

The Firebird **server** package is needed for ETLing against a local
`.fdb` sandbox (`--host=127.0.0.1`). Package name varies by EL
version:
- AlmaLinux 9: `firebird-superserver`
- AlmaLinux 10 / EPEL 10: just `firebird` (no `-server` suffix)

```bash
# PDO driver — try Remi first, EPEL as fallback
sudo dnf install -y php-firebird || sudo dnf install -y php8.4-pdo-firebird

# tools (gbak / isql-fb / fbsvcmgr) + the server, if you want local sandbox FB
sudo dnf install -y firebird-utils firebird-devel
sudo dnf install -y firebird-superserver 2>/dev/null || sudo dnf install -y firebird

# verify the PDO driver actually loaded:
php -m | grep -i firebird       # expect: pdo_firebird

# find the actual service unit name and start it
sudo systemctl list-unit-files | grep -i firebird
sudo systemctl enable --now firebird   # adjust if grep showed a different name
sudo ss -tlnp | grep 3050              # confirm FB is listening

# SYSDBA password handling varies by EL version + Firebird build:
# - EL9 / Firebird 3 installs may auto-generate /etc/firebird/SYSDBA.password
# - EL10 / Firebird 4 (current default) ships an EMPTY security DB and
#   the install does NOT seed SYSDBA. You bootstrap it yourself.
#
# Firebird 4's "Install incomplete" message you'll see if you try to
# connect over the network is a RED HERRING — the network connect is
# rejected outright (SQLSTATE 28000), it doesn't actually let you
# stay connected long enough to CREATE USER. The real path is
# EMBEDDED MODE: stop the daemon, isql-fb the security DB by file
# path (no host: prefix → embedded → no auth), CREATE USER, restart.
#
# Two prerequisites:
#  (a) Ownership: /var/lib/firebird/ must be firebird:firebird through
#      and through. If anything got root-owned (e.g. you ran isql-fb
#      as root by accident), embedded mode hits "Permission denied".
#  (b) Daemon stopped: embedded mode needs an exclusive file lock.

# Step 1 — repair ownership in case anything got stomped while debugging
sudo chown -R firebird:firebird /var/lib/firebird/

# Step 2 — locate the security DB (path varies by build)
sudo find /var/lib/firebird /opt/firebird -name 'security*.fdb' 2>/dev/null
# Typical:
#   /var/lib/firebird/secdb/security4.fdb   (EPEL 10 firebird-4)
#   /var/lib/firebird/system/security4.fdb  (some EL9 builds)
SECDB=/var/lib/firebird/secdb/security4.fdb   # adjust to what find returned

# Step 3 — stop daemon, bootstrap SYSDBA via embedded mode, restart
sudo systemctl stop firebird
sudo -u firebird /usr/bin/isql-fb "$SECDB" <<'SQL'
CREATE USER SYSDBA PASSWORD 'masterkey';
COMMIT;
QUIT;
SQL
# (If a SYSDBA row already exists with a different password, that
# CREATE will say "User already exists" — swap to ALTER USER instead.)
sudo systemctl start firebird

# Step 4 — smoke-test that SYSDBA/masterkey now works over the network
sudo -u firebird /usr/bin/isql-fb -user SYSDBA -password masterkey \
    localhost:employee <<<'QUIT;'
# Expect: clean exit, NO "Install incomplete" message.
```

If you ever need to RESET the SYSDBA password later, use SQL over a
working network connection (gsec is deprecated on Firebird 4):
```bash
sudo -u firebird /usr/bin/isql-fb -user SYSDBA -password <oldpass> \
    localhost:employee <<<"ALTER USER SYSDBA SET PASSWORD 'newpass'; COMMIT;"
```

If neither package is available in your repos, fall back to PECL:

```bash
sudo dnf install -y firebird-devel php-pear php-devel
sudo pecl install pdo_firebird
echo "extension=pdo_firebird.so" | sudo tee /etc/php.d/30-pdo_firebird.ini
sudo systemctl restart php-fpm
```

### 12b. Restore the legacy gbak + import one tenant

The Firebird server enforces `DatabaseAccess = Restrict
/var/lib/firebird/data` by default — meaning it will only open `.fdb`
files **under that directory**. If you point it at e.g. `/opt/foo.fdb`
you'll get `SQLSTATE[HY000] [335544831] Use of database at location
... is not allowed by server configuration`. Two fixes: put the
`.fdb` under `/var/lib/firebird/data/` (recommended), or edit
`/etc/firebird/firebird.conf` and add the directory to
`DatabaseAccess`.

```bash
# restore the legacy gbak into a path FB allows
sudo gbak -r /path/to/ekdosi.fbk /var/lib/firebird/data/ekdosi-sandbox.fdb \
    -user SYSDBA -password masterkey
sudo chown firebird:firebird /var/lib/firebird/data/ekdosi-sandbox.fdb

# import one tenant — host=127.0.0.1 talks to the local FB server
sudo -u ekdosi-app php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb=/var/lib/firebird/data/ekdosi-sandbox.fdb \
    --host=127.0.0.1 --fbuser=SYSDBA --fbpass=masterkey
```

### 12c. ETL against a remote Firebird (cutover day)

For the actual cutover, the `.fdb` lives on the legacy server, not
locally. `--fdb` is a path **on the remote server's filesystem**, not
on this box:

```bash
sudo -u ekdosi-app php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb="/opt/Data/ekdosi-myip.fdb" \
    --host=10.23.22.5 \
    --fbuser=EKDOSI --fbpass=ekdosi1234
```

If you see `Use of database at location ... is not allowed by server
configuration`, the **legacy server's** `firebird.conf` doesn't allow
opening that path — you'll need to either move the `.fdb` to a path
it does allow, or extend `DatabaseAccess` on the legacy box.

## 13. Re-deploy / update runbook

Save this as `/usr/local/bin/ekdosi-deploy.sh` on the server. All
artisan / composer / npm commands run as the `ekdosi-app` user;
only the systemd restarts need root.

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/ekdosi

sudo -u ekdosi-app php artisan down --render="errors::503" --retry=30

sudo -u ekdosi-app git fetch --tags origin
sudo -u ekdosi-app git checkout main
sudo -u ekdosi-app git pull --ff-only

sudo -u ekdosi-app composer install --no-dev --optimize-autoloader --no-interaction
sudo -u ekdosi-app npm ci
sudo -u ekdosi-app npm run build
sudo -u ekdosi-app php artisan filament:assets       # republish Filament's CSS/JS/fonts

sudo -u ekdosi-app php artisan migrate --force
sudo -u ekdosi-app php artisan config:cache
sudo -u ekdosi-app php artisan route:cache
sudo -u ekdosi-app php artisan view:cache
sudo -u ekdosi-app php artisan icons:cache
sudo -u ekdosi-app php artisan storage:link || true

systemctl restart php-fpm
systemctl restart ekdosi-queue

sudo -u ekdosi-app php artisan up
```

`chmod +x /usr/local/bin/ekdosi-deploy.sh`. Then deploys are
`sudo /usr/local/bin/ekdosi-deploy.sh`.

## 14. Verification checklist

After install:

- [ ] `curl -sI https://ekdosi.myip.gr/admin/login` returns 200 and the
      response body contains Filament's CSS/JS references (proves
      `filament:assets` ran).
- [ ] `curl -sI https://ekdosi.myip.gr/admin` returns 302 to
      `/admin/login` when unauthenticated (proves the tenant-aware
      middleware is wired).
- [ ] `sudo -u ekdosi-app php artisan about` shows: PHP 8.4, Laravel
      13, mariadb driver, cache/session/queue all on `database`,
      Filament v5.x, Shield 4.x.
- [ ] `sudo -u ekdosi-app php artisan migrate:status` shows all 25
      migrations as `Ran` (3 Laravel defaults + 19 ekdosi + 2 spatie
      + 1 country profile).
- [ ] `php -m | grep -E "bcmath|mbstring|pdo_mysql|intl|gd|zip|curl"`
      lists every one of them.
- [ ] `sudo -u ekdosi-app php artisan route:list | grep admin` shows
      `admin/{tenant:slug}` registered.
- [ ] `sudo systemctl status ekdosi-queue` is `active (running)`.
- [ ] `sudo crontab -u ekdosi-app -l` shows the scheduler entry.
- [ ] SELinux: `sudo ausearch -m AVC -ts recent | head` is empty after
      hitting the site for a minute.
- [ ] Sign in at `/admin/login` with the user you created in §7b and
      confirm you land on `/admin/{your-slug}` (the tenant dashboard).

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
