# ٨٢ — إشعارات الهواتف (Firebase Cloud Messaging)

> 2026-10-25

## ما يصل إلى الهاتف

- **التاجر**: إشعاران يصلان جرسه في التطبيق، ويصلان هاتفه حتى لو التطبيق مغلق:
  - «وصلت شحنتك …» مع ما قُبض.
  - قرار المعالجة: إعادة توصيل، أو تأجيل بموعده، أو راجع مؤكد.
  - وتغيّر مبلغ الوصل.
- الإرسال يتم من السيرفر عبر واجهة FCM HTTP v1، مع التوقيع بمفتاح حساب الخدمة.

## كيف يُسجَّل الهاتف

- بعد الدخول، يرسل التطبيق رمز الجهاز: `POST /api/v1/devices` بـ`{token}`.
- عند الخروج: `DELETE /api/v1/devices` بالرمز نفسه.
- الرمز فريدٌ على مستوى النظام، فإن دخل صاحبٌ آخر من الهاتف نفسه يُعاد ربطه به.
- حين ترفض Firebase الرمز نهائياً (`UNREGISTERED`) يُمسح من الجدول.

## الإعداد على السيرفر

مفتاح حساب الخدمة **خارج المستودع**:

1. الملف على السيرفر: `/etc/zajel/firebase-service-account.json`، يملكه root ومجموعته `www-data` (الرقم 33)
   بصلاحيات `640`: التطبيق والمجدوِل يعملان في الحاوية بـ`www-data`، فلا يقرآن ملفاً بـ`600` لـroot وحده.

   ```bash
   sudo chown root:33 /etc/zajel/firebase-service-account.json
   sudo chmod 640 /etc/zajel/firebase-service-account.json
   ```
2. في `deploy/.env` على السيرفر:
   ```
   FIREBASE_SERVICE_ACCOUNT_PATH=/etc/zajel/firebase-service-account.json
   ```
3. الحاوية تربط `/etc/zajel` للقراءة فقط (`deploy/compose.yaml`).

بلا هذا المتغيّر يصمت الإرسال، ولا يتعطّل شيء: الإشعار يبقى في الجرس.

## تطبيق التاجر

- **بلا ملف google-services.json ولا إضافة Gradle**: إعداد Firebase يأتي مع البناء بـ`--dart-define`
  (`FIREBASE_API_KEY`، `FIREBASE_APP_ID`، `FIREBASE_SENDER_ID`، `FIREBASE_PROJECT_ID`)، ويُهيّأ Firebase في Dart
  (`lib/core/push.dart`). فلا يتوقّف البناء على توافق إضافة Google Services مع AGP 9.1.
- **في GitHub**: خطوة «Firebase settings» تقرأ السرّ `GOOGLE_SERVICES_JSON_B64` (google-services.json بـbase64)،
  وتأخذ منه تطبيق `iq.wahaj.merchant_app`، وتكتبه مع `config/zajel.json` في `build-config.json` للبناء ثم يُحذف
  (`.github/scripts/firebase_defines.py`). بلا السرّ، أو بسرٍّ ليس فيه التطبيق، يُبنى بلا إشعارات وتظهر ملاحظة
  في صفحة التشغيل.
- **في التطبيق**:
  - بعد الدخول ومع كلّ فتح: يُسأل الإذن مرّةً (أندرويد 13+)، ويُسجَّل الرمز (`POST /devices`)، ويُعاد إن تجدّد.
  - قبل الخروج: يُلغى (`DELETE /devices`)، ويُمسح الرمز من الهاتف.
  - الإشعار والتطبيق مفتوح لا يظهر في شريط الهاتف، فيُعرض شريطاً في أسفل التطبيق.
  - في شريط الهاتف جرسٌ بلونٍ واحد بأحمر الشركة (`res/drawable/ic_notification.xml`)، لا أيقونة التطبيق.
  - نسخة العرض والاختبارات وبناء الويب بلا Firebase.
- الخادم يرسل إلى رمز FCM (`message.token`)، فيبقى `getToken` في التطبيق ولو عُلّم قديماً في
  firebase_messaging 17: طريق «FID» الجديد هدفه غير هذا الرمز.
- `google-services.json` نفسه لا يُرفع للمستودع (`.gitignore`).
- تطبيقا المندوب والكول سنتر مسجّلان في Firebase (`iq.wahaj.courier_app`، `iq.wahaj.callcenter_app`)، ولا
  يُرسل إليهما شيءٌ بعد.

## الاختبارات

- `tests/Feature/Api/PushNotificationTest.php`:
  - تسجيل الهاتف وإلغاؤه، ولا يتكرّر.
  - بلا مفتاح: لا إرسال، والإشعار يبقى في الجرس.
  - الإرسال إلى هواتف التاجر وحدها، ومسح الرمز المرفوض.
- التوقيع بـJWT يحتاج OpenSSL الكامل الموجود على السيرفر، ولا يُغطّى محلياً؛ لذلك يضع الاختبار رمز الوصول في الكاش.
