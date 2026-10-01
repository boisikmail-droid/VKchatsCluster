FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        icu-libs \
        libzip \
        oniguruma \
        sqlite-libs \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        sqlite-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql pdo_sqlite intl zip opcache \
    && apk del icu-dev libzip-dev oniguruma-dev sqlite-dev $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/zz-clear-env.conf /usr/local/etc/php-fpm.d/zz-clear-env.conf
COPY docker/php/entrypoint.sh /entrypoint.sh
COPY docker/php/entrypoint-k8s.sh /entrypoint-k8s.sh
RUN sed -i 's/\r$//' /entrypoint.sh /entrypoint-k8s.sh && chmod +x /entrypoint.sh /entrypoint-k8s.sh

COPY . .
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader \
    && mkdir -p storage/logs storage/framework/cache/data storage/framework/views bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
