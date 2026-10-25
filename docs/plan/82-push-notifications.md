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

## ملفات التطبيقات

- `google-services.json` لا يُرفع للمستودع (`.gitignore`). يُكتب وقت البناء من السرّ
  `GOOGLE_SERVICES_JSON_B64` في GitHub.
- معرّفات الحزم في Firebase يجب أن تطابق معرّفات التطبيقات: `iq.wahaj.merchant_app`،
  `iq.wahaj.courier_app`، `iq.wahaj.callcenter_app`.

## الاختبارات

- `tests/Feature/Api/PushNotificationTest.php`:
  - تسجيل الهاتف وإلغاؤه، ولا يتكرّر.
  - بلا مفتاح: لا إرسال، والإشعار يبقى في الجرس.
  - الإرسال إلى هواتف التاجر وحدها، ومسح الرمز المرفوض.
- التوقيع بـJWT يحتاج OpenSSL الكامل الموجود على السيرفر، ولا يُغطّى محلياً؛ لذلك يضع الاختبار رمز الوصول في الكاش.
