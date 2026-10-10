// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:flutter/services.dart';
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
    expect(find.text('الكرادة · تنتظر منذ يوم'), findsOneWidget);

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
      'أكّدتَ استلامها',
      'مستحقّ الشحنة ZA-20260124',
    ]) {
      expect(find.textContaining(text), findsWidgets, reason: text);
    }

    // «استلمتُها» على الكشف المدفوع غير المؤكَّد
    await tester.tap(find.text('استلمتُها'));
    await tester.pumpAndSettle();
    expect(find.text('أكّدت استلام الدفعة.'), findsOneWidget);
    expect(find.text('استلمتُها'), findsNothing);
    expect(find.text('أكّدتَ استلامها'), findsNWidgets(2));

    // طلب محاسبة بزين كاش المحفوظ
    await tester.tap(find.textContaining('اطلب محاسبة —'));
    await tester.pumpAndSettle();
    expect(find.text('طريقة الدفع'), findsOneWidget);
    expect(find.text('زين كاش'), findsOneWidget);
    await tester.tap(find.text('أرسل الطلب'));
    await tester.pumpAndSettle();
    expect(find.textContaining('تمّ الطلب REQ-261010-7'), findsOneWidget);
    expect(find.textContaining('طلبك REQ-261010-7 بانتظار المعالجة'), findsOneWidget);
    expect(find.textContaining('اطلب محاسبة —'), findsNothing);
  });
}
