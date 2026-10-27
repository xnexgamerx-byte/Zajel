import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

/// ألوان خانات التقويم بترتيبها: الواصل أخضر، وقيد التوصيل أزرق، والمؤجل ذهبيّ، وللمعالجة رماديّ دافئ، والراجع
/// أحمر — وكلٌّ باسمه وعدده بجانبه، فلا يُقرأ باللون وحده
const calendarColors = {
  'delivered': Palette.ok,
  'open': Color(0xFF2F6DB5),
  'postponed': Palette.postponed,
  'processing': Color(0xFF8A7472),
  'returned': Palette.returnRed,
};

const _weekdays = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];

Future<void> openCalendar(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => CalendarScreen(brand: brand)));

/// «تقويم الشحنات» في الرئيسية (docs/plan/87): بطاقةٌ صغيرة بآخر سبعة أيام — عددها ونسبة التسليم، وعمودٌ لكلّ
/// يومٍ بألوان ما صارت إليه — وتفتح التقويم كلّه.
class CalendarCard extends StatefulWidget {
  const CalendarCard({super.key, required this.brand, this.refresh});

  final Brand brand;
  final Listenable? refresh;

  @override
  State<CalendarCard> createState() => _CalendarCardState();
}

class _CalendarCardState extends State<CalendarCard> {
  CalendarData? data;

  @override
  void initState() {
    super.initState();
    widget.refresh?.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.refresh?.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.calendar();
      if (mounted) setState(() => data = d);
    } on ApiError {
      // البطاقة تختفي، والرئيسية تبقى
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    if (d == null) return const SizedBox.shrink();
    final peak = d.days.fold<int>(1, (m, day) => math.max(m, day.total));
    return Tap(
      radius: 20,
      shadow: cardShadow,
      onTap: () => openCalendar(context, widget.brand),
      child: Ink(
        padding: const EdgeInsets.fromLTRB(14, 10, 12, 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: Palette.line),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.calendar_month_rounded, size: 18, color: widget.brand.main),
                const SizedBox(width: 6),
                Text('تقويم الشحنات', style: display(14.5, w7, Palette.ink)),
                const Spacer(),
                Text('آخر ٧ أيام', style: font(11.5, w6, Palette.muted)),
                const SizedBox(width: 4),
                const Chev(size: 8, left: true, stroke: 1.6),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(money(d.total), style: display(22, w8, Palette.ink, height: 1.1)),
                    Text('شحنة أدخلتها', style: font(11, w6, Palette.slate)),
                    const SizedBox(height: 6),
                    if (d.rate != null) ...[
                      Text('${d.rate}٪', style: display(16, w8, Palette.ok, height: 1.1)),
                      Text('نسبة التسليم', style: font(11, w6, Palette.slate)),
                    ],
                  ],
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: SizedBox(
                    height: 84,
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        for (final day in d.days)
                          Expanded(
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.end,
                              children: [
                                _StackBar(day: day, height: 62 * day.total / peak),
                                const SizedBox(height: 3),
                                // رقم اليوم: أوضح من اسمٍ مختصر
                                Text('${day.date.day}', style: font(9.5, w6, Palette.muted, height: 1.2)),
                              ],
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            _Legend(buckets: d.buckets, compact: true),
          ],
        ),
      ),
    );
  }
}

/// عمود يومٍ بخاناته فوق بعضها، والواصل أسفلها
class _StackBar extends StatelessWidget {
  const _StackBar({required this.day, required this.height});

  final CalendarDay day;
  final double height;

  @override
  Widget build(BuildContext context) {
    if (day.total == 0) {
      return Container(
        width: 12,
        height: 3,
        decoration: BoxDecoration(color: Palette.line, borderRadius: BorderRadius.circular(2)),
      );
    }
    return Tooltip(
      message: '${day.total} شحنة',
      child: ClipRRect(
        borderRadius: BorderRadius.circular(4),
        child: SizedBox(
          width: 12,
          height: math.max(height, 4),
          child: Column(
            verticalDirection: VerticalDirection.up,
            children: [
              for (final k in CalendarData.keys)
                if ((day.counts[k] ?? 0) > 0)
                  Expanded(
                    flex: day.counts[k]!,
                    child: Container(margin: const EdgeInsets.only(top: 1), color: calendarColors[k]),
                  ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend({required this.buckets, this.compact = false});

  final List<StateTile> buckets;
  final bool compact;

  @override
  Widget build(BuildContext context) => Wrap(
    spacing: compact ? 10 : 14,
    runSpacing: 4,
    children: [
      for (final b in buckets)
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 9,
              height: 9,
              decoration: BoxDecoration(color: calendarColors[b.key], borderRadius: BorderRadius.circular(3)),
            ),
            const SizedBox(width: 4),
            Text('${b.label} ${money(b.count)}', style: font(compact ? 10.5 : 12, w7, Palette.slate)),
          ],
        ),
    ],
  );
}

/// «تقويم الشحنات» كاملاً: فترةٌ يختارها (اليوم، ٧ أيام، ٣٠ يوماً، أو من التقويم)، وأيّامها شريطاً يُلمس منه يومٌ
/// وحده، ثم عدد الشحنات بخاناته في دائرة، ونسبة التسليم، وعمودٌ لكلّ يوم، ومن أدخلها
class CalendarScreen extends StatefulWidget {
  const CalendarScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<CalendarScreen> createState() => _CalendarScreenState();
}

class _CalendarScreenState extends State<CalendarScreen> {
  CalendarData? data;
  String? error;
  late DateTimeRange range = _days(7);
  String period = '7';

  /// يومٌ من الشريط، وإلّا الفترة كلّها
  DateTime? day;

  Brand get brand => widget.brand;

  static DateTimeRange _days(int n) {
    final today = DateUtils.dateOnly(DateTime.now());
    return DateTimeRange(
      start: today.subtract(Duration(days: n - 1)),
      end: today,
    );
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => error = null);
    try {
      final d = await Api.instance.calendar(from: range.start, to: range.end);
      if (mounted) setState(() => data = d);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _pick(String p) async {
    DateTimeRange? next;
    if (p == 'custom') {
      final today = DateUtils.dateOnly(DateTime.now());
      next = await showDateRangePicker(
        context: context,
        firstDate: today.subtract(const Duration(days: 365)),
        lastDate: today,
        initialDateRange: range,
        helpText: 'اختر الفترة',
        saveText: 'اعرضها',
      );
      if (next == null) return;
    } else {
      next = _days(int.parse(p));
    }
    setState(() => (period = p, range = next!, day = null, data = null));
    await _load();
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
        title: Text('تقويم الشحنات', style: display(17, w8, Palette.ink)),
      ),
      body: d == null
          ? Waiting(brand: brand, error: error, onRetry: _load)
          : RefreshIndicator(
              color: brand.main,
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 30),
                children: [
                  _periods(),
                  const SizedBox(height: 10),
                  if (d.days.length > 1 && d.days.length <= 31) ...[_strip(d), const SizedBox(height: 12)],
                  _counts(d),
                  if (d.days.length > 1 && day == null) ...[const SizedBox(height: 12), _bars(d)],
                  if (d.byUser.isNotEmpty && day == null) ...[const SizedBox(height: 12), _byUser(d)],
                ],
              ),
            ),
    );
  }

  Widget _periods() => Wrap(
    spacing: 8,
    runSpacing: 8,
    children: [
      for (final (key, label) in [('1', 'اليوم'), ('7', '٧ أيام'), ('30', '٣٠ يوماً'), ('custom', 'فترة أخرى…')])
        ChoiceChip(
          label: Text(
            key == 'custom' && period == 'custom' ? '${_date(range.start)} — ${_date(range.end)}' : label,
            style: font(12.5, w7, period == key ? Colors.white : Palette.ink),
          ),
          selected: period == key,
          showCheckmark: false,
          selectedColor: brand.main,
          backgroundColor: Colors.white,
          side: BorderSide(color: period == key ? brand.main : Palette.line),
          onSelected: (_) => _pick(key),
        ),
    ],
  );

  static String _date(DateTime d) => '${d.day}/${d.month}';

  /// أيّام الفترة: اسم اليوم ورقمه، ويُلمس فيُعرض وحده — ويُلمس ثانيةً فتعود الفترة
  Widget _strip(CalendarData d) => SizedBox(
    height: 68,
    child: ListView(
      scrollDirection: Axis.horizontal,
      reverse: true,
      children: [
        for (final day in d.days.reversed)
          Padding(
            padding: const EdgeInsets.only(left: 6),
            child: Tap(
              radius: 16,
              onTap: () => setState(() => this.day = DateUtils.isSameDay(this.day, day.date) ? null : day.date),
              child: Ink(
                width: 58,
                padding: const EdgeInsets.symmetric(vertical: 6),
                decoration: BoxDecoration(
                  color: DateUtils.isSameDay(this.day, day.date) ? brand.main : Colors.white,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: DateUtils.isSameDay(this.day, day.date) ? brand.main : Palette.line),
                ),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      _weekdays[day.date.weekday - 1],
                      style: font(
                        10.5,
                        w6,
                        DateUtils.isSameDay(this.day, day.date) ? Colors.white : Palette.slate,
                        height: 1.2,
                      ),
                    ),
                    Text(
                      '${day.date.day}',
                      style: display(
                        17,
                        w8,
                        DateUtils.isSameDay(this.day, day.date) ? Colors.white : brand.deep,
                        height: 1.2,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
      ],
    ),
  );

  /// عدد الشحنات: دائرةٌ بخاناتها والعدد الكلّيّ في وسطها، ومفتاحها بجانبها — للفترة أو لليوم المختار
  Widget _counts(CalendarData d) {
    final picked = day == null ? null : d.days.where((x) => DateUtils.isSameDay(x.date, day)).firstOrNull;
    final buckets = picked == null
        ? d.buckets
        : [for (final b in d.buckets) StateTile(b.key, b.label, picked.counts[b.key] ?? 0)];
    final total = picked?.total ?? d.total;
    final delivered = buckets.firstWhere((b) => b.key == 'delivered').count;
    final returned = buckets.firstWhere((b) => b.key == 'returned').count;
    final rate = delivered + returned == 0 ? null : (delivered * 100 / (delivered + returned)).round();
    return WhiteCard(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            picked == null ? 'عدد الشحنات' : 'شحنات ${_weekdays[picked.date.weekday - 1]} ${_date(picked.date)}',
            style: display(15, w8, Palette.ink),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              SizedBox(
                width: 132,
                height: 132,
                child: CustomPaint(
                  painter: _Donut(buckets, total),
                  child: Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(money(total), style: display(24, w8, Palette.ink, height: 1.1)),
                        Text('العدد الكلّي', style: font(11, w6, Palette.slate)),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (final b in buckets)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 3),
                        child: Row(
                          children: [
                            Container(
                              width: 12,
                              height: 12,
                              decoration: BoxDecoration(
                                color: calendarColors[b.key],
                                borderRadius: BorderRadius.circular(4),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(child: Text(b.label, style: font(12.5, w7, Palette.slate))),
                            Text(money(b.count), style: display(14, w8, Palette.ink)),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
          if (rate != null) ...[
            const SizedBox(height: 14),
            Row(
              children: [
                Text('نسبة التسليم', style: font(12.5, w7, Palette.slate)),
                const Spacer(),
                Text('$rate٪', style: display(15, w8, Palette.ok)),
              ],
            ),
            const SizedBox(height: 6),
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(
                value: rate / 100,
                minHeight: 8,
                color: Palette.ok,
                backgroundColor: Palette.returnRed.withValues(alpha: .18),
              ),
            ),
            const SizedBox(height: 4),
            Text('من الواصل والراجع — وما زال في الطريق لا يُحسب', style: font(11, w5, Palette.muted)),
          ],
        ],
      ),
    );
  }

  Widget _bars(CalendarData d) {
    final peak = d.days.fold<int>(1, (m, day) => math.max(m, day.total));
    final dense = d.days.length > 14;
    return WhiteCard(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('يوماً بيوم', style: display(15, w8, Palette.ink)),
          const SizedBox(height: 10),
          SizedBox(
            height: 140,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                for (final day in d.days)
                  Expanded(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: [
                        if (!dense && day.total > 0) Text(money(day.total), style: font(10, w7, Palette.slate)),
                        _StackBar(day: day, height: 104 * day.total / peak),
                        const SizedBox(height: 4),
                        Text(
                          dense && day.date.day % 5 != 0 ? '' : '${day.date.day}',
                          style: font(10, w6, Palette.muted),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 10),
          _Legend(buckets: d.buckets),
        ],
      ),
    );
  }

  Widget _byUser(CalendarData d) => WhiteCard(
    padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('من أدخلها', style: display(15, w8, Palette.ink)),
        const SizedBox(height: 8),
        for (final (name, count) in d.byUser)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(
              children: [
                Expanded(child: Text(name, style: font(13, w7, Palette.ink))),
                Text(money(count), style: display(14, w8, brand.deep)),
              ],
            ),
          ),
      ],
    ),
  );
}

class _Donut extends CustomPainter {
  const _Donut(this.buckets, this.total);

  final List<StateTile> buckets;
  final int total;

  @override
  void paint(Canvas canvas, Size size) {
    const stroke = 16.0;
    final ring = (Offset.zero & size).deflate(stroke / 2);
    final track = Paint()
      ..color = Palette.line
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    canvas.drawArc(ring, 0, math.pi * 2, false, track);
    final sum = buckets.fold<int>(0, (a, b) => a + b.count);
    if (sum == 0) return;
    // فاصلٌ صغير بين الخانات بلون الخلفية
    const gap = .03;
    var start = -math.pi / 2;
    for (final b in buckets) {
      if (b.count == 0) continue;
      final sweep = b.count / sum * math.pi * 2;
      canvas.drawArc(
        ring,
        start + (sweep > gap * 2 ? gap / 2 : 0),
        sweep > gap * 2 ? sweep - gap : sweep,
        false,
        Paint()
          ..color = calendarColors[b.key] ?? Palette.muted
          ..style = PaintingStyle.stroke
          ..strokeWidth = stroke,
      );
      start += sweep;
    }
  }

  @override
  bool shouldRepaint(covariant _Donut old) => old.total != total || old.buckets != buckets;
}
