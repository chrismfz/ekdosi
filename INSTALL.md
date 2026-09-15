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

> **On cPanel / CloudLinux shared hosting** (no root shell for the app user,
> MultiPHP/PHP-Selector, CageFS, no systemd) the app, `.env`, DB and the
> `/install` wizard are identical — only *how you get PHP 8.4 on the CLI, why
> `proc_open` matters, and how to install `pdo_firebird`* differ. Those
> differences (found standing up `invoicer.myip.gr`) are collected in **§17** —
> read that alongside §5–§7 instead of the VM-specific §8–§13.

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
    php-mysqlnd php-pdo php-opcache php-sodium php-soap \
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
- `php-soap` — required by `AadeRegistryLookup` for the GSIS AFM → επωνυμία /
  ΔΟΥ / Δραστηριότητα lookup (the "Fill from AFM" button on the customer form).
  Composer hard-requires it (`ext-soap: "*"`); `composer install` refuses to
  proceed without it. Omitting it later forces a deploy-time reinstall.

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
           soap sodium tokenizer xml xmlwriter zip; do
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

The repo ships a demo seeder (a self-contained «DEMO Α.Ε.» tenant + an
`admin@ekdosi.local` user). **It is OPT-IN (SET-1):** it does nothing
unless you explicitly set `EKDOSI_SEED_DEMO=true`, so a stray
`db:seed --force` on a real host can never create a known-password
super_admin. On a dev/demo box:

```bash
EKDOSI_SEED_DEMO=true sudo -u ekdosi php artisan migrate:fresh --seed --force
```

Login: `admin@ekdosi.local` / `password` (override with
`EKDOSI_SEED_DEMO_PASSWORD`). Note: even with the flag on, the demo
seed is refused when `APP_ENV=production` (a second belt) — it's a
dev/staging tool only. For a REAL install don't use the demo seed at
all — run `php artisan ekdosi:install` (prompts for real credentials +
first company), or `php artisan ekdosi:create-admin` to add/reset a
super_admin once a company exists.

**Standing up a pre-configured company from a bundle.** If you already
run this company elsewhere, export it there
(`php artisan company:export --tenant=<slug>`, passphrase-prompted) and
provision the new box from that .zip in one command — the whole tenant
(identity, settings, sealed credentials, setup tables, assigned
operators) is restored and the install admin is made super_admin:

```bash
php artisan ekdosi:install --bundle=/path/to/<slug>.zip \
    --email=you@co.gr --password='…' --no-interaction \
    --bundle-passphrase='…'
```

Lookup seeding is skipped (the bundle carries VAT/types/payment
methods). The admin is created with YOUR `--password` even if that email
is listed as an operator in the bundle. Operators the bundle lists are
re-created with a random password — they set one via the
«ξέχασα τον κωδικό» flow (no credential ever travels in a bundle).

**No shell? Use the web wizard instead.** The `/install` wizard offers the
same thing without a console: pick **«Εισαγωγή από .zip»**, upload the
bundle (and its passphrase if it was exported encrypted — leave it blank
for a raw one), fill in the DB creds + admin, and submit. Hand a partner
the code + the `.zip`, have them create an empty MariaDB and point a
browser at the site — that's it. (The wizard's «Νέα εταιρία» mode is the
blank-company install as before.)

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
create your real tenant and first operator interactively. The user
also needs the `super_admin` role within the tenant — without a role
they can log in but every resource will be hidden or 403:

```bash
sudo -u ekdosi php artisan migrate --force        # schema only, no seed
# Populate Shield's permissions table (one row per action on each resource):
sudo -u ekdosi php artisan shield:generate --all --panel=admin --no-interaction
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

// Grant super_admin within the tenant. Spatie teams mode scopes roles by
// company_id, so we (a) set the active team and (b) create the role row
// with an explicit company_id so Shield's tenant-scoped RoleResource
// finds it under /admin/{slug}/shield/roles.
app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($company->id);
$role = \App\Models\Role::firstOrCreate([
    'name'       => 'super_admin',
    'guard_name' => 'web',
    'company_id' => $company->id,
]);
$role->syncPermissions(\Spatie\Permission\Models\Permission::pluck('name'));
$user->assignRole($role);

exit
```

Then browse to `https://ekdosi.myip.gr/admin/login`, sign in, and you
should land on `/admin/myip` (the dashboard) with the full nav.

The tinker recipe above is **first-user only** — bootstrap. Once
you're in the panel, day-to-day operations happen there:

- **More tenants** — `/admin/{any-slug}/companies` → "New company".
  Each new tenant gets its own URL prefix from its slug; per-tenant
  myDATA credentials live on the Company row, not in `.env`.
- **More users + role assignment** — TODO. A dedicated UserResource
  with attach-to-tenants + role pickers is the next slice after the
  domain resources land; until then operators are added with the
  same tinker pattern (User::create → companies()->attach →
  assignRole), but with the active team set explicitly via
  PermissionRegistrar::setPermissionsTeamId before assignRole, OR
  use the panel's Roles screen to attach a role to a user via the
  attach action.
- **Roles + permissions** — `/admin/{slug}/shield/roles` (provided by
  filament-shield). Create narrower roles like `operator` or
  `accountant_readonly`, pick which Resources/actions they can use,
  and attach to users.

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

### 9b. PHP upload limits — both fpm AND cli

PHP's defaults (`upload_max_filesize=2M`, `post_max_size=8M`) are far
too low for the **Firebird import UI** (`/admin/.../firebird-import`),
which lets operators upload a `.fbk` or `.fdb` directly through the
browser. Real ekdosi backups range from ~50 MB to a few hundred MB.

You must bump **both** the fpm config (the web request hits fpm) AND
the cli config (the queue worker runs the artisan subprocess that
does the actual import). Remi's PHP 8.4 puts them at:

```bash
sudo tee /etc/php.d/99-ekdosi-uploads.ini >/dev/null <<'INI'
; Upload limits for the Firebird import UI.
; Must be set in BOTH fpm and cli SAPI — Remi's php.d/ is shared,
; so this single drop-in covers both.
upload_max_filesize = 600M
post_max_size       = 700M      ; must be ≥ upload_max_filesize
memory_limit        = 768M      ; must be > post_max_size

; The import job can take a while on a large .fbk (gbak restore +
; transactional inserts). Don't let fpm kill it mid-flight.
max_execution_time  = 600
max_input_time      = 600
INI

sudo systemctl restart php-fpm
```

**Verify both SAPIs picked it up:**

```bash
# fpm side — what the web request sees
sudo -u ekdosi php-fpm -i 2>/dev/null | grep -E '^(upload_max_filesize|post_max_size|memory_limit)' \
    || php --ri core | grep -E '(upload_max_filesize|post_max_size|memory_limit)'

# cli side — what the queue worker sees when it shells out to artisan
php -r 'echo "upload_max_filesize=", ini_get("upload_max_filesize"),
        "\npost_max_size=", ini_get("post_max_size"),
        "\nmemory_limit=", ini_get("memory_limit"), "\n";'
```

Both should show your new values. **The import form's helperText
reads PHP's live `upload_max_filesize` / `post_max_size`** and
displays the effective ceiling to the operator — once this is bumped,
the form will show the new limit and stop suggesting the artisan
workaround for normal-sized backups.

**Don't forget** to bump nginx's `client_max_body_size` to match
(§10a — already set to `700M` in the template). Nginx rejects the
request before PHP ever sees it if the body exceeds its own limit.

**Symptom of getting this wrong:** the Filament drop-zone shows a
generic "Error during upload — tap to retry" and the Livewire request
returns 4xx with a body like
`The data.upload.<uuid> failed to upload.` No useful server-side log
entry — PHP rejects the request before Laravel boots, so it never
hits `storage/logs/laravel.log`. If you see this and the
helperText still shows `upload_max_filesize=2M`, you missed this step.

**`/tmp` sizing**: the import job restores `.fbk` to `.fdb` via
`gbak` (skipped for direct `.fdb` uploads), and the restored `.fdb`
lands in `sys_get_temp_dir()` (`/tmp` by default). Restored size is
1.5-2× the `.fbk`. If `/tmp` is tmpfs on this box
(`findmnt /tmp` shows `tmpfs`), a 500 MB `.fbk` can OOM the host.
Either give the box enough RAM, or set `TMPDIR=/var/tmp` in
`/etc/systemd/system/ekdosi-queue.service` (§11) and the same in the
fpm pool's environment so the upload-temp + restore land on disk.

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

    client_max_body_size 700M;       # must match php.ini post_max_size (§9b);
                                     # Firebird .fbk/.fdb uploads in the import UI
                                     # are the largest thing this server takes.
                                     # If you raise post_max_size, raise this too.

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

> **Shortcut:** `php artisan ops:cron` prints the exact crontab + worker lines for
> THIS host (real PHP binary + app path), for both a VPS and a cPanel/DirectAdmin
> box, plus the live cron/worker state — paste rather than hand-edit the examples
> below. After wiring, `php artisan ops:health` reports **cron** and **queue** as two
> separate signals, so a dead cron no longer looks like a dead worker. Shared-hosting
> specifics: [`docs/shared-hosting-deploy.md`](docs/shared-hosting-deploy.md).

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

> **⚠ Never add `--force` to `ExecStart`.** A worker started with `--force`
> ignores maintenance mode and keeps consuming jobs DURING a deploy — the one
> thing the drain below exists to prevent.

**Let the deploy user drain the worker (recommended).** `deploy/update.sh` and
`deploy/rollback.sh` stop the worker before `migrate`/restore. Without this the
unit is root-only, `systemctl stop` fails, and they fall back to the slower
portable drain. One sudoers drop-in fixes it:

```bash
sudo tee /etc/sudoers.d/ekdosi-queue >/dev/null <<'EOF'
ekdosi ALL=(root) NOPASSWD: /usr/bin/systemctl stop ekdosi-queue, /usr/bin/systemctl start ekdosi-queue
EOF
sudo chmod 440 /etc/sudoers.d/ekdosi-queue
# and, once, in the ekdosi user's ~/.bash_profile:
export QUEUE_STOP_CMD='sudo systemctl stop ekdosi-queue'
export QUEUE_START_CMD='sudo systemctl start ekdosi-queue'
```

### No systemd / no root (cPanel, Plesk, DirectAdmin, shared hosting)

There is nothing to configure — the deploy scripts fall back to
`php artisan ops:queue-drain`, which needs no privileges: it broadcasts
`queue:restart` (each worker finishes its current job and exits) and then waits
until no job is reserved. Combined with maintenance mode — already on at that
point — a worker that a supervisor or cron brings back simply sleeps until the
deploy is over. Run the worker however the host allows, e.g. a cron line:

```
* * * * * cd /home/USER/ekdosi && /usr/bin/php artisan queue:work --stop-when-empty --max-time=55 >/dev/null 2>&1
```

`QUEUE_DRAIN_TIMEOUT` (default 60s) is how long the deploy waits for an
in-flight job; raise it on a box that runs the long Firebird import.

(Future: when the WHMCS pull / myDATA-resend backlog warrants it,
switch `QUEUE_CONNECTION=redis` and add `redis` to the install list.
Database driver is fine for ~3 tenants.)

**Notes on the wired schedule** (`routes/console.php` — `whmcs:fetch-pending`
every 15 min, `mydata:reconcile-sales` daily 06:00, `mail-log:sweep-orphans`
every 15 min):

- **Toggles / timing** live in `config/ekdosi.php` via env, so you can disable
  a task without touching cron: `EKDOSI_SCHEDULE_WHMCS_FETCH=false`,
  `EKDOSI_SCHEDULE_MYDATA_RECONCILE=false`, `EKDOSI_SCHEDULE_MAIL_SWEEP=false`,
  `EKDOSI_WHMCS_FETCH_CRON`, `EKDOSI_MYDATA_RECONCILE_TIME`. After changing
  these on a host that caches config, run `php artisan config:clear` (or
  `optimize`).
- **`withoutOverlapping` needs the cache store working** — with the default
  database cache driver the `cache` + `cache_locks` tables must be migrated
  (they are, via §7). If you switch the cache driver, make sure it's reachable
  or `schedule:run` will error.
- **On every deploy run `php artisan queue:restart`** so the long-running
  worker picks up new code (already in `clean.sh`); `--max-time=3600` also
  recycles it hourly as a backstop.
- **Cron output is discarded** (`>> /dev/null`). `mydata:reconcile-sales` exits
  `2` when it finds discrepancies — if you want alerting, append the schedule
  output to a log instead and watch it.
- **Exception alerting (OPS-3)** — an unhandled exception (anywhere: web,
  scheduler task, queue job) emails the ops recipients, best-effort and deduped
  per error signature (`EKDOSI_ERROR_ALERTS=true` by default; recipients fall
  back to `EKDOSI_BACKUP_ALERT_EMAIL` → super_admins, or set a dedicated
  `EKDOSI_ERROR_ALERT_EMAIL`). It never suppresses the `laravel.log` line and a
  mail hiccup can't break the request/task — but it needs the **queue worker
  up** (the alert is queued) and mail configured. So the earlier scheduler note
  aside, real errors DO surface without anyone tailing the log.

### Logs — rotate them (OPS-14)

The default log channel is `single` — **one file** (`storage/logs/laravel.log`)
that grows forever. On a long-running host, rotate it one of two ways:

- **App-native (simplest):** switch to Laravel's daily channel — in `.env`
  set `LOG_STACK=daily` (and optionally `LOG_DAILY_DAYS=14` for retention),
  then `php artisan config:clear`. Laravel writes a dated file per day and
  prunes past the retention window. No OS config.
- **OS-level (covers every file under `storage/logs/`):** an logrotate rule.
  Create `/etc/logrotate.d/ekdosi`:

  ```
  /home/ekdosi/ekdosi/storage/logs/*.log {
      weekly
      rotate 8
      compress
      delaycompress
      missingok
      notifempty
      copytruncate
      su ekdosi ekdosi
  }
  ```

  `copytruncate` avoids needing the app to reopen the file after rotation.
  Adjust the path to your clone and `su` to the app user (§5a).

### Whole-DB backups (spatie/laravel-backup) — verify, don't just trust

Once the scheduler cron above is in place, the **whole-DB backup** (nightly
dump of ALL tenants + app files, 02:00) runs by default — `backup:run`,
`backup:clean` and `backup:monitor` ship enabled (`EKDOSI_SCHEDULE_BACKUP_*`,
see `config/ekdosi.php`). This is the disaster-recovery floor under the
per-tenant exports. Three things to configure for production:

1. **Off-site destination** — the default writes to the `local` disk only,
   which dies with the VM. Point `BACKUP_DESTINATION_DISKS` at a
   comma-separated disk list that includes an off-site one (e.g.
   `local,s3` with the `s3` disk configured via the `AWS_*` vars in
   `config/filesystems.php`, or a dedicated sftp disk you add there).
2. **Archive passphrase** — set `BACKUP_ARCHIVE_PASSWORD` (the archives
   contain the full dump, secrets included) and store it in the password
   manager. Without the passphrase the archive is unencrypted.
3. **Failure alerting recipient** — failures email the chain
   «Ρυθμίσεις συστήματος» override → `EKDOSI_BACKUP_ALERT_EMAIL` →
   all super_admin users. Set the env (comma-separated) or confirm the
   super_admin accounts have real mailboxes. Successful runs are silent
   by design; `backup:monitor` (08:00) mails when backups go stale.

Then **prove it works** — run once by hand and check both ends:

```bash
sudo -u ekdosi php artisan backup:run          # creates + pushes the archive
sudo -u ekdosi php artisan backup:list         # ages + sizes per destination
sudo -u ekdosi php artisan ops:health          # backup rows must be green
```

Finally, **do one restore drill before go-live**: pull the newest archive
from the off-site destination onto a scratch box/DB, unzip (passphrase!),
load the dump, and confirm you can log in and open a tenant's invoices.
A backup that has never been restored is a hope, not a backup. Repeat the
drill after any backup-config change (and ideally quarterly).

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
# (1) On the LEGACY server (FB3) — stop the app first, then back up.
#     Upstream Firebird's tools live at /opt/firebird/bin (not in PATH
#     by default). gbak needs SYSDBA. If you don't remember the SYSDBA
#     password but know an app-level user (EKDOSI etc.), see the
#     "if you only have the app user" note below.
ssh legacy.example.com
/opt/firebird/bin/gbak -b -user SYSDBA -password <legacy-sysdba-pass> \
    /opt/Data/ekdosi-myip.fdb \
    /tmp/ekdosi-myip-$(date +%F).fbk
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

**Quick schema-drift check** before a full cutover (cheap, ~50KB SQL
file, no row data leaves the legacy box). Useful in the days BEFORE
cutover so we know whether any columns/procs/triggers drifted since
the snapshot in `legacy/ekdosi-schema.sql`:

```bash
# On the LEGACY server — embedded mode fails because the daemon has
# the .fdb locked, so go through the network. localhost: only works
# if Firebird's listener binds to loopback; otherwise use the box's
# external IP (same one the C++Builder app uses, e.g. 10.23.22.5):
/opt/firebird/bin/isql -x -u EKDOSI -p <FB_PASSWORD> \
    10.23.22.5:/opt/Data/ekdosi-myip.fdb \
    > /tmp/ekdosi-myip-schema-$(date +%F).sql

# Transfer it to /legacy/ in the repo and diff against the snapshot:
scp legacy.example.com:/tmp/ekdosi-myip-schema-*.sql legacy/
diff legacy/ekdosi-schema.sql legacy/ekdosi-myip-schema-*.sql | head -50
```

The 2026-05-26 check found zero structural drift — same tables,
columns, procs, triggers as the snapshot in `legacy/ekdosi-schema.sql`,
only some Greek-language `COMMENT ON DOMAIN` cosmetic additions.

**If you only have the app user (EKDOSI), not SYSDBA**: app-level
users normally can't run `gbak -b`. Two recoveries on the legacy box:

```bash
# Reset SYSDBA via gsec (needs Firebird daemon running):
/opt/firebird/bin/gsec -user SYSDBA -password masterkey \
    -modify SYSDBA -pw <newpass>     # may need bootstrap if no current SYSDBA

# OR — service-manager backup using the app user (works if EKDOSI has
# the RDB$ADMIN role; check first):
/opt/firebird/bin/gbak -b \
    -user EKDOSI -password <FB_PASSWORD> \
    -se 10.23.22.5:service_mgr \
    10.23.22.5:/opt/Data/ekdosi-myip.fdb \
    /tmp/ekdosi-myip-$(date +%F).fbk
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
    --fbuser=EKDOSI --fbpass=<FB_PASSWORD>
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

### 13a. Private repo — authentication for clone + deploy

Both the runbook above **and** the bundled `deploy/update.sh` /
`deploy/rollback.sh` are **auth-agnostic**: they only ever run
`git fetch origin` + `git checkout` inside an **already-cloned** repo. They
never `clone`, never embed a token, and have **no hard-coded URL** — they use
whatever the repo's `origin` remote is wired to. So the only thing to arrange is
that `git fetch origin` succeeds **non-interactively** (deploys run unattended,
with no chance to type a password).

- **Public repo (HTTPS):** `git fetch` needs no credentials → nothing to set up,
  the deploy just works.
- **Private repo:** pick **ONE** of the three below. The deploy scripts stay
  **exactly the same** — you only change how git authenticates once, at setup.

On a VM every git command below is run as the app user (`sudo -u ekdosi …`, so
the key/credentials live in `/home/ekdosi`); on shared hosting (cPanel /
DirectAdmin) you're already logged in as that user, so drop the `sudo -u ekdosi`
prefix and `~` is your account home. `<APP_DIR>` = where the code lives
(`/var/www/ekdosi` on a VM, `~/ekdosi` or similar on shared hosting).

#### Option 1 — SSH deploy key (recommended) 🥇

A read-only key scoped to **this one repo**, with **no expiry** — the least
maintenance and the smallest blast radius. (§5b above is the VM quick-start of
this same option; this is the full picture, incl. shared hosting.)

```bash
# 1) Generate a passphrase-less key ON THE SERVER (one per server).
ssh-keygen -t ed25519 -N '' -f ~/.ssh/ekdosi_deploy -C "ekdosi-deploy@$(hostname)"

# 2) Trust github.com's host key so the first fetch doesn't prompt.
ssh-keyscan -t rsa,ecdsa,ed25519 github.com >> ~/.ssh/known_hosts

# 3) Print the PUBLIC key and paste it into GitHub:
#      repo → Settings → Deploy keys → Add deploy key
#    Leave "Allow write access" UNCHECKED — deploys only pull.
cat ~/.ssh/ekdosi_deploy.pub

# 4) Tell ssh to use this key for github.com (needed when it's not the
#    default ~/.ssh/id_ed25519, e.g. a dedicated deploy key):
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/ekdosi_deploy
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

# 5) Point origin at the SSH URL (skip if you already cloned over SSH).
git -C <APP_DIR> remote set-url origin git@github.com:chrismfz/ekdosi.git

# 6) Verify — must return WITHOUT any prompt:
git -C <APP_DIR> fetch origin
```

Pros: read-only, one repo only, never expires, no secret sitting in a file that
tools might log. Cons: none worth mentioning for a single private repo — this is
the default choice.

#### Option 2 — HTTPS + a fine-grained Personal Access Token (PAT)

Use this when SSH is awkward on the host (some locked-down panels). A
**fine-grained** token scoped to the one repo, read-only.

```bash
# 1) GitHub → Settings → Developer settings → Fine-grained tokens → Generate:
#      Resource owner: your account/org
#      Repository access: "Only select repositories" → chrismfz/ekdosi
#      Permissions → Repository → Contents: Read-only
#    (Contents:Read is all a pull needs. Set an expiry you'll actually renew.)

# 2) Let git CACHE the credential so the deploy never prompts. Two ways:

#   (a) Persistent store (simplest; token lands in ~/.git-credentials, 0600):
git config --global credential.helper store
git -C <APP_DIR> remote set-url origin https://github.com/chrismfz/ekdosi.git
git -C <APP_DIR> fetch origin
#     → Username: chrismfz     Password: <paste the PAT>   (saved for next time)

#   (b) OR feed it once, non-interactively (good for scripted provisioning):
printf 'protocol=https\nhost=github.com\nusername=chrismfz\npassword=%s\n' "$PAT" \
  | git credential approve
```

Do **NOT** bake the token into the remote URL
(`https://<token>@github.com/…`): it ends up in `.git/config`, in `ps` output,
and in any git trace/log. Keep it in the credential store instead. Cons vs.
Option 1: the token **expires** (you must rotate it), and it's a secret at rest
on disk.

#### Option 3 — the GitHub CLI (`gh`) as git's credential helper

Works, but adds a dependency (`gh` must be installed) and under the hood still
uses a token — so it's rarely worth it over a deploy key on a plain server. Use
it only where `gh` already exists.

```bash
# 1) Install gh (if not present) — see cli.github.com. Then authenticate
#    NON-interactively with a token that has Contents:Read on the repo:
echo "$PAT" | gh auth login --hostname github.com --git-protocol https --with-token

# 2) Make git reuse gh's stored token for github.com HTTPS:
gh auth setup-git

# 3) origin over HTTPS + verify:
git -C <APP_DIR> remote set-url origin https://github.com/chrismfz/ekdosi.git
git -C <APP_DIR> fetch origin      # no prompt — gh answers the credential request
```

`gh auth status` shows the active token; `gh auth logout` revokes it locally.

#### At a glance

| Option | Non-interactive | Scope | Expires | Best for |
| --- | --- | --- | --- | --- |
| **1. SSH deploy key** | ✅ | this repo, read-only | never | **default** — VM & shared hosting |
| 2. HTTPS + fine-grained PAT | ✅ | this repo, read-only | yes (rotate) | hosts where SSH is blocked |
| 3. `gh` CLI | ✅ | whatever the PAT grants | yes | only where `gh` is already installed |

Whichever you pick, once `git -C <APP_DIR> fetch origin` returns without a
prompt, **the deploy scripts need no changes** — the same
`deploy/update.sh <tag>` / `ekdosi-deploy.sh` keeps working on a private repo.

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
- [ ] `sudo -u ekdosi php artisan migrate:status` shows **no
      `Pending`**. Since the v2.0.2 squash a correct install prints
      `INFO  No migrations found.` — `database/migrations/` is empty
      and the schema comes from `database/schema/mariadb-schema.sql`,
      so that message is the EXPECTED output, not a failure. To confirm
      the schema really loaded: `SELECT COUNT(*) FROM migrations;`
      should return 220 (+1 per migration added since).
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
- [ ] `php artisan backup:list` shows a fresh whole-DB archive on an
      **off-site** destination (§11 backups: `BACKUP_DESTINATION_DISKS`
      beyond `local`, `BACKUP_ARCHIVE_PASSWORD` set, alert recipient
      real) — and you have restored one archive successfully at least once.

## 15. Hardening (do before going live)

- `APP_DEBUG=false` (set above; double-check).
- Rotate the dev DB password.
- Backups: the whole-DB spatie schedule is already wired in
  `routes/console.php` and enabled by default — configure the off-site
  disk + passphrase + alert recipient in §11 (Whole-DB backups). On top,
  enable **per-tenant** backups where wanted: Companies → Αντίγραφα
  ασφαλείας (destinations/retention per company) +
  `EKDOSI_SCHEDULE_COMPANY_BACKUPS=true`.
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

## 17. cPanel / CloudLinux (shared hosting) — the differences

This is **not** a separate runbook. The app code, `.env` (§6), the DB (§4, but
created via cPanel → MySQL® Databases instead of the CLI), and the `/install`
wizard (§7b) are all identical. What a cPanel/CloudLinux box changes is the
*plumbing around* PHP: there's no root shell for the account user, PHP is
selected through **MultiPHP Manager / PHP Selector**, the account runs inside a
**CageFS** jail, and there's **no systemd**. The four gotchas below were all hit
standing up `invoicer.myip.gr` on such a host — a symptom-first index:

| Symptom | Cause | Fix |
| --- | --- | --- |
| `composer install` → wall of `requires php >=8.4` | CLI `php` is the system default (e.g. 8.2), MultiPHP only set the *web* handler | §17a |
| `composer install` dies at `package:discover` with `proc_open() has been disabled` | `proc_open` in `disable_functions` for ea-php84 | §17b |
| Need `pdo_firebird` for the ETL, no RPM anywhere | bundled core ext, not PECL; EA4 ships no package | §17d |
| No shell / no root to run the §7b tinker recipe | — | use the `/install` wizard, §17e |

Convention below: a step the **account user** runs needs no `sudo`; a step marked
**(root / WHM)** needs the server admin (WHM has it; a pure reseller/account may
have to ask the host).

### 17a. CLI PHP must be 8.4 — MultiPHP only sets the *web* handler

MultiPHP Manager points the domain's **web** requests at `ea-php84`, but the SSH
shell's `php` / `composer` still resolve to the stack default (on rigel that was
8.2.33), so `composer install` fails against our PHP-8.4 `composer.lock`. Put the
ea-php84 binaries first on `PATH` for the account:

```bash
# ~/.bashrc  (the cPanel account user)
export PATH="/opt/cpanel/ea-php84/root/usr/bin:$PATH"
```

Then `source ~/.bashrc` (or re-login) and confirm:

```bash
php -v          # PHP 8.4.x
which php        # /opt/cpanel/ea-php84/root/usr/bin/php
```

Composer: cPanel ships one at `/opt/cpanel/composer/bin/composer`. Once `PATH` is
set, a `composer` on it runs under ea-php84; otherwise invoke it explicitly,
`php /opt/cpanel/composer/bin/composer install …`.

> **ea-php vs alt-php.** EasyApache's **ea-php84** (used here) and CloudLinux's
> **PHP Selector / alt-php** are two *different* toolchains, with different ini
> and extension dirs. Stay on ea-php — the same binary the web handler uses — so
> the CLI (queue/artisan) and the web SAPI see the same extensions and config.
> Mixing them is a subtle source of "works on the web, missing extension on the
> CLI" bugs. Everything in this section assumes **ea-php**.

### 17b. `proc_open` must NOT be in `disable_functions`

Composer's post-autoload step runs `@php artisan package:discover`, which — like
artisan itself, the queue worker, and `spatie/laravel-backup` — shells out via
Symfony Process → `proc_open()`. Many cPanel/CloudLinux ini presets disable
`proc_open` "for security", so `composer install` dies with
`proc_open() has been disabled for security reasons`.

**Fix (root / WHM)** — drop `proc_open` from ea-php84's `disable_functions`,
then rebuild the CageFS skeleton so the jailed account actually sees the new ini
(editing the ini alone is **not** enough under CageFS):

```bash
# (root/WHM) edit /opt/cpanel/ea-php84/root/etc/php.ini →
#   remove proc_open (and, if listed, proc_close / proc_get_status) from
#   disable_functions. WHM → MultiPHP INI Editor can toggle the same value.
cagefsctl --force-update      # rebuild the CageFS template from the new ini
cagefsctl -M                   # remount/refresh every CageFS-jailed user
```

Verify from the **account** shell (not root — CageFS makes them differ):

```bash
php -r 'var_dump(function_exists("proc_open"));'   # must be bool(true)
```

Then `composer install` gets past `package:discover`.

### 17c. `composer install`, not `composer update`

Same rule as the VM: install the pinned `composer.lock` — `composer update`
re-resolves and can pull versions the app was never tested against.

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan filament:assets      # republish Filament CSS/JS/fonts to public/
```

### 17d. `pdo_firebird` — compile the bundled ext against ea-php84

**You likely don't need this on prod.** `pdo_firebird` is only for the one-time
`migrate:firebird` ETL (§12). If you instead provision the tenant from a
**portability bundle** — `/install` → «Εισαγωγή από .zip» (§7b) — no
Firebird, no ETL, and none of this section is needed. On shared hosting that's
the recommended path.

If you *do* need the ETL on this box: `pdo_firebird` is a **bundled PHP core
extension** (not on PECL), and EasyApache ships **no** `ea-php84-php-pdo-firebird`
RPM (alt-php doesn't carry it either). The path that worked on rigel is to build
the bundled ext **out-of-tree** against the ea-php84 binary:

```bash
# 0) (root/WHM) Firebird client lib + headers must be present
#    (libfbclient + development headers, e.g. the OS `firebird-devel`).

# 1) PHP source matching ea-php84's EXACT version — the ABI must match.
php -v                                         # note the exact 8.4.z
cd ~/src                                        # any writable dir
curl -LO https://www.php.net/distributions/php-8.4.24.tar.gz    # ← use YOUR 8.4.z
tar xf php-8.4.24.tar.gz
cd php-8.4.24/ext/pdo_firebird

# 2) Build JUST this ext with the ea-php84 toolchain.
/opt/cpanel/ea-php84/root/usr/bin/phpize
./configure \
    --with-php-config=/opt/cpanel/ea-php84/root/usr/bin/php-config \
    --with-pdo-firebird           # append =/path/to/firebird if libfbclient
                                  # isn't on the default search path
make

# 3) (root/WHM) install the .so + load it, then refresh CageFS.
sudo make install                 # → ea-php84's extension dir
echo 'extension=pdo_firebird.so' \
    | sudo tee /opt/cpanel/ea-php84/root/etc/php.d/30-pdo_firebird.ini
sudo cagefsctl --force-update && sudo cagefsctl -M

# 4) Confirm (account shell).
php -m | grep -i firebird          # expect: pdo_firebird
```

Caveats — this is fragile, which is why the bundle-import path above is preferred
on prod:
- **The source version must equal ea-php84's exactly.** A `.so` built against
  8.4.24 won't load on 8.4.25 (`undefined symbol` / API-version mismatch), so
  **every EasyApache PHP update means rebuilding it**. Note the `.so` in your own
  runbook — cPanel doesn't track it and won't warn you.
- If the box is on **alt-php** (PHP Selector) rather than ea-php, the whole recipe
  changes (`selectorctl`, alt-php paths) — out of scope here.

### 17e. The `/install` web wizard — the no-shell / minimal-shell path

Once the code is cloned, `composer install` succeeds, and an **empty** MariaDB
exists (create it in cPanel → MySQL® Databases, note the `user`/`db` name-prefix
cPanel adds), you do **not** need the §7b tinker recipes. Browse to
`https://<site>/install`:

- **Token-gated** — no login exists yet, so the wizard proves filesystem access
  instead: it reads a one-time token from
  `storage/app/install/verify-token.txt` (created on first hit); `cat` it over
  SSH, or via cPanel File Manager, and paste it.
- It writes `.env` + `APP_KEY`, runs `migrate --force`, creates the first company
  + super_admin, and **hardcodes `DB_CONNECTION=mariadb`** (the squash guard in
  `AppServiceProvider` refuses `mysql`/blank), all atomically — `.env` is written
  **last**, so a half-finished run leaves no broken `.env` behind.
- **«Νέα εταιρία»** = a blank company; **«Εισαγωγή από .zip»** = import a
  portability bundle exported elsewhere with `company:export` (identity,
  settings, sealed credentials, lookups, assigned operators). The bundle path is
  what lets you skip Firebird/`pdo_firebird` on prod (§17d).
- It **refuses a non-empty DB** (unless you tick «allow existing») and an
  interrupted-restore DB (`unmigratable`) — guardrails, not bugs.
- Its final screen is the post-install checklist (§17f).

### 17f. Post-install on cPanel (no systemd)

The account user owns the tree, so no `chown` dance (§8) — just perms + the
no-systemd cron variants:

```bash
chmod -R 775 storage bootstrap/cache
php artisan storage:link
```

- **DocumentRoot → `public/`.** Point the domain/subdomain at
  `…/ekdosi/public`, not the repo root (cPanel → Domains → Document Root). The
  shipped `public/.htaccess` handles the front-controller rewrite; a repo-root
  redirect is a last resort only.
- **Scheduler + queue via cron** (no systemd — this is the §11 "No systemd"
  path, with the ea-php84 binary spelled out):

  ```cron
  * * * * * cd /home/USER/ekdosi && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >/dev/null 2>&1
  * * * * * cd /home/USER/ekdosi && /opt/cpanel/ea-php84/root/usr/bin/php artisan queue:work --stop-when-empty --max-time=55 >/dev/null 2>&1
  ```

  `php artisan ops:cron` prints these two lines pre-filled for **this** host
  (real binary + path) — paste rather than hand-edit.
- **Permissions/roles**: if `/install` didn't do it,
  `php artisan shield:generate --all --panel=admin --no-interaction`, then
  `php artisan shield:sync-super-admin` to re-assert the global super_admin.
- **HTTPS**: cPanel AutoSSL usually already covers the domain — confirm the cert
  is issued before go-live.
- **After any `.env` edit**: `php artisan config:clear` (shared hosting caches
  config too).
- **Health**: `php artisan ops:health` (§11) is the one-shot check — the
  queue/scheduler rows go green only once the two cron lines above are live. Then
  run §14's verification checklist.
