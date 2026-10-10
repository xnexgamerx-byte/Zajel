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
    await tester.enterText(find.byType(TextField), 'زهراء');
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
}
