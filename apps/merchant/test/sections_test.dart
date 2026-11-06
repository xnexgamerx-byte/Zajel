// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
//
// تطبيق الشركة الموحّد (docs/plan/114، 115): دخولٌ واحد، والنظام يقول قسم الحساب فيُفتح — التاجر أو الكول سنتر أو
// المندوب — والخروج من أيّها يعود إلى الدخول.
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:merchant_app/callcenter/core/demo.dart' as cc;
import 'package:merchant_app/callcenter/core/models.dart' as cc;
import 'package:merchant_app/callcenter/core/palette.dart' as cc;
import 'package:merchant_app/callcenter/core/whatsapp_template.dart';
import 'package:merchant_app/callcenter/screens/shell.dart' as cc;
import 'package:merchant_app/core/account.dart';
import 'package:merchant_app/core/api.dart';
import 'package:merchant_app/core/config.dart';
import 'package:merchant_app/courier/core/reminders.dart' as courier;
import 'package:merchant_app/main.dart';

Future<void> _loadCairo() async {
  final loader = FontLoader('Cairo');
  for (final w in [400, 500, 600, 700, 800]) {
    loader.addFont(Future.value(ByteData.view(File('assets/fonts/Cairo-$w.ttf').readAsBytesSync().buffer)));
  }
  await loader.load();
  // خطّ قسم التاجر في «وصل» (docs/plan/123): يُقاس به كما على الهاتف
  final kufi = FontLoader('NotoKufiArabic');
  for (final w in [400, 500, 600, 700, 800]) {
    kufi.addFont(Future.value(ByteData.view(File('assets/fonts/NotoKufiArabic-$w.ttf').readAsBytesSync().buffer)));
  }
  await kufi.load();
}

Future<void> _pump(WidgetTester tester, Widget app) async {
  expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');
  tester.view.physicalSize = const Size(393 * 3, 849 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(app);
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(_loadCairo);
  setUp(cc.Demo.reset);

  test('الأقسام بأسمائها في النظام، وما لا يعرفه التطبيق لا قسم له', () {
    expect(Section.of('merchant'), Section.merchant);
    expect(Section.of('callcenter'), Section.callcenter);
    expect(Section.of('courier'), Section.courier);
    expect(Section.of('admin'), isNull);
    expect(Section.of(null), isNull);
    // ويرسلها التطبيق كلّها عند الدخول
    expect(Api.sections, ['merchant', 'callcenter', 'courier']);
  });

  testWidgets('قسم الكول سنتر، ثم الخروج إلى الدخول الواحد، ثم دخولٌ يفتح قسم التاجر', (tester) async {
    await _pump(tester, const CompanyApp(signedIn: true, section: Section.callcenter));
    expect(find.text('سارة'), findsOneWidget);
    expect(find.text('أحمد'), findsNothing);

    // الخروج من قسم الكول سنتر
    await tester.tap(find.byTooltip('حسابي'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('تسجيل الخروج من هذا الجهاز'));
    await tester.tap(find.text('تسجيل الخروج من هذا الجهاز'));
    await tester.pumpAndSettle();
    expect(Account.signedIn, isFalse);
    expect(find.text('التجّار والمناديب والكول سنتر'), findsOneWidget);
    expect(find.text('سارة'), findsNothing);

    // دخولٌ بحساب تاجر (نسخة العرض تجيب بقسم التاجر): يُفتح قسمه لا قسم الكول سنتر
    await tester.enterText(find.byType(TextField).at(0), 'ahmad');
    await tester.enterText(find.byType(TextField).at(1), 'secret');
    await tester.tap(find.text('دخول').last);
    await tester.pumpAndSettle();
    expect(find.text('التجّار والمناديب والكول سنتر'), findsNothing);
    expect(find.text('أحمد'), findsOneWidget);
    // رئيسية التاجر بتصميم «وصل» (docs/plan/123)
    expect(find.text('شحناتك الآن'), findsOneWidget);
  });

  testWidgets('رسالة واتساب باسم الموظّفة ولو وصلت جلستها بعد فتح القسم بالرمز المحفوظ', (tester) async {
    Widget shell(cc.Session? session) => MaterialApp(
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: cc.Shell(brand: cc.Brand.hex('#B3202A'), session: session, onLogout: () async {}),
    );
    tester.view.physicalSize = const Size(393 * 3, 849 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    // يُفتح القسم بالرمز المحفوظ قبل أن تصل الجلسة من النظام
    await tester.pumpWidget(shell(null));
    await tester.pumpAndSettle();
    expect(WhatsAppTemplate.agent, '');

    await tester.pumpWidget(shell(cc.Session(name: 'زينب', companyName: 'الزاجل')));
    await tester.pumpAndSettle();
    expect(WhatsAppTemplate.agent, 'زينب');
    expect(WhatsAppTemplate.company, 'الزاجل');
  });

  test('خروج المندوب ينسى تنبيه الموعد الذي فُتح منه التطبيق', () async {
    courier.Reminders.opened.value = 42;
    await courier.Reminders.clear();
    expect(courier.Reminders.opened.value, isNull);
  });

  testWidgets('قسم المندوب: رئيسيّته، والخروج منه يعود إلى الدخول الواحد', (tester) async {
    await _pump(tester, const CompanyApp(signedIn: true, section: Section.courier));
    expect(find.text('مندوب تجريبي'), findsOneWidget);
    expect(find.text('بيدك الآن'), findsOneWidget);
    expect(find.text('سارة'), findsNothing);

    await tester.tap(find.text('المزيد').last);
    await tester.pumpAndSettle();
    // «المزيد» قائمةٌ كسولة: تُسحب حتى يُبنى زرّ الخروج في آخرها
    await tester.dragUntilVisible(
      find.text('تسجيل الخروج من هذا الجهاز'),
      find.byType(Scrollable).first,
      const Offset(0, -300),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('تسجيل الخروج من هذا الجهاز'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('خروج').last);
    await tester.pumpAndSettle();

    expect(Account.signedIn, isFalse);
    expect(find.text('التجّار والمناديب والكول سنتر'), findsOneWidget);
    expect(find.text('بيدك الآن'), findsNothing);
  });
}
