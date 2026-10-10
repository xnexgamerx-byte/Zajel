import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

Future<void> openImport(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => ImportScreen(brand: brand)));

/// «رفع شحنات من ملف» (docs/plan/57): استيراد البوابة نفسه — يختار ملف Excel من هاتفه فيُعاين
/// (الصحيح، والخاطئ بسببه) ولا يُنشأ شيء، ثم يؤكّد فتُنشأ الشحنات.
class ImportScreen extends StatefulWidget {
  const ImportScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<ImportScreen> createState() => _ImportScreenState();
}

class _ImportScreenState extends State<ImportScreen> {
  ({List<({String label, bool required})> columns, String template})? info;
  ImportPreview? preview;
  String? error;
  bool reading = false;
  bool saving = false;
  String? done;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final i = await Api.instance.importInfo();
      if (mounted) setState(() => (info = i, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _pick() async {
    Uint8List bytes;
    String name;
    if (AppConfig.demo) {
      (bytes, name) = (Uint8List(0), 'demo.xlsx');
    } else {
      final file = await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['xlsx', 'xls', 'csv']);
      if (file == null) return;
      (bytes, name) = (await file.readAsBytes(), file.name);
    }
    setState(() => (reading = true, done = null, preview = null));
    try {
      final p = await Api.instance.previewImport(bytes, name);
      if (mounted) setState(() => preview = p);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => reading = false);
    }
  }

  Future<void> _confirm(ImportPreview p) async {
    setState(() => saving = true);
    try {
      final message = await Api.instance.confirmImport(p.path, skipErrors: p.bad.isNotEmpty);
      if (mounted) setState(() => (done = message, preview = null));
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final i = info;
    final p = preview;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'رفع شحنات من ملف', subtitle: 'ملف Excel بأعمدة القالب — يُعايَن قبل أن يُحفظ'),
            Expanded(
              child: i == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                      children: [
                        if (done != null) ...[_done(done!), const SizedBox(height: 10)],
                        if (p == null) ...[
                          _columns(i),
                          const SizedBox(height: 12),
                          SheetButton(
                            brand: brand,
                            label: 'اختر الملف',
                            icon: Icons.upload_file_rounded,
                            busy: reading,
                            onTap: _pick,
                          ),
                          if (reading)
                            Padding(
                              padding: const EdgeInsets.only(top: 8),
                              child: Text('يقرأ الملف…', style: font(12, w6, Palette.slate)),
                            ),
                        ] else
                          ..._preview(p),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _columns(({List<({String label, bool required})> columns, String template}) i) => WhiteCard(
    padding: const EdgeInsets.all(12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('أعمدة الملف', style: font(13.5, w8, Palette.ink)),
        const SizedBox(height: 2),
        Text('الأعمدة بأسمائها، بأيّ ترتيب. ما عليه * لا بدّ منه.', style: font(11.5, w5, Palette.slate, height: 1.5)),
        const SizedBox(height: 8),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final c in i.columns)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                decoration: BoxDecoration(
                  color: c.required ? brand.soft : const Color(0xFFF2F4F7),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  c.required ? '${c.label} *' : c.label,
                  style: font(11.5, w7, c.required ? brand.main : Palette.ink),
                ),
              ),
          ],
        ),
        const SizedBox(height: 4),
        TextButton.icon(
          onPressed: () => launchUrl(Uri.parse(i.template), mode: LaunchMode.externalApplication),
          icon: Icon(Icons.download_rounded, size: 18, color: brand.main),
          label: Text('نزّل القالب', style: font(12.5, w8, brand.main)),
        ),
      ],
    ),
  );

  List<Widget> _preview(ImportPreview p) => [
    WhiteCard(
      padding: const EdgeInsets.all(12),
      child: Row(
        children: [
          Expanded(child: _count('في الملف', p.total, Palette.ink)),
          Expanded(child: _count('صحيح', p.good, const Color(0xFF0F7B4A))),
          Expanded(child: _count('بخطأ', p.bad.length, Palette.returnRed)),
        ],
      ),
    ),
    if (p.bad.isNotEmpty) ...[
      const SectionTitle('صفوفٌ بأخطاء — لن تُنشأ'),
      for (final b in p.bad)
        Container(
          margin: const EdgeInsets.only(bottom: 8),
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(color: const Color(0xFFFDECEA), borderRadius: BorderRadius.circular(12)),
          child: Text(
            'الصفّ ${b.row}${b.name == null ? '' : ' (${b.name})'}: ${b.errors.join(' · ')}',
            style: font(12, w6, Palette.returnRed, height: 1.5),
          ),
        ),
    ],
    if (p.rows.isNotEmpty) ...[
      SectionTitle(p.good > p.rows.length ? 'أوّل ${p.rows.length} من ${p.good}' : 'ما يُنشأ'),
      WhiteCard(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
        child: Column(
          children: [
            for (final (k, r) in p.rows.indexed) ...[
              if (k > 0) const Divider(height: 1, color: Palette.line),
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 8),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(r.name ?? 'بلا اسم', style: font(13, w7, Palette.ink)),
                          Text('${r.phone} · ${r.place}', style: font(11.5, w5, Palette.slate)),
                        ],
                      ),
                    ),
                    Text('${money(r.amount)} د.ع', style: font(12.5, w8, Palette.ink)),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    ],
    const SizedBox(height: 14),
    SheetButton(
      brand: brand,
      label: p.bad.isEmpty ? 'أنشئ الشحنات (${p.good})' : 'أنشئ الصحيحة وحدها (${p.good})',
      icon: Icons.check_rounded,
      busy: saving,
      onTap: p.good == 0 ? null : () => _confirm(p),
    ),
    const SizedBox(height: 8),
    SheetButton(brand: brand, label: 'اختر ملفاً آخر', outlined: true, onTap: saving ? null : _pick),
  ];

  Widget _count(String label, int n, Color color) => Column(
    children: [
      Text('$n', style: font(20, w8, color, height: 1.2)),
      Text(label, style: font(11.5, w6, Palette.slate)),
    ],
  );

  Widget _done(String message) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(color: const Color(0xFFE8F6EE), borderRadius: BorderRadius.circular(14)),
    child: Row(
      children: [
        const Icon(Icons.check_circle_rounded, color: Color(0xFF0F7B4A)),
        const SizedBox(width: 8),
        Expanded(child: Text(message, style: font(12.5, w7, const Color(0xFF0F7B4A), height: 1.5))),
      ],
    ),
  );
}
