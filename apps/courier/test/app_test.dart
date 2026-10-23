// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:courier_app/core/config.dart';
import 'package:courier_app/core/demo.dart';
import 'package:courier_app/core/models.dart';
import 'package:courier_app/core/palette.dart';
import 'package:courier_app/core/reminders.dart';
import 'package:courier_app/core/whatsapp.dart';
import 'package:courier_app/main.dart';
import 'package:courier_app/screens/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

/// خطّ التطبيق في الاختبار — وإلا قاس flutter_test بخطّ مربّعاتٍ عريض لا يشبه شيئاً
Future<void> _loadCairo() async {
  final loader = FontLoader('Cairo');
  for (final w in [400, 500, 600, 700, 800]) {
    loader.addFont(Future.value(ByteData.view(File('assets/fonts/Cairo-$w.ttf').readAsBytesSync().buffer)));
  }
  await loader.load();
}

Future<void> _start(WidgetTester tester) async {
  tester.view.physicalSize = const Size(393 * 3, 849 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const CourierApp(signedIn: true));
  await tester.pumpAndSettle();
}

/// يمرّر القائمة المعروضة حتى يظهر النصّ ثم يضغطه
Future<void> _tap(WidgetTester tester, Finder target) async {
  await tester.ensureVisible(target);
  await tester.pumpAndSettle();
  await tester.tap(target);
  await tester.pumpAndSettle();
}

/// الشريط السفلي: النصّ الأخير على الشاشة هو تسميته
Future<void> _tab(WidgetTester tester, String label) async {
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

Future<void> _openTasks(WidgetTester tester) async {
  await _tap(tester, find.text('ابدأ جولتك'));
  expect(find.text('بغداد — الكرادة (4)'), findsOneWidget);
}

Future<void> _openShipment(WidgetTester tester, String number) async {
  await _openTasks(tester);
  await _tap(tester, find.text(tag(number)).first);
}

void main() {
  setUpAll(_loadCairo);
  setUp(() {
    Demo.reset();
    WhatsAppTemplate.text.value = WhatsAppTemplate.suggested;
  });

  testWidgets('الدخول باسم التطبيق ونصّ المكتب', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: LoginScreen(brand: Brand.hex('#FE0A0E'), onSignedIn: (_) {}),
      ),
    );
    expect(find.text('الزاجل'), findsOneWidget);
    expect(find.text('تطبيق المندوب'), findsOneWidget);
    expect(find.text('نسيت كلمة المرور؟ راجع المكتب'), findsOneWidget);

    await tester.tap(find.text('دخول'));
    await tester.pump();
    expect(find.text('اكتب اسم المستخدم وكلمة المرور.'), findsOneWidget);
  });

  testWidgets('الرئيسية: ما بيده، والنقد وسقفه، وأرقام اليوم، وما يحتاج انتباهه', (tester) async {
    expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');
    expect(AppConfig.appName, 'مندوب الزاجل');
    await _start(tester);

    for (final text in [
      'مندوب تجريبي',
      'بيدك الآن',
      '7',
      '184,000',
      'النقد بيدك',
      '2,340,000',
      '78٪ من السقف',
      'سُلّمت اليوم',
      'راجع بيدي',
      'تحتاج انتباهك (5)',
      'طلب تغيير المبلغ ينتظر جواب الكول سنتر',
      'يُسلَّم بكود من الزبون',
      'راجعٌ بيدك: سلّمه للمخزن',
    ]) {
      await tester.ensureVisible(find.textContaining(text).first);
      expect(find.textContaining(text), findsWidgets, reason: text);
    }
    // الشريط: الماسح في وسطه
    expect(find.text('مسح الشحنة'), findsOneWidget);
  });

  testWidgets('مهامي: مجمّعة بالمنطقة، والبحث، والراجع', (tester) async {
    await _start(tester);
    await _openTasks(tester);

    expect(find.text('بغداد — المنصور (2)'), findsOneWidget);
    expect(find.text('ينتظر جواب الكول سنتر على المبلغ'), findsOneWidget);
    expect(find.text('يُسلَّم بكود'), findsNWidgets(2));

    await tester.enterText(find.byType(TextField), '180212');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.text(tag('180212')), findsOneWidget);
    expect(find.text(tag('180201')), findsNothing);

    await tester.enterText(find.byType(TextField), '');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    await _tap(tester, find.text('الراجع'));
    expect(find.text(tag('180190')), findsOneWidget);
    // ما لم يُسلَّم ولم يُقرَّر فيه بعد: في «الراجع» ينتظر، لا يختفي (docs/plan/75)
    expect(find.text('لم تُسلَّم — تنتظر قرار المعالجة (1)'), findsOneWidget);
    expect(find.text(tag('180188')), findsOneWidget);
    expect(find.text('تنتظر المعالجة'), findsOneWidget);
  });

  testWidgets('إعادة التوصيل والمؤجل: كلٌّ في خانته، والمؤجّلة بموعدها', (tester) async {
    await _start(tester);
    await _openTasks(tester);

    // ما أعاده الكول سنتر ليس مع ما خرج أوّل مرّة
    expect(find.text(tag('180186')), findsNothing);
    await _tap(tester, find.text('إعادة توصيل').first);
    expect(find.text(tag('180186')), findsOneWidget);
    expect(find.text('إعادة توصيل'), findsWidgets);

    await _tap(tester, find.text('المؤجل'));
    expect(find.text('موعدها اليوم (1)'), findsOneWidget);
    expect(find.text('موعدها غداً (1)'), findsOneWidget);
    expect(find.text('اليوم 4:00 م'), findsOneWidget);

    // «أوصلها الآن»: تعود إليه في «إعادة التوصيل» ويُسجَّل عليها عند الباب
    await _tap(tester, find.text(tag('180181')));
    expect(find.text('موعدها اليوم 4:00 م'), findsOneWidget);
    await _tap(tester, find.text('أوصلها الآن'));
    expect(find.text('الشحنة 180181 معك للتوصيل الآن.'), findsOneWidget);
    expect(find.text('سُلّمت'), findsOneWidget);
  });

  testWidgets('«مؤجلة» تسأل عن الموعد، والرئيسية تذكّر به', (tester) async {
    await _start(tester);
    // مواعيد اليوم وغداً أوّل الرئيسية
    expect(find.text('مواعيد التسليم'), findsOneWidget);
    expect(find.text('اليوم 4:00 م'), findsOneWidget);
    expect(find.text('منى جاسم · الكرادة'), findsOneWidget);

    await _openShipment(tester, '180209');
    await _tap(tester, find.text('مؤجلة'));
    await _tap(tester, find.text('الزبون طلب التأجيل'));
    await tester.enterText(find.byType(TextField).last, 'بعد العصر');
    await _tap(tester, find.text('حفظ'));
    expect(find.textContaining('متى الموعد؟'), findsOneWidget);
    await _tap(tester, find.text('غداً'));
    await _tap(tester, find.text('العصر 4:00'));
    await _tap(tester, find.text('حفظ'));

    await _tap(tester, find.text('المؤجل'));
    expect(find.text('موعدها غداً (2)'), findsOneWidget);
    expect(find.text('غداً 4:00 م'), findsOneWidget);
  });

  test('التنبيه: قبل الموعد بربع ساعة، أو التاسعة صباح يومه، ولا تنبيه لما فات', () {
    final now = DateTime(2026, 10, 22, 12);
    Appointment at(DateTime t) => Appointment(id: 1, number: '180181', recipient: 'منى جاسم', at: t, city: 'الكرادة');
    expect(Reminders.remindAt(at(DateTime(2026, 10, 22, 16)), now: now), DateTime(2026, 10, 22, 15, 45));
    expect(Reminders.remindAt(at(DateTime(2026, 10, 23)), now: now), DateTime(2026, 10, 23, 9));
    expect(Reminders.remindAt(at(DateTime(2026, 10, 22, 12, 10)), now: now), isNull);
    expect(Reminders.remindAt(at(DateTime(2026, 10, 22)), now: now), isNull);
    expect(appointmentLabel(DateTime(2026, 10, 22, 16), now: now), 'اليوم 4:00 م');
    expect(appointmentLabel(DateTime(2026, 10, 23, 10, 30), now: now), 'غداً 10:30 ص');
    expect(appointmentLabel(DateTime(2026, 10, 20), now: now), 'فات منذ يومين');
    expect(Reminders.body(at(DateTime(2026, 10, 22, 16))), contains('الشحنة 180181 — الكرادة'));
  });

  testWidgets('شحنةٌ بكود: «سُلّمت» لا تعمل قبل الكود، والكود الخاطئ يُرفض، والصحيح يُسلّمها', (tester) async {
    await _start(tester);
    await _openShipment(tester, '180201');

    expect(find.text('زينب علي'), findsOneWidget);
    expect(find.text('كود التسليم'), findsOneWidget);
    expect(find.text('اتصل قبل الوصول بربع ساعة'), findsOneWidget);
    // الكود لا يُعرض أبداً — يُطلب من الزبون
    expect(find.textContaining('4821'), findsNothing);

    await tester.tap(find.text('سُلّمت'));
    await tester.pumpAndSettle();
    expect(find.text('سُلّمت الشحنة؟'), findsNothing, reason: 'الزرّ مقفلٌ بلا كود');

    await tester.enterText(find.byType(TextField), '١١١١');
    await tester.pump();
    await _tap(tester, find.text('سُلّمت'));
    await _tap(tester, find.text('نعم، سُلّمت'));
    expect(find.text('كود التسليم غير صحيح.'), findsOneWidget);

    await tester.enterText(find.byType(TextField), '4821');
    await tester.pump();
    await _tap(tester, find.text('سُلّمت'));
    expect(find.textContaining('قبضت 25,000 د.ع'), findsOneWidget);
    await _tap(tester, find.text('نعم، سُلّمت'));

    expect(find.text('سُجِّلت الشحنة 180201: واصل.'), findsOneWidget);
    expect(find.text(tag('180201')), findsNothing, reason: 'خرجت من «مهامي»');

    await _tab(tester, 'حسابي');
    expect(find.textContaining('2,365,000'), findsWidgets);
  });

  testWidgets('لم تُسلَّم: السبب إلزامي، وما يحتاج توضيحاً لا يُحفظ بلا توضيح', (tester) async {
    await _start(tester);
    await _openShipment(tester, '180209');

    await _tap(tester, find.text('لم تُسلَّم'));
    expect(find.text('لم تُسلَّم — اختر السبب'), findsOneWidget);

    await _tap(tester, find.text('حفظ'));
    expect(find.textContaining('اختر السبب'), findsWidgets);

    await _tap(tester, find.text('الزبون رفض الاستلام'));
    await _tap(tester, find.text('حفظ'));
    expect(find.text('السبب «الزبون رفض الاستلام» يحتاج توضيحاً.'), findsOneWidget);

    await tester.enterText(find.byType(TextField).last, 'قال لم يطلبها');
    await _tap(tester, find.text('حفظ'));
    expect(find.text('سُجِّلت الشحنة 180209: لم يُسلَّم.'), findsOneWidget);
  });

  testWidgets('المبلغ لا يتغيّر عند الباب: يُطلب من الكول سنتر، والجزئي بعد اعتماده فقط', (tester) async {
    await _start(tester);
    await _openShipment(tester, '180212');

    expect(find.text('واصل جزئي بـ 20,000'), findsNothing);
    await _tap(tester, find.text('اطلب تغيير المبلغ'));
    await _tap(tester, find.text('السعر تغيّر'));
    await tester.enterText(find.byType(TextField).at(0), '15000');
    await tester.enterText(find.byType(TextField).at(1), 'اتّفق مع التاجر على خصم');
    await _tap(tester, find.text('أرسل للكول سنتر'));

    expect(find.textContaining('إلى الكول سنتر. لا تسلّم بالمبلغ الجديد حتى يُعتمد.'), findsWidgets);
    expect(find.textContaining('ينتظر جواب الكول سنتر'), findsOneWidget);
    expect(find.text('اطلب تغيير المبلغ'), findsNothing);

    // شحنةٌ اعتُمد لها واصلٌ جزئي
    await _tap(tester, find.byTooltip('رجوع'));
    await tester.enterText(find.byType(TextField), '180215');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    await _tap(tester, find.text(tag('180215')).first);
    expect(find.text('واصل جزئي بـ 20,000'), findsOneWidget);
    await _tap(tester, find.text('واصل جزئي بـ 20,000'));
    await _tap(tester, find.text('نعم، واصل جزئي'));
    expect(find.text('سُجِّلت الشحنة 180215: واصل جزئي.'), findsOneWidget);
  });

  testWidgets('حسابي والمزيد', (tester) async {
    await _start(tester);

    await _tab(tester, 'حسابي');
    for (final text in [
      'نقدك وعمولتك وحركاتك',
      'عمولتك المستحقّة',
      '180,000',
      'الواجب تسليمه',
      '2,160,000',
      'آخر الحركات',
      'CS-000031',
    ]) {
      await tester.ensureVisible(find.text(text).first);
      expect(find.text(text), findsWidgets, reason: text);
    }

    await _tab(tester, 'المزيد');
    await tester.scrollUntilVisible(find.text('أشارك موقعي ما دام التطبيق مفتوحاً'), 200);
    expect(find.text('أشارك موقعي ما دام التطبيق مفتوحاً'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('الراجع بيدي'), -200);
    expect(find.textContaining('نسخة عرضٍ'), findsOneWidget);
    await _tap(tester, find.text('الراجع بيدي'));
    expect(find.text(tag('180190')), findsOneWidget);
  });

  testWidgets('اليوم: ما سجّله وما حصّله، من اختصار الرئيسية', (tester) async {
    await _start(tester);
    await _tap(tester, find.text('اليوم').first);
    expect(find.text('ما سجّلته اليوم وما حصّلته'), findsOneWidget);
    expect(find.text('حصّلت اليوم'), findsOneWidget);
    expect(find.text('هدى كريم'), findsOneWidget);
    expect(find.text('لا يرد على الهاتف'), findsOneWidget);
  });

  testWidgets('الاستلام: يتّصل بالتاجر ويسجّل ما استلمه فعلاً', (tester) async {
    await _start(tester);
    await _tap(tester, find.text('الاستلام').first);
    expect(find.text('متجر الوردة'), findsOneWidget);
    expect(find.text('مقابل مطعم الساعة'), findsOneWidget);
    await _tap(tester, find.text('استلمت الطرود').first);
    expect(find.text('يُسجَّل استلام كلّ شحنات هذا التاجر المعلّقة دفعةً واحدة.'), findsOneWidget);
    await tester.enterText(find.byType(TextField).first, '11');
    await _tap(tester, find.text('سجّل الاستلام'));
    expect(find.text('استُلم 11 طرداً من متجر الوردة.'), findsOneWidget);
    expect(find.text('متجر الوردة'), findsNothing);
    expect(find.text('بيت العطور'), findsOneWidget);
  });

  testWidgets('حصصي: اعتراضٌ على العدد، وتأكيد دفعة الربح مرّة واحدة', (tester) async {
    await _start(tester);
    await _tap(tester, find.text('حصصي').first);
    expect(find.text('رصيدك المستحقّ الآن'), findsOneWidget);
    expect(find.textContaining('42,000', findRichText: true), findsOneWidget);

    await _tap(tester, find.text('استلمتُها').first);
    expect(find.text('أكّدت استلام الدفعة PP-000012.'), findsOneWidget);
    expect(find.text('استلمتُها'), findsNWidgets(2));

    await _tap(tester, find.text('العدد غير صحيح؟ اعترض'));
    await _tap(tester, find.text('أرسل الاعتراض'));
    expect(find.text('اكتب لماذا العدد غير صحيح.'), findsOneWidget);
    await tester.enterText(find.byType(TextField).last, 'جمعتُ طردين إضافيين');
    await _tap(tester, find.text('أرسل الاعتراض'));
    expect(find.text('سُجّل اعتراضك. سيُبتّ فيه من إدارة الفرع.'), findsOneWidget);
    expect(find.text('معترَض عليها'), findsOneWidget);
    expect(find.text('العدد غير صحيح؟ اعترض'), findsNothing);
  });

  testWidgets('الجرس والمحادثة مع المكتب من رأس الرئيسية', (tester) async {
    await _start(tester);
    await _tap(tester, find.byTooltip('الإشعارات'));
    expect(find.text('تغيير وقت الاستلام'), findsOneWidget);
    expect(find.text('جديد'), findsNWidgets(2));
    await _tap(tester, find.byTooltip('رجوع'));

    await _tap(tester, find.byTooltip('المحادثة مع المكتب'));
    expect(find.text('اتصلنا به: ينزل بعد عشر دقائق.'), findsOneWidget);
    expect(find.text('الشحنة ${tag('180209')} — نور الهدى'), findsOneWidget);
    await tester.enterText(find.byType(TextField), 'العنوان غلط، الزبون في الكرادة خارج');
    await _tap(tester, find.byTooltip('أرسل'));
    expect(find.text('العنوان غلط، الزبون في الكرادة خارج'), findsOneWidget);
  });

  testWidgets('بمسح الشحنة: لا يُسجَّل شيءٌ عند الباب قبل مسح الطرد نفسه', (tester) async {
    Demo.scanParcels = true;
    await _start(tester);
    await _openShipment(tester, '180209');
    expect(find.text('امسح الطرد قبل التسجيل'), findsOneWidget);

    // طردٌ آخر في يده: يرفضه النظام عند التسجيل، فيُمسح من جديد
    await _tap(tester, find.text('امسح الطرد قبل التسجيل'));
    expect(find.text('امسح الطرد الذي في يدك'), findsOneWidget);
    await tester.enterText(find.byType(TextField), '180212');
    await _tap(tester, find.text('تمّ'));
    expect(find.text('مسحت الطرد'), findsOneWidget);
    await _tap(tester, find.text('سُلّمت'));
    await _tap(tester, find.text('نعم، سُلّمت'));
    expect(find.text('ما مسحته ليس الشحنة 180209. امسح الطرد الذي في يدك.'), findsOneWidget);
    expect(find.text('امسح الطرد قبل التسجيل'), findsOneWidget);

    await _tap(tester, find.text('امسح الطرد قبل التسجيل'));
    await tester.enterText(find.byType(TextField), '180209');
    await _tap(tester, find.text('تمّ'));
    await _tap(tester, find.text('سُلّمت'));
    await _tap(tester, find.text('نعم، سُلّمت'));
    expect(find.text('سُجِّلت الشحنة 180209: واصل.'), findsOneWidget);
  });

  testWidgets('بمسح الشحنات: مندوب الاستلام يأخذ ما مسحه من شحنات التاجر وحده', (tester) async {
    Demo.scanParcels = true;
    await _start(tester);
    await _tap(tester, find.text('الاستلام').first);
    expect(find.text('تستلم بمسح باركود كلّ طرد: ما لم تمسحه يبقى عند التاجر.'), findsOneWidget);
    await _tap(tester, find.text('امسح الطرود').first);
    expect(find.text('0 من 12 متوقَّع'), findsOneWidget);

    Future<void> scan(String code) async {
      await tester.enterText(find.byType(TextField), code);
      await _tap(tester, find.text('أضف'));
    }

    await scan('190201');
    expect(find.text('الشحنة 190201 ليست لهذا التاجر.'), findsOneWidget);
    await scan('190101');
    expect(find.text('أُضيفت الشحنة 190101.'), findsOneWidget);
    await scan('190101');
    expect(find.text('الشحنة 190101 ممسوحةٌ سلفاً.'), findsOneWidget);
    await scan('190102');
    expect(find.text('2 من 12 متوقَّع'), findsOneWidget);

    await _tap(tester, find.text('سجّل استلام طردان'));
    expect(find.text('استُلم طردان من متجر الوردة.'), findsOneWidget);
    expect(find.text('متجر الوردة'), findsNothing);
  });

  testWidgets('رسالة واتساب للزبون: تُكتب في «المزيد» وتُملأ من الشحنة', (tester) async {
    await _start(tester);
    await _tab(tester, 'المزيد');
    await tester.scrollUntilVisible(find.text('رسالة واتساب للزبون'), 200);
    await _tap(tester, find.text('رسالة واتساب للزبون'));
    expect(find.text('كما يراها الزبون'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'هلا ');
    await tester.pump();
    await _tap(tester, find.text('اسم الزبون'));
    await tester.enterText(
      find.byType(TextField),
      '${tester.widget<TextField>(find.byType(TextField)).controller!.text}، الوصل ',
    );
    await tester.pump();
    await _tap(tester, find.text('رقم الوصل'));
    expect(find.text('هلا علي حسين، الوصل 180240'), findsOneWidget);
    await _tap(tester, find.text('احفظ الرسالة'));
    expect(WhatsAppTemplate.text.value, 'هلا {الزبون}، الوصل {الوصل}');
  });

  test('واتساب: الرقم العراقيّ دوليّاً والرسالة في الرابط', () {
    expect(whatsappNumber('0770 123 4567'), '9647701234567');
    expect(whatsappNumber('+964 770 123 4567'), '9647701234567');
    expect(whatsappNumber('7701234567'), '9647701234567');
    expect(whatsappNumber(''), isNull);
    expect(
      whatsappUri('07701234567', text: 'هلا علي')!.toString(),
      'https://wa.me/9647701234567?text=${Uri.encodeComponent('هلا علي')}',
    );
    expect(
      WhatsAppTemplate.fillWith('مرحباً {الزبون}، الوصل {الوصل} بـ{المبلغ}', {
        '{الزبون}': 'علي',
        '{الوصل}': '180240',
        '{المبلغ}': '35,000',
      }),
      'مرحباً علي، الوصل 180240 بـ35,000',
    );
  });
}
