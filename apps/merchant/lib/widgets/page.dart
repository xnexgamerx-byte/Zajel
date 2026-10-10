import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/palette.dart';
import 'bits.dart';

/// رأس الشاشة التي تُفتح فوق الشريط: رجوعٌ وعنوانٌ ووصفٌ قصير
class PageBar extends StatelessWidget {
  const PageBar({super.key, required this.title, this.subtitle, this.trailing});

  final String title;
  final String? subtitle;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
    child: Row(
      children: [
        Tooltip(
          message: 'رجوع',
          child: Tap(
            radius: 18,
            onTap: () => Navigator.of(context).pop(),
            child: Container(
              width: 36,
              height: 36,
              alignment: Alignment.center,
              decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
              child: const Chev(size: 11, stroke: 1.8),
            ),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: font(18, w8, Palette.ink, height: 1.2)),
              if (subtitle != null) Text(subtitle!, style: font(11.5, w5, Palette.slate, height: 1.3)),
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
                Text(error!, textAlign: TextAlign.center, style: font(14, w6, Palette.ink)),
                const SizedBox(height: 12),
                FilledButton(onPressed: onRetry, child: const Text('أعد المحاولة')),
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
        Text(title, textAlign: TextAlign.center, style: font(15, w8, Palette.ink)),
        const SizedBox(height: 4),
        Text(text, textAlign: TextAlign.center, style: font(12.5, w5, Palette.slate, height: 1.5)),
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
    padding: const EdgeInsets.fromLTRB(2, 14, 2, 8),
    child: Text(text, style: font(15, w8, Palette.ink)),
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

void toast(BuildContext context, String text, {bool bad = false}) => ScaffoldMessenger.of(context)
  ..hideCurrentSnackBar()
  ..showSnackBar(
    SnackBar(
      content: Text(text, style: font(13, w6, Colors.white)),
      backgroundColor: bad ? Palette.returnRed : null,
      behavior: SnackBarBehavior.floating,
    ),
  );

/// «هل أنت متأكد؟» بزرّين
Future<bool> confirm(BuildContext context, String title, String text, String yes) async =>
    await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(title, style: font(16, w8, Palette.ink)),
        content: Text(text, style: font(13, w5, Palette.slate, height: 1.6)),
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
  shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
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
            Center(
              child: Container(
                width: 38,
                height: 4,
                decoration: BoxDecoration(color: Palette.line, borderRadius: BorderRadius.circular(2)),
              ),
            ),
            const SizedBox(height: 14),
            Text(title, style: font(16, w8, Palette.ink)),
            const SizedBox(height: 2),
            Text(text, style: font(12, w5, Palette.slate, height: 1.5)),
            const SizedBox(height: 14),
            ...children,
          ],
        ),
      ),
    ),
  );
}

class ErrorNote extends StatelessWidget {
  const ErrorNote(this.message, {super.key});

  final String message;

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(top: 10),
    padding: const EdgeInsets.all(10),
    decoration: BoxDecoration(color: const Color(0xFFFDECEA), borderRadius: BorderRadius.circular(12)),
    child: Text(message, style: font(12.5, w6, Palette.returnRed, height: 1.5)),
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
    final fg = outlined ? brand.main : Colors.white;
    return Tap(
      radius: 14,
      onTap: busy ? null : onTap,
      child: Opacity(
        opacity: onTap == null && !busy ? .5 : 1,
        child: Container(
          height: 50,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: outlined ? Colors.white : brand.main,
            borderRadius: BorderRadius.circular(14),
            border: outlined ? Border.all(color: brand.main.withValues(alpha: .45)) : null,
          ),
          child: busy
              ? SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: fg))
              : Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (icon != null) ...[Icon(icon, size: 19, color: fg), const SizedBox(width: 6)],
                    Text(label, style: font(14.5, w8, fg)),
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
  });

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
    decoration: BoxDecoration(
      color: const Color(0xFFF6F8FB),
      borderRadius: BorderRadius.circular(12),
      border: Border.all(color: Palette.line),
    ),
    child: TextField(
      controller: controller,
      autofocus: autofocus,
      minLines: lines,
      maxLines: lines == 1 ? 1 : lines + 3,
      maxLength: maxLength,
      keyboardType: keyboard ?? (lines > 1 ? TextInputType.multiline : null),
      inputFormatters: formatters,
      textDirection: ltr ? TextDirection.ltr : null,
      style: font(13.5, w6, Palette.ink, height: 1.5),
      decoration: InputDecoration(
        isDense: true,
        counterText: '',
        hintText: hint,
        hintMaxLines: 3,
        hintStyle: font(12.5, w5, Palette.muted, height: 1.5),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.all(12),
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
    child: Text(text, style: font(12, w7, Palette.ink)),
  );
}
