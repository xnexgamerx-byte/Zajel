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
  // «تشانغا» للعناوين والأرقام الكبيرة (docs/plan/70)
  final display = FontLoader('Changa');
  for (final w in [500, 600, 700, 800]) {
    display.addFont(Future.value(ByteData.view(File('assets/fonts/Changa-$w.ttf').readAsBytesSync().buffer)));
  }
  await display.load();
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
      '، أحمد',
      // «رحلة شحناتك» محطّةً محطّة (docs/plan/70)
      'رحلة شحناتك الآن',
      'بانتظار مندوب',
      'بالمخزن',
      'قيد التوصيل',
      'سُلّمت اليوم',
      '3 راجعة إليك',
      // «أنشئ طلباً»: زرٌّ أحمر وثلاث بطاقات، ثم طباعة الوصولات (docs/plan/63)
      'الزبون والمبلغ والعنوان بخطوةٍ واحدة',
      'من صورة',
      'بصوتك',
      'اطلب استلام',
      'طباعة وصولات الطلبات المدخلة',
      'للمعالجة',
      'عالجها',
      'عرض الكل',
      // «تحتاج انتباهك» أوّلاً بتفاصيلها (docs/plan/61)
      'تحتاج انتباهك (3)',
      'الزبون طلب التأجيل',
      'طلب جديد',
    ]) {
      await _reveal(tester, find.textContaining(text));
      expect(find.textContaining(text), findsWidgets, reason: text);
    }

    // المال في «المالية» وحدها لا في الرئيسية (docs/plan/70)
    expect(find.textContaining('1,750,000'), findsNothing);
    expect(find.textContaining('المتاح للسحب'), findsNothing);
    // والأدوات في «حسابي» لا في الرئيسية (docs/plan/59)
    expect(find.text('أدوات سريعة'), findsNothing);

    // و«آخر الشحنات» بضغطة
    await tester.scrollUntilVisible(find.text('آخر الشحنات'), 300, scrollable: _vertical);
    await tester.tap(find.text('آخر الشحنات'));
    await tester.pumpAndSettle();
    expect(find.textContaining('#ZA-20260124', skipOffstage: false), findsWidgets);
  });

  testWidgets('شحناتي: الشرائح والبحث وفتح الشحنة', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();

    // محطّة «سُلّمت اليوم» في الرئيسية تفتح «شحناتي» على شريحة المسلَّمة
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

    // «طلب جديد» الأحمر في الرئيسية يفتح النموذج على محافظة التاجر
    await tester.tap(find.text('الزبون والمبلغ والعنوان بخطوةٍ واحدة'));
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

    // الزرّ الأحمر في وسط الشريط السفلي يفتح الكاميرا
    await tester.tapAt(const Offset(196.5, 780));
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
