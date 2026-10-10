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
      'صباح الخير، أحمد',
      'إجمالي المستحقات',
      'المتاح للسحب',
      'قيد المطابقة',
      '1,750,000',
      'إجمالي الشحنات',
      'إنشاء بالذكاء الاصطناعي',
      'أدوات سريعة',
      'للمعالجة',
      'عرض الكل',
      '#ZA-20260124',
      'طلب جديد',
    ]) {
      expect(find.textContaining(text), findsWidgets, reason: text);
    }

    // إخفاء الرصيد بالعين
    await tester.tap(find.byIcon(Icons.visibility_outlined));
    await tester.pump();
    expect(find.text('1,750,000'), findsNothing);
  });

  testWidgets('شحناتي: الشرائح والبحث وفتح الشحنة', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();

    // عدّاد «مسلمة» في الرئيسية يفتح «شحناتي» على شريحته
    await tester.tap(find.text('مسلمة').first);
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
    for (final text in ['مسار الشحنة', 'الزبون والعنوان', 'حساب الشحنة', 'كود التسليم', 'أرسل التتبّع للزبون']) {
      expect(find.text(text), findsOneWidget, reason: text);
    }
  });

  testWidgets('طلب جديد: أرقام الهاتف العربية، والمنطقة بالبحث، ويصلك، والحفظ', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const MerchantApp(signedIn: true));
    await tester.pumpAndSettle();

    // «إدخال يدوي» في الرئيسية يفتح النموذج على محافظة التاجر
    await tester.tap(find.text('إدخال يدوي'));
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
}

Finder _hint(String hint) =>
    find.byWidgetPredicate((w) => w is TextField && w.decoration?.hintText == hint, description: 'حقل «$hint»');

String _value(WidgetTester tester, String hint) => tester.widget<TextField>(_hint(hint)).controller!.text;
