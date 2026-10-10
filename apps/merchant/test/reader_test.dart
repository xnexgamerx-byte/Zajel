// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:flutter/material.dart';
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

/// القراءة في العرض تأخذ لحظة: تُنتظر ثم تستقرّ الشاشة
Future<void> _wait(WidgetTester tester) async {
  await tester.pump(const Duration(seconds: 1));
  await tester.pumpAndSettle();
}

Finder _hint(String hint) => find.byWidgetPredicate(
  (w) => w is TextField && (w.decoration?.hintText?.startsWith(hint) ?? false),
  description: 'حقل «$hint»',
);

String _value(WidgetTester tester, String hint) => tester.widget<TextField>(_hint(hint)).controller!.text;

void main() {
  setUpAll(_loadCairo);

  testWidgets('بالذكاء الاصطناعي: رسالة الزبون تملأ «طلب جديد» للمراجعة', (tester) async {
    await _start(tester);

    await tester.tap(find.text('إنشاء بالذكاء الاصطناعي'));
    await tester.pumpAndSettle();
    expect(find.text('لقطة شاشة'), findsOneWidget);

    // بلا رسالة: يقول ما يُنتظر منه
    await tester.tap(find.text('اقرأ الطلب'));
    await tester.pump();
    expect(find.text('الصق رسالة الزبون، أو اختر لقطة شاشةٍ لمحادثته.'), findsOneWidget);

    await tester.enterText(_hint('الصق الرسالة هنا'), 'علي حسين 07712345678 بغداد الكرادة قرب ساحة كهرمانة 25 الف');
    await tester.tap(find.text('اقرأ الطلب'));
    await _wait(tester);

    expect(find.text('قُرئ الطلب — راجعه ثم احفظ'), findsOneWidget);
    expect(_value(tester, 'اختياري — يُطبع على الوصل'), 'علي حسين');
    expect(_value(tester, '07xxxxxxxxx'), '07712345678');
    expect(_value(tester, 'ما يدفعه الزبون'), '25 000');
    expect(find.text('الكرادة'), findsOneWidget);
    expect(find.textContaining('سمعنا'), findsNothing);

    // يُخفى ولا يُمسّ ما في النموذج
    await tester.tap(find.byTooltip('إخفاء'));
    await tester.pump();
    expect(find.text('قُرئ الطلب — راجعه ثم احفظ'), findsNothing);
    expect(_value(tester, '07xxxxxxxxx'), '07712345678');
  });

  testWidgets('بالتسجيل الصوتي: يسجّل حتى «أوقف» ثم يُكتب ما سُمع', (tester) async {
    await _start(tester);

    await tester.tap(find.text('إنشاء بالتسجيل الصوتي'));
    await tester.pumpAndSettle();
    expect(find.text('اضغط المايك وتكلّم'), findsOneWidget);

    await tester.tap(find.byTooltip('تكلّم'));
    await tester.pump(const Duration(milliseconds: 1300));
    expect(find.text('يسجّل 0:01 — اضغط لتوقف وترسل'), findsOneWidget);

    await tester.tap(find.byTooltip('أوقف'));
    await tester.pump();
    expect(find.text('يسمع الطلب ويقرؤه…'), findsOneWidget);
    await _wait(tester);

    expect(find.text('قُرئ الطلب — راجعه ثم احفظ'), findsOneWidget);
    expect(find.textContaining('سمعنا: «مصطفى عادل'), findsOneWidget);
    expect(_value(tester, 'اختياري — يُطبع على الوصل'), 'مصطفى عادل');
    expect(_value(tester, '07xxxxxxxxx'), '07741077999');
  });
}
