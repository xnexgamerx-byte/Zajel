#!/usr/bin/env bash
#
# النقل إلى خادمٍ آخر — الخطوة الأولى، على الخادم القديم:
#
#   sudo ./export.sh
#
# يوقف الموقع (فلا يُكتب شيءٌ بعد النسخة ويضيع)، ويأخذ نسخةً أخيرة، ويحزمها
# مع .env في ملفٍ واحد. ثم على الخادم الجديد:
#
#   sudo ./setup.sh --restore zajel-move-….tar.gz
#
# والملف فيه كل شيء: مفتاح التطبيق (فتبقى روابط QR المطبوعة تعمل)، وكلمات
# السرّ، وقاعدة البيانات كاملة. انقله كما تنقل مفاتيح الخزنة، واحذفه بعد النقل.

set -euo pipefail
umask 077
cd "$(dirname "$0")"

die() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "شغّله بصلاحية المدير: sudo ./export.sh"
[ -f .env ] || die "لا .env هنا: هذا ليس خادم زاجل مثبَّتاً."

# الموقع يتوقّف، وقاعدة البيانات تبقى لتُنسَخ
docker compose stop web scheduler

./backup.sh

dir=$(grep -E '^BACKUP_DIR=' .env | cut -d= -f2- || true); dir=${dir:-/var/backups/zajel}
dump=$(ls -t "$dir"/zajel-*.sql.gz | head -n 1)

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cp .env "$work/.env"
cp "$dump" "$work/database.sql.gz"
git -C .. rev-parse HEAD > "$work/version" 2>/dev/null || true

bundle="$dir/zajel-move-$(date -u +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$bundle" -C "$work" .env database.sql.gz version

ip=$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')

cat <<DONE

  الحزمة: $bundle ($(du -h "$bundle" | cut -f1))

  ١. انسخها إلى الخادم الجديد (من جهازك أو من هنا):
       scp root@$ip:$bundle root@<عنوان الخادم الجديد>:/root/

  ٢. على الخادم الجديد:
       git clone https://github.com/xnexgamerx-byte/Zajel.git /opt/zajel
       cd /opt/zajel/deploy
       sudo ./setup.sh --restore /root/$(basename "$bundle")

  ٣. غيّر سجلّي DNS (النطاق و*.النطاق) إلى عنوان الخادم الجديد.

  الموقع هنا متوقّفٌ الآن كي لا يُكتب فيه شيءٌ يضيع.
  إن تراجعت عن النقل:  docker compose start web scheduler
DONE
