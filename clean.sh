#!/usr/bin/env bash
# Deploy refresh. Runs after every `git pull`.

echo "==> [1/4] DB backup before deploy..."
if sudo -u ekdosi php artisan backup:run --only-db --disable-notifications; then
    echo "    ✓ DB backup written."
else
    echo "    ⚠ WARNING: DB backup FAILED — review before relying on rollback! (deploy continues)"
fi

echo "==> [2/4] Autoload + migrations..."
sudo -u ekdosi composer dump-autoload --optimize
sudo -u ekdosi php artisan migrate --force

echo "==> [3/4] Compiled-cache refresh (NOT the app data cache)..."
sudo -u ekdosi php artisan optimize:clear     # config+route+view+compiled+events
sudo -u ekdosi php artisan optimize           # rebuild config+route caches

echo "==> [4/4] Restart runtime..."
sudo systemctl restart php-fpm                # flush opcache → new code
sudo -u ekdosi php artisan queue:restart      # workers pick up new code
