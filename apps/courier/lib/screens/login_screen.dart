import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/brand_cache.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';

/// دخول المندوب بحسابه نفسه في موقع الشركة — بلون الشركة واسمها (docs/plan/66).
///
/// تصميم «الموجة» (docs/plan/88): نصفٌ علويّ بلون الشركة تنساب في أسفله موجتان وتلمع فيه نجومٌ خفيفة، وشعار
/// الشركة في إطارٍ أبيض مربّع تنبض حوله حلقتان، واسمها واسم التطبيق تحته، ثم نموذج الدخول بطاقةً بيضاء تصعد.
/// الشعار والاسم من نظام الشركة (GET /company)، وقبل أن يصلا اسمها من الإعداد وحرفها — لا شيء منها في الكود.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.brand, required this.onSignedIn});

  final Brand brand;
  final ValueChanged<Session> onSignedIn;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> with TickerProviderStateMixin {
  /// ما يميّز هذا التطبيق في الشاشة نفسها
  static const _app = 'تطبيق المندوب';
  static const _welcome = 'ادخل لتبدأ مهامك اليوم';
  static const _userHint = 'اسم المستخدم';
  static const _help = ['الدخول بالحساب نفسه في موقع الشركة', 'نسيت كلمة المرور؟ راجع المكتب'];

  final username = TextEditingController();
  final password = TextEditingController();
  bool busy = false;
  bool shown = false;
  String? error;

  /// ما حُفظ في الهاتف أوّلاً — الشعار من أوّل لحظة — ثم ما يصل من النظام
  CompanyBrand? company = BrandCache.brand;

  /// الموج والنجوم ونبض الإطار ولمعة الزرّ: دورةٌ واحدة من اثنتي عشرة ثانية
  late final AnimationController loop = AnimationController(vsync: this, duration: const Duration(seconds: 12));

  /// دخول الشاشة: الشعار ثم الاسم ثم البطاقة
  late final AnimationController enter = AnimationController(vsync: this, duration: const Duration(milliseconds: 1100));

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    enter.forward();
    // تحت flutter test لا تتكرّر حركة، فينتهي الاختبار
    if (!AppConfig.testing) loop.repeat();
    Api.instance.company().then((c) async {
      if (c == null) return;
      await BrandCache.save(c);
      if (mounted) setState(() => company = c);
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // «تقليل الحركة» في الهاتف: تقف الحركة المتكرّرة
    if (MediaQuery.disableAnimationsOf(context)) loop.stop();
  }

  Future<void> _submit() async {
    if (busy) return;
    if (username.text.trim().isEmpty || password.text.isEmpty) {
      setState(() => error = 'اكتب اسم المستخدم وكلمة المرور.');
      return;
    }
    setState(() => (busy = true, error = null));
    try {
      widget.onSignedIn(await Api.instance.login(username.text.trim(), password.text));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  void dispose() {
    loop.dispose();
    enter.dispose();
    username.dispose();
    password.dispose();
    super.dispose();
  }

  String get _name => company?.name.isNotEmpty == true ? company!.name : AppConfig.companyName;

  String get _initial {
    final i = company?.initial ?? '';
    if (i.isNotEmpty) return i;
    final bare = _name.replaceFirst(RegExp(r'^ال(?=\S)'), '');
    return bare.isEmpty ? '؟' : bare.characters.first;
  }

  /// جزءٌ من دخول الشاشة بين بدايته ونهايته (٠–١)
  double _part(double from, double to) =>
      Curves.easeOutCubic.transform(((enter.value - from) / (to - from)).clamp(0, 1));

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.sizeOf(context);
    final top = MediaQuery.paddingOf(context).top;
    final hero = math.max(370.0, size.height * .47);
    return Scaffold(
      backgroundColor: Palette.bg,
      body: AnimatedBuilder(
        animation: Listenable.merge([loop, enter]),
        builder: (context, _) {
          final t = loop.value;
          return SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  height: hero,
                  child: CustomPaint(
                    painter: _HeroPainter(t, brand.main),
                    child: Padding(
                      padding: EdgeInsets.only(top: top + 34),
                      child: Column(
                        children: [
                          // الشعار ظاهرٌ من أوّل لحظة، يكبر قليلاً وحسب
                          Transform.scale(scale: .94 + .06 * _part(0, .5), child: _logo(t)),
                          const SizedBox(height: 16),
                          Opacity(
                            opacity: _part(.2, .75),
                            child: Transform.translate(
                              offset: Offset(0, 12 * (1 - _part(.2, .75))),
                              child: Column(
                                children: [
                                  Text(_name, textAlign: TextAlign.center, style: display(27, w8, Colors.white)),
                                  Text(
                                    _app,
                                    textAlign: TextAlign.center,
                                    style: font(14.5, w7, Colors.white.withValues(alpha: .9)),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                Transform.translate(
                  offset: Offset(0, -70 + 60 * (1 - _part(.3, 1))),
                  child: Opacity(opacity: _part(.3, 1), child: _form(t)),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  /// الشعار في إطارٍ أبيض مربّع، وحلقتان مربّعتان تنبضان حوله وتتلاشيان
  Widget _logo(double t) {
    const side = 128.0;
    Widget ring(double phase) {
      final p = (t * 4 + phase) % 1;
      return Transform.scale(
        scale: 1 + .32 * p,
        child: Container(
          width: side,
          height: side,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: Colors.white.withValues(alpha: .6 * (1 - p)), width: 2),
          ),
        ),
      );
    }

    final logo = company?.logo;
    final saved = logo == null ? null : BrandCache.logo;
    final letter = Center(child: Text(_initial, style: display(58, w8, brand.main, height: 1)));
    return SizedBox(
      width: side * 1.4,
      height: side * 1.4,
      child: Stack(
        alignment: Alignment.center,
        children: [
          if (loop.isAnimating) ...[ring(0), ring(.5)],
          Container(
            width: side,
            height: side,
            padding: const EdgeInsets.all(5),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(22),
              boxShadow: [
                BoxShadow(color: Colors.black.withValues(alpha: .22), blurRadius: 26, offset: const Offset(0, 12)),
              ],
            ),
            child: logo == null
                ? letter
                : saved != null
                ? Image.memory(saved, fit: BoxFit.contain, gaplessPlayback: true, errorBuilder: (_, _, _) => letter)
                : Image.network(
                    logo,
                    fit: BoxFit.contain,
                    errorBuilder: (_, _, _) => letter,
                    frameBuilder: (_, child, frame, sync) => AnimatedOpacity(
                      opacity: sync || frame != null ? 1 : 0,
                      duration: const Duration(milliseconds: 400),
                      child: child,
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  InputDecoration _field(String hint, IconData icon, {Widget? suffix}) => InputDecoration(
    hintText: hint,
    hintStyle: font(13, w5, Palette.muted),
    prefixIcon: Icon(icon, color: Palette.muted, size: 20),
    suffixIcon: suffix,
    filled: true,
    fillColor: Palette.bg,
    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 15),
    enabledBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(16),
      borderSide: const BorderSide(color: Palette.line, width: 1.4),
    ),
    focusedBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(16),
      borderSide: BorderSide(color: brand.main, width: 1.6),
    ),
  );

  Widget _form(double t) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 20),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          padding: const EdgeInsets.fromLTRB(20, 22, 20, 18),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(28),
            boxShadow: [
              BoxShadow(color: brand.main.withValues(alpha: .14), blurRadius: 40, offset: const Offset(0, 18)),
            ],
          ),
          child: AutofillGroup(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('أهلاً بعودتك', style: display(21, w8, Palette.ink)),
                const SizedBox(height: 2),
                Text(_welcome, style: font(13, w5, Palette.slate, height: 1.5)),
                const SizedBox(height: 16),
                TextField(
                  controller: username,
                  textInputAction: TextInputAction.next,
                  autofillHints: const [AutofillHints.username],
                  style: font(14.5, w6, Palette.ink),
                  decoration: _field(_userHint, Icons.person_outline_rounded),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: password,
                  obscureText: !shown,
                  onSubmitted: (_) => _submit(),
                  autofillHints: const [AutofillHints.password],
                  style: font(14.5, w6, Palette.ink),
                  decoration: _field(
                    'كلمة المرور',
                    Icons.lock_outline_rounded,
                    suffix: IconButton(
                      tooltip: shown ? 'أخفِ كلمة المرور' : 'أظهِر كلمة المرور',
                      onPressed: () => setState(() => shown = !shown),
                      icon: Icon(
                        shown ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                        color: Palette.muted,
                        size: 20,
                      ),
                    ),
                  ),
                ),
                if (error != null) ...[
                  const SizedBox(height: 12),
                  Text(error!, style: font(12.5, w6, brand.main, height: 1.5)),
                ],
                const SizedBox(height: 18),
                _button(t),
              ],
            ),
          ),
        ),
        const SizedBox(height: 18),
        for (final (i, line) in _help.indexed)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Text(
              line,
              textAlign: TextAlign.center,
              style: font(12, i == _help.length - 1 && _help.length > 1 ? w7 : w5, Palette.slate, height: 1.5),
            ),
          ),
      ],
    ),
  );

  /// زرّ الدخول بلون الشركة، تمرّ عليه لمعةٌ كلّ ثلاث ثوانٍ
  Widget _button(double t) {
    final p = (t * 4) % 1;
    return Material(
      color: brand.main,
      borderRadius: BorderRadius.circular(18),
      elevation: 0,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: busy ? null : _submit,
        child: SizedBox(
          height: 54,
          child: LayoutBuilder(
            builder: (context, box) => Stack(
              alignment: Alignment.center,
              children: [
                if (loop.isAnimating && p < .55)
                  Positioned(
                    top: 0,
                    bottom: 0,
                    left: -80 + (box.maxWidth + 160) * (p / .55),
                    width: 60,
                    child: Transform(
                      transform: Matrix4.skewX(-.35),
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            colors: [
                              Colors.white.withValues(alpha: 0),
                              Colors.white.withValues(alpha: .32),
                              Colors.white.withValues(alpha: 0),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                busy
                    ? const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                      )
                    : Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text('دخول', style: font(16.5, w8, Colors.white)),
                          const SizedBox(width: 8),
                          const Icon(Icons.arrow_forward_rounded, color: Colors.white, size: 20),
                        ],
                      ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// الرأس: لون الشركة، ودائرتان باهتتان، ونجومٌ تلمع واحدةً بعد أخرى، وموجتان تنسابان في أسفله — الأمامية بلون
/// خلفية الشاشة فتذوب فيها
class _HeroPainter extends CustomPainter {
  const _HeroPainter(this.t, this.color);

  final double t;
  final Color color;

  /// النجوم: موضعها نسبةً من الرأس، وحجمها، وموعد لمعتها
  static const _stars = [
    (.12, .16, 1.6, .0),
    (.30, .08, 1.1, .35),
    (.62, .12, 1.4, .7),
    (.86, .22, 1.8, .2),
    (.08, .42, 1.2, .55),
    (.92, .46, 1.3, .85),
    (.22, .62, 1.5, .1),
    (.78, .66, 1.1, .45),
    (.48, .04, 1.2, .62),
  ];

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width;
    final h = size.height;
    canvas.drawRect(Offset.zero & size, Paint()..color = color);
    canvas.drawCircle(Offset(w * .08, h * .02), w * .34, Paint()..color = Colors.white.withValues(alpha: .07));
    canvas.drawCircle(Offset(w * 1.02, h * .3), w * .28, Paint()..color = Colors.black.withValues(alpha: .07));

    for (final (x, y, r, phase) in _stars) {
      final glow = .5 + .5 * math.sin(2 * math.pi * (t * 3 + phase));
      canvas.drawCircle(
        Offset(w * x, h * y),
        r + .8 * glow,
        Paint()..color = Colors.white.withValues(alpha: .12 + .6 * glow),
      );
    }

    void wave(double base, double amp, double shift, Color fill) {
      // أسفل الرأس بقليل: لا يبقى تحت الموجة الأمامية خيطٌ من التي خلفها
      final path = Path()..moveTo(0, h + 4);
      for (var x = 0.0; x <= w; x += 4) {
        path.lineTo(x, base + amp * math.sin(2 * math.pi * (x / w * 1.2 + shift)));
      }
      path
        ..lineTo(w, h + 4)
        ..close();
      canvas.drawPath(path, Paint()..color = fill);
    }

    wave(h - 74, 12, -t, Colors.black.withValues(alpha: .12));
    wave(h - 48, 14, t * 2, Palette.bg);
  }

  @override
  bool shouldRepaint(covariant _HeroPainter old) => old.t != t || old.color != color;
}
