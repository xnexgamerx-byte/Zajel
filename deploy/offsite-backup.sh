#!/usr/bin/env bash
#
# نسخةٌ احتياطية خارج الخادم، على Cloudflare R2 (١٠ غيغابايت مجاناً):
#
#   sudo ./offsite-backup.sh
#
# قبله في Cloudflare (README: «النسخ الاحتياطي»): مخزن R2، ومفتاح API له وحده
# بصلاحية Object Read & Write. يسأل عن الرابط والمفتاح — والسرّ لا يظهر وهو
# يُلصق، ولا يبقى في سجلّ الأوامر — ويضبط rclone، ثم يأخذ نسخةً الآن ويرفعها
# ويتأكّد أنها وصلت كاملة، وبعدها وحدها يكتب BACKUP_REMOTE في .env: فكل نسخةٍ
# ليلية بعده تُرفع أيضاً. تشغيله مرّةً ثانية آمن: لمفتاحٍ جديد، أو على خادمٍ
# نُقل إليه النظام (export.sh لا يحمل المفتاح).

set -euo pipefail
cd "$(dirname "$0")"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
die() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "شغّله بصلاحية المدير: sudo ./offsite-backup.sh"
[ -f .env ] || die "النظام لم يُثبَّت هنا بعد: sudo ./setup.sh أوّلاً."

remote=wahaj-r2

# إعداد rclone للمدير، حيث يقرؤه backup.sh حين يشغّله cron كل ليلة. والجديد
# يُجرَّب أوّلاً في ملفٍّ مؤقّت: خطأٌ في اللصق لا يمسّ وجهةً تعمل
config="$(getent passwd root | cut -d: -f6)/.config/rclone/rclone.conf"
trial=$(mktemp)
err=$(mktemp)
trap 'rm -f "$trial" "$err"' EXIT
export RCLONE_CONFIG=$trial

if ! command -v rclone > /dev/null; then
    say "تثبيت rclone…"
    { apt-get update -q && apt-get install -y -q rclone; } > /dev/null \
        || die "تعذّر تثبيت rclone. جرّب: apt-get install rclone"
fi

# ── ما يُلصق من Cloudflare ──
read -rp "رابط S3 (https://….r2.cloudflarestorage.com): " url || die "لا جواب."
url=$(printf '%s' "$url" | tr -d ' \r\t')
# ورابط المخزن من صفحته يحمل اسمه بعد /
endpoint="" bucket=""
if [[ "$url" =~ ^(https://[^/]+)/*([^/]*) ]]; then
    endpoint=${BASH_REMATCH[1]} bucket=${BASH_REMATCH[2]}
fi
[[ "$endpoint" =~ ^https://[0-9a-f]{32}(\.[a-z]+)?\.r2\.cloudflarestorage\.com$ ]] \
    || die "هذا ليس رابط R2: يبدأ بـ https:// ثم ٣٢ حرفاً ثم .r2.cloudflarestorage.com"

read -rp "Access Key ID: " key_id || die "لا جواب."
key_id=$(printf '%s' "$key_id" | tr -d ' \r\t')
[[ "$key_id" =~ ^[0-9a-fA-F]{32}$ ]] \
    || die "Access Key ID اثنان وثلاثون حرفاً (أرقام وa إلى f). انسخه من صفحة المفتاح كما هو."

read -rsp "Secret Access Key (لا يظهر وأنت تلصقه، ثم Enter): " secret || die "لا جواب."
echo
secret=$(printf '%s' "$secret" | tr -d ' \r\t')
[[ "$secret" =~ ^[0-9a-fA-F]{64}$ ]] \
    || die "Secret Access Key أربعة وستون حرفاً (أرقام وa إلى f) — لا «Token value» الذي فوقه. انسخه من صفحة المفتاح كما هو."

if [ -z "$bucket" ]; then
    read -rp "اسم المخزن [wahaj-backups]: " bucket || die "لا جواب."
    bucket=${bucket:-wahaj-backups}
fi
[[ "$bucket" =~ ^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$ ]] || die "اسم مخزنٍ غير صحيح: $bucket"

# no_check_bucket: المفتاح لمخزنٍ واحد لا يرى قائمة المخازن ولا يُنشئها
configure() {
    rclone config delete "$remote" > /dev/null 2>&1 || true
    rclone config create "$remote" s3 provider=Cloudflare \
        access_key_id="$key_id" secret_access_key="$secret" endpoint="$endpoint" \
        region=auto acl=private no_check_bucket=true --non-interactive > /dev/null \
        || die "تعذّر ضبط rclone."
}
configure

quick=(--retries 1 --low-level-retries 1 --contimeout 15s --timeout 60s)

say "تجربة الوصول إلى المخزن $bucket…"
if ! rclone lsf "$remote:$bucket" --max-depth 1 "${quick[@]}" > /dev/null 2> "$err"; then
    tail -n 2 "$err" >&2
    die "لم يُفتح المخزن. تأكّد من اسمه ($bucket)، وأنّ المفتاح له بصلاحية Object Read & Write، وأنك نسخت المفتاحين كما هما."
fi

# ── نسخةٌ الآن، تُرفع ويُتأكّد منها قبل أن يُعتمد عليها كل ليلة ──
# هنا وحدها: الرفع بالمفتاح الجديد بعدها، لا بما في .env الآن
BACKUP_REMOTE='' ./backup.sh
dir=$(grep -E '^BACKUP_DIR=' .env | tail -n 1 | cut -d= -f2- || true); dir=${dir:-/var/backups/zajel}
file=$(find "$dir" -maxdepth 1 -name 'zajel-*.sql.gz' -printf '%T@ %p\n' | sort -n | tail -n 1 | cut -d' ' -f2-)
[ -n "$file" ] || die "لم تُصنع نسخة في $dir."

say "رفع $(basename "$file") ($(du -h "$file" | cut -f1))…"
rclone copy "$file" "$remote:$bucket" "${quick[@]}" 2> "$err" \
    || { tail -n 2 "$err" >&2; die "تعذّر الرفع إلى R2."; }
rclone check "$dir" "$remote:$bucket" --one-way --include "$(basename "$file")" "${quick[@]}" 2> "$err" \
    || { tail -n 2 "$err" >&2; die "النسخة في R2 لا تطابق التي هنا."; }

# وصلت: المفتاح الجديد مكان القديم، وBACKUP_REMOTE إليه
export RCLONE_CONFIG=$config
mkdir -p "$(dirname "$config")"
configure
chmod 600 "$config"

if grep -q '^BACKUP_REMOTE=' .env; then
    sed -i "s#^BACKUP_REMOTE=.*#BACKUP_REMOTE=$remote:$bucket#" .env
else
    echo "BACKUP_REMOTE=$remote:$bucket" >> .env
fi

say "تمّ: النسخة وصلت R2 كاملة، وكل نسخةٍ ليلية بعدها تُرفع إليه أيضاً."
cat <<DONE

  لترى النسخ: Cloudflare ← R2 ← $bucket
  وكي لا يمتلئ المخزن: $bucket ← Settings ← Object lifecycle rules ←
  Add rule، واحذف ما مضى عليه ٣٠ يوماً.

  والمفتاح يبقى في Bitwarden: على خادمٍ جديد يُعاد هذا الأمر به.
DONE
