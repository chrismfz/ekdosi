#!/usr/bin/env bash
#
# Deploy refresh. Runs after every `git pull`.
#
# SAFETY FIRST: take a database backup BEFORE anything that can mutate the
# schema or data (migrate --force, shield:generate). Uses spatie/laravel-backup
# (already installed; config/backup.php). Non-fatal — if the backup fails we
# WARN loudly but still let the deploy proceed, so a missing mysqldump binary
# or full disk doesn't wedge a routine cache-clear. Restore with the dump in
# the backup disk (storage/app/private/<app-name>/ by default).
#
# To prune old dumps per the retention policy in config/backup.php, the
# scheduler runs `backup:clean` — or run it by hand occasionally.

echo "==> [1/3] Backing up database before deploy..."
if sudo -u ekdosi php artisan backup:run --only-db --disable-notifications; then
    echo "    ✓ DB backup written to the backup disk."
else
    echo "    ⚠ WARNING: DB backup FAILED. Review before relying on a rollback!"
    echo "      (deploy continues — fix mysqldump path / disk space / config/backup.php)"
fi

echo "==> [2/3] Rebuilding autoload + restarting PHP-FPM..."
sudo systemctl restart php-fpm
sudo -u ekdosi  composer dump-autoload --optimize

echo "==> [3/3] Migrations + cache refresh..."
sudo -u ekdosi php artisan shield:generate
sudo -u ekdosi php artisan migrate --force
sudo -u ekdosi php artisan optimize:clear
sudo -u ekdosi php artisan cache:clear
sudo -u ekdosi php artisan config:clear
sudo -u ekdosi php artisan route:clear
sudo -u ekdosi php artisan view:clear
sudo -u ekdosi php artisan optimize
sudo -u ekdosi php artisan queue:restart
