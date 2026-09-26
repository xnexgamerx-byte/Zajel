#!/usr/bin/env bash
#
# يتأكّد من شهادة Cloudflare للمصدر قبل أن يُعتمد عليها:
#
#   ./origin-cert.sh wahaj.iq
#
# الشهادة في certs/origin.pem ومفتاحها في certs/origin.key، والمفتاح لها، وتشمل
# كل النطاقات الفرعية. خطأٌ هنا يظهر الآن برسالةٍ تقول ما العمل، لا لاحقاً خطأ
# 526 في كل صفحة. يشغّله setup.sh وcloudflare-mode.sh.

set -euo pipefail
cd "$(dirname "$0")"

die() { printf '\n\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

domain=${1:?"النطاق: ./origin-cert.sh wahaj.iq"}

if [ ! -s certs/origin.pem ] || [ ! -s certs/origin.key ]; then
    die "ضع شهادة Cloudflare للمصدر في deploy/certs/origin.pem ومفتاحها في deploy/certs/origin.key (README: «خلف Cloudflare»)، ثم أعد."
fi

# الملفّان يُملآن باللصق: أكثر الأخطاء نصٌّ في غير ملفّه، أو ناقص
if grep -q 'PRIVATE KEY' certs/origin.pem; then
    die "في certs/origin.pem مفتاحٌ لا شهادة: الشهادة («Origin Certificate») في origin.pem، والمفتاح («Private Key») في origin.key."
fi
openssl x509 -in certs/origin.pem -noout 2>/dev/null \
    || die "certs/origin.pem ليست شهادة. الصق نصّ «Origin Certificate» كاملاً، من سطر BEGIN إلى سطر END."
# -passin: مفتاحٌ بكلمة سرّ يُرفض هنا، لا يقف الأمر ينتظرها
openssl pkey -in certs/origin.key -passin pass: -noout 2>/dev/null \
    || die "certs/origin.key ليس مفتاحاً. الصق نصّ «Private Key» كاملاً، من سطر BEGIN إلى سطر END."

if [ "$(openssl x509 -in certs/origin.pem -noout -pubkey | sha256sum)" != "$(openssl pkey -in certs/origin.key -passin pass: -pubout | sha256sum)" ]; then
    die "certs/origin.key ليس مفتاح هذه الشهادة. الصق «Private Key» الذي ظهر معها — وإن ضاع فأنشئ في Cloudflare شهادةً جديدة وضع الاثنين."
fi

# شهادة عميل (Client Certificates) لا تحمل النطاق: تُرفض هنا أيضاً
if ! openssl x509 -in certs/origin.pem -noout -ext subjectAltName 2>/dev/null \
        | tr ',' '\n' | sed 's/^ *//' | grep -xF "DNS:*.$domain" > /dev/null; then
    die "الشهادة لا تشمل *.$domain: أنشئها من SSL/TLS ← Origin Server لـ $domain و*.$domain معاً."
fi

openssl x509 -in certs/origin.pem -noout -checkend 0 > /dev/null \
    || die "انتهت مدّة الشهادة. أنشئ في Cloudflare شهادةً جديدة وضعها مكانها."

chmod 644 certs/origin.pem
chmod 600 certs/origin.key
