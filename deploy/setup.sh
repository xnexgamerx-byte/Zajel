#!/usr/bin/env bash
#
# تثبيت زاجل على خادمٍ جديد (Ubuntu أو Debian). من مجلّد deploy:
#
#   sudo ./setup.sh
#
# يثبّت Docker إن غاب، ويكتب .env بأسرارٍ عشوائية، ويبني ويشغّل، ويزرع
# البيانات المرجعية (المحافظات، أسباب التعذّر، الباقات)، ويُنشئ مدير المنصّة،
# ويضبط النسخ الاحتياطي الليلي. تشغيله مرّةً ثانية آمن: لا يعيد ما تمّ.

set -euo pipefail
cd "$(dirname "$0")"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
die() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "شغّله بصلاحية المدير: sudo ./setup.sh"

# ── ١. Docker ──
if ! command -v docker > /dev/null || ! docker compose version > /dev/null 2>&1; then
    say "تثبيت Docker…"
    curl -fsSL https://get.docker.com | sh
fi

# ── ٢. الإعداد والأسرار ──
if [ ! -f .env ]; then
    read -rp "النطاق الأساسي (مثل zajel.iq): " domain
    domain=$(printf '%s' "$domain" | tr '[:upper:]' '[:lower:]' | sed 's#^https\?://##; s#/.*##; s#^www\.##')
    [[ "$domain" =~ ^[a-z0-9-]+(\.[a-z0-9-]+)+$ ]] || die "نطاقٌ غير صحيح: $domain"

    read -rp "بريدٌ لتنبيهات شهادات HTTPS: " email
    [[ "$email" == *@*.* ]] || die "بريدٌ غير صحيح: $email"

    # ذاكرة قاعدة البيانات: ربع ذاكرة الخادم، بين ٢٥٦ ميغابايت و٨ غيغابايت
    mem_mb=$(awk '/MemTotal/ {print int($2 / 1024)}' /proc/meminfo)
    pool_mb=$(( mem_mb / 4 )); (( pool_mb < 256 )) && pool_mb=256; (( pool_mb > 8192 )) && pool_mb=8192

    umask 077
    cat > .env <<ENV
DOMAIN=$domain
ACME_EMAIL=$email
APP_KEY=base64:$(openssl rand -base64 32)
DB_PASSWORD=$(openssl rand -hex 24)
DB_ROOT_PASSWORD=$(openssl rand -hex 24)
DB_BUFFER_POOL=${pool_mb}M
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

# ── ٤. أوّل تشغيل فقط: البيانات المرجعية ومدير المنصّة ──
# لا تُعاد في كل تشغيل: الباقات تُعدَّل من لوحة المنصّة، وإعادة الزرع تمحو التعديل
if [ ! -f .installed ]; then
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
