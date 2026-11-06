import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/art.dart';
import '../widgets/bits.dart';
import '../widgets/design.dart';
import '../widgets/page.dart';
import 'status_screen.dart';

Future<void> openStates(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => StatesScreen(brand: brand)));

/// «رحلة شحناتك» كلّها (docs/plan/87، 123): رأسٌ داكنٌ بمخزنٍ تخرج منه سيارة وعدد شحناتك، ثم كلّ حالةٍ في خانتها —
/// حلقةٌ بلونها حول عددها تمتلئ بنسبته من شحناتك، واسمها تحتها — وتفتح قائمتها.
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
    final top = MediaQuery.paddingOf(context).top;
    return Scaffold(
      backgroundColor: brand.night,
      body: d == null
          ? ColoredBox(
              color: Palette.bg,
              child: Waiting(brand: brand, error: error, onRetry: _load),
            )
          : RefreshIndicator(
              color: brand.main,
              onRefresh: _load,
              child: ListView(
                padding: EdgeInsets.zero,
                children: [
                  // الرأس الداكن بنقشه: مخزنٌ تخرج منه سيارة، وعدد شحناتك
                  CustomPaint(
                    painter: const IsoGrid(),
                    child: Padding(
                      padding: EdgeInsets.fromLTRB(16, (top > 0 ? top : 28) + 8, 16, 52),
                      child: Column(
                        children: [
                          Row(
                            children: [
                              SquareButton(
                                tip: 'رجوع',
                                onTap: () => Navigator.of(context).pop(),
                                onDark: true,
                                child: const BackArrow(color: Colors.white),
                              ),
                              Expanded(
                                child: Text(
                                  'رحلة شحناتك',
                                  textAlign: TextAlign.center,
                                  style: kf(16, Colors.white, bold: true),
                                ),
                              ),
                              const SizedBox(width: 44),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Expanded(
                                child: Padding(
                                  padding: const EdgeInsets.only(bottom: 16),
                                  child: Rise(
                                    delay: 300,
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text('أين كلّ شحنةٍ الآن', style: kf(12, Colors.white.withValues(alpha: .82))),
                                        Text(_count(d.total), style: kf(24, Colors.white, bold: true)),
                                        Text(
                                          'بحالتها ومكانها بالضبط',
                                          style: kf(14, Colors.white.withValues(alpha: .88)),
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                              Rise(delay: 200, child: Art('scene_hub', brand: brand, width: 200, height: 150)),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                  // الخانات على ورقةٍ ترتفع فوق الرأس
                  Transform.translate(
                    offset: const Offset(0, -28),
                    child: Container(
                      padding: const EdgeInsets.fromLTRB(16, 20, 16, 32),
                      decoration: const BoxDecoration(
                        color: Palette.bg,
                        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
                      ),
                      child: _grid(d),
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  /// «30 شحنة» بتمييزها العربي
  static String _count(int n) => switch (n) {
    0 => 'لا شحنات',
    1 => 'شحنة واحدة',
    2 => 'شحنتان',
    >= 3 && <= 10 => '$n شحنات',
    _ => '${money(n)} شحنة',
  };

  Widget _grid(StatesData d) {
    final tiles = d.tiles;
    return Column(
      children: [
        for (var r = 0; r < tiles.length; r += 3) ...[
          if (r > 0) const SizedBox(height: 10),
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (var i = r; i < r + 3; i++) ...[
                  if (i > r) const SizedBox(width: 10),
                  Expanded(
                    child: i < tiles.length
                        ? Rise(
                            delay: 160 + i * 45,
                            child: _Tile(
                              brand: brand,
                              tile: tiles[i],
                              view: StatusView.of(tiles[i].key),
                              share: tiles[i].key == 'all' ? 1 : (d.total == 0 ? 0 : tiles[i].count / d.total),
                              onTap: () => openStatus(context, brand, tiles[i].key).then((_) => _load()),
                            ),
                          )
                        : const SizedBox.shrink(),
                  ),
                ],
              ],
            ),
          ),
        ],
      ],
    );
  }
}

/// خانة حالة (docs/plan/87، 123): حلقةٌ بلونها تمتلئ بنسبتها من شحناتك وعددها في وسطها، واسمها، وشرطةٌ بلونها.
/// «كل الشحنات» داكنةٌ بلون الشركة
class _Tile extends StatelessWidget {
  const _Tile({required this.brand, required this.tile, required this.view, required this.share, required this.onTap});

  final Brand brand;
  final StateTile tile;
  final StatusView view;
  final double share;
  final VoidCallback onTap;

  /// أين هي (docs/plan/123): سطرٌ تحت اسم الحالة، و«لا شيء الآن» حين تفرغ
  static String? _where(StateTile tile) => tile.count == 0
      ? 'لا شيء الآن'
      : switch (tile.key) {
          'all' => 'في كلّ المراحل',
          'created' => 'عندك، لم تطلب استلامها',
          'pickup' => 'عندك، يمرّ عليك المندوب',
          'warehouse' => 'في المخزن',
          'courier' => 'خرجت للتوصيل',
          'redelivery' => 'تخرج مع المندوب ثانيةً',
          'postponed' => 'لموعدٍ اتّفق عليه الزبون',
          'processing' => 'تنتظر قرارك',
          'delivered' => 'عند زبائنها',
          'return_courier' => 'مع المندوب، إلى المخزن',
          'return_hub' => 'في المخزن',
          'returns' => 'تُسلَّم لك بكشف الرواجع',
          _ => null,
        };

  @override
  Widget build(BuildContext context) {
    final all = tile.key == 'all';
    final ink = all ? Colors.white : view.tone.ink;
    final track = all ? Colors.white.withValues(alpha: .22) : view.tone.tint;
    final number = all ? Colors.white : (tile.count == 0 ? const Color(0xFF8C8286) : Palette.ink);
    return Semantics(
      button: true,
      label: '${tile.label}: ${money(tile.count)}',
      excludeSemantics: true,
      onTap: onTap,
      child: Tap(
        radius: 20,
        shadow: all
            ? const [BoxShadow(color: Color(0x991C1416), blurRadius: 32, spreadRadius: -18, offset: Offset(0, 18))]
            : cardShadow,
        onTap: onTap,
        child: Ink(
          padding: const EdgeInsets.fromLTRB(6, 14, 6, 12),
          decoration: BoxDecoration(color: all ? brand.night : Colors.white, borderRadius: BorderRadius.circular(20)),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 150),
            child: Column(
              children: [
                SizedBox(
                  width: 72,
                  height: 72,
                  child: TweenAnimationBuilder<double>(
                    tween: Tween(begin: 0, end: share.clamp(0, 1).toDouble()),
                    duration: const Duration(milliseconds: 1200),
                    curve: const Cubic(.3, .7, .2, 1),
                    builder: (_, v, child) =>
                        CustomPaint(painter: _Ring(v, tile.count == 0 ? Colors.transparent : ink, track), child: child),
                    child: Center(
                      child: Padding(
                        padding: const EdgeInsets.all(10),
                        child: FittedBox(
                          fit: BoxFit.scaleDown,
                          child: Text(money(tile.count), style: kf(24, number, bold: true, height: 1)),
                        ),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  tile.label,
                  maxLines: 2,
                  textAlign: TextAlign.center,
                  style: kf(12, all ? Colors.white : Palette.ink, bold: true),
                ),
                if (_where(tile) case final where?) ...[
                  const SizedBox(height: 2),
                  Text(
                    where,
                    maxLines: 2,
                    textAlign: TextAlign.center,
                    style: kf(12, all ? Colors.white.withValues(alpha: .85) : Palette.muted),
                  ),
                ],
                const Spacer(),
                const SizedBox(height: 6),
                Container(
                  width: 20,
                  height: 3,
                  decoration: BoxDecoration(color: ink, borderRadius: BorderRadius.circular(2)),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// حلقةٌ رماديّة، ويمتلئ منها بلون الحالة بقدر نسبتها — ونقطةٌ صغيرة حين النسبة ضئيلة، فلا يختفي العدد القليل
class _Ring extends CustomPainter {
  const _Ring(this.value, this.color, this.track);

  final double value;
  final Color color;
  final Color track;

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
        ..color = track
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
  bool shouldRepaint(covariant _Ring old) => old.value != value || old.color != color || old.track != track;
}
