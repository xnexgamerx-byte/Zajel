import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/palette.dart';

/// قطعٌ متكرّرة في تصميم «وصل» (docs/plan/123): البطاقة الداكنة بنقشها، وحركة الظهور، وعنوان القسم.

/// نقش «وصل»: خطوطٌ مائلة بزاويتي ٣٠° و١٥٠° — خفيفةٌ بيضاء فوق الداكن، أو داكنةٌ خلف القوائم
class IsoGrid extends CustomPainter {
  const IsoGrid({this.color = const Color(0x12FFFFFF), this.step = 18});

  final Color color;
  final double step;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 1;
    final t = math.tan(math.pi / 6);
    for (var c = -size.width * t - step; c < size.height + size.width * t + step; c += step) {
      canvas.drawLine(Offset(0, c), Offset(size.width, c + size.width * t), paint);
      canvas.drawLine(Offset(0, c), Offset(size.width, c - size.width * t), paint);
    }
  }

  @override
  bool shouldRepaint(covariant IsoGrid old) => old.color != color || old.step != step;
}

/// البطاقة الداكنة بلون الشركة ونقشها: الإعلان، والمتاح للسحب، وبطاقة المتجر، وخيار الذكاء الاصطناعي
class DeepCard extends StatelessWidget {
  const DeepCard({
    super.key,
    required this.brand,
    required this.child,
    this.padding = const EdgeInsets.all(20),
    this.radius = 24,
    this.color,
    this.height,
  });

  final Brand brand;
  final Widget child;
  final EdgeInsets padding;
  final double radius;

  /// غير لون الشركة الداكن (الإعلان الثاني داكنٌ بالحبر)
  final Color? color;
  final double? height;

  @override
  Widget build(BuildContext context) => Container(
    height: height,
    clipBehavior: Clip.antiAlias,
    decoration: BoxDecoration(color: color ?? brand.night, borderRadius: BorderRadius.circular(radius)),
    child: CustomPaint(
      painter: const IsoGrid(),
      child: Padding(padding: padding, child: child),
    ),
  );
}

/// الظهور في «وصل»: يرتفع قليلاً ويظهر، بتأخيرٍ يرتّب العناصر واحداً بعد واحد. حركةٌ واحدة بلا مؤقّت (فلا يبقى
/// شيءٌ يعمل بعد الاختبارات)، وبلا حركةٍ لمن أطفأها في هاتفه
class Rise extends StatefulWidget {
  const Rise({super.key, required this.child, this.delay = 0, this.dy = 16, this.scale = false});

  final Widget child;

  /// بالملّي ثانية
  final int delay;
  final double dy;

  /// يكبر من صغيرٍ بدل أن يرتفع (الرسوم والشارات)
  final bool scale;

  @override
  State<Rise> createState() => _RiseState();
}

class _RiseState extends State<Rise> with SingleTickerProviderStateMixin {
  static const _run = 550;

  late final AnimationController c = AnimationController(
    vsync: this,
    duration: Duration(milliseconds: widget.delay + _run),
  );
  late final Animation<double> t = CurvedAnimation(
    parent: c,
    curve: Interval(widget.delay / (widget.delay + _run), 1, curve: const Cubic(.2, .8, .2, 1)),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (c.isAnimating || c.isCompleted) return;
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) {
      c.value = 1;
    } else {
      c.forward();
    }
  }

  @override
  void dispose() {
    c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: t,
    child: widget.child,
    builder: (_, child) {
      final v = t.value;
      if (widget.scale) {
        return Opacity(
          opacity: v.clamp(0, 1),
          child: Transform.scale(scale: .3 + .7 * v, child: child),
        );
      }
      return Opacity(
        opacity: v.clamp(0, 1),
        child: Transform.translate(offset: Offset(0, widget.dy * (1 - v)), child: child),
      );
    },
  );
}

/// عنوان قسمٍ في «وصل»: اسمه بخطّ ١٦، وعددٌ أو وصفٌ بجانبه، وزرٌّ في طرفه
class SectionHead extends StatelessWidget {
  const SectionHead(
    this.title, {
    super.key,
    this.note,
    this.badge,
    this.action,
    this.onAction,
    this.actionColor = Palette.ink,
  });

  final String title;

  /// «30 شحنة» بجانب العنوان
  final String? note;

  /// عددٌ في شارةٍ بلون الشركة
  final Widget? badge;
  final String? action;
  final VoidCallback? onAction;

  /// «المزيد» بلون الشركة
  final Color actionColor;

  @override
  Widget build(BuildContext context) => Row(
    crossAxisAlignment: CrossAxisAlignment.center,
    children: [
      // العنوان ووصفه يأخذان ما بقي، فتقف الشارة و«المزيد» في الطرف
      Expanded(
        child: Row(
          children: [
            Flexible(child: Text(title, style: kf(16, Palette.ink, bold: true))),
            if (note != null) ...[const SizedBox(width: 8), Text(note!, style: kf(12, Palette.muted))],
          ],
        ),
      ),
      ?badge,
      if (action != null)
        InkWell(
          onTap: onAction,
          borderRadius: BorderRadius.circular(12),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 40),
            child: Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(8, 0, 4, 0),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(action!, style: kf(14, actionColor, bold: true)),
                  const SizedBox(width: 2),
                  Icon(Icons.chevron_right_rounded, size: 18, color: actionColor),
                ],
              ),
            ),
          ),
        ),
    ],
  );
}

/// شارة عددٍ صغيرة بلون الشركة
class CountBadge extends StatelessWidget {
  const CountBadge({super.key, required this.brand, required this.count});

  final Brand brand;
  final int count;

  @override
  Widget build(BuildContext context) => Container(
    constraints: const BoxConstraints(minWidth: 24),
    height: 24,
    padding: const EdgeInsets.symmetric(horizontal: 8),
    alignment: Alignment.center,
    decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(12)),
    child: Text(money(count), style: kf(12, Colors.white, bold: true, height: 1)),
  );
}
