#!/usr/bin/env bash
# استنساخ المشاريع المرجعية لدراستها محلياً.
# الاستخدام:  ./scripts/fetch-references.sh [المجلد]
# المرجع الكامل: docs/research/delivery-platforms-2026.md

set -euo pipefail

DEST="${1:-./reference-projects}"
mkdir -p "$DEST"
cd "$DEST"

clone() {
  local url="$1" dir="$2" note="$3"
  if [ -d "$dir" ]; then
    echo "→ $dir موجود مسبقاً، تخطٍّ"
    return
  fi
  echo "→ استنساخ $dir — $note"
  git clone --depth 1 --single-branch "$url" "$dir"
}

# 1) Fleetbase — المرجع المعماري الأساسي (AGPL-3.0)
clone https://github.com/fleetbase/fleetbase.git      fleetbase     "نظام التشغيل اللوجستي: Laravel API + Ember console"
clone https://github.com/fleetbase/fleetops.git       fleetops      "نموذج البيانات: الطلبات والمندوبين والمسارات والتسعير"
clone https://github.com/fleetbase/navigator-app.git  navigator-app "تطبيق المندوب - React Native"
clone https://github.com/fleetbase/storefront.git     storefront    "نظام التجار متعدد البائعين"
clone https://github.com/fleetbase/storefront-app.git storefront-app "تطبيق الزبون - React Native"
clone https://github.com/fleetbase/customer-portal.git customer-portal "بوابة تتبع الزبون الذاتية"

# 2) Enatega — واجهات التطبيقات الثلاثة (MIT). تنبيه: الـ backend غير مضمّن ومدفوع.
clone https://github.com/enatega/food-delivery-multivendor.git enatega "تطبيقات الزبون/المندوب/التاجر + لوحة الإدارة"

# 3) codflow — الدفع عند الاستلام والعربية RTL (Apache-2.0)
clone https://github.com/bighadj22/codflow.git codflow "منصة COD: تسوية نقد المندوبين، تسعير بالمحافظة، OTP عبر واتساب"

echo
echo "تم. المشاريع في: $(pwd)"
echo "ابدأ من: fleetops/server/src/Models/Order.php و OrderConfig.php"

# ── المراجع العربية (بدون رخصة — للدراسة فقط، لا تنسخ الكود) ──
# التفاصيل: docs/research/arabic-delivery-systems.md
clone https://github.com/el-joe/marketplace_platform.git marketplace_platform "الأضخم: 131k سطر، 419 جدول، 5 لوحات (منصة/تاجر/شركة شحن/مندوب/مسوق)"
clone https://github.com/dllni-app/dllni_backend.git      dllni      "معياري: DeliveryCompany + Staff + تذاكر دعم + نزاعات"
clone https://github.com/Edzeery/edzeery.git              edzeery    "طبقة الاشتراكات: Plan/PlanFeature/FeatureConsumption + توثيق عربي"
clone https://github.com/K-YEY/Shipyaex.git               shipyaex   "مفردات COD: محصّل/مرتجع/أسباب رفض/محافظات"
clone https://github.com/ALRAZEL/my-project.git           alrazel    "5 تطبيقات Flutter عربية: إدارة/زبون/مندوب/مطعم/تاجر"

# ── مراجع توصيل الطرود والشحنات (غير المطاعم) ──
# التفاصيل: docs/research/parcel-delivery-systems.md
clone https://github.com/el-joe/marketplace_platforms.git marketplace_platforms "Monorepo: باكند 163k سطر + تطبيق شركة شحن + تطبيق مندوب + تطبيق تاجر + SQL + Postman"
clone https://github.com/mshari-11/firstlinelog.com.git   firstlinelog "منصة شركة لوجستيات سعودية: سائقون وأسطول ومالية عبر 16 مدينة"
clone https://github.com/lc3lx/Mrasil.git                 mrasil     "شحن طرود خالص: بوالص، مرتجعات، استبدالات، تتبع بعلامة التاجر، عقود"
