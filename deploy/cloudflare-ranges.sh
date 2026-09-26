#!/usr/bin/env bash
#
# عناوين Cloudflare في .env، للخادم خلفها (CADDYFILE=Caddyfile.cloudflare):
#
#   CLOUDFLARE_RANGES  لـ Caddy: منها وحدها يُصدَّق رأس X-Forwarded-For
#   TRUSTED_PROXIES    للتطبيق: القائمة نفسها بفواصل
#
# فيعرف النظام الزائر الحقيقيّ خلف Cloudflare — وحدّ محاولات الدخول يحسب كل
# زائرٍ وحده — ولا يكتب أحدٌ عنوانه بنفسه من خارجها. يشغّله setup.sh أوّل مرة،
# وupdate.sh في كل تحديث: القائمة من Cloudflare نفسها، وتتغيّر نادراً.

set -euo pipefail
cd "$(dirname "$0")"

[ -f .env ] || { echo "لا .env هنا" >&2; exit 1; }

ranges=$(
    { curl -fsS --max-time 20 https://www.cloudflare.com/ips-v4; echo;
      curl -fsS --max-time 20 https://www.cloudflare.com/ips-v6; echo; } \
    | grep -E '^[0-9a-fA-F:.]+/[0-9]+$' | tr '\n' ' ' | sed 's/ $//'
)

# أربعة عشر عنواناً لـ IPv4 وسبعةٌ لـ IPv6 منذ سنوات: أقلّ من عشرة يعني ردّاً مقطوعاً
[ "$(wc -w <<< "$ranges")" -ge 10 ] || { echo "قائمة عناوين Cloudflare لم تصل كاملة" >&2; exit 1; }

set_env() {
    if grep -q "^$1=" .env; then
        sed -i "s#^$1=.*#$1=\"$2\"#" .env
    else
        printf '%s="%s"\n' "$1" "$2" >> .env
    fi
}

set_env CLOUDFLARE_RANGES "$ranges"
set_env TRUSTED_PROXIES "${ranges// /,}"

echo "عناوين Cloudflare: $(wc -w <<< "$ranges")"
