#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/logs storage/framework/cache/data storage/framework/views bootstrap/cache
chmod -R 777 storage bootstrap/cache

php <<'PHP'
<?php
$host = getenv('DB_HOST') ?: 'mysql';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USERNAME') ?: 'chatbot2';
$pass = getenv('DB_PASSWORD') ?: '';
$db = getenv('DB_DATABASE') ?: 'chatbot2';

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

exec "$@"
