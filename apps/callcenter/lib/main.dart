import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/api.dart';
import 'core/brand_cache.dart';
import 'core/config.dart';
import 'core/models.dart';
import 'core/palette.dart';
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
  runApp(CallCenterApp(signedIn: signedIn));
}

/// «كول سنتر الزاجل» (docs/plan/73): مهامّ المتابعة في الجيب — بتصميم ز، عربيّ من اليمين، بخطّ «القاهرة» ولون الشركة.
class CallCenterApp extends StatefulWidget {
  const CallCenterApp({super.key, required this.signedIn});

  final bool signedIn;

  @override
  State<CallCenterApp> createState() => _CallCenterAppState();
}

class _CallCenterAppState extends State<CallCenterApp> {
  late Brand brand = Brand.hex(AppConfig.brandColor);
  Session? session;
  late bool signedIn = widget.signedIn || AppConfig.demo;

  @override
  void initState() {
    super.initState();
    if (signedIn) _loadSession();
  }

  Future<void> _loadSession() async {
    try {
      _adopt(await Api.instance.me());
    } on ApiError catch (e) {
      if (e.unauthorised) setState(() => signedIn = false);
    }
  }

  void _adopt(Session s) => setState(() {
    session = s;
    signedIn = true;
    if (s.companyColor != null && !AppConfig.brandFixed) {
      brand = Brand.hex(s.companyColor, fallback: AppConfig.brandColor);
    }
  });

  Future<void> _logout() async {
    await Api.instance.logout();
    setState(() {
      session = null;
      signedIn = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: ThemeData(
        useMaterial3: true,
        fontFamily: 'Cairo',
        scaffoldBackgroundColor: Palette.bg,
        colorScheme: ColorScheme.fromSeed(seedColor: brand.main, primary: brand.main, surface: Colors.white),
      ),
      builder: (context, child) => Directionality(textDirection: TextDirection.rtl, child: child!),
      home: signedIn
          ? Shell(brand: brand, session: session, onLogout: _logout)
          : LoginScreen(brand: brand, onSignedIn: _adopt),
    );
  }
}
