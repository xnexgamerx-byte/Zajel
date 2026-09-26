#!/usr/bin/env bash
#
# خادمٌ ثُبّت مباشراً (بشهادات Let's Encrypt) يصير خلف Cloudflare، والبيانات كما هي:
#
#   sudo ./cloudflare-mode.sh
#
# قبله: شهادة Cloudflare للمصدر في certs/ (README: «خلف Cloudflare»، الخطوة ٢).
# يتأكّد منها، ويكتب في .env عناوين Cloudflare وإعداد الويب خلفها، ويعيد تشغيل
# الويب والتطبيق بهما — ثوانٍ لا يجيب فيها الموقع — ثم يتأكّد أنّ الخادم يقدّم
# الشهادة، ويطبع العناوين لجدار الحماية. تشغيله مرّةً ثانية آمن.

set -euo pipefail
cd "$(dirname "$0")"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
die() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "شغّله بصلاحية المدير: sudo ./cloudflare-mode.sh"
[ -f .env ] || die "النظام لم يُثبَّت هنا بعد: sudo ./setup.sh، وأجب «نعم» عن Cloudflare."

domain=$(grep -E '^DOMAIN=' .env | cut -d= -f2-)

# ما يُكتب في .env يُكتب بعد أن يصحّ كل شيء: شهادةٌ خاطئة أو عناوين لم تصل
# توقفه قبل أن يتغيّر شيء، والخادم يعمل كما كان
./origin-cert.sh "$domain" || exit 1
./cloudflare-ranges.sh || die "تعذّر جلب عناوين Cloudflare من cloudflare.com/ips. تأكّد من اتصال الخادم ثم أعد."

if grep -q '^CADDYFILE=' .env; then
    sed -i 's/^CADDYFILE=.*/CADDYFILE=Caddyfile.cloudflare/' .env
else
    echo "CADDYFILE=Caddyfile.cloudflare" >> .env
fi

say "تشغيل الويب والتطبيق بالإعداد الجديد…"
docker compose up -d --wait || die "لم يبدأ. السبب في: docker compose logs web app"

# الخادم يقدّم شهادة المصدر نفسها، لا ما بقي من Let's Encrypt
expected=$(openssl x509 -in certs/origin.pem -noout -fingerprint -sha256)
served=""
for _ in $(seq 1 15); do
    served=$(timeout 10 openssl s_client -connect 127.0.0.1:443 -servername "admin.$domain" < /dev/null 2>/dev/null \
        | openssl x509 -noout -fingerprint -sha256 2>/dev/null || true)
    [ "$served" = "$expected" ] && break
    sleep 2
done
[ "$served" = "$expected" ] || die "الخادم لا يقدّم شهادة المصدر. السبب في: docker compose logs web"

# ومن الخارج كما يصل الزوّار: إلى Cloudflare ثم منها إلى هنا
headers=$(curl -sS --max-time 20 -o /dev/null -D - "https://admin.$domain/up" 2>/dev/null || true)
if [[ "$headers" =~ ^HTTP/[0-9.]+\ 200 ]] && grep -qi '^cf-ray:' <<< "$headers"; then
    via="✓ https://admin.$domain يصل عبر Cloudflare إلى هذا الخادم."
else
    via="✗ https://admin.$domain لم يصل عبر Cloudflare بعد. في Cloudflare: DNS فيه A لـ @ ولـ *
    إلى عنوان هذا الخادم والسحابة برتقالية، وSSL/TLS على Full (strict). ثم أعد هذا الأمر."
fi

say "تمّ: الخادم خلف Cloudflare، ويقدّم شهادة المصدر."
cat <<DONE

  $via

$(./cloudflare-ranges.sh --firewall)
DONE
