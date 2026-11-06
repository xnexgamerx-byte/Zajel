import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/models.dart';
import '../core/palette.dart';
import '../core/seen.dart';
import 'bits.dart';

/// يوم الشحنة عنواناً لمجموعتها في القوائم (docs/plan/123): «اليوم»، «أمس»، «الخميس 30/10»
String dayLabel(DateTime? at) {
  if (at == null) return 'بلا تاريخ';
  const days = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];
  final now = DateTime.now();
  final d = DateTime(at.year, at.month, at.day);
  final diff = DateTime(now.year, now.month, now.day).difference(d).inDays;
  return switch (diff) {
    0 => 'اليوم',
    1 => 'أمس',
    _ => '${days[at.weekday - 1]} ${at.day}/${at.month}',
  };
}

/// الشحنات بأيّام إدخالها: عنوانُ يومٍ (نصّ) قبل أوّل شحنةٍ فيه، ثم شحناته — لقائمةٍ تُبنى سطراً سطراً. القائمة من
/// النظام بالأحدث إدخالاً، فيوم الإدخال لا يتكرّر عنوانه
List<Object> dayEntries(List<RecentShipment> rows) {
  final out = <Object>[];
  String? last;
  for (final r in rows) {
    final day = dayLabel(r.created ?? r.at);
    if (day != last) {
      out.add(day);
      last = day;
    }
    out.add(r);
  }
  return out;
}

/// صفّ شحنةٍ في «سجلّ» وصل (docs/plan/123): شريطٌ شفّافٌ بلون حالتها ما دام فيها ما لم يفتحه التاجر، وأبيض بعد أن
/// يفتحها — ونقطة الحالة واسمها بلونهما دائماً. والتي فُتحت للتوّ يذوب لونها أمامه حين يرجع إلى القائمة.
class ShipmentRow extends StatefulWidget {
  const ShipmentRow({super.key, required this.row, required this.onTap});

  final RecentShipment row;
  final VoidCallback? onTap;

  @override
  State<ShipmentRow> createState() => _ShipmentRowState();
}

class _ShipmentRowState extends State<ShipmentRow> with SingleTickerProviderStateMixin {
  /// ذوبان اللون: يبقى ثلث المدّة ثم يذهب. والشاشة تحت الشحنة المفتوحة لا تتحرّك (TickerMode)، فيُرى حين يرجع
  late final melt = AnimationController(vsync: this, duration: const Duration(milliseconds: 1800));
  late bool fresh = Seen.fresh(widget.row);

  @override
  void initState() {
    super.initState();
    Seen.changed.addListener(_seen);
  }

  @override
  void didUpdateWidget(ShipmentRow old) {
    super.didUpdateWidget(old);
    if (old.row.id != widget.row.id || old.row.at != widget.row.at) {
      melt.value = 0;
      fresh = Seen.fresh(widget.row);
    }
  }

  @override
  void dispose() {
    Seen.changed.removeListener(_seen);
    melt.dispose();
    super.dispose();
  }

  void _seen() {
    final now = Seen.fresh(widget.row);
    if (now == fresh) return;
    setState(() => fresh = now);
    if (!now) melt.forward(from: 0);
  }

  @override
  Widget build(BuildContext context) {
    final row = widget.row;
    final tone = Tone.of(row.tone, row.status);
    final glass = tone.ink.withValues(alpha: .10);
    final glassLine = tone.ink.withValues(alpha: .18);
    final place = row.place;
    final number = row.number.isEmpty ? '' : '\u2066#${row.number}\u2069';
    final second = [if (place.isNotEmpty) place, if (number.isNotEmpty) number].join(' · ');
    return Semantics(
      button: true,
      label: [
        row.name,
        if (place.isNotEmpty) place,
        '${money(row.amount)} د.ع',
        row.status,
        if (fresh) 'لم تُفتح',
      ].join('، '),
      excludeSemantics: true,
      onTap: widget.onTap,
      child: Tap(
        radius: 18,
        onTap: widget.onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 400),
          constraints: const BoxConstraints(minHeight: 64),
          decoration: BoxDecoration(
            color: fresh ? glass : Colors.white,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: fresh ? glassLine : const Color(0x141C1416)),
          ),
          child: Stack(
            children: [
              // اللون الذي كان، يذوب
              Positioned.fill(
                child: AnimatedBuilder(
                  animation: melt,
                  builder: (_, _) {
                    final t = melt.value;
                    if (t == 0 || t == 1) return const SizedBox.shrink();
                    final a = t < .35 ? 1.0 : 1 - Curves.easeOut.transform((t - .35) / .65);
                    return DecoratedBox(
                      decoration: BoxDecoration(
                        color: glass.withValues(alpha: .10 * a),
                        borderRadius: BorderRadius.circular(17),
                      ),
                    );
                  },
                ),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                child: Row(
                  children: [
                    Container(
                      width: 40,
                      height: 40,
                      alignment: Alignment.center,
                      decoration: const BoxDecoration(
                        color: Colors.white,
                        shape: BoxShape.circle,
                        boxShadow: [
                          BoxShadow(color: Color(0x591C1416), blurRadius: 10, spreadRadius: -6, offset: Offset(0, 4)),
                        ],
                      ),
                      child: Text(
                        row.name.isEmpty ? '؟' : row.name.characters.first,
                        style: kf(16, tone.ink, bold: true, height: 1),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            row.name.isEmpty ? 'بلا اسم' : row.name,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: kf(14, Palette.ink, bold: true),
                          ),
                          if (second.isNotEmpty)
                            Text(
                              second,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: kf(12, Palette.slate, height: 1.5),
                            ),
                          // موعد المؤجّلة (docs/plan/86)، وأين الراجع الآن (docs/plan/87)
                          if (row.until != null)
                            Padding(padding: const EdgeInsets.only(top: 4), child: PostponedChip(row.until!)),
                          if (row.returnPlace != null) ReturnPlace(row.returnPlace!),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(money(row.amount), style: kf(14, Palette.ink, bold: true)),
                        ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 120),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Container(
                                width: 6,
                                height: 6,
                                decoration: BoxDecoration(color: tone.ink, shape: BoxShape.circle),
                              ),
                              const SizedBox(width: 6),
                              Flexible(
                                child: Text(
                                  row.status,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: kf(12, tone.ink, bold: true, height: 1.5),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// عنوان يومٍ فوق شحناته
class DayHeading extends StatelessWidget {
  const DayHeading(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(2, 12, 2, 8),
    child: Text(text, style: kf(12, Palette.muted, bold: true)),
  );
}

/// نقشٌ خفيفٌ من خطوطٍ مائلة خلف القوائم (docs/plan/123)، يظهر من خلف الصفوف الشفّافة
class GridBackdrop extends StatelessWidget {
  const GridBackdrop({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => CustomPaint(painter: const _Grid(), child: child);
}

class _Grid extends CustomPainter {
  const _Grid();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = const Color(0x0D1C1416)
      ..strokeWidth = 1;
    final t = math.tan(math.pi / 6);
    const step = 18.0;
    for (var c = -size.width * t - step; c < size.height + size.width * t + step; c += step) {
      canvas.drawLine(Offset(0, c), Offset(size.width, c + size.width * t), paint);
      canvas.drawLine(Offset(0, c), Offset(size.width, c - size.width * t), paint);
    }
  }

  @override
  bool shouldRepaint(covariant _Grid old) => false;
}
