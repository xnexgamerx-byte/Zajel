import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:printing/printing.dart';
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

/// «الطابعة» (docs/plan/63، 79): طلباته الجديدة التي لم يستلمها مندوبٌ بعد، مفروزةً خانتين —
/// «للطباعة» ما لم يُطبع وصله، و«مطبوعة» ما طُبع وينتظر المندوب. يحدّد منها ما يطبع ويختار مقاس
/// طابعته، فيطبعها التطبيق نفسه: طابعة الهاتف، أو ملفّ PDF يحفظه أو يرسله.
class LabelsScreen extends StatefulWidget {
  const LabelsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<LabelsScreen> createState() => _LabelsScreenState();
}

class _LabelsScreenState extends State<LabelsScreen> {
  String state = 'to_print';
  LabelsPage? data;
  String? error;
  bool printing = false;
  final chosen = <int>{};

  Brand get brand => widget.brand;
  bool get _printed => state == 'printed';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final asked = state;
    try {
      final d = await Api.instance.labels(state: asked);
      if (!mounted || asked != state) return;
      setState(() {
        data = d;
        error = null;
        chosen
          ..clear()
          // للطباعة كلّها محدّدةٌ افتراضاً، وإعادة الطباعة يختار منها ما يريد
          ..addAll(_printed ? const <int>[] : d.shipments.map((s) => s.id));
      });
    } on ApiError catch (e) {
      if (mounted && asked == state) setState(() => error = e.message);
    }
  }

  void _show(String s) {
    if (s == state) return;
    setState(() {
      state = s;
      data = data == null
          ? null
          : LabelsPage(
              shipments: const [],
              sizes: data!.sizes,
              state: s,
              toPrint: data!.toPrint,
              printed: data!.printed,
            );
      chosen.clear();
    });
    _load();
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
    final picked = await showSheet<(String, String)>(context, _SizeSheet(brand: brand, sizes: d.sizes, ids: ids));
    if (picked == null || !mounted) return;
    if (AppConfig.demo) {
      toast(context, 'في النسخة التجريبية لا تُطبع الوصولات.');
      return;
    }
    final (url, size) = picked;
    setState(() => printing = true);
    try {
      final html = await Api.instance.labelsPage(url);
      final format = labelFormat(size);
      final Uint8List pdf;
      try {
        // الصفحة نفسها التي يطبعها الموقع، تُحوَّل في الهاتف إلى PDF بمقاس الملصق
        // ignore: deprecated_member_use
        pdf = await Printing.convertHtml(html: html, baseUrl: url, format: format);
      } catch (_) {
        // هاتفٌ لا يحوّلها: تُفتح الصفحة نفسها داخل التطبيق، وفيها زرّ الطباعة
        if (!await launchUrl(Uri.parse(url), mode: LaunchMode.inAppBrowserView) && mounted) {
          toast(context, 'تعذّرت الطباعة على هذا الجهاز.', bad: true);
        }
        return;
      }
      await Printing.layoutPdf(name: 'وصولات ${ids.length}', format: format, onLayout: (_) async => pdf);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } catch (_) {
      if (mounted) toast(context, 'تعذّرت الطباعة على هذا الجهاز.', bad: true);
    } finally {
      if (mounted) {
        setState(() => printing = false);
        // ما فُتح للطباعة صار «مطبوعاً» في النظام: تُعاد الخانتان بعددهما
        _load();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            const PageBar(title: 'الطابعة', subtitle: 'طلباتك الجديدة قبل أن يستلمها المندوب'),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
              child: Row(
                children: [
                  Expanded(
                    child: _box('to_print', 'للطباعة', 'لم يُطبع وصلها', d?.toPrint, Icons.print_rounded, brand.main),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _box('printed', 'مطبوعة', 'تنتظر المندوب', d?.printed, Icons.task_alt_rounded, Palette.ok),
                  ),
                ],
              ),
            ),
            Expanded(
              child: d == null || (d.shipments.isEmpty && error != null)
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : d.shipments.isEmpty
                  ? Empty(
                      icon: _printed ? Icons.inventory_2_outlined : Icons.print_disabled_outlined,
                      title: _printed ? 'لا مطبوعة تنتظر المندوب' : 'لا طلبات تنتظر الطباعة',
                      text: _printed
                          ? 'ما تطبع وصله يبقى هنا حتى يستلمه المندوب، فينتقل إلى «قيد التوصيل».'
                          : 'كلّ طلبٍ تُدخله يظهر هنا لتطبع وصله وتلصقه على الطرد.',
                    )
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 2, 14, 24),
                        children: [
                          Text(
                            _printed
                                ? 'طُبعت وصولها وتنتظر مندوب الاستلام — حدّد ما تريد إعادة طباعته.'
                                : 'اطبع وصولها والصقها على الطرود. ما تطبعه ينتقل إلى «مطبوعة».',
                            style: font(11.5, w6, Palette.slate, height: 1.5),
                          ),
                          const SizedBox(height: 8),
                          WhiteCard(
                            padding: EdgeInsets.zero,
                            child: Material(
                              type: MaterialType.transparency,
                              child: Column(
                                children: [
                                  CheckboxListTile(
                                    value: _all,
                                    activeColor: brand.main,
                                    onChanged: (_) => _toggleAll(),
                                    title: Text('تحديد الكل', style: font(13.5, w8, Palette.ink)),
                                    subtitle: Text(
                                      'المحدّد ${chosen.length} من ${d.shipments.length}',
                                      style: font(11.5, w6, Palette.slate),
                                    ),
                                  ),
                                  for (final s in d.shipments) ...[
                                    const Divider(height: 1, color: Palette.line),
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
                  label: chosen.isEmpty
                      ? (_printed ? 'حدّد ما تعيد طباعته' : 'حدّد ما تطبعه')
                      : '${_printed ? 'أعد طباعة' : 'اطبع'} ${chosen.length} وصل',
                  icon: Icons.print_rounded,
                  busy: printing,
                  onTap: chosen.isEmpty || printing ? null : _print,
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// خانةٌ من الخانتين: أيقونتها ورقمها واسمها، والمختارة بلونها
  Widget _box(String key, String title, String hint, int? count, IconData icon, Color color) {
    final on = state == key;
    return Tap(
      radius: 18,
      shadow: on ? null : cardShadow,
      onTap: () => _show(key),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        decoration: BoxDecoration(
          color: on ? Color.lerp(Colors.white, color, .1) : Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: on ? color : Palette.line, width: on ? 1.6 : 1),
        ),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                color: on ? color : Color.lerp(Colors.white, color, .12),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 19, color: on ? Colors.white : color),
            ),
            const SizedBox(width: 9),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: font(13, w8, Palette.ink, height: 1.25)),
                  Text(hint, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(10.5, w6, Palette.slate)),
                ],
              ),
            ),
            Text(count == null ? '—' : money(count), style: display(18, w8, on ? color : Palette.ink)),
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
    subtitle: Row(
      children: [
        InkWell(
          onTap: () => openShipment(context, brand, s.id),
          child: Text('#${s.number}', textDirection: TextDirection.ltr, style: font(12, w8, brand.main)),
        ),
        const Spacer(),
        if (s.printedAt != null)
          Text('طُبع ${when(s.printedAt)}', style: font(10.5, w6, Palette.ok))
        else if (s.at != null)
          Text(when(s.at), style: font(10.5, w5, Palette.slate)),
      ],
    ),
  );
}

/// مقاس الصفحة بمقاس الملصق، بلا هوامش: الصفحة نفسها تضبط مكان كلّ شيء
PdfPageFormat labelFormat(String size) => switch (size) {
  '80x120' => const PdfPageFormat(80 * PdfPageFormat.mm, 120 * PdfPageFormat.mm),
  '100x100' => const PdfPageFormat(100 * PdfPageFormat.mm, 100 * PdfPageFormat.mm),
  'a4' => PdfPageFormat.a4,
  _ => const PdfPageFormat(100 * PdfPageFormat.mm, 150 * PdfPageFormat.mm),
};

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
      if (mounted) Navigator.of(context).pop((url, size));
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    return SheetFrame(
      title: 'اختر المقاس',
      text: '${widget.ids.length} وصل — تُطبع من التطبيق نفسه: اختر طابعتك، أو احفظها PDF وأرسلها.',
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
