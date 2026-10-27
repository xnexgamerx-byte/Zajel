import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'status_screen.dart';

Future<void> openStates(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => StatesScreen(brand: brand)));

/// «رحلة شحناتك اليوم» كلّها (docs/plan/87): كلّ حالةٍ في خانتها — حلقةٌ حول عددها تمتلئ بنسبته من شحناتك،
/// واسمها تحتها — وتفتح قائمتها.
class StatesScreen extends StatefulWidget {
  const StatesScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<StatesScreen> createState() => _StatesScreenState();
}

class _StatesScreenState extends State<StatesScreen> {
  StatesData? data;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.states();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      backgroundColor: Palette.bg,
      appBar: AppBar(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.white,
        centerTitle: true,
        title: Text('رحلة شحناتك اليوم', style: display(17, w8, Palette.ink)),
      ),
      body: d == null
          ? Waiting(brand: brand, error: error, onRetry: _load)
          : RefreshIndicator(
              color: brand.main,
              onRefresh: _load,
              child: GridView.count(
                padding: const EdgeInsets.fromLTRB(14, 16, 14, 30),
                crossAxisCount: 3,
                mainAxisSpacing: 10,
                crossAxisSpacing: 10,
                childAspectRatio: .86,
                children: [
                  for (final t in d.tiles)
                    _Tile(
                      tile: t,
                      view: StatusView.of(t.key),
                      share: t.key == 'all' ? 1 : (d.total == 0 ? 0 : t.count / d.total),
                      onTap: () => openStatus(context, brand, t.key).then((_) => _load()),
                    ),
                ],
              ),
            ),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.tile, required this.view, required this.share, required this.onTap});

  final StateTile tile;
  final StatusView view;
  final double share;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Tap(
    radius: 22,
    shadow: cardShadow,
    onTap: onTap,
    child: Ink(
      padding: const EdgeInsets.fromLTRB(6, 12, 6, 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Palette.line),
      ),
      child: Column(
        children: [
          Expanded(
            child: AspectRatio(
              aspectRatio: 1,
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: share.clamp(0, 1).toDouble()),
                duration: const Duration(milliseconds: 700),
                curve: Curves.easeOutCubic,
                builder: (_, v, child) => CustomPaint(painter: _Ring(v, view.color), child: child),
                child: Center(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      child: Text(money(tile.count), style: display(17, w8, Palette.ink, height: 1)),
                    ),
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(height: 6),
          SizedBox(
            height: 32,
            child: Center(
              child: Text(
                tile.label,
                maxLines: 2,
                textAlign: TextAlign.center,
                style: font(11.5, w7, Palette.ink, height: 1.3),
              ),
            ),
          ),
        ],
      ),
    ),
  );
}

/// حلقةٌ رماديّة، ويمتلئ منها بلون الحالة بقدر نسبتها — ونقطةٌ صغيرة حين النسبة ضئيلة، فلا يختفي العدد القليل
class _Ring extends CustomPainter {
  const _Ring(this.value, this.color);

  final double value;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    const stroke = 6.0;
    final rect = Offset.zero & size;
    final ring = rect.deflate(stroke / 2 + 2);
    canvas.drawArc(
      ring,
      0,
      math.pi * 2,
      false,
      Paint()
        ..color = Palette.line
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke,
    );
    if (value <= 0) return;
    canvas.drawArc(
      ring,
      -math.pi / 2,
      math.max(value, .02) * math.pi * 2,
      false,
      Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..strokeCap = StrokeCap.round,
    );
  }

  @override
  bool shouldRepaint(covariant _Ring old) => old.value != value || old.color != color;
}
