#!/bin/sh
# يسبق كل تشغيلٍ للحاوية: يطبخ الإعدادات، ويرحّل قاعدة البيانات في حاوية الويب وحدها.
set -e

# كل ما يكتبه artisan في storage يُكتَب باسم www-data، وإلا عجز عمّال PHP
# عن الكتابة في سجلٍّ أنشأه root فسقطت الصفحات بخطأ ٥٠٠
as_www() {
    if [ "$(id -u)" = "0" ]; then
        runuser -u www-data -- "$@"
    else
        "$@"
    fi
}

as_www php artisan optimize

if [ "$1" = "php-fpm8.5" ]; then
    as_www php artisan migrate --force
fi

exec "$@"
