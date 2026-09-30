#!/bin/sh
set -e

cd /var/www/html
export COMPOSER_ALLOW_SUPERUSER=1

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

sed -i 's/\r$//' .env

key=$(grep '^APP_KEY=' .env | cut -d= -f2- | tr -d '\r')
if [ -z "$key" ]; then
    key=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
    sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" .env
fi
export APP_KEY="$key"

token=$(grep '^ADMIN_TOKEN=' .env | cut -d= -f2- | tr -d '\r')
if [ -z "$token" ] || [ "$token" = "replace-with-a-long-random-string" ]; then
    token=$(php -r 'echo bin2hex(random_bytes(16));')
    sed -i "s/^ADMIN_TOKEN=.*/ADMIN_TOKEN=${token}/" .env
    echo "Сгенерирован ADMIN_TOKEN=${token}"
fi
export ADMIN_TOKEN="$token"

mkdir -p storage/logs storage/framework/cache/data storage/framework/views bootstrap/cache
chmod -R 777 storage bootstrap/cache

php <<'PHP'
<?php
$host = getenv('DB_HOST') ?: 'mysql';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USERNAME') ?: 'chatbot';
$pass = getenv('DB_PASSWORD') ?: 'chatbot';
$db = getenv('DB_DATABASE') ?: 'chatbot';

for ($i = 0; $i < 60; $i++) {
    try {
        new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "waiting for mysql...\n");
        sleep(2);
    }
}

fwrite(STDERR, "mysql is not ready\n");
exit(1);
PHP

php artisan migrate --force
php artisan vk:import-env

exec "$@"
