sudo systemctl restart php-fpm
sudo -u ekdosi php artisan optimize:clear
sudo -u ekdosi php artisan cache:clear
sudo -u ekdosi php artisan config:clear
sudo -u ekdosi php artisan route:clear
sudo -u ekdosi php artisan view:clear
sudo -u ekdosi php artisan optimize
sudo -u ekdosi php artisan queue:restart

