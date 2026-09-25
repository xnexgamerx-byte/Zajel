#!/usr/bin/env bash
#
# من Railway إلى خادمٍ عادي — على الخادم الجديد، من مجلّد deploy:
#
#   sudo ./from-railway.sh
#
# يسأل أربعة: رابط قاعدة Railway العامّ (MYSQL_PUBLIC_URL من متغيّرات خدمة
# MySQL)، وAPP_KEY (من متغيّرات خدمة زاجل)، والنطاق، والبريد. ثم ينسخ القاعدة
# ويصنع حزمة نقلٍ كالتي يصنعها export.sh، ويكمل بـ setup.sh --restore.
#
# المفتاح نفسه لا مفتاحٌ جديد: رموز QR على الوصولات المطبوعة تبقى تعمل.

set -euo pipefail
umask 077
cd "$(dirname "$0")"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
die() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "شغّله بصلاحية المدير: sudo ./from-railway.sh"
[ -f .installed ] && die "هذا الخادم يعمل عليه زاجل بالفعل. النقل لخادمٍ جديد فقط."

if ! command -v docker > /dev/null || ! docker compose version > /dev/null 2>&1; then
    say "تثبيت Docker…"
    curl -fsSL https://get.docker.com | sh
fi

say "من Railway: خدمة MySQL ← Variables ← MYSQL_PUBLIC_URL"
read -rp "الرابط: " url
[[ "$url" =~ ^mysql://([^:]+):([^@]+)@([^:/]+):([0-9]+)/([^?]+) ]] \
    || die "ليس رابط MySQL: يبدأ بـ mysql:// وفيه المستخدم وكلمة المرور والمضيف والمنفذ."
db_user=${BASH_REMATCH[1]} db_pass=${BASH_REMATCH[2]} db_host=${BASH_REMATCH[3]}
db_port=${BASH_REMATCH[4]} db_name=${BASH_REMATCH[5]}

say "من Railway: خدمة زاجل ← Variables ← APP_KEY"
read -rp "APP_KEY: " app_key
[[ "$app_key" == base64:* ]] || die "APP_KEY يبدأ بـ base64:"

read -rp "النطاق الأساسي (مثل zajel.iq): " domain
domain=$(printf '%s' "$domain" | tr '[:upper:]' '[:lower:]' | sed 's#^https\?://##; s#/.*##; s#^www\.##')
[[ "$domain" =~ ^[a-z0-9-]+(\.[a-z0-9-]+)+$ ]] || die "نطاقٌ غير صحيح: $domain"

read -rp "بريدٌ لتنبيهات شهادات HTTPS: " email
[[ "$email" == *@*.* ]] || die "بريدٌ غير صحيح: $email"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

say "نسخ قاعدة البيانات من Railway…"
docker run --rm --network host -e MYSQL_PWD="$db_pass" mysql:8.4 \
    mysqldump -h "$db_host" -P "$db_port" -u "$db_user" --single-transaction --quick \
        --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$db_name" \
    | gzip > "$work/database.sql.gz"

gzip -dc "$work/database.sql.gz" | tail -n 1 | grep -q 'Dump completed' \
    || die "النسخة ناقصة: تأكّد من الرابط، وأنّ خدمة MySQL على Railway تعمل."

cat > "$work/.env" <<ENV
DOMAIN=$domain
ACME_EMAIL=$email
APP_KEY=$app_key
DB_PASSWORD=$(openssl rand -hex 24)
DB_ROOT_PASSWORD=$(openssl rand -hex 24)
DB_BUFFER_POOL=1G
BACKUP_DIR=/var/backups/zajel
BACKUP_KEEP_DAYS=14
BACKUP_REMOTE=
ENV

tar -czf "$work/zajel-move.tar.gz" -C "$work" .env database.sql.gz
ZAJEL_BUNDLE_IS_TEMP=1 ./setup.sh --restore "$work/zajel-move.tar.gz"
