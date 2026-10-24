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

/// يجعل أيّ بطاقةٍ قابلةً للّمس بتموّجٍ داخل زواياها.
///
/// والظلّ هنا لا على Ink الداخل: Material يقصّ ما يرسمه Ink على حدوده، فيظهر ظلّه مربّعاً باهتاً
class Tap extends StatelessWidget {
  const Tap({super.key, required this.child, this.onTap, this.radius = 14, this.shadow});

  final Widget child;
  final VoidCallback? onTap;
  final double radius;
  final List<BoxShadow>? shadow;

  @override
  Widget build(BuildContext context) {
    final tap = Material(
      type: MaterialType.transparency,
      child: InkWell(onTap: onTap, borderRadius: BorderRadius.circular(radius), child: child),
    );
    if (shadow == null) return tap;
    return DecoratedBox(
      decoration: BoxDecoration(borderRadius: BorderRadius.circular(radius), boxShadow: shadow),
      child: tap,
    );
  }
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

  /// لون الحالة بثيم الرئيسية (docs/plan/77): خلفيةٌ فاتحة من لونها ونصٌّ به — الراجع أحمر ليُميَّز
  /// (docs/plan/50)، وما ينتظر قرارك بلون الشركة، والواصل أخضر، وما في الطريق أزرق، والباقي رماديّ
  /// والمؤجّل أصفر (docs/plan/79)
  static Color colorOf(Brand brand, RecentShipment row) => switch (row.tone) {
    'red' => Palette.returnRed,
    'green' => Palette.ok,
    'blue' => const Color(0xFF2F6DB5),
    'yellow' => Palette.postponed,
    'amber' || 'urgent' => brand.main,
    _ => row.urgent ? brand.main : Palette.slate,
  };

  @override
  Widget build(BuildContext context) {
    final color = colorOf(brand, row);
    final yellow = row.tone == 'yellow';
    return Container(
      width: width,
      height: height,
      padding: const EdgeInsets.symmetric(horizontal: 4),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        // الأصفر الفاتح وحده لا يُقرأ عليه أصفر: خلفيّته صفراء ونصّه ذهبيٌّ داكن
        color: yellow ? Palette.postponedSoft : Color.lerp(Colors.white, color, .12),
        borderRadius: BorderRadius.circular(height / 2),
      ),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Text(
          row.status,
          maxLines: 1,
          style: font(size, w8, yellow ? Palette.postponedInk : Color.lerp(color, Colors.black, .1)!, height: 1),
        ),
      ),
    );
  }
}

/// صفّ كشفٍ أو إيصالٍ في تصميم ز (docs/plan/70): مربّعٌ أحمر شفّاف مظلَّل، بأيقونةٍ في مربّعٍ بتدرّج
/// لون الشركة، وعنوانه وسطره، وفي طرفه رقمه وحالته.
class TintedRow extends StatelessWidget {
  const TintedRow({
    super.key,
    required this.brand,
    required this.icon,
    required this.title,
    required this.subtitle,
    this.end,
    this.status,
    this.onTap,
  });

  final Brand brand;
  final IconData icon;
  final String title;
  final String subtitle;
  final Widget? end;
  final Widget? status;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Tap(
    radius: 20,
    onTap: onTap,
    child: Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: LinearGradient(
          begin: AlignmentDirectional.topStart,
          end: AlignmentDirectional.bottomEnd,
          colors: [brand.main.withValues(alpha: .10), brand.main.withValues(alpha: .04)],
        ),
        border: Border.all(color: brand.main.withValues(alpha: .20)),
        boxShadow: [
          BoxShadow(
            color: brand.main.withValues(alpha: .14),
            blurRadius: 20,
            spreadRadius: -12,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              gradient: brand.hero,
              borderRadius: BorderRadius.circular(14),
              boxShadow: brand.glow(.5),
            ),
            child: Icon(icon, size: 20, color: Colors.white),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(14, w8, Palette.ink, height: 1.35),
                ),
                Text(
                  subtitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(12, w5, Palette.muted, height: 1.35),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [?end, if (end != null && status != null) const SizedBox(height: 5), ?status],
          ),
        ],
      ),
    ),
  );
}

/// «امسح للتأكيد»: حالةٌ تنتظر مسح ورقتها (docs/plan/69)
class ScanPill extends StatelessWidget {
  const ScanPill({super.key, required this.brand, required this.text});

  final Brand brand;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
    decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(20), boxShadow: brand.glow(.5)),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Icon(Icons.qr_code_scanner_rounded, size: 13, color: Colors.white),
        const SizedBox(width: 4),
        Text(text, style: font(11, w8, Colors.white, height: 1.3)),
      ],
    ),
  );
}

/// «استلمتها ✓»: ما أُكّد
class OkPill extends StatelessWidget {
  const OkPill({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
    decoration: BoxDecoration(color: Palette.okSoft, borderRadius: BorderRadius.circular(20)),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const Icon(Icons.check_rounded, size: 13, color: Palette.ok),
        const SizedBox(width: 3),
        Text(text, style: font(11, w8, Palette.ok, height: 1.3)),
      ],
    ),
  );
}

/// الزرّ الأحمر الكبير في تصميم ز: تدرّج لون الشركة وتوهّجه
class HeroButton extends StatelessWidget {
  const HeroButton({super.key, required this.brand, required this.label, this.icon, this.onTap, this.height = 54});

  final Brand brand;
  final String label;
  final IconData? icon;
  final VoidCallback? onTap;
  final double height;

  @override
  Widget build(BuildContext context) => Tap(
    shadow: brand.glow(),
    radius: 18,
    onTap: onTap,
    child: Ink(
      height: height,
      decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(18)),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          if (icon != null) ...[Icon(icon, size: 21, color: Colors.white), const SizedBox(width: 8)],
          Text(label, style: font(15, w8, Colors.white)),
        ],
      ),
    ),
  );
}
