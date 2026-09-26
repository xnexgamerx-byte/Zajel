#!/usr/bin/env bash
#
# تحديث النظام لآخر نسخة: نسخةٌ احتياطية أولاً، ثم الشيفرة، ثم الصور.
# الترحيلات تجري وحدها حين تبدأ حاوية التطبيق.
#
#   sudo ./update.sh

set -euo pipefail
cd "$(dirname "$0")"

./backup.sh
git -C .. pull --ff-only

# خلف Cloudflare: عناوينها من جديد. إن تعذّر الوصول إليها تبقى السابقة
if grep -q '^CADDYFILE=Caddyfile.cloudflare' .env; then
    ./cloudflare-ranges.sh || echo "تعذّر تحديث عناوين Cloudflare؛ تبقى السابقة." >&2
fi

docker compose up -d --build --wait
docker image prune -f > /dev/null
docker compose ps
