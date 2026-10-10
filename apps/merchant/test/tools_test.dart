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

Future<void> _open(WidgetTester tester, String tool) async {
  expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');
  tester.view.physicalSize = const Size(393 * 3, 849 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(const MerchantApp(signedIn: true));
  await tester.pumpAndSettle();
  // من «المزيد» في الشريط السفلي
  await tester.tap(find.text('المزيد'));
  await tester.pumpAndSettle();
  await tester.tap(find.text(tool).last);
  await tester.pumpAndSettle();
}

Finder _hint(String hint) => find.byWidgetPredicate(
  (w) => w is TextField && (w.decoration?.hintText?.startsWith(hint) ?? false),
  description: 'حقل «$hint»',
);

void main() {
  setUpAll(_loadCairo);

  testWidgets('طلبات الاستلام: عدد الطرود واليوم، وطلبٌ مفتوحٌ واحد', (tester) async {
    await _open(tester, 'طلبات الاستلام');
    expect(find.text('PU000118'), findsOneWidget);
    expect(find.textContaining('15 طرد · استُلم 14'), findsOneWidget);

    await tester.tap(find.text('اطلب مندوب استلام'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أرسل الطلب'));
    await tester.pump();
    expect(find.text('كم طرداً عندك؟ اكتب العدد.'), findsOneWidget);

    await tester.enterText(_hint('مثلاً 12'), '١٢');
    await tester.tap(find.text('غداً'));
    await tester.tap(find.text('أرسل الطلب'));
    await tester.pumpAndSettle();

    expect(find.text('أُرسل طلب استلام برقم PU000119.'), findsOneWidget);
    expect(find.text('بانتظار مندوب'), findsOneWidget);
    expect(find.textContaining('12 طرد · يوم'), findsOneWidget);
    expect(find.text('اطلب مندوب استلام'), findsNothing);
    expect(find.textContaining('طلبك مفتوح'), findsOneWidget);
  });

  testWidgets('طلباتي: «وصلتني» على الإيصال، وكشف راجعٍ يُطلب ويُلغى', (tester) async {
    await _open(tester, 'طلباتي');
    expect(find.text('3 شحنة'), findsOneWidget);
    expect(find.text('إيصال RB000034'), findsOneWidget);

    await tester.tap(find.text('وصلتني'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'وصلتني').last);
    await tester.pumpAndSettle();
    expect(find.text('أكّدت استلام رواجع الإيصال RB000034.'), findsOneWidget);
    expect(find.text('وصلتك'), findsNWidgets(2));

    await tester.tap(find.text('اطلب كشف راجع'));
    await tester.pumpAndSettle();
    expect(find.text('مع مندوب الاستلام'), findsOneWidget);
    await tester.tap(find.text('أرسل الطلب'));
    await tester.pumpAndSettle();
    expect(find.textContaining('أُرسل طلب كشف راجع برقم'), findsOneWidget);
    expect(find.text('بانتظار المعالجة'), findsOneWidget);

    await tester.tap(find.text('ألغِ الطلب'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ألغِه'));
    await tester.pumpAndSettle();
    expect(find.text('ملغى'), findsOneWidget);
    expect(find.text('ألغِ الطلب'), findsNothing);
  });

  testWidgets('الدعم: ردّ الشركة يُقرأ ويُجاب، ورسالةٌ جديدة تفتح محادثتها', (tester) async {
    await _open(tester, 'الدعم');
    expect(find.text('واتساب الدعم'), findsOneWidget);
    expect(find.text('الشكاوى'), findsOneWidget);

    await tester.tap(find.text('تأخّر طرد الكرادة'));
    await tester.pumpAndSettle();
    expect(find.text('سارة — خدمة التجّار'), findsOneWidget);
    expect(find.textContaining('الزبون ما يرد'), findsOneWidget);

    await tester.enterText(_hint('اكتب ردّك'), 'تمام، شكراً');
    await tester.tap(find.byTooltip('أرسل'));
    await tester.pumpAndSettle();
    expect(find.text('تمام، شكراً'), findsOneWidget);

    await tester.tap(find.byTooltip('رجوع'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('رسالة جديدة'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أرسل'));
    await tester.pump();
    expect(find.text('اكتب موضوع رسالتك.'), findsOneWidget);

    await tester.enterText(_hint('مثلاً: تأخّر'), 'تعديل عنوان');
    await tester.enterText(_hint('اكتب ما تريد'), 'غيّروا عنوان الاستلام رجاءً');
    await tester.tap(find.text('أرسل'));
    await tester.pumpAndSettle();
    expect(find.text('تعديل عنوان'), findsOneWidget);
    expect(find.text('غيّروا عنوان الاستلام رجاءً'), findsOneWidget);
  });

  testWidgets('وصلات للطباعة: دفترٌ جديد بعدده ومقاسه', (tester) async {
    await _open(tester, 'وصلات للطباعة');
    expect(find.text('90000001–90000050'), findsOneWidget);
    expect(find.textContaining('استُعمل 31 من 50'), findsOneWidget);

    await tester.tap(find.text('اطبع دفتراً جديداً'));
    await tester.pumpAndSettle();
    await tester.enterText(_hint('50'), '٣٠٠');
    await tester.tap(find.text('جهّز واطبع'));
    await tester.pump();
    expect(find.text('الدفتر من وصلٍ واحد إلى 200 وصل.'), findsOneWidget);

    await tester.tap(find.text('100'));
    await tester.tap(find.text('100×100 مم'));
    await tester.tap(find.text('جهّز واطبع'));
    await tester.pumpAndSettle();
    expect(find.text('جاهزٌ دفترٌ من 100 وصلاً: 90000051–90000150.'), findsOneWidget);
    expect(find.text('90000051–90000150'), findsOneWidget);
  });

  testWidgets('رفع شحنات من ملف: معاينةٌ بالخاطئ ثم إنشاء الصحيح', (tester) async {
    await _open(tester, 'رفع شحنات من ملف');
    expect(find.text('هاتف المستلم *'), findsOneWidget);
    expect(find.text('نزّل القالب'), findsOneWidget);

    await tester.tap(find.text('اختر الملف'));
    await tester.pump(const Duration(seconds: 1));
    await tester.pumpAndSettle();
    expect(find.textContaining('الصفّ 4 (بلا هاتف): هاتف المستلم مطلوب'), findsOneWidget);
    expect(find.text('زينب كاظم'), findsOneWidget);
    expect(find.text('75,000 د.ع'), findsOneWidget);

    await tester.tap(find.text('أنشئ الصحيحة وحدها (2)'));
    await tester.pumpAndSettle();
    expect(find.textContaining('أُنشئت شحناتك، عددها 2'), findsOneWidget);
    expect(find.text('اختر الملف'), findsOneWidget);
  });
}
