import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/palette.dart';
import 'bits.dart';

/// زرّ «وصل» المربّع (docs/plan/123): ٤٤ بزوايا ١٤ وحدٍّ رفيع، أبيض — للرجوع والإغلاق والأيقونات في الرؤوس
class SquareButton extends StatelessWidget {
  const SquareButton({super.key, required this.tip, required this.onTap, required this.child, this.onDark = false});

  final String tip;
  final VoidCallback? onTap;
  final Widget child;

  /// على رأسٍ داكن: زجاجٌ أبيض شفّاف بحدٍّ خفيف، والسهم أبيض
  final bool onDark;

  @override
  Widget build(BuildContext context) => Tooltip(
    message: tip,
    child: Tap(
      radius: 14,
      onTap: onTap,
      child: Container(
        width: 44,
        height: 44,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: onDark ? Colors.white.withValues(alpha: .10) : Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: onDark ? Colors.white.withValues(alpha: .22) : Palette.line),
        ),
        child: child,
      ),
    ),
  );
}

/// سهم الرجوع في «وصل»: يشير إلى اليمين، من حيث جاء
class BackArrow extends StatelessWidget {
  const BackArrow({super.key, this.color = Palette.ink});

  final Color color;

  @override
  // arrow_back ينقلب مع اتجاه العربية فيشير إلى اليمين
  Widget build(BuildContext context) => Icon(Icons.arrow_back_rounded, size: 22, color: color);
}

/// رأس الشاشة التي تُفتح فوق الشريط: رجوعٌ وعنوانٌ ووصفٌ قصير
class PageBar extends StatelessWidget {
  const PageBar({super.key, required this.title, this.subtitle, this.trailing});

  final String title;
  final String? subtitle;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
    child: Row(
      children: [
        SquareButton(tip: 'رجوع', onTap: () => Navigator.of(context).pop(), child: const BackArrow()),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: kf(20, Palette.ink, bold: true, height: 1.4),
              ),
              if (subtitle != null)
                Text(subtitle!, maxLines: 2, overflow: TextOverflow.ellipsis, style: kf(12, Palette.slate)),
            ],
          ),
        ),
        ?trailing,
      ],
    ),
  );
}

/// ما يُعرض حتى تصل البيانات: دائرةٌ تدور، أو الخطأ بزرّ «أعد المحاولة»
class Waiting extends StatelessWidget {
  const Waiting({super.key, required this.brand, this.error, required this.onRetry});

  final Brand brand;
  final String? error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(
    child: error == null
        ? CircularProgressIndicator(color: brand.main)
        : Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(error!, textAlign: TextAlign.center, style: kf(14, Palette.ink)),
                const SizedBox(height: 12),
                FilledButton(
                  onPressed: onRetry,
                  child: Text('أعد المحاولة', style: kf(14, Colors.white, bold: true)),
                ),
              ],
            ),
          ),
  );
}

/// قائمةٌ فارغة: أيقونةٌ وجملتان
class Empty extends StatelessWidget {
  const Empty({super.key, required this.icon, required this.title, required this.text});

  final IconData icon;
  final String title;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 40, horizontal: 24),
    child: Column(
      children: [
        Icon(icon, size: 40, color: Palette.muted),
        const SizedBox(height: 10),
        Text(title, textAlign: TextAlign.center, style: kf(16, Palette.ink, bold: true)),
        const SizedBox(height: 4),
        Text(text, textAlign: TextAlign.center, style: kf(14, Palette.slate)),
      ],
    ),
  );
}

/// عنوان قسمٍ داخل الصفحة
class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(2, 16, 2, 8),
    child: Text(text, style: kf(16, Palette.ink, bold: true)),
  );
}

/// شريحة حالةٍ صغيرة بلونٍ هادئ
class Chip2 extends StatelessWidget {
  const Chip2(this.text, {super.key, this.color = Palette.slate});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
    decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
    child: Text(text, style: font(11, w8, color)),
  );
}

/// رسالةٌ قصيرة في أسفل الشاشة (docs/plan/123): شريطٌ داكنٌ بزوايا مدوّرة، والخطأ أحمر
void toast(BuildContext context, String text, {bool bad = false}) => ScaffoldMessenger.of(context)
  ..hideCurrentSnackBar()
  ..showSnackBar(
    SnackBar(
      content: Text(text, style: kf(14, Colors.white, bold: true)),
      backgroundColor: bad ? Palette.returnRed : Palette.ink,
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    ),
  );

/// «هل أنت متأكد؟» بزرّين
Future<bool> confirm(BuildContext context, String title, String text, String yes) async =>
    await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(title, style: kf(16, Palette.ink, bold: true)),
        content: Text(text, style: kf(14, Palette.slate)),
        actions: [
          TextButton(onPressed: () => Navigator.of(c).pop(false), child: const Text('تراجع')),
          FilledButton(onPressed: () => Navigator.of(c).pop(true), child: Text(yes)),
        ],
      ),
    ) ??
    false;

// ------------------------------------------------------------------ نوافذ الإدخال السفلية

Future<T?> showSheet<T>(BuildContext context, Widget child) => showModalBottomSheet<T>(
  context: context,
  isScrollControlled: true,
  backgroundColor: Colors.white,
  shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
  builder: (_) => child,
);

/// إطار النافذة: مقبضٌ وعنوانٌ ووصف، ثم ما فيها — وترتفع فوق لوحة المفاتيح
class SheetFrame extends StatelessWidget {
  const SheetFrame({super.key, required this.title, required this.text, required this.children});

  final String title;
  final String text;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.fromLTRB(16, 10, 16, 16 + MediaQuery.viewInsetsOf(context).bottom),
    child: SafeArea(
      top: false,
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SheetHandle(),
            const SizedBox(height: 14),
            Text(title, style: kf(16, Palette.ink, bold: true)),
            const SizedBox(height: 2),
            Text(text, style: kf(12, Palette.slate)),
            const SizedBox(height: 14),
            ...children,
          ],
        ),
      ),
    ),
  );
}

/// مقبض النافذة السفلية
class SheetHandle extends StatelessWidget {
  const SheetHandle({super.key});

  @override
  Widget build(BuildContext context) => Center(
    child: Container(
      width: 40,
      height: 4,
      decoration: BoxDecoration(color: const Color(0xFFDCD4D8), borderRadius: BorderRadius.circular(2)),
    ),
  );
}

class ErrorNote extends StatelessWidget {
  const ErrorNote(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(top: 10),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(color: Tone.red.tint, borderRadius: BorderRadius.circular(14)),
    child: Text(message, style: kf(12, Palette.returnRed, bold: true)),
  );
}

class SheetButton extends StatelessWidget {
  const SheetButton({
    super.key,
    required this.brand,
    required this.label,
    required this.onTap,
    this.icon,
    this.busy = false,
    this.outlined = false,
  });

  final Brand brand;
  final String label;
  final VoidCallback? onTap;
  final IconData? icon;
  final bool busy;
  final bool outlined;

  @override
  Widget build(BuildContext context) {
    final fg = outlined ? Palette.ink : Colors.white;
    return Tap(
      radius: 16,
      onTap: busy ? null : onTap,
      child: Opacity(
        opacity: onTap == null && !busy ? .5 : 1,
        child: Container(
          height: 52,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: outlined ? Colors.white : brand.main,
            borderRadius: BorderRadius.circular(16),
            border: outlined ? Border.all(color: const Color(0xFFDCD4D8)) : null,
          ),
          child: busy
              ? SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: fg))
              : Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (icon != null) ...[Icon(icon, size: 20, color: fg), const SizedBox(width: 8)],
                    Flexible(
                      child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: kf(16, fg, bold: true)),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

/// حقل الإدخال في النوافذ: رماديّ فاتح بحدٍّ رفيع
class InputBox extends StatelessWidget {
  const InputBox({
    super.key,
    required this.controller,
    required this.hint,
    this.lines = 1,
    this.maxLength,
    this.keyboard,
    this.formatters,
    this.ltr = false,
    this.autofocus = false,
    this.obscure = false,
  });

  /// كلمة مرور: تُكتب نقاطاً
  final bool obscure;

  final TextEditingController controller;
  final String hint;
  final int lines;
  final int? maxLength;
  final TextInputType? keyboard;
  final List<TextInputFormatter>? formatters;
  final bool ltr;
  final bool autofocus;

  @override
  Widget build(BuildContext context) => Container(
    constraints: const BoxConstraints(minHeight: 48),
    alignment: Alignment.center,
    decoration: BoxDecoration(
      color: const Color(0xFFFAF9F9),
      borderRadius: BorderRadius.circular(14),
      border: Border.all(color: Palette.line),
    ),
    child: TextField(
      controller: controller,
      autofocus: autofocus,
      obscureText: obscure,
      minLines: lines,
      maxLines: lines == 1 ? 1 : lines + 3,
      maxLength: maxLength,
      keyboardType: keyboard ?? (lines > 1 ? TextInputType.multiline : null),
      inputFormatters: formatters,
      textDirection: ltr ? TextDirection.ltr : null,
      style: kf(14, Palette.ink),
      decoration: InputDecoration(
        isDense: true,
        counterText: '',
        hintText: hint,
        hintMaxLines: 3,
        hintStyle: kf(14, const Color(0xFF8C8286)),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      ),
    ),
  );
}

/// تسمية الحقل فوقه
class FieldLabel extends StatelessWidget {
  const FieldLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 6, top: 4),
    child: Text(text, style: kf(12, Palette.ink, bold: true)),
  );
}

/// صفحات الشريط تعود إلى الرئيسية (docs/plan/61): زرّ الرجوع في رأسها، وزرّ رجوع الهاتف كذلك
class ShellNav extends InheritedWidget {
  const ShellNav({super.key, required this.home, required super.child});

  final VoidCallback home;

  static ShellNav? of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<ShellNav>();

  @override
  bool updateShouldNotify(ShellNav oldWidget) => false;
}

/// زرّ الرجوع في رأس صفحةٍ من صفحات الشريط: إلى الرئيسية
class TabBack extends StatelessWidget {
  const TabBack({super.key});

  @override
  Widget build(BuildContext context) {
    final nav = ShellNav.of(context);
    if (nav == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsetsDirectional.only(end: 12),
      child: SquareButton(tip: 'رجوع', onTap: nav.home, child: const BackArrow()),
    );
  }
}
