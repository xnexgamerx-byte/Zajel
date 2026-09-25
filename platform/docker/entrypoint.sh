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
        # Railway: DB_URL مرجعٌ إلى خدمة MySQL (${{MySQL.MYSQL_URL}}). فارغٌ يعني
        # أنّ المرجع لم يجد خدمةً بذلك الاسم، فيتّصل Laravel بـ 127.0.0.1
        # ويسقط بمئة سطرٍ لا تقول ذلك
        if [ "$1" = "zajel-railway" ] && [ -z "${DB_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
            if [ -n "${DB_URL+set}" ]; then
                echo "DB_URL وصل فارغاً: مرجعه لم يجد خدمة قاعدة البيانات." >&2
                echo "في المشروع خدمة MySQL باسم MySQL تماماً، وفي متغيّرات زاجل:" >&2
                echo 'DB_URL=${{MySQL.MYSQL_URL}}' >&2
                echo "وإن كان اسم خدمة القاعدة غير ذلك فضعه مكان MySQL." >&2
            else
                echo "DB_URL لم يصل إلى هذه الخدمة (${RAILWAY_SERVICE_NAME:-؟}) أصلاً." >&2
                echo "أضفه في Variables لهذه الخدمة نفسها واحفظه (✓)، ثم Deploy لتطبيق التغيير." >&2
            fi
            # الأسماء وحدها، لا القيم: فيها كلمة سرّ القاعدة
            names=$(env | cut -d= -f1 | grep -iE 'db|mysql|database' | grep -vx 'DB_CONNECTION' | sort | tr '\n' ' ')
            echo "متغيّرات القاعدة التي وصلت: ${names:-لا شيء}" >&2
            exit 1
        fi

        # قاعدةٌ تبدأ مع التطبيق (أوّل نشرٍ على Railway) تتأخّر ثوانيَ: تُنتظَر
        # بدل السقوط. وغير ذلك من أخطاء الترحيل يُظهَر فوراً كما هو
        attempt=1
        until as_www php artisan migrate --force > /tmp/migrate.log 2>&1; do
            if [ "$attempt" -ge 20 ] \
                || ! grep -qE 'SQLSTATE\[HY000\] \[(2002|2006|2013)\]|getaddrinfo|php_network_getaddresses' /tmp/migrate.log; then
                cat /tmp/migrate.log >&2
                exit 1
            fi
            echo "قاعدة البيانات لم تجب بعد (محاولة $attempt من 20)…" >&2
            attempt=$((attempt + 1))
            sleep 3
        done
        cat /tmp/migrate.log

        as_www php artisan zajel:bootstrap
        ;;
esac

exec "$@"
