import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';

/// الدخول باسم المستخدم وكلمة المرور كما في الموقع — بلون الشركة واسمها.
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
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 72,
                    height: 72,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      gradient: brand.tile,
                      borderRadius: BorderRadius.circular(22),
                      boxShadow: [
                        BoxShadow(color: brand.main.withValues(alpha: .3), blurRadius: 18, offset: const Offset(0, 8)),
                      ],
                    ),
                    child: const Icon(Icons.local_shipping_rounded, color: Colors.white, size: 36),
                  ),
                ),
                const SizedBox(height: 18),
                Text(AppConfig.companyName, textAlign: TextAlign.center, style: font(22, w8, Palette.ink)),
                const SizedBox(height: 4),
                Text('ادخل لمتابعة شحناتك وحسابك', textAlign: TextAlign.center, style: font(13, w5, Palette.slate)),
                const SizedBox(height: 26),
                WhiteCard(
                  radius: 18,
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      TextField(
                        controller: username,
                        textInputAction: TextInputAction.next,
                        autofillHints: const [AutofillHints.username],
                        style: font(14, w6, Palette.ink),
                        decoration: _field('اسم المستخدم أو رقم الهاتف', Icons.person_outline_rounded),
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
                        Text(error!, style: font(12.5, w6, brand.main)),
                      ],
                      const SizedBox(height: 16),
                      SizedBox(
                        height: 50,
                        child: FilledButton(
                          onPressed: busy ? null : _submit,
                          style: FilledButton.styleFrom(
                            backgroundColor: brand.main,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                          ),
                          child: busy
                              ? const SizedBox(
                                  width: 22,
                                  height: 22,
                                  child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                                )
                              : Text('دخول', style: font(15, w8, Colors.white)),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 18),
                Text(
                  'نسيت كلمة المرور؟ راجع شركة التوصيل لتعيينها.',
                  textAlign: TextAlign.center,
                  style: font(12, w5, Palette.slate),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
