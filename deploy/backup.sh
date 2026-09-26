#!/usr/bin/env bash
#
# نسخةٌ احتياطية لقاعدة البيانات، مضغوطة، بتاريخها.
# يشغّلها cron كل ليلة (يضبطه setup.sh)، ويشغّلها update.sh قبل كل تحديث.
#
# كل ما يستحقّ الحفظ في قاعدة البيانات: الجلسات والذاكرة المؤقّتة فيها أيضاً،
# وملفات storage مؤقّتة (سجلّات، واستيرادٌ قيد المعاينة).
#
# الاسترجاع (يمسح ما في القاعدة الآن ويضع النسخة مكانه):
#   gunzip -c /var/backups/zajel/zajel-….sql.gz \
#     | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot zajel'

set -euo pipefail
umask 077
cd "$(dirname "$0")"

envget() { grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- || true; }

dir=$(envget BACKUP_DIR);       dir=${dir:-/var/backups/zajel}
keep=$(envget BACKUP_KEEP_DAYS); keep=${keep:-14}
# BACKUP_REMOTE فارغاً في البيئة: نسخةٌ هنا وحدها (offsite-backup.sh يرفعها بنفسه)
remote=${BACKUP_REMOTE-$(envget BACKUP_REMOTE)}

mkdir -p "$dir"
chmod 700 "$dir"

file="$dir/zajel-$(date -u +%Y%m%d-%H%M%S).sql.gz"
trap 'rm -f "$file.part"' EXIT

# --single-transaction: لقطةٌ متّسقة والنظام يعمل، بلا قفل جداول
docker compose exec -T db sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot --single-transaction --quick \
        --routines --triggers --no-tablespaces --set-gtid-purged=OFF zajel' \
    | gzip > "$file.part"

# نسخةٌ انقطعت في منتصفها أخطر من لا نسخة: تبدو سليمة حتى يوم الحاجة
if ! gzip -dc "$file.part" | tail -n 1 | grep -q 'Dump completed'; then
    rm -f "$file.part"
    echo "$(date -u +%FT%TZ) فشلت النسخة الاحتياطية: الملف ناقص" >&2
    exit 1
fi

mv "$file.part" "$file"
echo "$(date -u +%FT%TZ) نسخة: $file ($(du -h "$file" | cut -f1))"

find "$dir" -name 'zajel-*.sql.gz' -mtime +"$keep" -delete

# نسخةٌ على الخادم نفسه لا تنفع يوم يضيع الخادم
if [ -n "$remote" ]; then
    if command -v rclone >/dev/null; then
        rclone copy "$file" "$remote"
        echo "$(date -u +%FT%TZ) نُسخت إلى $remote"
    else
        echo "$(date -u +%FT%TZ) BACKUP_REMOTE مضبوط لكن rclone غير مثبّت: لا نسخة خارجية" >&2
        exit 1
    fi
fi
