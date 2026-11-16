# Deploying Ilmora on an Ubuntu VPS

One server runs the API, the queue worker, the scheduler and the web app. Ubuntu 22.04 or 24.04, 2 GB RAM or more.

## 1. Packages

```bash
sudo apt update
sudo apt install -y nginx postgresql redis-server git unzip supervisor \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-redis
# Composer: https://getcomposer.org/download/   Node 22: https://nodejs.org (or nvm)
```

## 2. Database

```bash
sudo -u postgres psql -c "CREATE USER ilmora WITH PASSWORD 'choose-a-strong-password';"
sudo -u postgres psql -c "CREATE DATABASE ilmora OWNER ilmora;"
```

Keep PostgreSQL listening on localhost only.

## 3. API

```bash
sudo mkdir -p /var/www/ilmora && sudo chown $USER /var/www/ilmora
git clone <your repo url> /var/www/ilmora
cd /var/www/ilmora/apps/api
cp .env.example .env
composer install --no-dev --optimize-autoloader
php artisan key:generate
```

Edit `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.your-domain.com
DB_PASSWORD=choose-a-strong-password
QUEUE_CONNECTION=redis
CACHE_STORE=redis
MESSAGE_DRIVER=http            # log until you have a WhatsApp / SMS gateway
MESSAGE_HTTP_URL=https://your-gateway/send
MESSAGE_HTTP_TOKEN=...
```

Then:

```bash
php artisan migrate --force
php artisan ilmora:install --email=you@your-domain.com --password='a-long-password'
php artisan filament:assets && php artisan config:cache && php artisan route:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

`ilmora:install` creates the Basic, Standard and Premium plans and your super admin. Do not run `DemoSeeder` on a production database.

## 4. Web app

```bash
cd /var/www/ilmora/apps/mobile
echo "VITE_API_URL=https://api.your-domain.com/api/v1" > .env
npm ci && npm run build        # output in dist/
```

## 5. Nginx

`/etc/nginx/sites-available/ilmora`:

```nginx
# API and Filament admin
server {
    server_name api.your-domain.com;
    root /var/www/ilmora/apps/api/public;
    index index.php;
    client_max_body_size 20m;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}

# Web app
server {
    server_name app.your-domain.com;
    root /var/www/ilmora/apps/mobile/dist;
    index index.html;
    location / { try_files $uri /index.html; }
    location /assets/ { expires 1y; add_header Cache-Control "public, immutable"; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/ilmora /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.your-domain.com -d app.your-domain.com
```

HTTPS is required: the app stores a login token and works with fee data.

## 6. Queue worker and scheduler

`/etc/supervisor/conf.d/ilmora.conf`:

```ini
[program:ilmora-worker]
command=php /var/www/ilmora/apps/api/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
( sudo crontab -u www-data -l 2>/dev/null; echo "* * * * * php /var/www/ilmora/apps/api/artisan schedule:run >> /dev/null 2>&1" ) | sudo crontab -u www-data -
```

The scheduler sends queued parent messages every minute.

## 7. Backups

```bash
sudo -u postgres pg_dump -Fc ilmora > /var/backups/ilmora-$(date +%F).dump   # run nightly from cron, copy off the server
```

## 8. Updating

```bash
cd /var/www/ilmora && git pull
cd apps/api && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache && php artisan route:cache
cd ../mobile && npm ci && npm run build
sudo supervisorctl restart ilmora-worker
```

## 9. Android and iOS

Set `VITE_API_URL` to the production API, then `npm run cap:sync` and build in Android Studio (`npx cap open android`) or Xcode. Use your own `appId` in `capacitor.config.ts` before publishing to the stores.

## Messaging gateway

With `MESSAGE_DRIVER=http`, each queued message is posted to `MESSAGE_HTTP_URL` as JSON (`to`, `channel`, `body`) with the bearer token. Point it at your WhatsApp Business provider or SMS gateway, or at a small adapter in front of it. Until then, staff can send any message by hand with the WhatsApp button, which opens WhatsApp with the text filled in.
