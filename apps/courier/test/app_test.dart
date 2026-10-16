// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:courier_app/core/config.dart';
import 'package:courier_app/core/demo.dart';
import 'package:courier_app/core/palette.dart';
import 'package:courier_app/main.dart';
import 'package:courier_app/screens/login_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

/// خطّ التطبيق في الاختبار — وإلا قاس flutter_test بخطّ مربّعاتٍ عريض لا يشبه شيئاً
Future<void> _loadCairo() async {
  final loader = FontLoader('Cairo');
  for (final w in [400, 500, 600, 700, 800]) {
    loader.addFont(Future.value(ByteData.view(File('assets/fonts/Cairo-$w.ttf').readAsBytesSync().buffer)));
  }
  await loader.load();
}

Future<void> _start(WidgetTester tester) async {
  tester.view.physicalSize = const Size(393 * 3, 849 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const CourierApp(signedIn: true));
  await tester.pumpAndSettle();
}

/// يمرّر القائمة المعروضة حتى يظهر النصّ ثم يضغطه
Future<void> _tap(WidgetTester tester, Finder target) async {
  await tester.ensureVisible(target);
  await tester.pumpAndSettle();
  await tester.tap(target);
  await tester.pumpAndSettle();
}

/// الشريط السفلي: النصّ الأخير على الشاشة هو تسميته
Future<void> _tab(WidgetTester tester, String label) async {
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

Future<void> _openTasks(WidgetTester tester) async {
  await _tap(tester, find.text('ابدأ جولتك'));
  expect(find.text('بغداد — الكرادة (4)'), findsOneWidget);
}

Future<void> _openShipment(WidgetTester tester, String number) async {
  await _openTasks(tester);
  await _tap(tester, find.text(tag(number)).first);
}

void main() {
  setUpAll(_loadCairo);
  setUp(Demo.reset);

  testWidgets('الدخول باسم التطبيق ونصّ المكتب', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: LoginScreen(brand: Brand.hex('#FE0A0E'), onSignedIn: (_) {}),
      ),
    );
    expect(find.text('الزاجل'), findsOneWidget);
    expect(find.text('تطبيق المندوب'), findsOneWidget);
    expect(find.text('نسيت كلمة المرور؟ راجع المكتب'), findsOneWidget);

    await tester.tap(find.text('دخول'));
    await tester.pump();
    expect(find.text('اكتب اسم المستخدم وكلمة المرور.'), findsOneWidget);
  });

  testWidgets('الرئيسية: ما بيده، والنقد وسقفه، وأرقام اليوم، وما يحتاج انتباهه', (tester) async {
    expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');
    expect(AppConfig.appName, 'مندوب الزاجل');
    await _start(tester);

    for (final text in [
      'مندوب تجريبي',
      'بيدك الآن',
      '6',
      '165,000',
      'النقد بيدك',
      '2,340,000',
      '78٪ من السقف',
      'سُلّمت اليوم',
      'راجع بيدي',
      'تحتاج انتباهك (5)',
      'طلب تغيير المبلغ ينتظر جواب الكول سنتر',
      'يُسلَّم بكود من الزبون',
      'راجعٌ بيدك: سلّمه للمخزن',
    ]) {
      await tester.ensureVisible(find.textContaining(text).first);
      expect(find.textContaining(text), findsWidgets, reason: text);
    }
    // الشريط: الماسح في وسطه
    expect(find.text('مسح الشحنة'), findsOneWidget);
  });

  testWidgets('مهامي: مجمّعة بالمنطقة، والبحث، والراجع', (tester) async {
    await _start(tester);
    await _openTasks(tester);

    expect(find.text('بغداد — المنصور (2)'), findsOneWidget);
    expect(find.text('ينتظر جواب الكول سنتر على المبلغ'), findsOneWidget);
    expect(find.text('يُسلَّم بكود'), findsNWidgets(2));

    await tester.enterText(find.byType(TextField), '180212');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(find.text(tag('180212')), findsOneWidget);
    expect(find.text(tag('180201')), findsNothing);

    await tester.enterText(find.byType(TextField), '');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    await _tap(tester, find.text('الراجع 1'));
    expect(find.text(tag('180190')), findsOneWidget);
    expect(find.text('راجع'), findsWidgets);
  });

  testWidgets('شحنةٌ بكود: «سُلّمت» لا تعمل قبل الكود، والكود الخاطئ يُرفض، والصحيح يُسلّمها', (tester) async {
    await _start(tester);
    await _openShipment(tester, '180201');

    expect(find.text('زينب علي'), findsOneWidget);
    expect(find.text('كود التسليم'), findsOneWidget);
    expect(find.text('اتصل قبل الوصول بربع ساعة'), findsOneWidget);
    // الكود لا يُعرض أبداً — يُطلب من الزبون
    expect(find.textContaining('4821'), findsNothing);

    await tester.tap(find.text('سُلّمت'));
    await tester.pumpAndSettle();
    expect(find.text('سُلّمت الشحنة؟'), findsNothing, reason: 'الزرّ مقفلٌ بلا كود');

    await tester.enterText(find.byType(TextField), '١١١١');
    await tester.pump();
    await _tap(tester, find.text('سُلّمت'));
    await _tap(tester, find.text('نعم، سُلّمت'));
    expect(find.text('كود التسليم غير صحيح.'), findsOneWidget);

    await tester.enterText(find.byType(TextField), '4821');
    await tester.pump();
    await _tap(tester, find.text('سُلّمت'));
    expect(find.textContaining('قبضت 25,000 د.ع'), findsOneWidget);
    await _tap(tester, find.text('نعم، سُلّمت'));

    expect(find.text('سُجِّلت الشحنة 180201: واصل.'), findsOneWidget);
    expect(find.text(tag('180201')), findsNothing, reason: 'خرجت من «مهامي»');

    await _tab(tester, 'حسابي');
    expect(find.textContaining('2,365,000'), findsWidgets);
  });

  testWidgets('لم تُسلَّم: السبب إلزامي، وما يحتاج توضيحاً لا يُحفظ بلا توضيح', (tester) async {
    await _start(tester);
    await _openShipment(tester, '180209');

    await _tap(tester, find.text('لم تُسلَّم'));
    expect(find.text('لم تُسلَّم — اختر السبب'), findsOneWidget);

    await _tap(tester, find.text('حفظ'));
    expect(find.textContaining('اختر السبب'), findsWidgets);

    await _tap(tester, find.text('الزبون رفض الاستلام'));
    await _tap(tester, find.text('حفظ'));
    expect(find.text('السبب «الزبون رفض الاستلام» يحتاج توضيحاً.'), findsOneWidget);

    await tester.enterText(find.byType(TextField).last, 'قال لم يطلبها');
    await _tap(tester, find.text('حفظ'));
    expect(find.text('سُجِّلت الشحنة 180209: لم يُسلَّم.'), findsOneWidget);
  });

  testWidgets('المبلغ لا يتغيّر عند الباب: يُطلب من الكول سنتر، والجزئي بعد اعتماده فقط', (tester) async {
    await _start(tester);
    await _openShipment(tester, '180212');

    expect(find.text('واصل جزئي بـ 20,000'), findsNothing);
    await _tap(tester, find.text('اطلب تغيير المبلغ'));
    await _tap(tester, find.text('السعر تغيّر'));
    await tester.enterText(find.byType(TextField).at(0), '15000');
    await tester.enterText(find.byType(TextField).at(1), 'اتّفق مع التاجر على خصم');
    await _tap(tester, find.text('أرسل للكول سنتر'));

    expect(find.textContaining('إلى الكول سنتر. لا تسلّم بالمبلغ الجديد حتى يُعتمد.'), findsWidgets);
    expect(find.textContaining('ينتظر جواب الكول سنتر'), findsOneWidget);
    expect(find.text('اطلب تغيير المبلغ'), findsNothing);

    // شحنةٌ اعتُمد لها واصلٌ جزئي
    await _tap(tester, find.byTooltip('رجوع'));
    await tester.enterText(find.byType(TextField), '180215');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    await _tap(tester, find.text(tag('180215')).first);
    expect(find.text('واصل جزئي بـ 20,000'), findsOneWidget);
    await _tap(tester, find.text('واصل جزئي بـ 20,000'));
    await _tap(tester, find.text('نعم، واصل جزئي'));
    expect(find.text('سُجِّلت الشحنة 180215: واصل جزئي.'), findsOneWidget);
  });

  testWidgets('حسابي والمزيد', (tester) async {
    await _start(tester);

    await _tab(tester, 'حسابي');
    for (final text in [
      'نقدك وعمولتك وحركاتك',
      'عمولتك المستحقّة',
      '180,000',
      'الواجب تسليمه',
      '2,160,000',
      'آخر الحركات',
      'CS-000031',
    ]) {
      await tester.ensureVisible(find.text(text).first);
      expect(find.text(text), findsWidgets, reason: text);
    }

    await _tab(tester, 'المزيد');
    expect(find.text('أشارك موقعي ما دام التطبيق مفتوحاً'), findsOneWidget);
    expect(find.textContaining('نسخة عرضٍ'), findsOneWidget);
    await _tap(tester, find.text('الراجع بيدي'));
    expect(find.text(tag('180190')), findsOneWidget);
  });
}
