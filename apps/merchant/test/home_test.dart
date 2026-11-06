// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:merchant_app/core/config.dart';
import 'package:merchant_app/main.dart';

/// خطّ التطبيق في الاختبار — وإلا قاس flutter_test بخطّ مربّعاتٍ عريض لا يشبه شيئاً
Future<void> _loadCairo() async {
  final loader = FontLoader('Cairo');
  for (final w in [400, 500, 600, 700, 800]) {
    loader.addFont(Future.value(ByteData.view(File('assets/fonts/Cairo-$w.ttf').readAsBytesSync().buffer)));
  }
  await loader.load();
  // خطّ قسم التاجر في «وصل» (docs/plan/123): يُقاس به كما على الهاتف
  final kufi = FontLoader('NotoKufiArabic');
  for (final w in [400, 500, 600, 700, 800]) {
    kufi.addFont(Future.value(ByteData.view(File('assets/fonts/NotoKufiArabic-$w.ttf').readAsBytesSync().buffer)));
  }
  await kufi.load();
}

void main() {
  setUpAll(_loadCairo);

  testWidgets('رئيسية التاجر تعرض أقسام التصميم كلّها', (tester) async {
    expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');

    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const CompanyApp(signedIn: true));
    await tester.pumpAndSettle();

    for (final text in [
      // الترويسة: التحيّة واسم المتجر، والدعم الفني والجرس (docs/plan/79، 123)
      'أحمد',
      'الدعم الفني',
      // الإعلانات: رسومٌ بلون الشركة ما لم تضع الشركة صورها
      'مندوبٌ يمرّ عليك',
      // «شحناتك الآن»: الطابعة أوّلاً ثم الحالات بألوانها، و«المزيد» لكلّ الحالات
      'شحناتك الآن',
      'المزيد',
      'الطابعة',
      'بالمخزن',
      'مع المندوب',
      'للمعالجة',
      'راجعة',
      'مسلّمة',
      // «تحتاج انتباهك»: بطاقة القرار، ورقما اليوم، وشريط المعالجة
      'تحتاج انتباهك',
      'الزبون لا يرد',
      'أعد التوصيل',
      'أجّلها',
      'أرجعها',
      'سُلّمت اليوم',
      'إجمالي الشحنات',
      'نسبة التسليم',
      'عالجها',
      // تقويم الشحنات وأداؤها (docs/plan/87)
      'تقويم الشحنات',
    ]) {
      await _reveal(tester, find.textContaining(text));
      expect(find.textContaining(text), findsWidgets, reason: text);
    }
    expect(find.text('شحناتي الأخيرة'), findsNothing);
    expect(find.text('آخر الشحنات'), findsNothing);

    // «+» في وسط الشريط يسأل: بالذكاء الاصطناعي، أو بالمسح، أو بصوتك، أو يكتبها بنفسه
    final semantics = tester.ensureSemantics();
    await tester.tap(find.bySemanticsLabel('شحنة جديدة'));
    await tester.pumpAndSettle();
    for (final text in ['اختر طريقة الإدخال', 'من صورة أو رسالة', 'بالمسح', 'بصوتك', 'أكتبها بنفسي']) {
      expect(find.text(text), findsOneWidget, reason: text);
    }
    // ويُغلق كما فُتح
    await tester.tap(find.bySemanticsLabel('إغلاق').last);
    await tester.pumpAndSettle();
    expect(find.text('اختر طريقة الإدخال'), findsNothing);
    semantics.dispose();
  });

  testWidgets('قارئ الشاشة يفعّل الزرّ الأوسط والشريط وخانات الرئيسية وصفّ الشحنة', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    final semantics = tester.ensureSemantics();

    await tester.pumpWidget(const CompanyApp(signedIn: true));
    await tester.pumpAndSettle();

    // ما يفعله TalkBack بلمستين: فعل «tap» على العقدة نفسها، لا لمسةٌ على مكانها
    tester.semantics.tap(find.semantics.byLabel('شحنة جديدة'));
    await tester.pumpAndSettle();
    expect(find.text('اختر طريقة الإدخال'), findsOneWidget);
    tester.semantics.tap(find.semantics.byLabel('إغلاق').last);
    await tester.pumpAndSettle();
    expect(find.text('اختر طريقة الإدخال'), findsNothing);

    tester.semantics.tap(find.semantics.byLabel('مع المندوب: 28'));
    await tester.pumpAndSettle();
    expect(find.text('خرجت مع المندوب في طريقها إلى زبونها'), findsOneWidget);
    // زرّ الرجوع المربّع يُقرأ بتلميحه
    tester.semantics.tap(find.semantics.byPredicate((n) => n.tooltip == 'رجوع'));
    await tester.pumpAndSettle();

    tester.semantics.tap(find.semantics.byLabel('الشحنات'));
    await tester.pumpAndSettle();
    tester.semantics.tap(find.semantics.byLabel(RegExp('^زهراء كريم')));
    await tester.pumpAndSettle();
    expect(find.text('مسار الشحنة'), findsOneWidget);
    semantics.dispose();
  });

  testWidgets('حالة الشحنات: كلّ حالةٍ قائمتها وحدها، والجديدة ليست قيد التوصيل، والدعم في الترويسة', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const CompanyApp(signedIn: true));
    await tester.pumpAndSettle();

    // الدعم الفني مكان الإعدادات (docs/plan/79)
    expect(find.byTooltip('الدعم الفني'), findsOneWidget);
    expect(find.byIcon(Icons.settings_outlined), findsNothing);

    // «مع المندوب»: ما خرج للتوصيل وحده، لا الجديدة التي في «الطابعة»
    await _reveal(tester, find.text('مع المندوب').first);
    await tester.tap(find.text('مع المندوب').first);
    await tester.pumpAndSettle();
    expect(find.text('خرجت مع المندوب في طريقها إلى زبونها'), findsOneWidget);
    expect(find.text('علي حسين'), findsOneWidget);
    // وما بالمخزن له خانته
    expect(find.text('كرار عادل'), findsNothing);
    expect(find.text('مريم سعد'), findsNothing);
    expect(find.text('زهراء كريم'), findsNothing);

    // شرائح المحافظات صارت في الكول سنتر وحده (docs/plan/93)
    expect(find.textContaining('كل المحافظات'), findsNothing);
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();

    // «المزيد» يفتح الحالات كلّها، و«مؤجلة» بشارتها الصفراء وموعدها (docs/plan/86، 123)
    await tester.scrollUntilVisible(find.text('المزيد'), -200, scrollable: _vertical);
    await tester.tap(find.text('المزيد'));
    await tester.pumpAndSettle();
    expect(find.text('رحلة شحناتك'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('مؤجلة'), 150, scrollable: _vertical);
    await tester.tap(find.text('مؤجلة'));
    await tester.pumpAndSettle();
    expect(find.text('زهراء كريم'), findsOneWidget);
    expect(find.text('علي حسين'), findsNothing);
    expect(find.text('مؤجل إلى غداً الساعة 9:00 م'), findsOneWidget);
  });

  testWidgets('اختيار الإشعارات: ما يصل الهاتف، وبصوتٍ أم بلا صوت، وكيف يظهر', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const CompanyApp(signedIn: true));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('الإشعارات، فيها جديد'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('اختيار الإشعارات'));
    await tester.pumpAndSettle();

    for (final text in ['وصلت شحنتك', 'للمعالجة', 'تمت معالجتها', 'صوت الإشعار', 'هكذا تظهر على شاشة هاتفك']) {
      expect(find.text(text), findsOneWidget, reason: text);
    }
    expect(find.text('وصلت شحنتك 000288'), findsOneWidget);

    // هل تصل الإشعارات؟ (docs/plan/89): الحال سطراً سطراً، و«جرّب إشعاراً الآن»
    expect(find.text('إشعارات الهاتف لا تصل'), findsOneWidget);
    expect(find.textContaining('نسخة العرض: لا تصل الهاتف إشعارات'), findsOneWidget);
    expect(find.textContaining('جاهزٌ للإرسال'), findsOneWidget);
    await tester.tap(find.text('جرّب إشعاراً الآن'));
    await tester.pumpAndSettle();
    expect(find.text('في نسخة العرض لا تصل الهاتف إشعارات.'), findsOneWidget);
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();

    // الواصل لا يصل الهاتف: تختفي معاينته
    await tester.tap(find.widgetWithText(SwitchListTile, 'وصلت شحنتك'));
    await tester.pumpAndSettle();
    expect(find.text('وصلت شحنتك 000288'), findsNothing);
    expect(find.text('شحنتك 000291 لم تُسلَّم — للمعالجة'), findsOneWidget);

    // بلا صوت
    await tester.tap(find.widgetWithText(SwitchListTile, 'صوت الإشعار'));
    await tester.pumpAndSettle();
    expect(find.text('يصل بصمت في شريط الإشعارات'), findsOneWidget);

    // يعود كما كان لبقية الاختبارات
    await tester.tap(find.widgetWithText(SwitchListTile, 'وصلت شحنتك'));
    await tester.tap(find.widgetWithText(SwitchListTile, 'صوت الإشعار'));
    await tester.pumpAndSettle();
  });

  testWidgets('الشحنات: الشرائح والبحث وفتح الشحنة', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const CompanyApp(signedIn: true));
    await tester.pumpAndSettle();

    // «سُلّمت اليوم» في الرئيسية تفتح «الشحنات» على شريحة المسلَّمة
    await _reveal(tester, find.text('سُلّمت اليوم'));
    // فوق الشريط السفلي لا تحته
    await tester.ensureVisible(find.text('سُلّمت اليوم'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('سُلّمت اليوم'));
    await tester.pumpAndSettle();
    expect(find.text('الشحنات'), findsWidgets);
    expect(find.text('محمد علي'), findsOneWidget);
    expect(find.text('نور خالد'), findsNothing);

    // البحث في الكل
    await tester.tap(find.textContaining('الكل').first);
    await tester.pumpAndSettle();
    await tester.enterText(_hint('ابحث بالاسم أو الهاتف أو رقم الوصل'), 'زهراء');
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pumpAndSettle();
    expect(find.text('زهراء كريم'), findsOneWidget);
    expect(find.text('محمد علي'), findsNothing);

    // الشحنة: مسارها وزبونها وحسابها
    await tester.tap(find.text('زهراء كريم'));
    await tester.pumpAndSettle();
    for (final text in [
      'كود التسليم',
      'مسار الشحنة',
      'الزبون والعنوان',
      'حساب الشحنة',
      'أرسل التتبّع للزبون',
      'اسأل عن الشحنة',
    ]) {
      await tester.scrollUntilVisible(find.text(text), 200, scrollable: _vertical);
      expect(find.text(text), findsOneWidget, reason: text);
    }
  });

  testWidgets('طلب جديد: أرقام الهاتف العربية، والمنطقة بالبحث، ويصلك، والحفظ', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const CompanyApp(signedIn: true));
    await tester.pumpAndSettle();

    // «+» في وسط الشريط ثم «أكتبها بنفسي» يفتح النموذج على محافظة التاجر
    final semantics = tester.ensureSemantics();
    await tester.tap(find.bySemanticsLabel('شحنة جديدة'));
    await tester.pumpAndSettle();
    semantics.dispose();
    await tester.tap(find.text('أكتبها بنفسي'));
    await tester.pumpAndSettle();
    expect(find.text('بغداد'), findsOneWidget);

    // لوحة الهاتف العربية: تُكتب أرقاماً لاتينية
    await tester.enterText(_hint('07xxxxxxxxx'), '٠٧٨٠١٢٣٤٥٦٧');
    expect(_value(tester, '07xxxxxxxxx'), '07801234567');

    // المنطقة بالبحث: «كراده» تجد «الكرادة»
    await tester.tap(find.text('اختر المنطقة'));
    await tester.pumpAndSettle();
    await tester.enterText(_hint('ابحث عن المنطقة'), 'كراده');
    await tester.pumpAndSettle();
    await tester.tap(find.text('الكرادة'));
    await tester.pumpAndSettle();
    expect(find.text('الكرادة'), findsOneWidget);

    // السعر بمسافات الآلاف، و«يصلك» من التسعيرة
    await tester.enterText(_hint('ما يدفعه الزبون'), '25000');
    expect(_value(tester, 'ما يدفعه الزبون'), '25 000');
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();
    expect(find.textContaining('يصلك 20,000 د.ع', findRichText: true), findsOneWidget);

    await tester.dragUntilVisible(find.text('حفظ الشحنة'), find.text('العنوان'), const Offset(0, -300));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حفظ الشحنة'));
    await tester.pumpAndSettle();
    expect(find.textContaining('حُفظت الشحنة', findRichText: true), findsOneWidget);
    // والنموذج فارغٌ للتالية
    expect(_value(tester, '07xxxxxxxxx'), '');
  });

  testWidgets('الزرّ الأوسط: «بالمسح» يمسح الوصل المطبوع ثم يُكتب الطلب عليه', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const CompanyApp(signedIn: true));
    await tester.pumpAndSettle();

    // «+» المرفوع في وسط الشريط ثم «بالمسح» يفتح الكاميرا
    final semantics = tester.ensureSemantics();
    await tester.tap(find.bySemanticsLabel('شحنة جديدة'));
    await tester.pumpAndSettle();
    semantics.dispose();
    await tester.tap(find.text('بالمسح'));
    await tester.pumpAndSettle();
    expect(find.text('امسح الوصل المطبوع'), findsOneWidget);

    // رقمٌ ليس وصلاً يُرفض بسببه
    await tester.enterText(_hint('90000001'), '1234');
    await tester.tap(find.text('تحقّق'));
    await tester.pumpAndSettle();
    expect(find.textContaining('ليس رقم وصلٍ مطبوع'), findsOneWidget);

    // والوصل الصحيح — بلوحةٍ عربية — يفتح «طلب جديد» عليه
    await tester.enterText(_hint('90000001'), '٩٠٠٠٠٠٠٧');
    await tester.tap(find.text('تحقّق'));
    await tester.pumpAndSettle();
    expect(find.text('امسح الوصل المطبوع'), findsNothing);
    expect(find.text('على الوصل المطبوع'), findsOneWidget);
    expect(find.text('90000007'), findsOneWidget);

    // «بلا وصل» يتركه
    await tester.tap(find.byTooltip('بلا وصل'));
    await tester.pumpAndSettle();
    expect(find.text('على الوصل المطبوع'), findsNothing);
  });
}

Finder _hint(String hint) =>
    find.byWidgetPredicate((w) => w is TextField && w.decoration?.hintText == hint, description: 'حقل «$hint»');

String _value(WidgetTester tester, String hint) => tester.widget<TextField>(_hint(hint)).controller!.text;

/// قائمة الرئيسية العمودية — لا شرائح الإعلانات الأفقية فوقها
final _vertical = find.byWidgetPredicate((w) => w is Scrollable && w.axisDirection == AxisDirection.down).first;

/// ينزل في الرئيسية حتى يُبنى ما يُطلب (القائمة تبني ما يظهر وحده)
Future<void> _reveal(WidgetTester tester, Finder finder) async {
  for (var i = 0; i < 20 && finder.evaluate().isEmpty; i++) {
    await tester.drag(_vertical, const Offset(0, -150));
    await tester.pumpAndSettle();
  }
}
