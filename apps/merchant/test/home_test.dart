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
}

void main() {
  setUpAll(_loadCairo);

  testWidgets('رئيسية التاجر تعرض أقسام التصميم كلّها', (tester) async {
    expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');

    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();

    for (final text in [
      // الترويسة والبحث (docs/plan/76)
      'أحمد',
      'شحناتك مع',
      'ابحث برقم الشحنة أو اسم الزبون',
      // الرقمان بنسبةٍ حقيقيّة
      'سُلّمت اليوم',
      'إجمالي الشحنات',
      'نسبة التسليم',
      // ما ينتظر قراره
      'عالجها',
      // رحلة شحناتك اليوم حول «إنشاء شحنة» (docs/plan/86)
      'رحلة شحناتك اليوم',
      'قيد التوصيل',
      'مسلّمة',
      'راجع مؤكّد',
      'إنشاء شحنة',
      // المالية بالرصيد، وطباعة الطلبات ظاهرة، وطلب الاستلام
      'التفاصيل المالية',
      '1,750,000',
      'طباعة الطلبات',
      'مندوب استلام',
      // «شحنات تحتاج انتباهك» مكان آخر الشحنات
      'شحنات تحتاج انتباهك',
      'الزبون طلب التأجيل',
      'عرض الكل',
    ]) {
      await _reveal(tester, find.textContaining(text));
      expect(find.textContaining(text), findsWidgets, reason: text);
    }
    expect(find.text('شحناتي الأخيرة'), findsNothing);
    expect(find.text('آخر الشحنات'), findsNothing);

    // «إنشاء شحنة» في الوسط يسأل: يدويّاً، أو من صورة، أو بصوتك
    await _reveal(tester, find.text('إنشاء شحنة').first);
    await tester.tap(find.text('إنشاء شحنة').first);
    await tester.pumpAndSettle();
    for (final text in ['أكتبها بنفسي', 'من صورة أو رسالة', 'بصوتي']) {
      expect(find.text(text), findsOneWidget, reason: text);
    }
  });

  testWidgets('حالة الشحنات: كلّ حالةٍ قائمتها وحدها، والجديدة ليست قيد التوصيل، والدعم في الترويسة', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();

    // الدعم الفني مكان الإعدادات (docs/plan/79)
    expect(find.byTooltip('الدعم الفني'), findsOneWidget);
    expect(find.byIcon(Icons.settings_outlined), findsNothing);

    // «قيد التوصيل»: ما استلمه المندوب وحده، لا الجديدة التي في «الطابعة»
    await _reveal(tester, find.text('قيد التوصيل').first);
    await tester.tap(find.text('قيد التوصيل').first);
    await tester.pumpAndSettle();
    expect(find.text('استلمها المندوب، وهي في طريقها إلى زبائنك'), findsOneWidget);
    expect(find.text('علي حسين'), findsOneWidget);
    expect(find.text('كرار عادل'), findsOneWidget);
    expect(find.text('مريم سعد'), findsNothing);
    expect(find.text('زهراء كريم'), findsNothing);

    // بمحافظةٍ واحدة (docs/plan/86)
    expect(find.textContaining('كل المحافظات'), findsOneWidget);
    await tester.tap(find.text('بابل 1'));
    await tester.pumpAndSettle();
    expect(find.text('علي حسين'), findsOneWidget);
    expect(find.text('كرار عادل'), findsNothing);
    await tester.tap(find.text('كل المحافظات'));
    await tester.pumpAndSettle();
    expect(find.text('كرار عادل'), findsOneWidget);
    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();

    // «تحتاج انتباهك»: المؤجّلة بشارتها الصفراء، وموعدها (docs/plan/86)
    await _reveal(tester, find.text('تحتاج انتباهك').first);
    await tester.tap(find.text('تحتاج انتباهك').first);
    await tester.pumpAndSettle();
    expect(find.text('زهراء كريم'), findsOneWidget);
    expect(find.text('علي حسين'), findsNothing);
    expect(find.text('مؤجل إلى غداً الساعة 9:00 م'), findsOneWidget);
  });

  testWidgets('اختيار الإشعارات: ما يصل الهاتف، وبصوتٍ أم بلا صوت، وكيف يظهر', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('الإشعارات'));
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

  testWidgets('شحناتي: الشرائح والبحث وفتح الشحنة', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();

    // بحث الرئيسية يفتح «شحناتي» والمؤشّر في خانة البحث
    await tester.tap(find.textContaining('ابحث برقم الشحنة أو اسم الزبون'));
    await tester.pumpAndSettle();
    expect(tester.widget<TextField>(_hint('ابحث بالاسم أو الهاتف أو رقم الوصل')).focusNode!.hasFocus, isTrue);
    await tester.tap(find.text('الرئيسية'));
    await tester.pumpAndSettle();

    // «سُلّمت اليوم» في الرئيسية تفتح «شحناتي» على شريحة المسلَّمة
    await tester.tap(find.text('سُلّمت اليوم').first);
    await tester.pumpAndSettle();
    expect(find.text('شحناتي'), findsWidgets);
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

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();

    // «إنشاء شحنة» في الرئيسية ثم «أكتبها بنفسي» يفتح النموذج على محافظة التاجر
    await _reveal(tester, find.text('إنشاء شحنة').first);
    await tester.tap(find.text('إنشاء شحنة').first);
    await tester.pumpAndSettle();
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

  testWidgets('الزرّ الأوسط: يمسح الوصل المطبوع ثم يُكتب الطلب عليه', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();

    // زرّ المسح الأحمر المرفوع في وسط الشريط السفلي يفتح الكاميرا
    await tester.tap(find.byTooltip('امسح الوصل أو أنشئ شحنة'));
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
