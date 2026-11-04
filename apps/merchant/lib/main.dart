import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'callcenter/core/demo.dart' as cc;
import 'callcenter/core/models.dart' as cc;
import 'callcenter/core/palette.dart' as cc;
import 'callcenter/screens/shell.dart' as cc;
import 'core/access.dart';
import 'courier/core/api.dart' as courier;
import 'courier/core/demo.dart' as courier;
import 'courier/core/models.dart' as courier;
import 'courier/core/palette.dart' as courier;
import 'courier/core/reminders.dart' as courier;
import 'courier/screens/shell.dart' as courier;
import 'core/account.dart';
import 'core/api.dart';
import 'core/brand_cache.dart';
import 'core/config.dart';
import 'core/demo.dart';
import 'core/models.dart';
import 'core/palette.dart';
import 'core/push.dart';
import 'screens/login_screen.dart';
import 'screens/shell.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.dark,
      statusBarBrightness: Brightness.light,
    ),
  );
  final signedIn = await Api.instance.restore();
  // شعار الشركة من الهاتف لشاشة الدخول، ويُحدَّث من النظام في الخلفية (docs/plan/88)
  await BrandCache.load();
  Api.instance.company().then((c) {
    if (c != null) BrandCache.save(c);
  });
  runApp(CompanyApp(signedIn: signedIn));
}

/// تطبيق الشركة (docs/plan/48، 114، 115): تطبيقٌ واحدٌ بعلامتها لتجّارها ومناديبها وموظّفي الكول سنتر — عربيّ من
/// اليمين، بخطّ «القاهرة» ولونها. دخولٌ واحد، والنظام يقول قسم الحساب: التاجر يُفتح له قسم التاجر، والمندوب قسمه،
/// وموظّف الكول سنتر قسمه. وكلّ قسمٍ كما كان تطبيقه: شاشاته وتصميمه وما يفتحه.
class CompanyApp extends StatefulWidget {
  const CompanyApp({super.key, required this.signedIn, this.section});

  final bool signedIn;

  /// القسم الذي يُفتح — للاختبارات ونسخة العرض. وإلّا فالمحفوظ مع الرمز، ثم ما يقوله النظام
  final Section? section;

  @override
  State<CompanyApp> createState() => _CompanyAppState();
}

class _CompanyAppState extends State<CompanyApp> {
  late Brand brand = Brand.hex(AppConfig.brandColor);
  late cc.Brand callBrand = cc.Brand.hex(AppConfig.brandColor);
  late courier.Brand courierBrand = courier.Brand.hex(AppConfig.brandColor);

  late Section? section =
      widget.section ??
      Account.section ??
      (AppConfig.demo ? Section.of(AppConfig.demoSection) ?? Section.merchant : null);
  late bool signedIn = (widget.signedIn || AppConfig.demo) && section != null;

  /// جلسة القسم المفتوح وحدها، والأخريان null
  Session? merchantSession;
  cc.Session? callSession;
  courier.Session? courierSession;

  final messenger = GlobalKey<ScaffoldMessengerState>();

  @override
  void initState() {
    super.initState();
    // إشعارٌ وصل والتطبيق مفتوح: شريطٌ في أسفله، فلا يفوت (docs/plan/82، 90)
    Push.onForeground = (title, body) => messenger.currentState?.showSnackBar(
      SnackBar(behavior: SnackBarBehavior.floating, content: Text([title, body].where((t) => t.isNotEmpty).join('\n'))),
    );
    if (!signedIn) return;
    // نسخة العرض: القسم ببياناته التجريبية، بلا نظام
    if (AppConfig.demo) return _apply(SignIn(section!, null));
    _loadSession();
  }

  Future<void> _loadSession() async {
    try {
      _adopt(await Api.instance.me());
    } on ApiError catch (e) {
      if (e.unauthorised && mounted) setState(() => (signedIn = false, section = null));
    }
  }

  /// دخل الحساب (أو عاد بالرمز المحفوظ): يُفتح قسمه، ويُسجَّل الهاتف للإشعارات بعد الدخول ومع كلّ فتح (docs/plan/82)
  void _adopt(SignIn s) {
    if (!mounted) return;
    setState(() => _apply(s));
    Push.register();
  }

  /// القسم وجلسته ولون الشركة منها — جلسة القسم المفتوح وحدها، والأخريان null
  void _apply(SignIn s) {
    final body = s.body;
    final String? color;
    merchantSession = null;
    callSession = null;
    courierSession = null;
    switch (s.section) {
      case Section.merchant:
        final m = body == null ? Demo.session : Session.fromJson(body);
        // ما يفتحه الحساب (docs/plan/87): صاحب المتجر كلّه، وموظّفه ما فتحه له
        Access.set(m.permissions);
        merchantSession = m;
        color = m.companyColor;
      case Section.callcenter:
        final c = body == null ? cc.Demo.session : cc.Session.fromJson(body);
        Access.set(null);
        callSession = c;
        color = c.companyColor;
      case Section.courier:
        final c = body == null ? courier.Demo.session : courier.Session.fromJson(body);
        Access.set(null);
        courierSession = c;
        color = c.companyColor;
    }
    section = s.section;
    signedIn = true;
    if (color != null && !AppConfig.brandFixed) {
      brand = Brand.hex(color, fallback: AppConfig.brandColor);
      callBrand = cc.Brand.hex(color, fallback: AppConfig.brandColor);
      courierBrand = courier.Brand.hex(color, fallback: AppConfig.brandColor);
    }
  }

  Future<void> _logout() async {
    final was = section;
    await Push.unregister();
    await Api.instance.logout();
    Access.set(null);
    // المندوب: لا تبقى مواعيده تُنبَّه على الهاتف، ولا أسبابه في الذاكرة، لمن يدخل بعده (docs/plan/115)
    if (was == Section.courier) {
      await courier.Reminders.clear();
      courier.Api.instance.reset();
    }
    if (!mounted) return;
    setState(() {
      merchantSession = null;
      callSession = null;
      courierSession = null;
      section = null;
      signedIn = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final open = signedIn ? section : null;
    return MaterialApp(
      scaffoldMessengerKey: messenger,
      title:
          switch (open) {
            Section.merchant => merchantSession?.companyName,
            Section.callcenter => callSession?.companyName,
            Section.courier => courierSession?.companyName,
            null => null,
          } ??
          AppConfig.companyName,
      debugShowCheckedModeBanner: false,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: ThemeData(
        useMaterial3: true,
        fontFamily: 'Cairo',
        scaffoldBackgroundColor: switch (open) {
          Section.callcenter => cc.Palette.bg,
          Section.courier => courier.Palette.bg,
          _ => Palette.bg,
        },
        colorScheme: ColorScheme.fromSeed(seedColor: brand.main, primary: brand.main, surface: Colors.white),
      ),
      // قسم التاجر وشاشة الدخول بقياس تصميمهما وإن كبّر الهاتف خطّه (docs/plan/77، 78)؛ وقسما الكول سنتر والمندوب
      // يكبران مع خطّ الهاتف كما كان تطبيقاهما
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: open == Section.callcenter || open == Section.courier
            ? child!
            : MediaQuery.withClampedTextScaling(maxScaleFactor: 1.0, child: child!),
      ),
      home: switch (open) {
        null => LoginScreen(brand: brand, onSignedIn: _adopt),
        Section.merchant => Shell(brand: brand, session: merchantSession, onLogout: _logout),
        Section.callcenter => cc.Shell(brand: callBrand, session: callSession, onLogout: _logout),
        Section.courier => courier.Shell(brand: courierBrand, session: courierSession, onLogout: _logout),
      },
    );
  }
}
