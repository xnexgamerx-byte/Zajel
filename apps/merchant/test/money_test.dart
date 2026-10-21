// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:flutter/services.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:merchant_app/core/config.dart';
import 'package:merchant_app/main.dart';

Future<void> _loadCairo() async {
  final loader = FontLoader('Cairo');
  for (final w in [400, 500, 600, 700, 800]) {
    loader.addFont(Future.value(ByteData.view(File('assets/fonts/Cairo-$w.ttf').readAsBytesSync().buffer)));
  }
  await loader.load();
}

Future<void> _start(WidgetTester tester) async {
  expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');
  tester.view.physicalSize = const Size(393 * 3, 849 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(const MerchantApp(signedIn: true));
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(_loadCairo);

  testWidgets('للمعالجة: بطاقة الرئيسية تفتحها، والتأجيل يُسجَّل ويُخرج الشحنة', (tester) async {
    await _start(tester);

    await _reveal(tester, find.text('للمعالجة'));
    await tester.ensureVisible(find.text('للمعالجة').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('للمعالجة').first);
    await tester.pumpAndSettle();

    for (final text in [
      'حسين علي',
      'الهاتف مغلق',
      'فاطمة محمد',
      'الزبون رفض الاستلام',
      'إعادة توصيل',
      'تأجيل',
      'إرجاع',
    ]) {
      expect(find.textContaining(text, findRichText: true), findsWidgets, reason: text);
    }
    expect(find.text('بغداد · الكرادة · تنتظر منذ يوم'), findsOneWidget);

    // تأجيل «حسين علي» (البطاقة الثانية) إلى الغد
    await tester.tap(find.text('تأجيل').at(1));
    await tester.pumpAndSettle();
    expect(find.textContaining('تأجيل — \u2066#ZA-20260130'), findsOneWidget);
    await tester.tap(find.text('غداً'));
    await tester.pump();
    await tester.tap(find.text('أكّد: تأجيل'));
    await tester.pumpAndSettle();

    expect(find.text('سُجّل قرارك: مؤجل.'), findsOneWidget);
    expect(find.textContaining('حسين علي', findRichText: true), findsNothing);
    expect(find.textContaining('فاطمة محمد', findRichText: true), findsOneWidget);
    expect(find.textContaining('شحنتان لم يستلمها'), findsOneWidget);
    expect(find.textContaining('تنتظر منذ يوم'), findsNothing);
  });

  testWidgets('المالية: الرصيد بتفصيله، وطلب المحاسبة، و«استلمتُها»', (tester) async {
    await _start(tester);

    await tester.tap(find.text('المالية').last);
    await tester.pumpAndSettle();

    for (final text in [
      '1,250,000',
      '500,000',
      'MS000041',
      'MS000037',
      'استلمتها',
      'امسح للتأكيد',
      'استلام الحساب',
      // «مؤشراتك هذا الشهر» بدل تركيب الرصيد (docs/plan/70)
      'مؤشراتك هذا الشهر',
      'تشرين الأول',
      '90%',
      '1,792,000',
      '▲ 12%',
      'مستحقّ الشحنة ZA-20260124',
    ]) {
      await _reveal(tester, find.textContaining(text));
      expect(find.textContaining(text), findsWidgets, reason: text);
    }

    // الكشف يُضغط فتُفتح تفاصيله بشحناته (docs/plan/61)
    await tester.tap(find.text('MS000037'));
    await tester.pumpAndSettle();
    expect(find.text('شحنات الكشف'), findsOneWidget);
    expect(find.text('لك'), findsOneWidget);
    expect(find.textContaining('#ZA-'), findsWidgets);
    await tester.tap(find.byTooltip('رجوع').last);
    await tester.pumpAndSettle();

    // «استلام الحساب»: لا زرّ يؤكّد — يُمسح الوصل أو يُكتب رمزه (docs/plan/69)
    expect(find.text('استلمتُها'), findsNothing);
    await _reveal(tester, find.text('استلام الحساب'));
    await tester.tap(find.text('استلام الحساب'));
    await tester.pumpAndSettle();
    expect(find.text('استلام بالباركود'), findsOneWidget);

    // رمزٌ ليس لورقةٍ من أوراقه
    await tester.enterText(find.byType(TextField), 'AAAAA-BBBBB');
    await tester.tap(find.text('أكّد'));
    await tester.pumpAndSettle();
    expect(find.textContaining('ليس لورقةٍ من أوراقك'), findsOneWidget);

    // والرمز المطبوع تحت الباركود، بأحرفٍ صغيرة وشرطة
    await tester.enterText(find.byType(TextField), 'k7m2p-qx9rt');
    await tester.tap(find.text('أكّد'));
    await tester.pumpAndSettle();
    expect(find.text('تمّ تأكيد الاستلام'), findsOneWidget);
    expect(find.text('أكّدت استلام دفعة الكشف MS000041.'), findsOneWidget);
    await tester.tap(find.text('تمّ'));
    await tester.pumpAndSettle();
    expect(find.text('امسح للتأكيد'), findsNothing);
    expect(find.text('استلمتها'), findsNWidgets(2));

    // طلب محاسبة بزين كاش المحفوظ
    await tester.scrollUntilVisible(find.text('اطلب محاسبة'), -300, scrollable: _vertical);
    await tester.tap(find.text('اطلب محاسبة'));
    await tester.pumpAndSettle();
    expect(find.text('طريقة الدفع'), findsOneWidget);
    expect(find.text('زين كاش'), findsOneWidget);
    await tester.tap(find.text('أرسل الطلب'));
    await tester.pumpAndSettle();
    expect(find.textContaining('تمّ الطلب REQ-261010-7'), findsOneWidget);
    expect(find.textContaining('طلبك REQ-261010-7 بانتظار المعالجة'), findsOneWidget);
    expect(find.text('اطلب محاسبة'), findsNothing);
  });
}

final _vertical = find.byWidgetPredicate((w) => w is Scrollable && w.axisDirection == AxisDirection.down).first;

/// ينزل في الرئيسية حتى يُبنى ما يُطلب (القائمة تبني ما يظهر وحده)
Future<void> _reveal(WidgetTester tester, Finder finder) async {
  for (var i = 0; i < 20 && finder.evaluate().isEmpty; i++) {
    await tester.drag(_vertical, const Offset(0, -150));
    await tester.pumpAndSettle();
  }
}
