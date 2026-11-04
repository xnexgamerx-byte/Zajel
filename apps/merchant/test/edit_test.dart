// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:merchant_app/core/config.dart';
import 'package:merchant_app/core/palette.dart';
import 'package:merchant_app/screens/shipment_screen.dart';

Future<void> _loadCairo() async {
  final loader = FontLoader('Cairo');
  for (final w in [400, 500, 600, 700, 800]) {
    loader.addFont(Future.value(ByteData.view(File('assets/fonts/Cairo-$w.ttf').readAsBytesSync().buffer)));
  }
  await loader.load();
}

Future<void> _shipment(WidgetTester tester, int id) async {
  expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');
  tester.view.physicalSize = const Size(393 * 3, 849 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: ShipmentScreen(brand: Brand.hex(null), id: id),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// تعديل التاجر لشحنته (docs/plan/113)
void main() {
  setUpAll(_loadCairo);

  testWidgets('قبل الاستلام: كلّ النموذج بقيمها، والحفظ يرجع إلى الشحنة', (tester) async {
    await _shipment(tester, 11);
    await tester.tap(find.byTooltip('تعديل الشحنة'));
    await tester.pumpAndSettle();

    expect(find.text('تعديل الشحنة'), findsOneWidget);
    expect(find.textContaining('لم يستلمها المندوب بعد'), findsOneWidget);
    expect(find.text('المحافظة *', findRichText: true), findsOneWidget);
    expect(find.text('السعر مع التوصيل *', findRichText: true), findsOneWidget);
    expect(find.text('مريم سعد'), findsOneWidget);
    expect(find.text('52 000'), findsOneWidget);

    // الحقول فيها Scrollable خاصّ بها: يُسحب الـListView الأعلى نفسه
    await tester.drag(find.byType(ListView).last, const Offset(0, -2000));
    await tester.pumpAndSettle();
    await tester.tap(find.text('حفظ التعديل'));
    await tester.pumpAndSettle();
    expect(find.text('حُفظ التعديل.'), findsOneWidget);
    expect(find.text('تعديل الشحنة'), findsNothing);
  });

  testWidgets('في الطريق: بيانات الزبون وحدها، والوجهة والسعر للقراءة', (tester) async {
    await _shipment(tester, 8);
    await tester.tap(find.byTooltip('تعديل الشحنة'));
    await tester.pumpAndSettle();

    expect(find.textContaining('استلمها المندوب'), findsOneWidget);
    expect(find.text('المحافظة *', findRichText: true), findsNothing);
    expect(find.text('السعر مع التوصيل *', findRichText: true), findsNothing);
    expect(find.textContaining('تغييره من الكول سنتر'), findsOneWidget);
  });

  testWidgets('المحسومة: لا زرّ تعديل', (tester) async {
    await _shipment(tester, 6);
    expect(find.byTooltip('تعديل الشحنة'), findsNothing);
  });

  testWidgets('احذف الشحنة من التعديل: يُسأل أوّلاً، ثم تُغلق الشحنة وتُعاد القوائم', (tester) async {
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    var changed = 0;
    void count() => changed++;
    shipmentsChanged.addListener(count);
    addTearDown(() => shipmentsChanged.removeListener(count));

    // الشحنة تُفتح فوق قائمةٍ كما في التطبيق
    await tester.pumpWidget(
      MaterialApp(
        home: Directionality(
          textDirection: TextDirection.rtl,
          child: Builder(
            builder: (context) => Scaffold(
              body: Center(
                child: TextButton(
                  onPressed: () => openShipment(context, Brand.hex(null), 11),
                  child: const Text('القائمة'),
                ),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('القائمة'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('تعديل الشحنة'));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView).last, const Offset(0, -2000));
    await tester.pumpAndSettle();

    // تراجع: لا يُحذف شيء
    await tester.tap(find.text('احذف الشحنة'));
    await tester.pumpAndSettle();
    expect(find.text('لا تُستلم ولا تُوصل بعدها.'), findsOneWidget);
    await tester.tap(find.text('تراجع'));
    await tester.pumpAndSettle();
    expect(find.text('احذف الشحنة'), findsOneWidget);
    expect(changed, 0);

    // احذف: بسببه، ثم تُغلق الشحنة ويعود إلى القائمة
    await tester.tap(find.text('احذف الشحنة'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).last, 'ألغى الزبون');
    await tester.tap(find.text('احذف'));
    await tester.pumpAndSettle();
    expect(find.text('حُذفت الشحنة.'), findsOneWidget);
    expect(find.text('القائمة'), findsOneWidget);
    expect(find.text('تعديل الشحنة'), findsNothing);
    expect(changed, 1);
  });

  testWidgets('في الطريق: لا زرّ حذف', (tester) async {
    await _shipment(tester, 8);
    await tester.tap(find.byTooltip('تعديل الشحنة'));
    await tester.pumpAndSettle();
    await tester.drag(find.byType(ListView).last, const Offset(0, -2000));
    await tester.pumpAndSettle();
    expect(find.text('حفظ التعديل'), findsOneWidget);
    expect(find.text('احذف الشحنة'), findsNothing);
  });
}
