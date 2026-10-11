import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'shipment_screen.dart';

Future<void> openLabels(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => LabelsScreen(brand: brand)));

/// «طباعة الوصولات» (docs/plan/63): طلباته المدخلة التي لم يستلمها مندوبٌ بعد — كلّها محدّدةٌ افتراضاً،
/// يُلغي منها ما شاء، ثم يختار مقاس طابعته فتُفتح صفحة الطباعة في متصفّح الهاتف.
class LabelsScreen extends StatefulWidget {
  const LabelsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<LabelsScreen> createState() => _LabelsScreenState();
}

class _LabelsScreenState extends State<LabelsScreen> {
  LabelsPage? data;
  String? error;
  final chosen = <int>{};

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.labels();
      if (!mounted) return;
      setState(() {
        data = d;
        error = null;
        chosen
          ..clear()
          ..addAll(d.shipments.map((s) => s.id));
      });
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  bool get _all => data != null && data!.shipments.isNotEmpty && chosen.length == data!.shipments.length;

  void _toggleAll() => setState(() {
    if (_all) {
      chosen.clear();
    } else {
      chosen.addAll(data!.shipments.map((s) => s.id));
    }
  });

  Future<void> _print() async {
    final d = data!;
    final ids = [
      for (final s in d.shipments)
        if (chosen.contains(s.id)) s.id,
    ];
    final url = await showSheet<String>(context, _SizeSheet(brand: brand, sizes: d.sizes, ids: ids));
    if (url == null || !mounted) return;
    if (AppConfig.demo) {
      toast(context, 'في النسخة التجريبية لا تُفتح صفحة الطباعة.');
      return;
    }
    if (!await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication) && mounted) {
      toast(context, 'تعذّر فتح صفحة الطباعة على هذا الجهاز.', bad: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            const PageBar(title: 'طباعة الوصولات', subtitle: 'طلباتك المدخلة التي لم يستلمها المندوب بعد'),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : d.shipments.isEmpty
                  ? const Empty(
                      icon: Icons.print_disabled_outlined,
                      title: 'لا طلبات تنتظر الطباعة',
                      text: 'كلّ طلبٍ تُدخله يظهر هنا حتى يستلمه المندوب.',
                    )
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 24),
                        children: [
                          WhiteCard(
                            padding: EdgeInsets.zero,
                            child: Material(
                              type: MaterialType.transparency,
                              child: CheckboxListTile(
                                value: _all,
                                tristate: false,
                                activeColor: brand.main,
                                onChanged: (_) => _toggleAll(),
                                title: Text('تحديد الكل', style: font(14, w8, Palette.ink)),
                                subtitle: Text(
                                  'المحدّد ${chosen.length} من ${d.shipments.length}',
                                  style: font(12, w6, Palette.slate),
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(height: 10),
                          WhiteCard(
                            padding: EdgeInsets.zero,
                            child: Material(
                              type: MaterialType.transparency,
                              child: Column(
                                children: [
                                  for (final (i, s) in d.shipments.indexed) ...[
                                    if (i > 0) const Divider(height: 1, color: Palette.line),
                                    _row(s),
                                  ],
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
            ),
            if (d != null && d.shipments.isNotEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 6, 14, 12),
                child: SheetButton(
                  brand: brand,
                  label: chosen.isEmpty ? 'حدّد ما تطبعه' : 'اطبع ${chosen.length} وصل',
                  icon: Icons.print_rounded,
                  onTap: chosen.isEmpty ? null : _print,
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _row(LabelRow s) => CheckboxListTile(
    value: chosen.contains(s.id),
    activeColor: brand.main,
    controlAffinity: ListTileControlAffinity.leading,
    onChanged: (on) => setState(() => on == true ? chosen.add(s.id) : chosen.remove(s.id)),
    title: Row(
      children: [
        Expanded(
          child: Text(
            [s.name, s.place].where((t) => t.isNotEmpty).join(' · '),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: font(13, w7, Palette.ink),
          ),
        ),
        Text('${money(s.amount)} د.ع', style: font(12.5, w8, Palette.ink)),
      ],
    ),
    subtitle: InkWell(
      onTap: () => openShipment(context, brand, s.id),
      child: Text('#${s.number}', textDirection: TextDirection.ltr, style: font(12, w8, brand.main)),
    ),
  );
}

/// المقاس: ما تطبعه طابعة التاجر — ملصقٌ حراريّ أو ورقة A4
class _SizeSheet extends StatefulWidget {
  const _SizeSheet({required this.brand, required this.sizes, required this.ids});

  final Brand brand;
  final List<Choice> sizes;
  final List<int> ids;

  @override
  State<_SizeSheet> createState() => _SizeSheetState();
}

class _SizeSheetState extends State<_SizeSheet> {
  late String size = widget.sizes.first.key;
  bool busy = false;
  String? error;

  static const _hints = {
    'a6': 'طابعة ملصقات حرارية — الأشيع',
    '80x120': 'مقاس الوصل المطبوع مسبقاً',
    '100x100': 'ملصقٌ مربّع',
    'a4': 'طابعة ورق عادية: أربعة وصولات في الورقة',
  };

  Future<void> _go() async {
    setState(() => (busy = true, error = null));
    try {
      final url = await Api.instance.labelsLink(widget.ids, size);
      if (mounted) Navigator.of(context).pop(url);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    return SheetFrame(
      title: 'اختر المقاس',
      text: '${widget.ids.length} وصل — تُفتح صفحة الطباعة في متصفّح الهاتف، فاطبعها بطابعتك أو احفظها PDF.',
      children: [
        for (final s in widget.sizes)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Tap(
              radius: 14,
              onTap: () => setState(() => size = s.key),
              child: Container(
                padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
                decoration: BoxDecoration(
                  color: size == s.key ? brand.soft : Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: size == s.key ? brand.main : Palette.line, width: size == s.key ? 1.6 : 1),
                ),
                child: Row(
                  children: [
                    Icon(
                      size == s.key ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                      color: size == s.key ? brand.main : Palette.muted,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(s.label, style: font(14, w8, Palette.ink)),
                          if (_hints[s.key] != null) Text(_hints[s.key]!, style: font(11.5, w5, Palette.slate)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        if (error != null) ErrorNote(error!),
        const SizedBox(height: 8),
        SheetButton(brand: brand, label: 'اطبع', icon: Icons.print_rounded, busy: busy, onTap: _go),
      ],
    );
  }
}
