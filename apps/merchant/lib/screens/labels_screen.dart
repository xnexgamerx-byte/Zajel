import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:pdf/pdf.dart';
import 'package:printing/printing.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/art.dart';
import '../widgets/design.dart';
import '../widgets/page.dart';
import '../widgets/rows.dart';
import 'shipment_screen.dart';

Future<void> openLabels(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => LabelsScreen(brand: brand)));

/// «الطابعة» (docs/plan/63، 79، 123): طلباته الجديدة التي لم يستلمها مندوبٌ بعد، في خانتين — «للطباعة» ما لم يُطبع
/// وصله، و«مطبوعة» ما طُبع وينتظر المندوب. يحدّد منها عدداً، ثم يطبعها من هاتفه، أو ينزّلها ملفّ PDF يشاركه، أو
/// يحذفها بعد تأكيد.
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
  List<LabelRow> get _rows => data?.shipments ?? const [];
  List<LabelRow> get _picked => [
    for (final s in _rows)
      if (chosen.contains(s.id)) s,
  ];

  /// «حذف» لمن يملكه وحده (docs/plan/123): ميزة تعديل التاجر وصلاحية الإدخال، كما يقول النظام لكلّ شحنة
  bool get _canDelete => _rows.any((s) => s.deletable);

  @override
  void initState() {
    super.initState();
    _load();
    // حُذفت شحنةٌ جديدة من تعديلها أو من هنا (docs/plan/117، 123): لا تبقى في الطابعة
    shipmentsChanged.addListener(_load);
  }

  @override
  void dispose() {
    shipmentsChanged.removeListener(_load);
    super.dispose();
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
      final d = data;
      data = d == null
          ? null
          : LabelsPage(shipments: const [], sizes: d.sizes, state: s, toPrint: d.toPrint, printed: d.printed);
      chosen.clear();
    });
    _load();
  }

  /// العدد المحدّد: الأوّل فالأوّل من القائمة
  void _setN(int n) => setState(() {
    chosen
      ..clear()
      ..addAll(_rows.take(n).map((s) => s.id));
  });

  Future<void> _print() async {
    final d = data!;
    final ids = [for (final s in _picked) s.id];
    final picked = await showSheet<(String, String)>(
      context,
      _SizeSheet(brand: brand, sizes: d.sizes, ids: ids, download: false),
    );
    if (picked == null || !mounted) return;
    if (AppConfig.demo) {
      toast(context, 'في النسخة التجريبية لا تُطبع الوصولات.');
      return;
    }
    final (url, size) = picked;
    setState(() => printing = true);
    try {
      final Uint8List pdf;
      try {
        pdf = await labelsPdf(url, size);
      } catch (_) {
        // هاتفٌ لا يحوّلها: تُفتح الصفحة نفسها داخل التطبيق، وفيها زرّ الطباعة
        if (!await launchUrl(Uri.parse(url), mode: LaunchMode.inAppBrowserView) && mounted) {
          toast(context, 'تعذّرت الطباعة على هذا الجهاز.', bad: true);
        }
        return;
      }
      await Printing.layoutPdf(name: 'وصولات ${ids.length}', format: labelFormat(size), onLayout: (_) async => pdf);
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

  Future<void> _download() async {
    final d = data!;
    await showSheet<void>(
      context,
      _SizeSheet(brand: brand, sizes: d.sizes, ids: [for (final s in _picked) s.id], download: true),
    );
    // تجهيز الملف يفتح رابط الوصولات، فتصير «مطبوعة» في النظام
    if (mounted) _load();
  }

  Future<void> _delete() async {
    final done = await showSheet<String>(context, _DeleteSheet(brand: brand, rows: _picked));
    if (done == null || !mounted) return;
    toast(context, done);
    // تُعاد الطابعة والشحنات والرئيسية بما بقي
    shipmentsChanged.value++;
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            _head(),
            Padding(padding: const EdgeInsets.fromLTRB(16, 8, 16, 0), child: _tabs(d)),
            Expanded(
              child: d == null || (d.shipments.isEmpty && error != null)
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : GridBackdrop(
                      child: RefreshIndicator(
                        color: brand.main,
                        onRefresh: _load,
                        child: ListView(
                          padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
                          children: [
                            if (d.shipments.isNotEmpty) ...[_stepper(), const SizedBox(height: 10)],
                            if (d.shipments.isEmpty) _empty(),
                            for (final (i, s) in d.shipments.indexed) ...[
                              Rise(key: ValueKey('${state}_${s.id}'), delay: 40 + math.min(i, 8) * 45, child: _row(s)),
                              const SizedBox(height: 10),
                            ],
                          ],
                        ),
                      ),
                    ),
            ),
            if (d != null && d.shipments.isNotEmpty) _bar(),
          ],
        ),
      ),
    );
  }

  Widget _head() => Padding(
    padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
    child: Row(
      children: [
        SquareButton(tip: 'رجوع', onTap: () => Navigator.of(context).maybePop(), child: const BackArrow()),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('الطابعة', style: kf(24, Palette.ink, bold: true)),
              Text('وصولات الطلبات الجديدة', style: kf(12, Palette.muted)),
            ],
          ),
        ),
      ],
    ),
  );

  /// الخانتان: «للطباعة» و«مطبوعة» بعدد كلٍّ، والمختارة بيضاء تنزلق إليها
  Widget _tabs(LabelsPage? d) {
    final tabs = [('to_print', 'للطباعة', d?.toPrint), ('printed', 'مطبوعة', d?.printed)];
    final at = _printed ? 1 : 0;
    return Container(
      height: 52,
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: const Color(0xFFEAE3E8), borderRadius: BorderRadius.circular(16)),
      child: LayoutBuilder(
        builder: (context, box) {
          final w = box.maxWidth / 2;
          return Stack(
            children: [
              AnimatedPositionedDirectional(
                duration: const Duration(milliseconds: 420),
                curve: const Cubic(.2, .8, .2, 1),
                start: at * w,
                top: 0,
                bottom: 0,
                width: w,
                child: Container(
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    boxShadow: const [BoxShadow(color: Color(0x1F1C1416), blurRadius: 8, offset: Offset(0, 2))],
                  ),
                ),
              ),
              Row(
                children: [
                  for (final (key, label, n) in tabs)
                    Expanded(
                      child: Semantics(
                        selected: state == key,
                        button: true,
                        child: InkWell(
                          borderRadius: BorderRadius.circular(12),
                          onTap: () => _show(key),
                          child: Center(
                            child: Text.rich(
                              TextSpan(
                                children: [
                                  TextSpan(text: label),
                                  if (n != null) TextSpan(text: ' $n', style: kf(14, Palette.slate)),
                                ],
                              ),
                              style: kf(14, state == key ? Palette.ink : Palette.slate, bold: true),
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ],
          );
        },
      ),
    );
  }

  /// «حدّد العدد»: ناقصٌ وزائدٌ بينهما العدد، و«الكل» أو «ألغِ الكل»
  Widget _stepper() {
    final n = chosen.length;
    final all = _rows.length;
    Widget step(String sign, String tip, VoidCallback? onTap) => Semantics(
      button: true,
      label: tip,
      excludeSemantics: true,
      onTap: onTap,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          width: 44,
          height: 44,
          child: Center(child: Text(sign, style: kf(24, onTap == null ? Palette.muted : Palette.ink, height: 1))),
        ),
      ),
    );
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 12, 12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), boxShadow: cardShadow),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('حدّد العدد', style: kf(14, Palette.ink, bold: true)),
                Text(n == 0 ? 'لا شيء محدّد من $all' : 'المحدّد $n من $all', style: kf(12, Palette.muted)),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 2),
            decoration: BoxDecoration(
              color: const Color(0xFFFAF9F9),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Palette.line),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                step('−', 'أقلّ', n > 0 ? () => _setN(n - 1) : null),
                SizedBox(
                  width: 28,
                  child: Text('$n', textAlign: TextAlign.center, style: kf(16, Palette.ink, bold: true)),
                ),
                step('+', 'أكثر', n < all ? () => _setN(n + 1) : null),
              ],
            ),
          ),
          const SizedBox(width: 8),
          InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: () => _setN(n == all ? 0 : all),
            child: Container(
              height: 44,
              padding: const EdgeInsets.symmetric(horizontal: 14),
              alignment: Alignment.center,
              decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(14)),
              child: Text(n == all ? 'ألغِ الكل' : 'الكل', style: kf(14, brand.main, bold: true)),
            ),
          ),
        ],
      ),
    );
  }

  Widget _empty() => Padding(
    padding: const EdgeInsets.fromLTRB(16, 32, 16, 16),
    child: Column(
      children: [
        Art('printer', brand: brand, width: 100, height: 87),
        const SizedBox(height: 12),
        Text(
          _printed ? 'لا وصولات مطبوعة تنتظر المندوب' : 'لا وصولات تنتظر الطباعة',
          textAlign: TextAlign.center,
          style: kf(14, Palette.ink, bold: true),
        ),
        const SizedBox(height: 4),
        Text(
          _printed
              ? 'ما تطبع وصله يبقى هنا حتى يستلمه المندوب، فينتقل إلى «قيد التوصيل».'
              : 'كلّ طلبٍ تُدخله يظهر هنا لتطبع وصله وتلصقه على الطرد.',
          textAlign: TextAlign.center,
          style: kf(12, Palette.slate),
        ),
      ],
    ),
  );

  /// صفٌّ يُحدَّد بلمسة، ويُفتح بلمسةٍ مطوّلة
  Widget _row(LabelRow s) {
    final on = chosen.contains(s.id);
    final second = [
      if (s.place.isNotEmpty) s.place,
      if (_printed && s.printedAt != null) 'طُبع ${when(s.printedAt)}',
    ].join(' · ');
    return Semantics(
      checked: on,
      onLongPressHint: 'افتح الشحنة',
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: () => setState(() => on ? chosen.remove(s.id) : chosen.add(s.id)),
          onLongPress: () => openShipment(context, brand, s.id),
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 250),
            constraints: const BoxConstraints(minHeight: 64),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: on ? brand.soft : Colors.white,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: on ? brand.main : Palette.line, width: 1.5),
            ),
            child: Row(
              children: [
                AnimatedContainer(
                  duration: const Duration(milliseconds: 250),
                  width: 28,
                  height: 28,
                  decoration: BoxDecoration(
                    color: on ? brand.main : Colors.white,
                    borderRadius: BorderRadius.circular(9),
                    border: Border.all(color: on ? brand.main : const Color(0xFFB9AFB4), width: 2),
                  ),
                  child: AnimatedOpacity(
                    duration: const Duration(milliseconds: 200),
                    opacity: on ? 1 : 0,
                    child: const Icon(Icons.check_rounded, size: 16, color: Colors.white),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        s.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: kf(14, Palette.ink, bold: true),
                      ),
                      if (second.isNotEmpty)
                        Text(second, maxLines: 1, overflow: TextOverflow.ellipsis, style: kf(12, Palette.slate)),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(money(s.amount), style: kf(14, Palette.ink, bold: true)),
                    Text('وصل \u2066${s.number}\u2069', style: kf(12, Palette.slate)),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// أسفل الشاشة: «اطبع N» عريضاً، وبجانبه «تنزيل» و«حذف» — كلّها بالمحدّد
  Widget _bar() {
    final n = chosen.length;
    final on = n > 0 && !printing;
    final canDelete = _canDelete;
    final deleteOn = on && _picked.every((s) => s.deletable);
    Widget square(String label, String tip, IconData icon, Color bg, Color ink, VoidCallback? onTap) => Semantics(
      button: true,
      label: tip,
      excludeSemantics: true,
      onTap: onTap,
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 300),
          width: 64,
          height: 56,
          decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(16)),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, size: 18, color: ink),
              const SizedBox(height: 2),
              Text(label, style: kf(12, ink, bold: true, height: 1.3)),
            ],
          ),
        ),
      ),
    );
    return Container(
      padding: EdgeInsets.fromLTRB(16, 12, 16, 12 + math.max(8, MediaQuery.paddingOf(context).bottom)),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Palette.line)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Semantics(
              button: true,
              enabled: on,
              child: InkWell(
                borderRadius: BorderRadius.circular(16),
                onTap: on ? _print : null,
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 300),
                  height: 56,
                  decoration: BoxDecoration(
                    color: on ? brand.main : Palette.line,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (printing)
                        const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                        )
                      else
                        Icon(Icons.print_outlined, size: 18, color: on ? Colors.white : Palette.slate),
                      const SizedBox(width: 8),
                      Text(
                        '${_printed ? 'أعد طباعة' : 'اطبع'}${n > 0 ? ' $n' : ''}',
                        style: kf(16, on || printing ? Colors.white : Palette.slate, bold: true),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(width: 8),
          square(
            'تنزيل',
            n > 0 ? 'نزّل ${wcount(n)} ملفّ PDF' : 'نزّل المحدّد',
            Icons.download_rounded,
            on ? brand.soft : Palette.bg,
            on ? brand.main : Palette.slate,
            on ? _download : null,
          ),
          if (canDelete) ...[
            const SizedBox(width: 8),
            square(
              'حذف',
              n > 0 ? 'احذف ${shipmentCount(n)}' : 'احذف المحدّد',
              Icons.delete_outline_rounded,
              deleteOn ? Tone.red.tint : Palette.bg,
              deleteOn ? Tone.red.ink : Palette.slate,
              deleteOn ? _delete : null,
            ),
          ],
        ],
      ),
    );
  }
}

/// «وصلان» و«3 وصولات» بتمييزها العربي
String wcount(int n) => switch (n) {
  0 => 'لا وصولات',
  1 => 'وصل واحد',
  2 => 'وصلان',
  >= 3 && <= 10 => '$n وصولات',
  _ => '$n وصلاً',
};

/// «شحنتان» و«3 شحنات»
String shipmentCount(int n) => switch (n) {
  0 => 'لا شحنات',
  1 => 'شحنة واحدة',
  2 => 'شحنتان',
  >= 3 && <= 10 => '$n شحنات',
  _ => '$n شحنة',
};

/// مقاس الصفحة بمقاس الملصق أو الورقة، بلا هوامش: الصفحة نفسها تضبط مكان كلّ شيء
PdfPageFormat labelFormat(String size) => switch (size) {
  '80x120' => const PdfPageFormat(80 * PdfPageFormat.mm, 120 * PdfPageFormat.mm),
  '100x100' => const PdfPageFormat(100 * PdfPageFormat.mm, 100 * PdfPageFormat.mm),
  'a5' => PdfPageFormat.a5,
  'a4one' || 'a4' => PdfPageFormat.a4,
  'a3' => PdfPageFormat.a3,
  _ => const PdfPageFormat(100 * PdfPageFormat.mm, 150 * PdfPageFormat.mm),
};

/// صفحة الوصولات نفسها التي يطبعها الموقع، تُحوَّل في الهاتف إلى PDF بمقاسها — وفتحها يجعلها «مطبوعة»
Future<Uint8List> labelsPdf(String url, String size) async {
  final html = await Api.instance.labelsPage(url);
  // ignore: deprecated_member_use
  return Printing.convertHtml(html: html, baseUrl: url, format: labelFormat(size));
}

/// المقاس، ثم الطباعة أو الملف: ملصقات الطابعة الحرارية ثلاثةٌ في صف، والورق العادي ثلاثٌ تحتها — كلٌّ مرسومٌ بنسبته
class _SizeSheet extends StatefulWidget {
  const _SizeSheet({required this.brand, required this.sizes, required this.ids, required this.download});

  final Brand brand;
  final List<LabelSize> sizes;
  final List<int> ids;

  /// ملفٌّ يُشارَك بدل الطباعة
  final bool download;

  @override
  State<_SizeSheet> createState() => _SizeSheetState();
}

class _SizeSheetState extends State<_SizeSheet> {
  late String size = widget.sizes.first.key;

  /// 0 يختار، 1 يُجهَّز الملف، 2 جاهز
  int step = 0;
  bool busy = false;
  String? error;
  Uint8List? pdf;

  Brand get brand => widget.brand;
  int get n => widget.ids.length;
  LabelSize get picked => widget.sizes.firstWhere((s) => s.key == size, orElse: () => widget.sizes.first);

  /// «ملصق 10×10 سم» أو «ورقة A4»
  String get sizeName => picked.paper ? 'ورقة ${picked.name}' : 'ملصق \u2066${picked.name}\u2069 سم';
  String get fileName => 'وصولات \u2066$n.pdf\u2069';
  String get pages => switch (n) {
    1 => 'صفحة واحدة',
    2 => 'صفحتان',
    <= 10 => '$n صفحات',
    _ => '$n صفحة',
  };

  Future<void> _print() async {
    setState(() => (busy = true, error = null));
    try {
      final url = await Api.instance.labelsLink(widget.ids, size);
      if (mounted) Navigator.of(context).pop((url, size));
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  Future<void> _make() async {
    setState(() => (step = 1, error = null));
    if (AppConfig.demo) {
      // نسخة العرض تُري الخطوات بلا ملف
      await Future<void>.delayed(const Duration(milliseconds: 1200));
      if (mounted) setState(() => step = 2);
      return;
    }
    String? url;
    try {
      url = await Api.instance.labelsLink(widget.ids, size);
      final bytes = await labelsPdf(url, size);
      if (mounted) setState(() => (pdf = bytes, step = 2));
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, step = 0));
    } catch (_) {
      if (!mounted) return;
      // هاتفٌ لا يحوّلها: تُفتح الصفحة نفسها، ومنها يحفظها PDF
      if (url != null && await launchUrl(Uri.parse(url), mode: LaunchMode.inAppBrowserView)) {
        if (mounted) Navigator.of(context).pop();
        return;
      }
      if (mounted) setState(() => (error = 'تعذّر تجهيز الملف على هذا الجهاز.', step = 0));
    }
  }

  Future<void> _share() async {
    final bytes = pdf;
    if (bytes == null) {
      toast(context, 'في النسخة التجريبية لا يُنشأ الملف.');
      Navigator.of(context).pop();
      return;
    }
    await Printing.sharePdf(bytes: bytes, filename: 'وصولات $n.pdf');
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final dl = widget.download;
    final labels = [
      for (final s in widget.sizes)
        if (!s.paper) s,
    ];
    final papers = [
      for (final s in widget.sizes)
        if (s.paper) s,
    ];
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SheetHandle(),
              const SizedBox(height: 12),
              Row(
                children: [
                  Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(16)),
                    child: Icon(dl ? Icons.download_rounded : Icons.print_outlined, size: 21, color: brand.main),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(dl ? 'نزّلها ملفّاً' : 'اطبع الوصولات', style: kf(16, Palette.ink, bold: true)),
                        Text(
                          dl
                              ? '${wcount(n)} في ملف PDF، تحفظه وترسله لمن يطبعه'
                              : '${wcount(n)}، من هاتفك إلى طابعتك مباشرة',
                          style: kf(12, Palette.slate),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              if (step == 0) ...[
                Text('اختر المقاس', style: kf(14, Palette.ink, bold: true)),
                if (labels.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text('ملصق الطابعة الحرارية', style: kf(12, Palette.slate)),
                  const SizedBox(height: 8),
                  _grid(labels, 0),
                ],
                if (papers.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text('أو ورقٌ عادي، وصلٌ يملأ الورقة', style: kf(12, Palette.slate)),
                  const SizedBox(height: 8),
                  _grid(papers, labels.length),
                ],
              ],
              if (step == 1) _file(ready: false),
              if (step == 2) _file(ready: true),
              if (error != null) ErrorNote(error!),
              const SizedBox(height: 16),
              if (!dl)
                SheetButton(
                  brand: brand,
                  label: 'اطبع ${wcount(n)}، $sizeName',
                  icon: Icons.print_outlined,
                  busy: busy,
                  onTap: _print,
                )
              else if (step == 0)
                SheetButton(brand: brand, label: 'جهّز الملف، $sizeName', icon: Icons.download_rounded, onTap: _make)
              else if (step == 2)
                Row(
                  children: [
                    Expanded(
                      child: SheetButton(
                        brand: brand,
                        label: 'شارك الملف',
                        icon: Icons.ios_share_rounded,
                        onTap: _share,
                      ),
                    ),
                    const SizedBox(width: 10),
                    SizedBox(
                      width: 96,
                      child: SheetButton(
                        brand: brand,
                        label: 'تم',
                        outlined: true,
                        onTap: () => Navigator.of(context).pop(),
                      ),
                    ),
                  ],
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _grid(List<LabelSize> list, int from) => GridView(
    shrinkWrap: true,
    padding: EdgeInsets.zero,
    physics: const NeverScrollableScrollPhysics(),
    gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
      crossAxisCount: 3,
      mainAxisSpacing: 8,
      crossAxisSpacing: 8,
      mainAxisExtent: 112,
    ),
    children: [for (final (i, s) in list.indexed) Rise(delay: 120 + (from + i) * 40, child: _tile(s))],
  );

  Widget _tile(LabelSize s) {
    final on = s.key == size;
    // الملصقات بمقياسٍ واحد، والورق بمقياسٍ واحد: يُرى الفرق بينها كما هو
    final k = s.paper ? 52 / 420 : 52 / 150;
    return Semantics(
      inMutuallyExclusiveGroup: true,
      checked: on,
      button: true,
      label: '${s.paper ? 'ورقة' : 'ملصق'} ${s.name}${s.paper ? '' : ' سم'}، ${s.kind}',
      excludeSemantics: true,
      onTap: () => setState(() => size = s.key),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: () => setState(() => size = s.key),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 250),
          padding: const EdgeInsets.fromLTRB(6, 10, 6, 8),
          decoration: BoxDecoration(
            color: on ? brand.soft : Colors.white,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: on ? brand.main : Palette.line, width: 1.5),
          ),
          child: Column(
            children: [
              Expanded(
                child: Center(
                  child: _Shape(
                    width: (s.width > 0 ? s.width : 100) * k,
                    height: (s.height > 0 ? s.height : 100) * k,
                    paper: s.paper,
                    line: on ? brand.main : const Color(0xFFCFC6CA),
                  ),
                ),
              ),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(text: '\u2066${s.name}\u2069'),
                    if (!s.paper) const TextSpan(text: ' سم'),
                  ],
                ),
                style: kf(14, on ? brand.main : Palette.ink, bold: true),
              ),
              Text(s.kind, style: kf(12, Palette.slate, height: 1.3)),
            ],
          ),
        ),
      ),
    );
  }

  /// الملف: يُجهَّز بشريطٍ يمتلئ، ثم جاهزٌ بعلامة
  Widget _file({required bool ready}) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
    decoration: BoxDecoration(color: ready ? Tone.green.tint : Palette.bg, borderRadius: BorderRadius.circular(18)),
    child: Row(
      children: [
        CustomPaint(
          size: const Size(40, 48),
          painter: _PdfIcon(edge: ready ? const Color(0xFFBFDCCB) : const Color(0xFFDCD4D8)),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(fileName, style: kf(14, Palette.ink, bold: true)),
              if (!ready) ...[
                const SizedBox(height: 6),
                ClipRRect(
                  borderRadius: BorderRadius.circular(3),
                  child: LinearProgressIndicator(
                    minHeight: 6,
                    color: brand.main,
                    backgroundColor: const Color(0xFFE4DDE0),
                  ),
                ),
                const SizedBox(height: 6),
                Text('يُجهَّز الملف، $pages', style: kf(12, Palette.slate)),
              ] else ...[
                Text('$sizeName، وصلٌ في كلّ صفحة', style: kf(12, const Color(0xFF3D3337))),
                Text('جاهز، وعُلّمت وصولاته مطبوعة', style: kf(12, Tone.green.ink, bold: true)),
              ],
            ],
          ),
        ),
        if (ready) ...[
          const SizedBox(width: 8),
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(color: Tone.green.ink, shape: BoxShape.circle),
            child: const Icon(Icons.check_rounded, size: 18, color: Colors.white),
          ),
        ],
      ],
    ),
  );
}

/// الملصق أو الورقة بنسبتها: باركودٌ في أعلاها، ورمزٌ في أسفلها، وسطران للملصق
class _Shape extends StatelessWidget {
  const _Shape({required this.width, required this.height, required this.paper, required this.line});

  final double width;
  final double height;
  final bool paper;
  final Color line;

  @override
  Widget build(BuildContext context) => AnimatedContainer(
    duration: const Duration(milliseconds: 250),
    width: width,
    height: height,
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(paper ? 2 : 3),
      border: Border.all(color: line, width: 1.5),
      boxShadow: const [BoxShadow(color: Color(0x4D1C1416), blurRadius: 12, offset: Offset(0, 6), spreadRadius: -8)],
    ),
    child: ClipRect(
      child: CustomPaint(painter: _LabelMarks(paper: paper)),
    ),
  );
}

class _LabelMarks extends CustomPainter {
  const _LabelMarks({required this.paper});

  final bool paper;

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width;
    final h = size.height;
    final ink = Paint()..color = Palette.ink;
    // الباركود: خطوطٌ رفيعة في أعلاه
    final top = h * .12;
    final barH = h * (paper ? .16 : .20);
    for (var x = w * .14; x < w * .86; x += 2.5) {
      canvas.drawRect(Rect.fromLTWH(x, top, 1, barH), ink);
    }
    // الرمز: مربّعٌ في أسفله يساراً
    final q = w * (paper ? .26 : .28);
    canvas.drawRect(Rect.fromLTWH(w * .12, h - h * .10 - q, q, q), Paint()..color = Palette.ink.withValues(alpha: .7));
    if (!paper) {
      final grey = Paint()..color = const Color(0xFFCFC6CA);
      final lw = w * .38;
      canvas.drawRect(Rect.fromLTWH(w - w * .12 - lw, h - h * .14 - 2, lw, 2), grey);
      canvas.drawRect(Rect.fromLTWH(w - w * .12 - lw, h - h * .14 - 7, lw, 2), grey);
    }
  }

  @override
  bool shouldRepaint(_LabelMarks old) => old.paper != paper;
}

/// أيقونة ملفّ PDF: ورقةٌ مطويّة الزاوية وعليها شارةٌ حمراء
class _PdfIcon extends CustomPainter {
  const _PdfIcon({required this.edge});

  final Color edge;

  @override
  void paint(Canvas canvas, Size size) {
    final page = Path()
      ..moveTo(7, 1)
      ..lineTo(26, 1)
      ..lineTo(37, 12)
      ..lineTo(37, 43)
      ..arcToPoint(const Offset(34, 46), radius: const Radius.circular(3))
      ..lineTo(7, 46)
      ..arcToPoint(const Offset(4, 43), radius: const Radius.circular(3))
      ..lineTo(4, 4)
      ..arcToPoint(const Offset(7, 1), radius: const Radius.circular(3))
      ..close();
    canvas.drawPath(page, Paint()..color = Colors.white);
    final stroke = Paint()
      ..color = edge
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    canvas.drawPath(page, stroke);
    canvas.drawPath(
      Path()
        ..moveTo(26, 1)
        ..lineTo(26, 9)
        ..arcToPoint(const Offset(29, 12), radius: const Radius.circular(3), clockwise: false)
        ..lineTo(37, 12),
      stroke,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(const Rect.fromLTWH(0, 26, 28, 13), const Radius.circular(3)),
      Paint()..color = Tone.red.ink,
    );
    final text = TextPainter(
      text: const TextSpan(
        text: 'PDF',
        style: TextStyle(fontSize: 8.5, fontWeight: FontWeight.w700, color: Colors.white, fontFamily: 'Cairo'),
      ),
      textDirection: TextDirection.ltr,
    )..layout();
    text.paint(canvas, Offset(14 - text.width / 2, 32.5 - text.height / 2));
  }

  @override
  bool shouldRepaint(_PdfIcon old) => old.edge != edge;
}

/// «حذف N؟»: المحدّد بأسمائه، وسببٌ اختياريّ يراه المكتب — ثم تُحذف واحدةً واحدة
class _DeleteSheet extends StatefulWidget {
  const _DeleteSheet({required this.brand, required this.rows});

  final Brand brand;
  final List<LabelRow> rows;

  @override
  State<_DeleteSheet> createState() => _DeleteSheetState();
}

class _DeleteSheetState extends State<_DeleteSheet> {
  final reason = TextEditingController();
  bool busy = false;
  String? error;

  @override
  void dispose() {
    reason.dispose();
    super.dispose();
  }

  Future<void> _go() async {
    setState(() => (busy = true, error = null));
    var done = 0;
    for (final s in widget.rows) {
      try {
        await Api.instance.deleteShipment(s.id, reason: reason.text);
        done++;
      } on ApiError catch (e) {
        if (!mounted) return;
        // ما حُذف قبلها حُذف: تُعاد القائمة ويُقال ما لم يُحذف
        if (done > 0) shipmentsChanged.value++;
        setState(() => (error = done > 0 ? 'حُذفت ${shipmentCount(done)}، ثم: ${e.message}' : e.message, busy = false));
        return;
      }
    }
    if (mounted) Navigator.of(context).pop('حُذفت ${shipmentCount(done)}');
  }

  @override
  Widget build(BuildContext context) {
    final rows = widget.rows;
    final n = rows.length;
    final shown = rows.take(3).toList();
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 12, 16, 24 + MediaQuery.viewInsetsOf(context).bottom),
      child: SafeArea(
        top: false,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SheetHandle(),
              const SizedBox(height: 12),
              Row(
                children: [
                  Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(color: Tone.red.tint, borderRadius: BorderRadius.circular(16)),
                    child: Icon(Icons.delete_outline_rounded, size: 21, color: Tone.red.ink),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('حذف ${shipmentCount(n)}؟', style: kf(16, Palette.ink, bold: true)),
                        Text('لا تُستلم ولا تُوصل بعدها، ولا يمكن التراجع.', style: kf(12, Palette.slate)),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Container(
                clipBehavior: Clip.antiAlias,
                decoration: BoxDecoration(color: Palette.bg, borderRadius: BorderRadius.circular(16)),
                child: Column(
                  children: [
                    for (final (i, s) in shown.indexed)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        decoration: BoxDecoration(
                          border: i == 0 ? null : const Border(top: BorderSide(color: Color(0xFFE4DDE0))),
                        ),
                        child: Row(
                          children: [
                            Expanded(
                              child: Text(
                                s.name,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: kf(14, Palette.ink, bold: true),
                              ),
                            ),
                            Text('وصل \u2066${s.number}\u2069', style: kf(12, Palette.slate)),
                          ],
                        ),
                      ),
                    if (n > 3)
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        decoration: const BoxDecoration(
                          border: Border(top: BorderSide(color: Color(0xFFE4DDE0))),
                        ),
                        child: Text('و${n - 3} أخرى', style: kf(14, Palette.ink, bold: true)),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              Text('السبب (اختياري، يراه المكتب)', style: kf(12, Palette.slate)),
              const SizedBox(height: 6),
              InputBox(controller: reason, hint: 'مثلاً: ألغى الزبون', maxLength: 255),
              if (error != null) ErrorNote(error!),
              const SizedBox(height: 16),
              Row(
                children: [
                  Expanded(
                    child: Semantics(
                      button: true,
                      child: InkWell(
                        borderRadius: BorderRadius.circular(16),
                        onTap: busy ? null : _go,
                        child: Container(
                          height: 52,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(color: Tone.red.ink, borderRadius: BorderRadius.circular(16)),
                          child: busy
                              ? const SizedBox(
                                  width: 20,
                                  height: 20,
                                  child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                                )
                              : Text(
                                  n > 1 ? 'نعم، احذفها كلها' : 'نعم، احذفها',
                                  style: kf(16, Colors.white, bold: true),
                                ),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  SizedBox(
                    width: 110,
                    child: SheetButton(
                      brand: widget.brand,
                      label: 'تراجع',
                      outlined: true,
                      onTap: busy ? null : () => Navigator.of(context).pop(),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
