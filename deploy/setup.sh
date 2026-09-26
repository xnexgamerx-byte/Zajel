#!/usr/bin/env bash
#
# تثبيت وهج العراق على خادمٍ جديد (Ubuntu أو Debian). من مجلّد deploy:
#
#   sudo ./setup.sh                            تثبيتٌ جديد
#   sudo ./setup.sh --restore zajel-move-….tar.gz   نقلٌ من خادمٍ آخر (export.sh)
#
# يثبّت Docker إن غاب، ويكتب .env بأسرارٍ عشوائية، ويبني ويشغّل، ويزرع
# البيانات المرجعية (المحافظات، أسباب التعذّر، الباقات)، ويُنشئ مدير المنصّة،
# ويضبط النسخ الاحتياطي الليلي. تشغيله مرّةً ثانية آمن: لا يعيد ما تمّ.
#
# وفي النقل: .env والقاعدة من الحزمة بدل الأسرار الجديدة والزرع — فالمفتاح نفسه
# (روابط QR المطبوعة تعمل)، والحسابات نفسها، والجلسات نفسها.

set -euo pipefail

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
die() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

restore=""
if [ "${1:-}" = "--restore" ]; then
    [ -r "${2:-}" ] || die "لا ملف بهذا الاسم: ${2:-}"
    restore=$(realpath "$2")
fi

cd "$(dirname "$0")"

[ "$(id -u)" = "0" ] || die "شغّله بصلاحية المدير: sudo ./setup.sh"

# ذاكرة قاعدة البيانات: ربع ذاكرة الخادم، بين ٢٥٦ ميغابايت و٨ غيغابايت.
# تُحسب من جديد في النقل: خادمٌ أكبر هو غالباً سبب النقل
buffer_pool() {
    local mem_mb pool_mb
    mem_mb=$(awk '/MemTotal/ {print int($2 / 1024)}' /proc/meminfo)
    pool_mb=$(( mem_mb / 4 )); (( pool_mb < 256 )) && pool_mb=256; (( pool_mb > 8192 )) && pool_mb=8192
    echo "${pool_mb}M"
}

# ── ١. Docker ──
if ! command -v docker > /dev/null || ! docker compose version > /dev/null 2>&1; then
    say "تثبيت Docker…"
    curl -fsSL https://get.docker.com | sh
fi

# ── ٢. الإعداد والأسرار ──
if [ -n "$restore" ]; then
    # نظامٌ يعمل هنا لا يُمحى بخطأ في اسم ملف
    [ -f .installed ] && die "هذا الخادم يعمل عليه النظام بالفعل، والاسترجاع يمحو قاعدته. الاسترجاع لخادمٍ جديد فقط."

    work=$(mktemp -d)
    trap 'rm -rf "$work"' EXIT
    tar -xzf "$restore" -C "$work"
    [ -f "$work/.env" ] && [ -f "$work/database.sql.gz" ] || die "هذه ليست حزمة نقلٍ من export.sh."

    if [ -f .env ] && ! cmp -s .env "$work/.env"; then
        die "هنا .env مختلف عن الذي في الحزمة. إن كان من محاولةٍ سابقة على هذا الخادم: docker compose down -v && rm .env ثم أعد."
    fi

    install -m 600 "$work/.env" .env
    sed -i "s/^DB_BUFFER_POOL=.*/DB_BUFFER_POOL=$(buffer_pool)/" .env
    say "الإعداد من الحزمة: $(grep -E '^DOMAIN=' .env | cut -d= -f2-)"
elif [ ! -f .env ]; then
    read -rp "النطاق الأساسي (مثل wahaj.iq): " domain
    domain=$(printf '%s' "$domain" | tr '[:upper:]' '[:lower:]' | sed 's#^https\?://##; s#/.*##; s#^www\.##')
    [[ "$domain" =~ ^[a-z0-9-]+(\.[a-z0-9-]+)+$ ]] || die "نطاقٌ غير صحيح: $domain"

    read -rp "بريدٌ لتنبيهات شهادات HTTPS: " email
    [[ "$email" == *@*.* ]] || die "بريدٌ غير صحيح: $email"

    umask 077
    cat > .env <<ENV
DOMAIN=$domain
ACME_EMAIL=$email
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=$(openssl rand -hex 24)
DB_ROOT_PASSWORD=$(openssl rand -hex 24)
DB_BUFFER_POOL=$(buffer_pool)
BACKUP_DIR=/var/backups/zajel
BACKUP_KEEP_DAYS=14
BACKUP_REMOTE=
ENV
    umask 022
    say "كُتب deploy/.env. احفظ نسخةً منه خارج الخادم: فيه مفتاح التطبيق الذي تُوقَّع به روابط التتبّع المطبوعة على الوصولات."
fi

# ── ٣. البناء والتشغيل ──
say "بناء الصور وتشغيلها (أول مرّة: بضع دقائق)…"
# --wait: حتى تنتهي الترحيلات ويعمل كل شيء
docker compose up -d --build --wait \
    || die "التطبيق لم يبدأ. السبب في: docker compose logs app"

# ── ٤. أوّل تشغيل فقط: القاعدة من الحزمة، أو البيانات المرجعية ومدير المنصّة ──
# لا تُعاد في كل تشغيل: الباقات تُعدَّل من لوحة المنصّة، وإعادة الزرع تمحو التعديل
if [ -n "$restore" ] && [ ! -f .installed ]; then
    say "استرجاع قاعدة البيانات…"
    docker compose stop web scheduler

    # قاعدةٌ فارغة ثم النسخة: جداول ترحيلاتٍ أحدث من النسخة لا تبقى فتتعارض
    docker compose exec -T db sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE zajel; CREATE DATABASE zajel"'
    gunzip -c "$work/database.sql.gz" \
        | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot zajel'

    # نسخةٌ من شيفرةٍ أقدم من هذه: ما جدّ من ترحيلاتٍ يجري الآن
    docker compose exec -T -u www-data app php artisan migrate --force
    docker compose up -d --wait

    date -u +%FT%TZ > .installed
elif [ ! -f .installed ]; then
    docker compose exec -T -u www-data app php artisan db:seed --force

    say "مدير المنصّة: منه تُسجَّل الشركات."
    read -rp "رقم هاتف المدير (07xxxxxxxxx): " admin_phone
    docker compose exec -u www-data app php artisan zajel:admin "$admin_phone"

    date -u +%FT%TZ > .installed
fi

# ── ٥. النسخ الاحتياطي الليلي: ٣:٣٠ فجراً بتوقيت بغداد ──
cat > /etc/cron.d/zajel-backup <<CRON
30 0 * * * root $(pwd)/backup.sh >> /var/log/zajel-backup.log 2>&1
CRON
chmod 644 /etc/cron.d/zajel-backup

domain=$(grep -E '^DOMAIN=' .env | cut -d= -f2-)
ip=$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')

if [ -n "$restore" ]; then
    say "تمّ النقل."
    cat <<DONE

  الآن غيّر سجلّي DNS عند مزوّد النطاق إلى هذا الخادم:
    A   $domain      →  $ip
    A   *.$domain    →  $ip

  بعد دقائق يفتح النظام من هنا بكل بياناته وحساباته، وتُصدَر الشهادات وحدها.
  تأكّد: ادخل https://admin.$domain/admin/login وافتح آخر شحنةٍ في شركة.

  ثم أوقف الخادم القديم بعد يومٍ أو يومين من العمل هنا بلا مشكلة.
DONE
    # حزمةٌ صنعها from-railway.sh تُحذف وحدها؛ وما نُسخ باليد يُحذف باليد
    if [ -z "${ZAJEL_BUNDLE_IS_TEMP:-}" ]; then
        echo "  واحذف الحزمة، ففيها كل الأسرار:  rm $restore"
    fi
    exit 0
fi

say "تمّ."
cat <<DONE

  سجلّات DNS المطلوبة عند مزوّد النطاق (إن لم تُضَف بعد):
    A   $domain      →  $ip
    A   *.$domain    →  $ip

  لوحة المنصّة:  https://admin.$domain/admin/login
  ومنها «تسجيل شركة»؛ فيعمل نظامها على  https://<نطاقها>.$domain

  الحالة:   docker compose ps
  السجلّات: docker compose logs -f app
  التحديث:  sudo ./update.sh
DONE
