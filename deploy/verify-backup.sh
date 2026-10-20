#!/usr/bin/env bash
#
# تحقّق النسخة الاحتياطية (docs/plan/68 §١٧-٨): نسخةٌ لا تُستعاد ليست نسخة.
# تُستعاد آخر نسخةٍ (أو ما تسمّيه) في قاعدةٍ جانبية «zajel_verify» على الخادم نفسه، ثم يُشغَّل
# عليها قبول النظام المالي: المطابقة كلّها، وسلسلتا البصمات (الدفتر وسجلّ التدقيق)، وفحوص
# الإقفال. قاعدة التشغيل لا تُمسّ، والجانبية تُحذف بعدها (--keep يبقيها للفحص اليدوي).
#
#   sudo ./verify-backup.sh                      # آخر نسخة
#   sudo ./verify-backup.sh /var/backups/zajel/zajel-20260101-003000.sql.gz --keep
#
# التقرير في storage/app/acceptance داخل حاوية التطبيق، ولا يغادر الخادم.

set -euo pipefail
cd "$(dirname "$0")"

envget() { grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- || true; }

dir=$(envget BACKUP_DIR); dir=${dir:-/var/backups/zajel}
file=""
keep=0
for arg in "$@"; do
    case "$arg" in
        --keep) keep=1 ;;
        *) file=$arg ;;
    esac
done

if [ -z "$file" ]; then
    # الأسماء بتاريخها (zajel-YYYYmmdd-HHMMSS): آخرها بالترتيب آخرها زمناً
    file=$(find "$dir" -maxdepth 1 -name 'zajel-*.sql.gz' 2> /dev/null | sort | tail -n 1)
fi
if [ -z "$file" ] || [ ! -f "$file" ]; then
    echo "لا نسخة في $dir: شغّل ./backup.sh أولاً." >&2
    exit 1
fi

echo "$(date -u +%FT%TZ) استعادة $file في zajel_verify"

docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "
    DROP DATABASE IF EXISTS zajel_verify;
    CREATE DATABASE zajel_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL ON zajel_verify.* TO \"$MYSQL_USER\"@\"%\";"'

gunzip -c "$file" | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot zajel_verify'

status=0
docker compose exec -T -u www-data app php artisan finance:acceptance --database=zajel_verify --no-pages || status=$?

if [ "$keep" -eq 0 ]; then
    docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS zajel_verify;"'
fi

if [ "$status" -eq 0 ]; then
    echo "$(date -u +%FT%TZ) النسخة تُستعاد، ودفترها مطابقٌ وسلسلتاها سليمتان."
else
    echo "$(date -u +%FT%TZ) النسخة استُعيدت لكن في فحصها ما يُراجَع: انظر التقرير أعلاه." >&2
fi
exit "$status"
