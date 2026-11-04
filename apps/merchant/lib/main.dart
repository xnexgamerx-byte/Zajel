import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'callcenter/core/demo.dart' as cc;
import 'callcenter/core/models.dart' as cc;
import 'callcenter/core/palette.dart' as cc;
import 'callcenter/screens/shell.dart' as cc;
import 'core/access.dart';
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

/// تطبيق الشركة (docs/plan/48، 114): تطبيقٌ واحدٌ بعلامتها لتجّارها وموظّفي الكول سنتر — عربيّ من اليمين، بخطّ
/// «القاهرة» ولونها. دخولٌ واحد، والنظام يقول قسم الحساب: التاجر يُفتح له قسم التاجر، وموظّف الكول سنتر قسمه.
/// وكلّ قسمٍ كما كان تطبيقه: شاشاته وتصميمه وما يفتحه.
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

  late Section? section =
      widget.section ??
      Account.section ??
      (AppConfig.demo ? Section.of(AppConfig.demoSection) ?? Section.merchant : null);
  late bool signedIn = (widget.signedIn || AppConfig.demo) && section != null;

  /// جلسة القسم المفتوح: واحدةٌ منهما، والأخرى null
  Session? merchantSession;
  cc.Session? callSession;

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

  /// القسم وجلسته ولون الشركة منها — جلسة القسم المفتوح وحدها، والأخرى null
  void _apply(SignIn s) {
    final body = s.body;
    final String? color;
    switch (s.section) {
      case Section.merchant:
        final m = body == null ? Demo.session : Session.fromJson(body);
        // ما يفتحه الحساب (docs/plan/87): صاحب المتجر كلّه، وموظّفه ما فتحه له
        Access.set(m.permissions);
        merchantSession = m;
        callSession = null;
        color = m.companyColor;
      case Section.callcenter:
        final c = body == null ? cc.Demo.session : cc.Session.fromJson(body);
        Access.set(null);
        merchantSession = null;
        callSession = c;
        color = c.companyColor;
    }
    section = s.section;
    signedIn = true;
    if (color != null && !AppConfig.brandFixed) {
      brand = Brand.hex(color, fallback: AppConfig.brandColor);
      callBrand = cc.Brand.hex(color, fallback: AppConfig.brandColor);
    }
  }

  Future<void> _logout() async {
    await Push.unregister();
    await Api.instance.logout();
    Access.set(null);
    setState(() {
      merchantSession = null;
      callSession = null;
      section = null;
      signedIn = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final call = signedIn && section == Section.callcenter;
    return MaterialApp(
      scaffoldMessengerKey: messenger,
      title: (call ? callSession?.companyName : merchantSession?.companyName) ?? AppConfig.companyName,
      debugShowCheckedModeBanner: false,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: ThemeData(
        useMaterial3: true,
        fontFamily: 'Cairo',
        scaffoldBackgroundColor: call ? cc.Palette.bg : Palette.bg,
        colorScheme: ColorScheme.fromSeed(seedColor: brand.main, primary: brand.main, surface: Colors.white),
      ),
      // قسم التاجر وشاشة الدخول بقياس تصميمهما وإن كبّر الهاتف خطّه (docs/plan/77، 78)؛ وقسم الكول سنتر يكبر مع
      // خطّ الهاتف كما كان تطبيقه
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: call ? child! : MediaQuery.withClampedTextScaling(maxScaleFactor: 1.0, child: child!),
      ),
      home: !signedIn
          ? LoginScreen(brand: brand, onSignedIn: _adopt)
          : call
          ? cc.Shell(brand: callBrand, session: callSession, onLogout: _logout)
          : Shell(brand: brand, session: merchantSession, onLogout: _logout),
    );
  }
}
