import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

/// «مهامي» (docs/plan/66): ما بيده للتوصيل مجمّعاً بالمحافظة والمنطقة، والراجع الذي يعود به
/// إلى المخزن، وبحثٌ برقم الوصل أو الاسم — كشاشته في الموقع.
class TasksScreen extends StatefulWidget {
  const TasksScreen({super.key, required this.brand, required this.kind, required this.refresh, required this.onOpen});

  final Brand brand;

  /// delivery أو returns — تختارها الرئيسية أيضاً
  final ValueNotifier<String> kind;
  final ValueNotifier<int> refresh;
  final void Function(int id) onOpen;

  @override
  State<TasksScreen> createState() => _TasksScreenState();
}

class _TasksScreenState extends State<TasksScreen> {
  final search = TextEditingController();
  Timer? _debounce;
  TaskList? data;
  String? error;

  /// عدد كلّ خانة على شريحتها، من النظام بلا بحث
  Map<String, int> counts = const {};

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.kind.addListener(_load);
    widget.refresh.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.kind.removeListener(_load);
    widget.refresh.removeListener(_load);
    _debounce?.cancel();
    search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final kind = widget.kind.value;
    try {
      final d = await Api.instance.tasks(kind: kind, q: search.text.trim());
      if (!mounted || kind != widget.kind.value) return;
      setState(() => (data = d, error = null, counts = d.counts.isEmpty ? counts : d.counts));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  void _searchChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _load);
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    final kind = widget.kind.value;
    return SafeArea(
      bottom: false,
      child: RefreshIndicator(
        color: brand.main,
        onRefresh: _load,
        child: ListView(
          // نسخة العرض على الويب بلا شريط حالة: تحت الشريط المرسوم
          padding: EdgeInsets.fromLTRB(16, AppConfig.demo ? 36 : 18, 16, 130),
          children: [
            Row(
              children: [
                const TabBack(),
                Text('مهامي', style: font(22, w8, Palette.ink)),
              ],
            ),
            if (d != null)
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(text: '${d.count} شحنة', style: font(12.5, w6, Palette.slate)),
                    if (d.kind == 'delivery' || d.kind == 'redelivery') ...[
                      TextSpan(text: ' · للتحصيل ', style: font(12.5, w6, Palette.slate)),
                      TextSpan(text: money(d.toCollect), style: font(12.5, w8, Palette.ink)),
                      TextSpan(text: ' د.ع', style: font(12.5, w6, Palette.slate)),
                    ] else
                      TextSpan(
                        text: d.kind == 'postponed'
                            ? ' · بمواعيدها، الأقرب أوّلاً'
                            : ' · الراجع للمخزن، وما ينتظر المعالجة يبقى معك',
                        style: font(12.5, w6, Palette.slate),
                      ),
                  ],
                ),
              ),
            const SizedBox(height: 12),
            _segments(kind, d),
            const SizedBox(height: 10),
            _searchBox(),
            if (d == null)
              Padding(
                padding: const EdgeInsets.only(top: 60),
                child: Waiting(brand: brand, error: error, onRetry: _load),
              )
            else if (d.groups.isEmpty)
              search.text.isNotEmpty
                  ? Empty(
                      icon: Icons.search_off_rounded,
                      title: 'لا نتائج',
                      text: 'لا شحنة بيدك برقم أو اسم «${search.text}».',
                    )
                  : Empty(
                      icon: switch (kind) {
                        'returns' => Icons.assignment_return_outlined,
                        'postponed' => Icons.event_available_rounded,
                        'redelivery' => Icons.replay_rounded,
                        _ => Icons.inventory_2_outlined,
                      },
                      title: switch (kind) {
                        'returns' => 'لا راجع بيدك',
                        'postponed' => 'لا مؤجّلة بيدك',
                        'redelivery' => 'لا إعادة توصيل',
                        _ => 'لا شحنات بيدك الآن',
                      },
                      text: switch (kind) {
                        'returns' => 'ما يرجع معك، وما لم يُسلَّم وينتظر قرار المعالجة، يظهر هنا حتى يُحسم.',
                        'postponed' => 'ما تؤجّله بموعدٍ يظهر هنا، ويذكّرك التطبيق في موعده.',
                        'redelivery' => 'ما يعيده الكول سنتر إليك بعد محاولةٍ فاشلة يظهر هنا.',
                        _ => 'حين يُسلّمك المخزن شحناتٍ للتوصيل تظهر هنا مجمّعةً بالمنطقة.',
                      },
                    )
            else
              for (final g in d.groups) ...[
                Padding(
                  padding: const EdgeInsets.fromLTRB(4, 16, 4, 8),
                  child: Text('${g.title} (${g.items.length})', style: font(13.5, w8, Palette.slate)),
                ),
                WhiteCard(
                  child: Column(
                    children: [
                      for (final (i, row) in g.items.indexed) ...[
                        if (i > 0) const Divider(height: 1, indent: 14, endIndent: 14, color: Palette.line),
                        _row(row),
                      ],
                    ],
                  ),
                ),
              ],
          ],
        ),
      ),
    );
  }

  /// الخانات الأربع (docs/plan/75) بألوان نتيجتها: التوصيل، وإعادته، والمؤجل أصفر، والراجع أحمر
  Widget _segments(String kind, TaskList? d) {
    Widget seg(String value, String label, IconData icon, Color color) {
      final on = kind == value;
      final count = counts[value];
      return Expanded(
        child: Tap(
          radius: 12,
          onTap: on ? null : () => widget.kind.value = value,
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 180),
            height: 58,
            padding: const EdgeInsets.symmetric(horizontal: 2),
            decoration: BoxDecoration(
              color: on ? Colors.white : Colors.transparent,
              borderRadius: BorderRadius.circular(12),
              boxShadow: on ? cardShadow : null,
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(icon, size: 16, color: on ? color : Palette.muted),
                    if (count != null && count > 0) ...[
                      const SizedBox(width: 4),
                      Container(
                        constraints: const BoxConstraints(minWidth: 18),
                        height: 18,
                        padding: const EdgeInsets.symmetric(horizontal: 5),
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: on ? color : Palette.muted.withValues(alpha: .18),
                          borderRadius: BorderRadius.circular(9),
                        ),
                        child: Text('$count', style: font(10.5, w8, on ? Colors.white : Palette.slate, height: 1)),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 3),
                Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(12, on ? w8 : w6, on ? Palette.ink : Palette.slate, height: 1.2),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: Palette.eye, borderRadius: BorderRadius.circular(15)),
      child: Row(
        children: [
          seg('delivery', 'التوصيل', Icons.local_shipping_outlined, brand.main),
          seg('redelivery', 'إعادة توصيل', Icons.replay_rounded, OutcomeColors.delivered),
          seg('postponed', 'المؤجل', Icons.event_repeat_rounded, OutcomeColors.postponed),
          seg('returns', 'الراجع', Icons.assignment_return_outlined, OutcomeColors.returned),
        ],
      ),
    );
  }

  Widget _searchBox() => Container(
    height: 46,
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(13),
      border: Border.all(color: Palette.line),
    ),
    child: TextField(
      controller: search,
      onChanged: _searchChanged,
      textInputAction: TextInputAction.search,
      style: font(13.5, w6, Palette.ink),
      decoration: InputDecoration(
        hintText: 'ابحث برقم الوصل أو الاسم',
        hintStyle: font(13, w5, Palette.muted),
        prefixIcon: const Icon(Icons.search_rounded, color: Palette.muted, size: 21),
        suffixIcon: search.text.isEmpty
            ? null
            : IconButton(
                tooltip: 'مسح البحث',
                icon: const Icon(Icons.close_rounded, size: 18, color: Palette.muted),
                onPressed: () {
                  search.clear();
                  _load();
                },
              ),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.symmetric(vertical: 12),
      ),
    ),
  );

  /// موعد المؤجّلة على سطرها: أحمر إن فات، وأصفر غير ذلك
  Widget _until(DateTime at) {
    final now = DateTime.now();
    final past = DateTime(at.year, at.month, at.day).isBefore(DateTime(now.year, now.month, now.day));
    final color = past ? OutcomeColors.returned : OutcomeColors.postponed;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: OutcomeColors.soft(color), borderRadius: BorderRadius.circular(20)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.alarm_rounded, size: 13, color: OutcomeColors.ink(color)),
          const SizedBox(width: 3),
          Text(appointmentLabel(at), style: font(11, w8, OutcomeColors.ink(color))),
        ],
      ),
    );
  }

  Widget _row(TaskRow r) {
    final waiting = r.ticketStatus == 'open';
    final approved = r.ticketStatus == 'approved';
    return Tap(
      onTap: () => widget.onOpen(r.id),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Number(r.number),
                      if (r.status != 'out_for_delivery' && !r.waiting && r.until == null)
                        Pill(r.statusLabel, color: statusColor(r.status)),
                      if (r.redelivery) const Pill('إعادة توصيل', color: OutcomeColors.delivered),
                      if (r.waiting) const Pill('تنتظر المعالجة', color: OutcomeColors.returned),
                      if (r.until != null) _until(r.until!),
                      if (waiting) const Pill('تذكرة', color: Palette.warn),
                      if (approved) const Pill('جزئي معتمد', color: Palette.ok),
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(r.place, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(12.5, w6, Palette.slate)),
                  if (waiting) Text('ينتظر جواب الكول سنتر على المبلغ', style: font(12, w7, Palette.warn, height: 1.5)),
                  if (r.failure != null) Text('آخر سبب: ${r.failure}', style: font(12, w6, Palette.warn, height: 1.5)),
                  if (r.fragile || r.hasNote) ...[
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 6,
                      children: [if (r.fragile) const Pill('قابل للكسر'), if (r.hasNote) const Pill('ملاحظة')],
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(width: 10),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(money(r.amount), style: font(15, w8, Palette.ink)),
                Text('د.ع', style: font(11, w6, Palette.slate)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
