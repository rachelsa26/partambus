# PARTAMBUS - image aplikasi (PHP 8.3 + Apache)
# Build context = root repo (lihat docker-compose.yml).

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --ignore-platform-reqs --no-scripts

FROM php:8.3-apache

# Ekstensi yang dibutuhkan aplikasi + PhpSpreadsheet (import produk).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libfreetype6-dev libjpeg62-turbo-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd \
 && rm -rf /var/lib/apt/lists/*

# Aplikasi mengandalkan .htaccess untuk menutup folder config/, lib/,
# database/, backups/ dan file *.sql. Tanpa AllowOverride All, file-file
# itu bisa diunduh publik — ada test keamanan yang memeriksa ini.
RUN a2enmod rewrite headers \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf \
 && echo 'date.timezone=Asia/Jakarta' > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor

# Folder yang ditulis aplikasi saat runtime (backup & import).
RUN mkdir -p backups imports && chown -R www-data:www-data backups imports

HEALTHCHECK --interval=5s --timeout=3s --retries=20 \
  CMD curl -fsS -o /dev/null http://localhost/auth/login.php || exit 1
