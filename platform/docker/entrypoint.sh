#!/bin/sh
# يسبق كل تشغيلٍ للحاوية: يطبخ الإعدادات، ويرحّل قاعدة البيانات ويجهّزها في
# حاوية الويب وحدها (لا في مجدوِل deploy/compose.yaml: اثنان يرحّلان معاً يتصادمان).
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

# مفتاح التطبيق يوقّع الجلسات وروابط QR المطبوعة: لا يُولَّد وحده في كل
# بدء (فتبطل الروابط)، بل يُقترَح مرّةً ليُحفظ في الإعدادات
if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY مفقود. أضفه إلى الإعدادات (Variables) بهذه القيمة، واحفظها في مكانٍ آمن:" >&2
    echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" >&2
    exit 1
fi

# Railway: الموقع بلا APP_URL يُبنى من النطاق
if [ -z "${APP_URL:-}" ] && [ -n "${ZAJEL_TENANT_DOMAIN:-}" ]; then
    export APP_URL="https://admin.${ZAJEL_TENANT_DOMAIN}"
fi

as_www php artisan optimize

case "$1" in
    php-fpm8.5|zajel-railway)
        as_www php artisan migrate --force
        as_www php artisan zajel:bootstrap
        ;;
esac

exec "$@"
