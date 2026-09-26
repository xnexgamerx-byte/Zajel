#!/usr/bin/env bash
#
# عناوين Cloudflare في .env، للخادم خلفها (CADDYFILE=Caddyfile.cloudflare):
#
#   CLOUDFLARE_RANGES  لـ Caddy: منها وحدها يُصدَّق رأس X-Forwarded-For
#   TRUSTED_PROXIES    للتطبيق: القائمة نفسها بفواصل
#
# فيعرف النظام الزائر الحقيقيّ خلف Cloudflare — وحدّ محاولات الدخول يحسب كل
# زائرٍ وحده — ولا يكتب أحدٌ عنوانه بنفسه من خارجها. يشغّله setup.sh
# وcloudflare-mode.sh أوّل مرة، وupdate.sh في كل تحديث: القائمة من Cloudflare
# نفسها، وتتغيّر نادراً.
#
#   ./cloudflare-ranges.sh --firewall   قواعد جدار الحماية بالعناوين التي في .env

set -euo pipefail
cd "$(dirname "$0")"

[ -f .env ] || { echo "لا .env هنا" >&2; exit 1; }
[ -r .env ] || { echo "شغّله بصلاحية المدير: sudo $0" >&2; exit 1; }

current() { grep -E '^CLOUDFLARE_RANGES=' .env | cut -d'"' -f2 || true; }

# الخادم لا يصله أحدٌ إلا عبر Cloudflare. وعلى ٤٤٣ وحده: مع Always Use HTTPS
# يحوّل Cloudflare زائر http عنده، فلا يأتي الخادمَ على ٨٠ أبداً
if [ "${1:-}" = "--firewall" ]; then
    cat <<RULES
  جدار الحماية عند المزوّد — لا ufw على الخادم: Docker يفتح منافذه من تحته
  (Hetzner: Firewalls ← Create Firewall، ثم Apply to ← هذا الخادم):

    TCP 22    Any IPv4 وAny IPv6، كما هي
    TCP 443   هذه العناوين وحدها — احذف منها Any IPv4 وAny IPv6:
$(current | tr ' ' '\n' | sed 's/^/      /')

  وفي Cloudflare ← SSL/TLS ← Edge Certificates: Always Use HTTPS مفعّل.
RULES
    exit 0
fi

before=$(current)

# القائمتان كاملتان أو لا شيء: واحدةٌ منهما وحدها قائمةٌ ناقصة
ranges=$(
    { curl -fsS --max-time 20 https://www.cloudflare.com/ips-v4 || exit 1; echo;
      curl -fsS --max-time 20 https://www.cloudflare.com/ips-v6 || exit 1; echo; } \
    | grep -E '^[0-9a-fA-F:.]+/[0-9]+$' | tr '\n' ' ' | sed 's/ $//'
)

# نحو عشرين عنواناً منذ سنوات: أقلّ من عشرة يعني ردّاً مقطوعاً
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

# عنوانٌ جديد لها غائبٌ عن جدار الحماية: زوّارٌ يمرّون منه لا يصلون الخادم
if [ -n "$before" ] && [ "$before" != "$ranges" ]; then
    echo "تغيّرت عناوين Cloudflare منذ آخر مرّة: حدّث بها جدار الحماية." >&2
    ./cloudflare-ranges.sh --firewall >&2
fi
