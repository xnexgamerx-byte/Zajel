#!/usr/bin/env bash
#
# نسخةٌ احتياطية لقاعدة البيانات، مضغوطة، بتاريخها — ومعها الملفّات المرفوعة.
# يشغّلها cron كل ليلة (يضبطه setup.sh)، ويشغّلها update.sh قبل كل تحديث.
#
# كل ما يستحقّ الحفظ في قاعدة البيانات (الجلسات والذاكرة المؤقّتة فيها أيضاً)،
# إلّا ما يرفعه الناس: صور إعلانات التطبيق ومرفقات المحادثات، في حجم storage.
# وتلك لا تتغيّر بعد رفعها، فمرآةٌ واحدة في files/ تُزاد كل ليلة — لا أرشيفٌ
# كاملٌ كل ليلة يتضاعف مع كل نسخة. وباقي storage مؤقّت (سجلّات، واستيرادٌ قيد المعاينة).
#
# الاسترجاع (يمسح ما في القاعدة الآن ويضع النسخة مكانه):
#   gunzip -c /var/backups/zajel/zajel-….sql.gz \
#     | docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot zajel'
# والملفّات (تُضاف إلى ما هناك، ولا يُمسح شيء):
#   tar -C /var/backups/zajel/files -cf - . \
#     | docker compose exec -T app tar -C /var/www/html/storage/app/private -xf -

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

# ── الملفّات المرفوعة: مرآةٌ في files/ ──
# لا تُوقف النسخة إن تعذّرت: القاعدة نُسخت، وupdate.sh يحتاج أن يمضي ولو كان
# التطبيق نفسه هو المعطّل الذي جاء التحديث ليصلحه. لكنها تُقال في السجلّ.
uploads=/var/www/html/storage/app/private
if present=$(docker compose exec -T app sh -c \
        'cd /var/www/html/storage/app/private 2> /dev/null || exit 0
         for d in ads attachments; do [ -d "$d" ] && printf "%s " "$d"; done; exit 0'); then
    if [ -n "$present" ]; then
        mkdir -p "$dir/files"
        # tar يحفظ أوقات الملفّات، فالرفع الخارجي أدناه لا يعيد ما رُفع
        # shellcheck disable=SC2086  # أسماء مجلّداتٍ ثابتة بلا مسافات
        if docker compose exec -T app tar -C "$uploads" -cf - $present | tar -C "$dir/files" -xf -; then
            echo "$(date -u +%FT%TZ) الملفّات المرفوعة: $dir/files ($(du -sh "$dir/files" | cut -f1))"
        else
            echo "$(date -u +%FT%TZ) تعذّر نسخ الملفّات المرفوعة؛ القاعدة نُسخت" >&2
        fi
    fi
else
    echo "$(date -u +%FT%TZ) حاوية التطبيق لا تعمل: لم تُنسخ الملفّات المرفوعة هذه المرّة؛ القاعدة نُسخت" >&2
fi

# نسخةٌ على الخادم نفسه لا تنفع يوم يضيع الخادم
if [ -n "$remote" ]; then
    if command -v rclone >/dev/null; then
        rclone copy "$file" "$remote"
        echo "$(date -u +%FT%TZ) نُسخت إلى $remote"
        # المرآة تُزاد ولا تُمسح هناك أيضاً. والأسماء عشوائية لا تتكرّر، فالحجم
        # يكفي للمقارنة ويغني عن سؤال المخزن عن كل ملفٍّ على حدة. وما حذفته قاعدة
        # «٩٠ يوماً» في المخزن يُرفع ثانيةً في الليلة التالية
        if [ -d "$dir/files" ]; then
            rclone copy "$dir/files" "${remote%/}/files" --size-only
            echo "$(date -u +%FT%TZ) والملفّات المرفوعة إلى ${remote%/}/files"
        fi
    else
        echo "$(date -u +%FT%TZ) BACKUP_REMOTE مضبوط لكن rclone غير مثبّت: لا نسخة خارجية" >&2
        exit 1
    fi
fi
