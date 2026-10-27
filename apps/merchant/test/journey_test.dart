// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:merchant_app/core/access.dart';
import 'package:merchant_app/main.dart';

/// docs/plan/87: «بالمخزن» ومكان الراجع، وخانات الحالات، والتقويم، وموظّفو المتجر بصلاحياتهم.
Future<void> _loadCairo() async {
  final loader = FontLoader('Cairo');
  for (final w in [400, 500, 600, 700, 800]) {
    loader.addFont(Future.value(ByteData.view(File('assets/fonts/Cairo-$w.ttf').readAsBytesSync().buffer)));
  }
  await loader.load();
}

final _vertical = find.byWidgetPredicate((w) => w is Scrollable && w.axisDirection == AxisDirection.down).first;

Future<void> _reveal(WidgetTester tester, Finder finder) async {
  for (var i = 0; i < 25 && finder.evaluate().isEmpty; i++) {
    await tester.drag(_vertical, const Offset(0, -150));
    await tester.pumpAndSettle();
  }
}

Future<void> _app(WidgetTester tester) async {
  tester.view.physicalSize = const Size(393 * 3, 849 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const MerchantApp(signedIn: true));
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(_loadCairo);

  testWidgets('الرئيسية: ثلاثٌ تحتاج انتباهك، وتقويم الشحنات بأدائها', (tester) async {
    await _app(tester);

    await _reveal(tester, find.textContaining('نبأ سالم'));
    expect(find.textContaining('نبأ سالم'), findsOneWidget);
    expect(find.textContaining('رسل حيدر'), findsNothing, reason: 'الرابعة في «عرض الكل»');
    await _reveal(tester, find.text('آخر ٧ أيام'));
    expect(find.textContaining('رسل حيدر'), findsNothing);
    expect(find.text('آخر ٧ أيام'), findsOneWidget);
    expect(find.text('شحنة أدخلتها'), findsOneWidget);

    await tester.drag(_vertical, const Offset(0, -200));
    await tester.pumpAndSettle();
    await tester.tap(find.text('آخر ٧ أيام'));
    await tester.pumpAndSettle();
    for (final text in ['عدد الشحنات', 'العدد الكلّي', 'تم التسليم', 'نسبة التسليم', 'يوماً بيوم', 'من أدخلها']) {
      await _reveal(tester, find.text(text));
      expect(find.text(text), findsWidgets, reason: text);
    }

    // يومٌ من الشريط وحده، ثم يعود إلى الفترة
    await tester.drag(_vertical, const Offset(0, 2000));
    await tester.pumpAndSettle();
    final today = DateTime.now();
    await tester.tap(find.text('${today.day}').first);
    await tester.pumpAndSettle();
    expect(find.textContaining('شحنات '), findsWidgets);
    expect(find.text('يوماً بيوم'), findsNothing);

    // فترةٌ أخرى
    await tester.tap(find.text('٣٠ يوماً'));
    await tester.pumpAndSettle();
    expect(find.text('عدد الشحنات'), findsOneWidget);
  });

  testWidgets('رحلة شحناتك اليوم: كلّ الحالات في خاناتٍ بحلقاتها، وكلٌّ تفتح قائمتها', (tester) async {
    await _app(tester);

    await tester.tap(find.text('كل الحالات'));
    await tester.pumpAndSettle();
    for (final text in [
      'كل الشحنات',
      'جديدة',
      'بالمخزن',
      'مع المندوب',
      'مؤجلة',
      'راجع عند المندوب',
      'راجع في المخزن',
    ]) {
      await tester.scrollUntilVisible(find.text(text), 120, scrollable: _vertical);
      expect(find.text(text), findsOneWidget, reason: text);
    }

    await tester.scrollUntilVisible(find.text('بالمخزن'), -120, scrollable: _vertical);
    await tester.tap(find.text('بالمخزن'));
    await tester.pumpAndSettle();
    expect(find.text('كرار عادل'), findsOneWidget);
    expect(find.text('علي حسين'), findsNothing);
  });

  testWidgets('شحناتي: «بالمخزن» شريحةٌ وحدها، وتحت كلّ راجعٍ مكانه', (tester) async {
    await _app(tester);

    await tester.tap(find.text('شحناتي').last);
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('بالمخزن').first);
    await tester.pumpAndSettle();
    expect(find.text('كرار عادل'), findsOneWidget);
    expect(find.text('علي حسين'), findsNothing);

    // شرائح «شحناتي» تنزلق جانباً: «تحتاج انتباهك» بعد «بالمخزن»
    final chips = find.ancestor(of: find.textContaining('بالمخزن').first, matching: find.byType(Scrollable)).first;
    await tester.scrollUntilVisible(find.textContaining('تحتاج انتباهك'), 120, scrollable: chips);
    await tester.tap(find.textContaining('تحتاج انتباهك').first);
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('راجع في المخزن'), 150, scrollable: _vertical);
    expect(find.text('راجع عند مركز محافظة بابل'), findsOneWidget);
    expect(find.text('راجع في المخزن'), findsOneWidget);
  });

  testWidgets('موظّفو المتجر: يضيف صاحب المتجر محاسباً بصلاحياته', (tester) async {
    await _app(tester);

    await tester.tap(find.text('المزيد'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('موظّفو المتجر'));
    await tester.pumpAndSettle();
    expect(find.text('سجى علي'), findsOneWidget);
    expect(find.text('موظّف إدخال'), findsOneWidget);

    await tester.tap(find.text('أضف موظّفاً'));
    await tester.pumpAndSettle();
    // بلا صلاحية: لا يُحفظ
    await tester.enterText(find.widgetWithText(TextField, 'مثلاً: سجى علي'), 'حيدر');
    await tester.enterText(find.widgetWithText(TextField, '07xxxxxxxxx'), '07705556677');
    await tester.enterText(find.widgetWithText(TextField, 'ستّة أحرفٍ أو أكثر'), 'secret12');
    await tester.tap(find.text('محاسب'));
    await tester.pumpAndSettle();
    expect(tester.widget<CheckboxListTile>(find.widgetWithText(CheckboxListTile, 'المالية')).value, isTrue);
    expect(tester.widget<CheckboxListTile>(find.widgetWithText(CheckboxListTile, 'إدخال الشحنات')).value, isFalse);

    // صلاحيةٌ زائدة: يصير «مخصّصاً»
    await tester.ensureVisible(find.widgetWithText(CheckboxListTile, 'الدعم'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(CheckboxListTile, 'الدعم'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('مخصّص'));
    await tester.pumpAndSettle();
    expect(
      tester.widget<ChoiceChip>(find.ancestor(of: find.text('مخصّص'), matching: find.byType(ChoiceChip))).selected,
      isTrue,
    );
    await tester.tap(find.widgetWithText(CheckboxListTile, 'الدعم'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.text('أضفه'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أضفه'));
    await tester.pumpAndSettle();
    expect(find.text('حيدر'), findsOneWidget);
    expect(find.text('محاسب'), findsOneWidget);
  });

  testWidgets('موظّف الإدخال: لا مالية ولا رصيد ولا موظّفين — وما فُتح له يعمل', (tester) async {
    await _app(tester);
    addTearDown(() => Access.set(null, staff: true));

    Access.set(['shipments', 'create', 'print', 'pickups'], staff: true);
    await tester.pumpAndSettle();

    expect(find.text('المالية'), findsNothing);
    expect(find.text('التفاصيل المالية'), findsNothing);
    expect(find.text('1,750,000'), findsNothing);
    expect(find.text('عالجها'), findsNothing);
    expect(find.byTooltip('الدعم الفني'), findsNothing);
    expect(find.text('طباعة الطلبات'), findsOneWidget);
    expect(find.text('شحناتي'), findsWidgets);

    await tester.tap(find.text('المزيد'));
    await tester.pumpAndSettle();
    for (final hidden in ['موظّفو المتجر', 'كشوفات الرواجع', 'استلام راجع بالباركود', 'الدعم', 'طلباتي', 'للمعالجة']) {
      expect(find.text(hidden), findsNothing, reason: hidden);
    }
    expect(find.text('الطابعة — وصولات الطلبات الجديدة'), findsOneWidget);
    expect(find.text('طلبات الاستلام'), findsOneWidget);

    // محاسبٌ بلا شحنات: لا «شحناتي» ولا أرقامها
    Access.set(['finance'], staff: true);
    await tester.pumpAndSettle();
    expect(find.text('شحناتي'), findsNothing);
    expect(find.text('المالية'), findsOneWidget);
  });
}
