# syntax=docker/dockerfile:1
#
# صورة الإنتاج، ولا يلزم على الخادم PHP ولا Node.
#   app     — PHP-FPM 8.5 بالشيفرة والحزم والواجهة المبنيّة   (deploy/compose.yaml)
#   web     — Caddy بالملفات العامّة، يمرّر الباقي إلى app     (deploy/compose.yaml)
#   railway — الاثنان والمجدوِل في حاويةٍ واحدة على $PORT (deploy/railway.md). آخر مرحلة
#             عمداً: Railway يبني آخر مرحلةٍ في الملف

# ── الواجهة: Vite يبني CSS وJS ──
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# المشروع كلّه لا resources وحدها: Tailwind يلتقط الأصناف من كل الملفات
COPY . .
RUN npm run build

# ── حزم PHP، بلا أدوات التطوير ──
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-req=ext-*

# ── التطبيق: PHP من Ubuntu LTS نفسها، فتحديثاته الأمنية تأتي مع كل بناء ──
FROM ubuntu:26.04 AS app

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-curl \
        php8.5-zip php8.5-gd php8.5-intl php8.5-bcmath ca-certificates tzdata \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/php

COPY docker/php.ini /etc/php/8.5/fpm/conf.d/99-zajel.ini
COPY docker/php.ini /etc/php/8.5/cli/conf.d/99-zajel.ini
COPY docker/php-fpm.conf /etc/php/8.5/fpm/pool.d/zz-zajel.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/zajel-entrypoint
ENTRYPOINT ["zajel-entrypoint"]
CMD ["php-fpm8.5", "--nodaemonize"]

# ── خادم الويب: الملفات العامّة في المسار نفسه الذي يراه PHP ──
FROM caddy:2 AS web
COPY --from=app /var/www/html/public /var/www/html/public

# ── Railway: Caddy وPHP-FPM معاً، وإعدادات الإنتاج افتراضية فيبقى القليل للوحة ──
FROM app AS railway

COPY --from=caddy:2 /usr/bin/caddy /usr/bin/caddy
COPY docker/Caddyfile.railway /etc/caddy/Caddyfile
COPY docker/php-fpm.railway.conf /etc/php/8.5/fpm/pool.d/zzz-railway.conf
COPY docker/railway-start.sh /usr/local/bin/zajel-railway

# خلف وكيل Railway: عنوان الزائر من X-Forwarded-For (config/trustedproxy.php)
ENV APP_NAME=زاجل \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_LOCALE=ar \
    APP_FALLBACK_LOCALE=ar \
    TRUSTED_PROXIES=* \
    DB_CONNECTION=mysql \
    SESSION_DRIVER=database \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    MAIL_MAILER=log

CMD ["zajel-railway"]
