import 'package:flutter/material.dart';

import '../core/models.dart';
import '../core/palette.dart';

/// سهمٌ رفيع كما في التصميم: «›» يشير يميناً في كل البطاقات و«‹» في «عرض الكل»،
/// بلا انعكاسٍ مع اتجاه الصفحة. size ارتفاعه، وعرضه نصفه.
class Chev extends StatelessWidget {
  const Chev({super.key, this.size = 8, this.color = Palette.ink, this.left = false, this.stroke = 1.5});

  final double size;
  final Color color;
  final bool left;
  final double stroke;

  @override
  Widget build(BuildContext context) =>
      CustomPaint(size: Size(size * .55, size), painter: _ChevPainter(color, left, stroke));
}

class _ChevPainter extends CustomPainter {
  _ChevPainter(this.color, this.left, this.stroke);

  final Color color;
  final bool left;
  final double stroke;

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width, h = size.height, i = stroke / 2;
    final path = left
        ? (Path()
            ..moveTo(w - i, i)
            ..lineTo(i, h / 2)
            ..lineTo(w - i, h - i))
        : (Path()
            ..moveTo(i, i)
            ..lineTo(w - i, h / 2)
            ..lineTo(i, h - i));
    canvas.drawPath(
      path,
      Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(covariant _ChevPainter old) => old.color != color || old.left != left;
}

/// بطاقةٌ بيضاء بزوايا التصميم وظلّه الناعم
class WhiteCard extends StatelessWidget {
  const WhiteCard({super.key, required this.child, this.padding = EdgeInsets.zero, this.radius = 14, this.height});

  final Widget child;
  final EdgeInsets padding;
  final double radius;
  final double? height;

  @override
  Widget build(BuildContext context) => Container(
    height: height,
    padding: padding,
    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(radius), boxShadow: cardShadow),
    child: child,
  );
}

/// مربّع أيقونة فاتح (وردي) بأيقونةٍ بلون الشركة
class SoftIcon extends StatelessWidget {
  const SoftIcon({super.key, required this.brand, required this.child, this.size = 27, this.radius = 8});

  final Brand brand;
  final Widget child;
  final double size;
  final double radius;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    alignment: Alignment.center,
    decoration: BoxDecoration(
      gradient: LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [brand.softer, brand.soft],
      ),
      borderRadius: BorderRadius.circular(radius),
    ),
    child: child,
  );
}

/// مربّع أيقونة بتدرّج لون الشركة وأيقونةٍ بيضاء
class SolidIcon extends StatelessWidget {
  const SolidIcon({
    super.key,
    required this.brand,
    required this.icon,
    this.size = 26,
    this.iconSize = 16,
    this.radius = 7,
  });

  final Brand brand;
  final IconData icon;
  final double size;
  final double iconSize;
  final double radius;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    alignment: Alignment.center,
    decoration: BoxDecoration(
      gradient: brand.tile,
      borderRadius: BorderRadius.circular(radius),
      boxShadow: [BoxShadow(color: brand.main.withValues(alpha: .22), blurRadius: 6, offset: const Offset(0, 2))],
    ),
    child: Icon(icon, size: iconSize, color: Colors.white),
  );
}

/// يجعل أيّ بطاقةٍ قابلةً للّمس بتموّجٍ داخل زواياها
class Tap extends StatelessWidget {
  const Tap({super.key, required this.child, this.onTap, this.radius = 14});

  final Widget child;
  final VoidCallback? onTap;
  final double radius;

  @override
  Widget build(BuildContext context) => Material(
    type: MaterialType.transparency,
    child: InkWell(onTap: onTap, borderRadius: BorderRadius.circular(radius), child: child),
  );
}

/// شارة الحالة كما في التصميم: ما ينتظر قرارك بلون الشركة، والباقي رماديّة
class StatusPill extends StatelessWidget {
  const StatusPill({
    super.key,
    required this.brand,
    required this.row,
    this.width = 55,
    this.height = 18,
    this.size = 6.9,
  });

  final Brand brand;
  final RecentShipment row;
  final double width;
  final double height;
  final double size;

  @override
  Widget build(BuildContext context) => Container(
    width: width,
    height: height,
    padding: const EdgeInsets.symmetric(horizontal: 4),
    alignment: Alignment.center,
    decoration: BoxDecoration(
      color: row.urgent ? brand.coral : Palette.pill,
      borderRadius: BorderRadius.circular(height / 2),
    ),
    child: FittedBox(
      fit: BoxFit.scaleDown,
      child: Text(row.status, maxLines: 1, style: font(size, w8, Colors.white, height: 1)),
    ),
  );
}
