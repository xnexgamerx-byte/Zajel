// يُشغَّل ببيانات العرض: flutter test --dart-define-from-file=config/demo.json
import 'dart:io';

import 'package:callcenter_app/core/config.dart';
import 'package:callcenter_app/core/demo.dart';
import 'package:callcenter_app/core/palette.dart';
import 'package:callcenter_app/main.dart';
import 'package:callcenter_app/screens/login_screen.dart';
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
  expect(AppConfig.demo, isTrue, reason: 'شغّل الاختبار بـ config/demo.json');
  tester.view.physicalSize = const Size(393 * 3, 849 * 3);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const CallCenterApp(signedIn: true));
  await tester.pumpAndSettle();
}

/// يمرّر حتى يظهر الهدف ثم يضغطه
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

void main() {
  setUpAll(_loadCairo);
  setUp(Demo.reset);

  testWidgets('الدخول باسم الشركة والكول سنتر', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        supportedLocales: const [Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: LoginScreen(brand: Brand.hex('#B3202A'), onSignedIn: (_) {}),
      ),
    );
    expect(find.text('الزاجل'), findsOneWidget);
    expect(find.text('الكول سنتر'), findsOneWidget);
    await tester.tap(find.text('دخول').last);
    await tester.pump();
    expect(find.text('اكتب اسم المستخدم وكلمة المرور.'), findsOneWidget);
  });

  testWidgets('الرئيسية: «الآن» أوّلاً، ثم الطوابير بألوانها وما أنجزته اليوم', (tester) async {
    expect(AppConfig.appName, 'كول سنتر الزاجل');
    await _start(tester);
    expect(find.text('سارة'), findsOneWidget);
    expect(find.text('مندوبٌ ينتظرك عند الباب'), findsOneWidget);
    for (final text in [
      'تذاكر المبلغ',
      'للمعالجة',
      'محادثات التجّار',
      'رسائل المناديب',
      'حالة الشحنات',
      'قيد التوصيل',
      'أنجزتِ اليوم',
      'تذاكر المبلغ حسمتِها',
    ]) {
      await tester.ensureVisible(find.text(text).first);
      expect(find.text(text), findsWidgets, reason: text);
    }

    // «ابدئي» يفتح التذاكر — الأقدم أوّلاً
    await _tap(tester, find.text('ابدئي'));
    expect(find.text('المندوب عند الباب — الأقدم أوّلاً'), findsOneWidget);
    expect(find.text(tag('TK000214')), findsOneWidget);
  });

  testWidgets('التذكرة: «أعالجها» ثم الاعتماد — وتذكرة زميلتها مقفولةٌ باسمها', (tester) async {
    await _start(tester);
    await _tab(tester, 'التذاكر');

    // تذكرة زينب: تُقرأ ولا تُعالَج
    expect(find.text('تعالجها زينب'), findsOneWidget);
    await _tap(tester, find.text(tag('TK000215')));
    expect(find.text('أعالجها'), findsNothing);
    expect(find.textContaining('لا تُعالَج مرّتين'), findsOneWidget);
    await _tap(tester, find.byTooltip('رجوع'));

    await _tap(tester, find.text(tag('TK000214')));
    expect(find.text('الزبون يقول اتّفق مع التاجر على 45 ألف'), findsOneWidget);
    expect(find.text('اعتماد'), findsNothing);
    await _tap(tester, find.text('أعالجها'));
    expect(find.text('اعتماد'), findsOneWidget);
    await _tap(tester, find.text('اعتماد'));
    expect(find.text('اعتُمد الطلب TK000214 بـ45,000 د.ع — يراه المندوب الآن في صفحة الشحنة.'), findsOneWidget);
    expect(find.text(tag('TK000214')), findsNothing);
  });

  testWidgets('المعالجة: «أعالجها»، رسالةٌ للتاجر، ثم القرار — وشحنة زميلتها مقفولة', (tester) async {
    await _start(tester);
    await _tab(tester, 'المعالجة');
    expect(find.text('كتب عنها التاجر أو المندوب — ينتظر ردّك'), findsOneWidget);
    expect(find.text('تعالجها نور'), findsOneWidget);

    await _tap(tester, find.text('منى جاسم'));
    expect(find.text('الزبونة اتصلت بينا، تريد التوصيل بعد العصر'), findsOneWidget);
    await _tap(tester, find.text('أعالجها'));
    await tester.enterText(find.byType(TextField).first, 'تمّ، نعيدها اليوم بعد العصر');
    await _tap(tester, find.byTooltip('أرسل'));
    expect(find.text('تمّ، نعيدها اليوم بعد العصر'), findsOneWidget);

    await _tap(tester, find.text('إعادة توصيل'));
    await _tap(tester, find.text('نعم، أعيديها'));
    expect(find.text('عولجت 180231: إعادة توصيل.'), findsOneWidget);
    expect(find.text('منى جاسم'), findsNothing);

    // شحنة نور
    await _tap(tester, find.text('كرار عادل'));
    expect(find.text('تعالجها نور'), findsOneWidget);
    expect(find.text('أعالجها'), findsNothing);
  });

  testWidgets('المحادثات: تُفتح فتصير لها وتردّ — ومحادثة زميلتها تُقرأ ولا يُردّ عليها', (tester) async {
    await _start(tester);
    await _tab(tester, 'المحادثات');
    await _tap(tester, find.text('متجر الوردة'));
    // صورة التاجر في المحادثة (بلا رابطٍ في نسخة العرض: اسمها وحجمها)
    expect(find.text('waybill.jpg · 184 ك.ب'), findsOneWidget);
    await tester.enterText(find.byType(TextField), 'الشحنة خرجت مع المندوب اليوم');
    await _tap(tester, find.byTooltip('أرسل'));
    expect(find.text('الشحنة خرجت مع المندوب اليوم'), findsOneWidget);
    await _tap(tester, find.byTooltip('رجوع'));

    await _tap(tester, find.text('بيت العطور'));
    expect(find.textContaining('لا تُعالَج مرّتين'), findsOneWidget);
    expect(find.byType(TextField), findsNothing);
  });

  testWidgets('ابحث: رقم الوصل يفتح الشحنة، وإن كانت في المعالجة تُفتح منها', (tester) async {
    await _start(tester);
    await _tap(tester, find.byTooltip('ابحث عن شحنة'));
    await tester.enterText(find.byType(TextField), '180231');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(find.text('الوصل كاملاً'), findsOneWidget);
    expect(find.text('في المعالجة — افتحيها'), findsOneWidget);
  });

  testWidgets('حالة الشحنات: كلّ خانةٍ قائمتها، والراجع المؤكد يعود للتوصيل بسببه', (tester) async {
    await _start(tester);

    // المؤجل بموعده
    await _tap(tester, find.text('مؤجل').first);
    expect(find.text('اتُّفق مع الزبون على موعد — ما فات موعده أو اليوم أوّلاً'), findsOneWidget);
    expect(find.text('زهراء كريم'), findsOneWidget);
    expect(find.textContaining('موعدها'), findsWidgets);
    expect(find.text('علي حسين'), findsNothing);
    await _tap(tester, find.byTooltip('رجوع'));

    // الراجع المؤكد: ما زال بيد المندوب، فيعود للتوصيل بسببٍ تكتبه
    await _tap(tester, find.text('راجع مؤكد').first);
    expect(find.textContaining('بيد المندوب أحمد كريم'), findsOneWidget);
    expect(find.textContaining('استُلم في المخزن'), findsOneWidget);
    await _tap(tester, find.text('أحمد ناصر'));
    await _tap(tester, find.text('أعيديها للتوصيل'));
    await _tap(tester, find.text('أعيديها'));
    expect(find.text('اكتبي لماذا تعود للتوصيل: من اتّفق مع الزبون وعلى ماذا.'), findsOneWidget);
    await tester.enterText(find.byType(TextField).last, 'اتّصلت بالزبون واقتنع بالاستلام اليوم');
    await _tap(tester, find.text('أعيديها'));
    expect(find.text('عادت 180331 للتوصيل مع مندوبها — «إعادة توصيل».'), findsOneWidget);
  });

  testWidgets('أنجزتِ اليوم: كلّ قرارٍ يُفتح بتفاصيله، وتذاكر المبلغ بما حُسم', (tester) async {
    await _start(tester);

    await _tap(tester, find.text('إعادة توصيل').last);
    expect(find.text('ما عالجتِه اليوم'), findsOneWidget);
    expect(find.text('يستلم بعد العصر'), findsOneWidget);
    expect(find.text('رفض نهائياً'), findsNothing);
    await _tap(tester, find.text('الكل 7'));
    expect(find.text('رفض نهائياً'), findsOneWidget);
    await _tap(tester, find.byTooltip('رجوع'));

    await _tap(tester, find.text('تذاكر المبلغ حسمتِها'));
    expect(find.text('50,000 ← 45,000 د.ع'), findsOneWidget);
    expect(find.text('التاجر لا يقبل البيع الجزئي'), findsOneWidget);
  });

  testWidgets('الشحنة: الرقم والوصل يُنسخان، والهاتف ظاهر، والتواصل بالخط أو واتساب، والتحديث بقواعده', (tester) async {
    await _start(tester);
    await _tap(tester, find.byTooltip('ابحث عن شحنة'));
    await tester.enterText(find.byType(TextField), '180301');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(find.text('سجلّها'), findsOneWidget);

    // تعديل البيانات في السجلّ باسمه، لا بحالةٍ مكرّرة
    expect(find.text('تعديل بيانات'), findsOneWidget);

    expect(find.text(tag('180301')), findsNWidgets(2));
    // الحافظة في الاختبار: ما نُسخ يُقرأ
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') copied = (call.arguments as Map)['text'] as String?;
      return null;
    });
    await _tap(tester, find.byIcon(Icons.copy_rounded).first);
    expect(copied, '180301');
    expect(find.text('نُسخ رقم الوصل 180301'), findsOneWidget);
    await _tap(tester, find.text('الوصل كاملاً'));
    expect(find.text('نُسخ الوصل كاملاً'), findsOneWidget);
    expect(copied, contains('الهاتف: 07801234567'));
    expect(copied, contains('المبلغ: 35,000 د.ع'));

    expect(find.text('07801234567'), findsOneWidget);
    await _tap(tester, find.byTooltip('تواصلي مع الزبون'));
    expect(find.text('اتصال بالخط'), findsOneWidget);
    expect(find.text('واتساب برسالة الشحنة'), findsOneWidget);
    expect(find.textContaining('الوصل 180301'), findsWidgets);
    await tester.tapAt(const Offset(20, 20));
    await tester.pumpAndSettle();

    await _tap(tester, find.text('حدّثي الحالة'));
    await _tap(tester, find.text('لم يُسلَّم').last);
    await _tap(tester, find.text('تغيير عنوان · يحتاج توضيحاً'));
    await _tap(tester, find.text('حدّثي'));
    expect(find.textContaining('يحتاج توضيحاً — يظهر مع السبب'), findsOneWidget);
    await tester.enterText(find.byType(TextField).last, 'العنوان الجديد: الكرادة قرب الجسر');
    await _tap(tester, find.text('حدّثي'));
    expect(find.text('تم تحديث الشحنة 180301.'), findsOneWidget);
  });

  testWidgets('المعالجة: توضيح المندوب مع السبب، ورسالة واتساب من القالب', (tester) async {
    await _start(tester);
    await _tab(tester, 'المعالجة');
    await _tap(tester, find.text('منى جاسم'));
    expect(find.textContaining('توضيح المندوب: اتّصلت مرّتين'), findsOneWidget);
    expect(find.text('07801234567'), findsOneWidget);
    await _tap(tester, find.byTooltip('تواصلي مع الزبون'));
    expect(find.textContaining('حاول المندوب توصيله ولم يتمّ (لا يرد على الهاتف)'), findsOneWidget);
  });

  testWidgets('المعالجة: «الحرّة» تفرز ما لم تفتحه أحد، ولمس رقم الوصل يفتح تتبّعها، والردود الجاهزة', (tester) async {
    await _start(tester);
    await _tab(tester, 'المعالجة');
    expect(find.text('كرار عادل'), findsOneWidget);
    await _tap(tester, find.text('الحرّة'));
    expect(find.text('كرار عادل'), findsNothing, reason: 'تعالجها نور');
    expect(find.text('منى جاسم'), findsOneWidget);
    await _tap(tester, find.textContaining('الحرّة'));

    await _tap(tester, find.text('منى جاسم'));
    await _tap(tester, find.text('التتبّع'));
    expect(find.text('في المعالجة — افتحيها'), findsOneWidget);
    await _tap(tester, find.byTooltip('رجوع').last);

    // ردٌّ جاهز يُكتب في الحقل ثم يُرسل
    await _tap(tester, find.text('أعالجها'));
    await _tap(tester, find.text('تم التبليغ').first);
    await _tap(tester, find.byTooltip('أرسل'));
    expect(find.text('تم التبليغ'), findsWidgets);
  });

  testWidgets('رسائل المناديب تُقرأ ويُردّ عليها في التطبيق', (tester) async {
    await _start(tester);
    await _tap(tester, find.text('رسائل المناديب'));
    expect(find.text('أحمد كريم'), findsOneWidget);
    expect(find.textContaining('ينتظر ردّك منذ'), findsOneWidget);
    await _tap(tester, find.text('أحمد كريم'));
    expect(find.text('الزبون ما يرد، شنو أسوي؟\nالوصل ${tag('180301')}'), findsOneWidget);
    await tester.enterText(find.byType(TextField), 'أرسل له واتساب');
    await _tap(tester, find.byTooltip('أرسل'));
    expect(find.text('أرسل له واتساب'), findsOneWidget);

    // رقم الشحنة في المحادثة يفتح معلوماتها
    await _tap(tester, find.text(tag('180301')).first);
    expect(find.text('الوصل كاملاً'), findsOneWidget);
  });

  testWidgets('الشحنة والتذكرة: محادثتا التاجر والمندوب، ورقم الوصل في التذكرة', (tester) async {
    await _start(tester);
    await _tap(tester, find.byTooltip('ابحث عن شحنة'));
    await tester.enterText(find.byType(TextField), '180301');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(find.text('المحادثات'), findsOneWidget);
    await tester.enterText(find.byType(TextField).last, 'الزبون يسأل متى توصل');
    await _tap(tester, find.byTooltip('أرسل'));
    expect(find.text('الزبون يسأل متى توصل'), findsOneWidget);
    await _tap(tester, find.byTooltip('رجوع').last);
    await _tap(tester, find.byTooltip('رجوع').last);

    await _tab(tester, 'التذاكر');
    await _tap(tester, find.text(tag('TK000214')));
    expect(find.text(tag('180240')), findsOneWidget);
    expect(find.text('التتبّع'), findsOneWidget);
    expect(find.text('المحادثات'), findsOneWidget);
  });
}
