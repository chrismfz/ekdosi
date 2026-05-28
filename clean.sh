sudo systemctl restart php-fpm
sudo -u ekdosi  composer dump-autoload --optimize

sudo -u ekdosi php artisan shield:generate
sudo -u ekdosi php artisan migrate --force  
sudo -u ekdosi php artisan optimize:clear
sudo -u ekdosi php artisan cache:clear
sudo -u ekdosi php artisan config:clear
sudo -u ekdosi php artisan route:clear
sudo -u ekdosi php artisan view:clear
sudo -u ekdosi php artisan optimize
sudo -u ekdosi php artisan queue:restart
