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

Before anything else, turn off firewalld and SELinux. This box runs
behind a network firewall / cloud security group, so the OS-level
firewalld just gets in certbot's way; SELinux is enforcing by default
on EL10 and blocks both `php-fpm → mariadb tcp:3306` and the view-cache
writes under `storage/framework/views/`. Living with SELinux means
writing per-app policies; we opt out instead.

```bash
sudo systemctl disable --now firewalld

sudo setenforce 0                                                # immediate effect
sudo sed -i 's/^SELINUX=.*/SELINUX=disabled/' /etc/selinux/config # persist across reboots
```

Then the repos:

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

If it shows 8.3 or older, the simplest fix is to wipe every `php-*`
RPM and re-run §2's install line — `dnf distro-sync` won't always
bump packages that were pinned to the older module stream:

```bash
sudo dnf remove -y 'php-*'                       # nuke any 8.3-stream survivors
sudo dnf module reset  -y php
sudo dnf module enable -y php:remi-8.4
# now re-run the dnf install -y php php-cli ... line from §2
php -v       # confirm 8.4
```

> Order matters: if you `dnf install php-*` *before* doing the
> Remi repo + module-reset dance in §1, the stock 8.1/8.3 stream
> wins and you end up here. Do §1 fully first, then §2.

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
    cronie

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
include `/usr/local/bin`, so `sudo -u ekdosi composer ...`
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
later step that says `ekdosi`). Default home (`/home/ekdosi`)
is left in place so composer / npm have somewhere to write their
caches; the app code itself lives separately under `/var/www/ekdosi`:

```bash
sudo useradd -r -m -s /sbin/nologin ekdosi
```

### 5b. Give the system user a GitHub deploy key (private repo)

If the ekdosi repo is private, the HTTPS clone will block on a
username prompt. Use a per-server SSH deploy key:

```bash
sudo -u ekdosi mkdir -p /home/ekdosi/.ssh
sudo -u ekdosi chmod 700 /home/ekdosi/.ssh

# generate the deploy key (no passphrase — this key only reads the repo)
sudo -u ekdosi ssh-keygen -t ed25519 -N '' \
    -f /home/ekdosi/.ssh/id_ed25519 \
    -C "ekdosi@$(hostname)"

# trust github.com's host key so the clone doesn't prompt
sudo -u ekdosi sh -c 'ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> /home/ekdosi/.ssh/known_hosts'

# print the public key — paste this into the repo's
# Settings → Deploy keys → Add deploy key (read-only is fine)
sudo cat /home/ekdosi/.ssh/id_ed25519.pub
```

(If the repo is **public**, skip 5b and the clone in 5c can use the
`https://` URL — but for production deploys we want a key anyway so
nothing ever pauses for credentials.)

### 5c. Clone and install dependencies

```bash
sudo mkdir -p /var/www/ekdosi
sudo chown ekdosi:ekdosi /var/www/ekdosi

# Private repo (SSH, recommended):
sudo -u ekdosi git clone git@github.com:chrismfz/ekdosi.git /var/www/ekdosi
# OR Public repo (HTTPS):
# sudo -u ekdosi git clone https://github.com/chrismfz/ekdosi.git /var/www/ekdosi

cd /var/www/ekdosi

sudo -u ekdosi composer install --no-dev --optimize-autoloader --no-interaction
sudo -u ekdosi php artisan filament:assets   # publish Filament's CSS/JS/fonts to public/

# Vite asset build (only needed once we have custom CSS/JS in resources/).
# Skip on a fresh repo — no committed package-lock.json yet, and the
# Filament panel uses the pre-built assets that filament:assets just
# published. Run this block once we start writing custom frontend code:
#   sudo -u ekdosi npm install        # generates package-lock.json on first run
#   sudo -u ekdosi npm run build      # vite build -> public/build/
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
sudo -u ekdosi cp .env.example .env
sudo -u ekdosi php artisan key:generate
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
sudo chown -R ekdosi:ekdosi /var/www/ekdosi/storage /var/www/ekdosi/bootstrap/cache
```

**First, sanity-check that the app is actually pointed at MariaDB** —
if `.env` wasn't created in §6, Laravel falls back to SQLite by
default, and `migrate` will silently create `database/database.sqlite`
and migrate there instead:

```bash
sudo -u ekdosi php artisan db:show 2>&1 | head -3
# Expected first line: "MariaDB ............................. <version>"
# If you see "SQLite", go back to §6 — your .env is missing.
```

Then:

```bash
sudo -u ekdosi php artisan migrate --force      # --force confirms running in production
sudo -u ekdosi php artisan storage:link         # public/storage -> storage/app/public
sudo -u ekdosi php artisan config:cache
sudo -u ekdosi php artisan route:cache
sudo -u ekdosi php artisan view:cache
sudo -u ekdosi php artisan icons:cache          # blade-icons (Filament uses them heavily)
```

(After deploys, re-run `config:cache`, `route:cache`, `view:cache`,
`icons:cache` — they need to be rebuilt when `.env` or config files
change.)

## 7b. Create the first tenant + admin user

The app is **multi-tenant**: there's no usable login until at least one
`Company` row + one `User` row attached to it exist. The Filament panel
lives at `https://ekdosi.myip.gr/admin/{tenant-slug}/...` — `/admin`
alone redirects to the user's first tenant.

**Pick ONE of the three paths below — they're alternatives, not a
sequence.** The seeder and the production tinker recipe both create a
`myip` row; running both back-to-back hits a `slug_unique` violation
on the second.

### Dev / staging — just seed it

The repo ships a seeder that creates three sample tenants (`myip`,
`nixpal`, `sample-ee`) and an `admin@ekdosi.local` user attached to
all three:

```bash
sudo -u ekdosi php artisan migrate:fresh --seed --force
```

Login: `admin@ekdosi.local` / `password`. **Never run this on a
production box** — the password is hard-coded and known.

After this you have schema + an admin user + three empty tenants. If
you also want actual ekdosi data to play with (71 customers, 161
invoices, etc.), continue to §12 — the ETL fills the `myip` tenant
from the legacy `.fbk` shipped at
`legacy/ekdosi-main/db_backup/ekdosi.fbk`. The other two tenants
stay empty until you ETL them from their own `.fbk` files.

### Dev / staging but with YOUR real tenant values

If you seeded above but want real values on the `myip` tenant (real
AFM, real tax office, real myDATA creds later) instead of the
placeholder seed values, **update** the row rather than creating a
new one:

```bash
sudo -u ekdosi php artisan tinker
```
```php
\App\Models\Company::where('slug', 'myip')->update([
    'name'              => 'MyIP',
    'afm'               => '800561849',
    'tax_office'        => 'Xanthi',
    'country_code'      => 'GR',
    'einvoice_provider' => 'gr-mydata',
    'mydata_production' => false,
]);
exit
```

The §12 ETL preserves these values — it doesn't touch `companies`
rows it didn't create.

If you also want a personal login (in addition to the seeded
`admin@ekdosi.local`), do it in **one tinker session** — variables
don't persist between exits, so splitting the create+attach across
two `tinker` invocations leaves the new user with zero tenants
attached and the panel 404s after login:

```bash
sudo -u ekdosi php artisan tinker
```
```php
$company = \App\Models\Company::where('slug', 'myip')->first();
$user    = \App\Models\User::create([
    'name'              => 'Chris',
    'email'             => 'chris@myip.gr',
    'password'          => bcrypt('REPLACE-WITH-STRONG-PASSWORD'),
    'email_verified_at' => now(),
]);
$user->companies()->attach($company->id);
$user->companies()->pluck('slug');   // sanity: should print ["myip"]
exit
```

### Production — tinker recipe (no seed)

Skip the seeder entirely on a production box (so you don't end up
with the known-password admin or sample-ee throwaway tenant), then
create your real tenant and first operator interactively:

```bash
sudo -u ekdosi php artisan migrate --force        # schema only, no seed
sudo -u ekdosi php artisan tinker
```

```php
$company = \App\Models\Company::create([
    'name'              => 'My I.P.',
    'slug'              => 'myip',
    'country_code'      => 'GR',
    'einvoice_provider' => 'gr-mydata',
    'afm'               => '800561849',
    'tax_office'        => 'Xanthi',
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

## 8. Filesystem ownership

The dedicated `ekdosi` FPM pool (set up in §9) runs as the `ekdosi`
user, so file ownership stays single-user: `ekdosi:ekdosi`
throughout. nginx only ever serves static files (and even then,
PHP requests are proxied to the FPM socket), so it doesn't need
write access:

```bash
sudo chown -R ekdosi:ekdosi /var/www/ekdosi
sudo find /var/www/ekdosi -type d -exec chmod 755 {} \;
sudo find /var/www/ekdosi -type f -exec chmod 644 {} \;

# storage/ and bootstrap/cache/ — Laravel writes here at runtime
sudo chmod -R 775 /var/www/ekdosi/storage /var/www/ekdosi/bootstrap/cache

# restore executable bits stripped by the bulk `chmod 644` above
sudo chmod 755 /var/www/ekdosi/artisan
sudo find /var/www/ekdosi/vendor/bin -maxdepth 1 -type f -exec chmod 755 {} \;
```

## 9. php-fpm

Don't reuse the default `/etc/php-fpm.d/www.conf` pool — it runs as
`apache:apache` (a name the package ships even when no httpd is
installed) and you'd be fighting cross-user perms forever. Disable
it and add a dedicated pool that runs as the `ekdosi` user, listens
on a per-app socket, and lines up with everything else named
`ekdosi`:

```bash
# Disable the default pool so the only one left is ours
sudo mv /etc/php-fpm.d/www.conf /etc/php-fpm.d/www.conf.disabled

# Write the ekdosi pool
sudo tee /etc/php-fpm.d/ekdosi.conf >/dev/null <<'POOL'
[ekdosi]
user = ekdosi
group = ekdosi

listen = /run/php-fpm/ekdosi.sock
listen.owner = nginx
listen.group = nginx
listen.mode = 0660

pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500

; per-tenant secrets and APP_KEY come from .env, not the pool env
clear_env = no

php_admin_value[error_log] = /var/log/php-fpm/ekdosi-error.log
php_admin_flag[log_errors] = on
POOL

sudo systemctl enable --now php-fpm

# Confirm only the ekdosi pool is running, owned by the right user
ps -ef | grep php-fpm | grep -v grep
```

Expected `ps` output: one master as root, several `pool ekdosi`
workers as `ekdosi`. If you see workers running as `apache`, the
disable+restart in this section didn't take — re-run.

## 10. Web server — nginx (recommended)

### 10a. HTTP-only config first

Start with port 80 ONLY. **Don't write an HTTPS server block yet** —
nginx refuses to start if you reference `ssl_certificate` files that
don't exist, and certbot can't issue the cert without a running
nginx serving the `/.well-known/acme-challenge/` HTTP-01 path.
`certbot --nginx` rewrites this file in §10b to add the HTTPS block
and cert paths in one step.

Create `/etc/nginx/conf.d/ekdosi.myip.gr.conf`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name ekdosi.myip.gr;

    root /var/www/ekdosi/public;
    index index.php;

    client_max_body_size 32M;        # invoice PDFs / scanned attachments

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "no-referrer-when-downgrade";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/ekdosi.sock;
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

Start nginx (firewalld is off; if the box has a network firewall in
front, open ports 80 + 443 there):

```bash
sudo nginx -t && sudo systemctl enable --now nginx
```

Verify HTTP works (DNS must point at this host first):

```bash
curl -sI http://ekdosi.myip.gr/   # expect HTTP/1.1 200 (or 302 to login)
```

### 10b. Let certbot add HTTPS

`certbot --nginx` automatically:
- Serves the ACME challenge from the existing port-80 server block.
- Issues + installs the cert under `/etc/letsencrypt/live/...`.
- Edits `/etc/nginx/conf.d/ekdosi.myip.gr.conf` to add the `listen 443 ssl;` block, the `ssl_certificate` paths, and an HTTP→HTTPS redirect on port 80.
- Reloads nginx.

```bash
sudo certbot --nginx -d ekdosi.myip.gr -n --redirect --agree-tos -m you@example.com
```

If certbot prints "Some challenges have failed", the most common
causes are: (1) DNS for `ekdosi.myip.gr` doesn't resolve to this
host yet — `dig +short ekdosi.myip.gr` should return your public
IP; (2) port 80 is firewalled off by the cloud provider (open it in
the security group, not just firewalld); (3) something else is
already listening on port 80 — `sudo ss -tlnp | grep :80`.

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
        SetHandler "proxy:unix:/run/php-fpm/ekdosi.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog  /var/log/httpd/ekdosi-error.log
    CustomLog /var/log/httpd/ekdosi-access.log combined
</VirtualHost>
```

Cert: `sudo certbot --apache -d ekdosi.myip.gr --redirect --agree-tos -m you@example.com`

If you go with apache, change `listen.owner` / `listen.group` in
`/etc/php-fpm.d/ekdosi.conf` from `nginx` to `apache` so httpd can
talk to the socket. The pool itself still runs as `ekdosi:ekdosi`.

## 11. Background jobs

### Scheduler (cron) — runs Laravel's `schedule:run` every minute

```bash
sudo crontab -u ekdosi -e
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
User=ekdosi
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

### 12a. Install Firebird (client tools + PDO driver + server)

Package names differ between EL9 and EL10. The `||` falls back to
whatever's actually in your repos:

```bash
# PDO driver for PHP (one of these will exist)
sudo dnf install -y php-firebird || sudo dnf install -y php8.4-pdo-firebird

# Client tools (gbak / isql-fb / fbsvcmgr) + server
sudo dnf install -y firebird-utils firebird-devel
sudo dnf install -y firebird-superserver 2>/dev/null || sudo dnf install -y firebird

# Verify the PHP driver loaded and find the service unit name
php -m | grep -i firebird                       # expect: pdo_firebird
sudo systemctl list-unit-files | grep -i firebird

# Start FB (use the unit name the grep above printed)
sudo systemctl enable --now firebird
sudo ss -tlnp | grep 3050                       # confirm FB is listening
```

If neither PDO package is in your repos, fall back to PECL:

```bash
sudo dnf install -y firebird-devel php-pear php-devel
sudo pecl install pdo_firebird
echo "extension=pdo_firebird.so" | sudo tee /etc/php.d/30-pdo_firebird.ini
sudo systemctl restart php-fpm
```

### 12b. Bootstrap the SYSDBA password (Firebird 4 / EL10)

EPEL 10's `firebird` package ships an empty `security4.fdb` — no
SYSDBA user exists yet, so every network-auth path is locked out.
Set the password via **embedded mode** (daemon stopped, security
DB opened directly by file path, auth layer bypassed). This is the
only path that works on a virgin install:

```bash
# (1) Make sure /var/lib/firebird/ is fully owned by the firebird user
sudo chown -R firebird:firebird /var/lib/firebird/

# (2) Stop the daemon — embedded mode needs an exclusive file lock
sudo systemctl stop firebird

# (3) Bootstrap SYSDBA via embedded mode. -user SYSDBA is required so
#     the session has the privilege to create the PLG$SRP backing
#     table on the first user write.
sudo -u firebird /usr/bin/isql-fb -user SYSDBA \
    /var/lib/firebird/secdb/security4.fdb <<'SQL'
CREATE USER SYSDBA PASSWORD 'masterkey';
COMMIT;
QUIT;
SQL

# (4) Restart the daemon
sudo systemctl start firebird
```

If you see no output from step (3) — that's success. Any
`Statement failed` line means something's wrong; check that the
chown ran (1) and the daemon really stopped (2).

### 12c. Restore the bundled `.fbk` (sandbox)

The repo ships a `.fbk` backup at
`legacy/ekdosi-main/db_backup/ekdosi.fbk`. Restore it via `gbak -r`
— **don't copy a `.fdb` directly**, because each major Firebird
version uses its own on-disk structure (FB3 = ODS 12, FB4 = ODS 13)
and a copied file errors with `SQLSTATE 335544379 unsupported on-disk
structure`. The `.fbk` format is portable across versions; `gbak -r`
writes a fresh ODS-13 `.fdb`.

The output path **must** live under `/var/lib/firebird/data/` —
Firebird's default `DatabaseAccess = Restrict /var/lib/firebird/data`
refuses paths elsewhere (`SQLSTATE 335544831 Use of database at
location ... is not allowed by server configuration`).

```bash
sudo -u firebird /usr/bin/gbak -r \
    /var/www/ekdosi/legacy/ekdosi-main/db_backup/ekdosi.fbk \
    /var/lib/firebird/data/ekdosi-sandbox.fdb \
    -user SYSDBA -password masterkey
sudo chown firebird:firebird /var/lib/firebird/data/ekdosi-sandbox.fdb
```

### 12d. Run the ETL

```bash
sudo -u ekdosi php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb=/var/lib/firebird/data/ekdosi-sandbox.fdb \
    --host=127.0.0.1 --fbuser=SYSDBA --fbpass=masterkey
```

Expected output ends with `Done. Run the golden-test comparison
next (see README).` Tables that don't exist in the bundled `.fbk`
(MARK, CONF_PARAMS, AUTO_INVOICE_LOG — all post-myDATA additions)
are reported as `(skipped: table absent in this .fbk ...)` and
that's fine.

Verify with a row count + the VAT-rounding golden test:

```bash
mariadb -uekdosi -p ekdosi -e "
  SELECT 'customers' t, COUNT(*) n FROM customers WHERE company_id=1
  UNION ALL SELECT 'products', COUNT(*) FROM products WHERE company_id=1
  UNION ALL SELECT 'invoices', COUNT(*) FROM invoices WHERE company_id=1
  UNION ALL SELECT 'invoice_lines', COUNT(*) FROM invoice_lines WHERE company_id=1
  UNION ALL SELECT 'payments', COUNT(*) FROM payments WHERE company_id=1;
  SELECT il.id, il.net_price, il.gross_price, il.vat_percent
  FROM invoice_lines il
  WHERE company_id=1
    AND ABS(il.gross_price - ROUND(il.net_price * (1 + il.vat_percent/100), 2)) > 0.01
  LIMIT 20;
"
```

The second query should return an empty set — that's the VAT/discount
math reproducing the legacy values byte-exact, the gate CLAUDE.md
calls out before trusting the import.

### 12e. Production cutover — final ETL from a fresh legacy gbak

**This is the recommended path for real data.** Don't directly connect
the new FB4-client app to the live legacy FB3 server for cutover:

1. Legacy uses **FB3 (ODS 12)**, new host uses **FB4 (ODS 13)**. The
   on-disk file formats are not interchangeable — `gbak`'s `.fbk`
   backup format is.
2. Direct connect needs the legacy `firebird.conf` to allow the
   remote IP + the specific `.fdb` path; production configs almost
   always restrict both.
3. A `.fbk` is a frozen point-in-time snapshot — no race against live
   writes mid-ETL. If the ETL crashes you re-run against the same
   `.fbk` until it's clean.
4. `migrate:firebird` is re-runnable (wipes this tenant's rows first
   and re-imports), so you can iterate against the same `.fbk` while
   debugging mapping bugs without ever touching production.

```bash
# (1) On the LEGACY server (FB3) — stop the app first, then back up:
ssh legacy.example.com
gbak -b /opt/Data/ekdosi-myip.fdb /tmp/ekdosi-myip-$(date +%F).fbk \
    -user SYSDBA -password masterkey
exit

# (2) Transfer to this box:
scp legacy.example.com:/tmp/ekdosi-myip-*.fbk /tmp/

# (3) Restore on FB4 (writes a fresh ODS-13 .fdb from any FB version's .fbk):
sudo -u firebird /usr/bin/gbak -r \
    /tmp/ekdosi-myip-2026-XX-XX.fbk \
    /var/lib/firebird/data/ekdosi-myip.fdb \
    -user SYSDBA -password masterkey
sudo chown firebird:firebird /var/lib/firebird/data/ekdosi-myip.fdb

# (4) ETL against the local FB4 server:
sudo -u ekdosi php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb=/var/lib/firebird/data/ekdosi-myip.fdb \
    --host=127.0.0.1 --fbuser=SYSDBA --fbpass=masterkey

# (5) Golden test — zero divergent rows is the gate for trusting the import.
#     (See CLAUDE.md's "Golden test" section.)
mariadb -uekdosi -p ekdosi -e "
  SELECT il.id, il.net_price, il.gross_price, il.vat_percent
  FROM invoice_lines il
  WHERE company_id=1
    AND ABS(il.gross_price - ROUND(il.net_price * (1 + il.vat_percent/100), 2)) > 0.01
  LIMIT 20;"

# Repeat (1)→(5) per legacy tenant (myip, nixpal, ...).
```

### 12f. Direct-remote-connect (escape hatch, NOT for cutover)

If you really need to ETL while the legacy app is still serving — e.g.
mid-day sandboxing against live data, no cutover yet — you can point
FB4 client at the FB3 server. The wire protocol is backward
compatible. `--fdb` is then a path **on the legacy server's filesystem**:

```bash
sudo -u ekdosi php artisan migrate:firebird --company="MyIP" --slug=myip \
    --fdb="/opt/Data/ekdosi-myip.fdb" \    # path on the LEGACY box
    --host=10.23.22.5 \
    --fbuser=EKDOSI --fbpass=ekdosi1234
```

If you see `Use of database at location ... is not allowed by server
configuration`, the legacy box's `firebird.conf` `DatabaseAccess`
doesn't allow opening that path remotely. Either fix the legacy
config or fall back to 12c.

## 13. Re-deploy / update runbook

Save this as `/usr/local/bin/ekdosi-deploy.sh` on the server. All
artisan / composer / npm commands run as the `ekdosi` user;
only the systemd restarts need root.

> If you ever run `git` against `/var/www/ekdosi` as a different
> user (root, your own login) you'll hit
> `fatal: detected dubious ownership in repository at '/var/www/ekdosi'`.
> Fix that once per box:
> ```bash
> sudo git config --global --add safe.directory /var/www/ekdosi
> ```
> The deploy script below sidesteps this by `sudo -u ekdosi`'ing
> every git call.

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/ekdosi

sudo -u ekdosi php artisan down --render="errors::503" --retry=30

sudo -u ekdosi git fetch --tags origin
sudo -u ekdosi git checkout main
sudo -u ekdosi git pull --ff-only

sudo -u ekdosi composer install --no-dev --optimize-autoloader --no-interaction
sudo -u ekdosi php artisan filament:assets       # republish Filament's CSS/JS/fonts

# Vite — only if a package-lock.json is committed (it isn't, yet).
# When custom frontend assets land, replace `|| true` with hard fail.
test -f package-lock.json && sudo -u ekdosi npm ci && sudo -u ekdosi npm run build || true

sudo -u ekdosi php artisan migrate --force
sudo -u ekdosi php artisan config:cache
sudo -u ekdosi php artisan route:cache
sudo -u ekdosi php artisan view:cache
sudo -u ekdosi php artisan icons:cache
sudo -u ekdosi php artisan storage:link || true

systemctl restart php-fpm
systemctl restart ekdosi-queue

sudo -u ekdosi php artisan up
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
- [ ] `sudo -u ekdosi php artisan about` shows: PHP 8.4, Laravel
      13, mariadb driver, cache/session/queue all on `database`,
      Filament v5.x, Shield 4.x.
- [ ] `sudo -u ekdosi php artisan migrate:status` shows all 25
      migrations as `Ran` (3 Laravel defaults + 19 ekdosi + 2 spatie
      + 1 country profile).
- [ ] `php -m | grep -E "bcmath|mbstring|pdo_mysql|intl|gd|zip|curl"`
      lists every one of them.
- [ ] `sudo -u ekdosi php artisan route:list | grep admin` shows
      `admin/{tenant:slug}` registered.
- [ ] `sudo systemctl status ekdosi-queue` is `active (running)`.
- [ ] `sudo crontab -u ekdosi -l` shows the scheduler entry.
- [ ] `ps -ef | grep php-fpm` shows workers running as `ekdosi`
      (not `apache`); only the master is root.
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
