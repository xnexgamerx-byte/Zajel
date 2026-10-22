import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';

/// دخول موظّفة الكول سنتر بحسابها نفسه في موقع الشركة — بلون الشركة واسمها (docs/plan/73).
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.brand, required this.onSignedIn});

  final Brand brand;
  final ValueChanged<Session> onSignedIn;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final username = TextEditingController();
  final password = TextEditingController();
  bool busy = false;
  bool shown = false;
  String? error;

  Brand get brand => widget.brand;

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
    username.dispose();
    password.dispose();
    super.dispose();
  }

  InputDecoration _field(String hint, IconData icon, {Widget? suffix}) => InputDecoration(
    hintText: hint,
    hintStyle: font(13, w5, Palette.slate),
    prefixIcon: Icon(icon, color: Palette.muted, size: 20),
    suffixIcon: suffix,
    filled: true,
    fillColor: Colors.white,
    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
    enabledBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(14),
      borderSide: const BorderSide(color: Palette.line),
    ),
    focusedBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(14),
      borderSide: BorderSide(color: brand.main, width: 1.4),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return Scaffold(
      body: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // رأسٌ بلون الشركة: سمّاعة الكول سنتر وما يُعمل هنا
            Container(
              padding: EdgeInsets.fromLTRB(24, top + 40, 24, 70),
              decoration: BoxDecoration(
                gradient: brand.hero,
                borderRadius: const BorderRadius.vertical(bottom: Radius.circular(36)),
              ),
              child: Column(
                children: [
                  Container(
                    width: 76,
                    height: 76,
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: .16),
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.white.withValues(alpha: .35), width: 1.5),
                    ),
                    child: const Icon(Icons.headset_mic_rounded, color: Colors.white, size: 36),
                  ),
                  const SizedBox(height: 14),
                  Text(AppConfig.companyName, style: display(24, w7, Colors.white)),
                  Text('الكول سنتر', style: font(14, w7, Colors.white.withValues(alpha: .9))),
                  const SizedBox(height: 10),
                  Text(
                    'تذاكر المبلغ والمعالجة ومحادثات التجّار — في يدك',
                    textAlign: TextAlign.center,
                    style: font(12.5, w5, Colors.white.withValues(alpha: .82), height: 1.5),
                  ),
                ],
              ),
            ),
            Transform.translate(
              offset: const Offset(0, -44),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                child: WhiteCard(
                  radius: 24,
                  padding: const EdgeInsets.all(18),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text('دخول', style: display(18, w7, Palette.ink)),
                      const SizedBox(height: 12),
                      TextField(
                        controller: username,
                        textInputAction: TextInputAction.next,
                        autofillHints: const [AutofillHints.username],
                        style: font(14, w6, Palette.ink),
                        decoration: _field('اسم المستخدم', Icons.person_outline_rounded),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: password,
                        obscureText: !shown,
                        onSubmitted: (_) => _submit(),
                        autofillHints: const [AutofillHints.password],
                        style: font(14, w6, Palette.ink),
                        decoration: _field(
                          'كلمة المرور',
                          Icons.lock_outline_rounded,
                          suffix: IconButton(
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
                      const SizedBox(height: 16),
                      HeroButton(brand: brand, label: busy ? 'يدخل…' : 'دخول', onTap: busy ? null : _submit),
                      const SizedBox(height: 14),
                      Text(
                        'بالحساب نفسه في موقع الشركة. نسيت كلمة المرور؟ راجع مدير الشركة.',
                        textAlign: TextAlign.center,
                        style: font(12, w5, Palette.slate, height: 1.5),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
