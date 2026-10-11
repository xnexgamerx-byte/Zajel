import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'create_screen.dart' show LatinDigits, Thousands;

/// صورةٌ اختارها التاجر من الاستوديو
class PickedImage {
  const PickedImage(this.bytes, this.name);

  final Uint8List bytes;
  final String name;
}

Future<void> openBatch(BuildContext context, Brand brand, List<PickedImage> images, {VoidCallback? onCreated}) =>
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => BatchScreen(brand: brand, images: images, onCreated: onCreated),
      ),
    );

enum _Step { waiting, uploading, reading, read, failed, creating, created }

/// طلبٌ من صورة: ما قُرئ منها، ويُكمَل باليد قبل الإنشاء
class _Item {
  _Item(this.image, this.index);

  final PickedImage image;
  final int index;
  _Step step = _Step.waiting;
  double sent = 0;
  String? error;
  Map<String, dynamic> fields = {};
  String? governorateName;
  String? areaName;
  String? number;

  String text(String key) => '${fields[key] ?? ''}'.trim();

  /// ما ينقص ليُنشأ: الهاتف والمحافظة والمنطقة والمبلغ
  List<String> get missing => [
    if (text('recipient_phone').isEmpty) 'الهاتف',
    if (text('governorate_id').isEmpty) 'المحافظة',
    if (text('city_id').isEmpty) 'المنطقة',
    if (text('cod_amount').isEmpty) 'المبلغ',
  ];

  bool get ready => step == _Step.read && missing.isEmpty;
}

/// صورٌ كثيرة مرّةً واحدة (docs/plan/63): تُرفع وتُقرأ معاً بقدر ما يحتمل الخادم، فتظهر طلباتها واحداً
/// واحداً أوّلاً بأوّل؛ ما نقصه شيءٌ يُكمَل بضغطة، ثم «أنشئ الكل».
class BatchScreen extends StatefulWidget {
  const BatchScreen({super.key, required this.brand, required this.images, this.onCreated});

  final Brand brand;
  final List<PickedImage> images;
  final VoidCallback? onCreated;

  @override
  State<BatchScreen> createState() => _BatchScreenState();
}

class _BatchScreenState extends State<BatchScreen> {
  late final items = [for (final (i, image) in widget.images.indexed) _Item(image, i)];
  CreateForm? form;
  final clock = Stopwatch();
  Duration? took;
  bool creating = false;
  Timer? ticker;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    unawaited(_start());
  }

  @override
  void dispose() {
    ticker?.cancel();
    super.dispose();
  }

  Future<void> _start() async {
    try {
      form = await Api.instance.createForm();
    } on ApiError {
      form = null;
    }
    if (!mounted) return;
    clock.start();
    ticker = Timer.periodic(const Duration(milliseconds: 250), (_) {
      if (mounted) setState(() {});
    });
    // بقدر نوى الخادم معاً: كلّ عاملٍ يأخذ الصورة التالية حتى تنتهي
    final parallel = (form?.batchParallel ?? 4).clamp(1, 8);
    var next = 0;
    Future<void> worker() async {
      while (mounted && next < items.length) {
        await _read(items[next++]);
      }
    }

    await Future.wait([for (var w = 0; w < parallel; w++) worker()]);
    clock.stop();
    ticker?.cancel();
    if (mounted) setState(() => took = clock.elapsed);
  }

  Future<void> _read(_Item item) async {
    setState(() => item.step = _Step.uploading);
    try {
      final name = item.image.name.contains('.') ? item.image.name : '${item.image.name}.jpg';
      final reading = await Api.instance.readImageTracked(
        item.image.bytes,
        name,
        demo: item.index,
        sent: (sent) {
          if (!mounted) return;
          item.sent = sent;
          if (sent >= 1 && item.step == _Step.uploading) item.step = _Step.reading;
        },
      );
      if (!mounted) return;
      setState(() {
        item.fields = {...reading.fields};
        item.governorateName = reading.found['governorate_id'];
        item.areaName = reading.found['city_id'];
        item.step = _Step.read;
      });
    } on ApiError catch (e) {
      if (mounted) setState(() => (item.step = _Step.failed, item.error = e.message, item.sent = 1));
    }
  }

  int get _uploaded => items.where((i) => i.step != _Step.waiting && i.step != _Step.uploading).length;
  int get _read_ => items.where((i) => i.step.index >= _Step.read.index).length;
  bool get _allRead => items.every((i) => i.step.index >= _Step.read.index);

  Future<void> _edit(_Item item) async {
    final f = form;
    if (f == null) return;
    final changed = await showSheet<bool>(context, _EditSheet(brand: brand, form: f, item: item));
    if (changed == true && mounted) setState(() {});
  }

  Future<void> _createAll() async {
    final ready = items.where((i) => i.ready).toList();
    if (ready.isEmpty) return;
    setState(() => creating = true);
    var made = 0;
    var next = 0;
    Future<void> worker() async {
      while (mounted && next < ready.length) {
        final item = ready[next++];
        setState(() => (item.step = _Step.creating, item.error = null));
        try {
          final created = await Api.instance.createShipment({
            'recipient_name': item.text('recipient_name'),
            'recipient_phone': item.text('recipient_phone'),
            if (item.text('recipient_phone_alt').isNotEmpty) 'recipient_phone_alt': item.text('recipient_phone_alt'),
            'governorate_id': item.text('governorate_id'),
            'city_id': item.text('city_id'),
            if (item.text('landmark').isNotEmpty) 'landmark': item.text('landmark'),
            'cod_amount': int.tryParse(item.text('cod_amount').replaceAll(RegExp(r'\D'), '')),
            'pieces_count': int.tryParse(item.text('pieces_count')) ?? 1,
            'description': form?.goods ?? 'ملابس',
            'size': 'normal',
            'type': 'delivery',
            if (item.text('notes').isNotEmpty) 'notes': item.text('notes'),
          });
          made++;
          if (mounted) setState(() => (item.step = _Step.created, item.number = created.row.number));
        } on ApiError catch (e) {
          if (mounted) setState(() => (item.step = _Step.read, item.error = e.message));
        }
      }
    }

    await Future.wait([for (var w = 0; w < 3; w++) worker()]);
    if (!mounted) return;
    setState(() => creating = false);
    if (made > 0) {
      widget.onCreated?.call();
      toast(context, 'أُنشئت $made شحنة.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final ready = items.where((i) => i.ready).length;
    final waiting = items.where((i) => i.step == _Step.read && !i.ready).length;
    final done = items.where((i) => i.step == _Step.created).length;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            PageBar(title: 'قراءة الصور', subtitle: '${items.length} صورة — كلّ صورةٍ طلب'),
            Padding(padding: const EdgeInsets.fromLTRB(14, 0, 14, 8), child: _progress()),
            Expanded(
              child: ListView.separated(
                padding: const EdgeInsets.fromLTRB(14, 0, 14, 20),
                itemCount: items.length,
                separatorBuilder: (_, _) => const SizedBox(height: 8),
                itemBuilder: (_, i) => _card(items[i]),
              ),
            ),
            if (_allRead && done < items.length)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 4, 14, 12),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (waiting > 0)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Text(
                          '$waiting تحتاج تكملة — اضغط على الطلب الأحمر.',
                          style: font(12, w7, Palette.returnRed),
                        ),
                      ),
                    SheetButton(
                      brand: brand,
                      label: ready == 0 ? 'أكمل الطلبات أوّلاً' : 'أنشئ الكل ($ready)',
                      icon: Icons.check_circle_rounded,
                      busy: creating,
                      onTap: ready == 0 ? null : _createAll,
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _progress() {
    final n = items.length;
    final uploaded = _uploaded;
    final sent = items.fold<double>(0, (a, i) => a + i.sent) / (n == 0 ? 1 : n);
    final seconds = (took ?? clock.elapsed).inMilliseconds / 1000;
    final allUp = uploaded == n;
    return WhiteCard(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                allUp ? Icons.cloud_done_rounded : Icons.cloud_upload_rounded,
                color: allUp ? const Color(0xFF0F7B4A) : brand.main,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  allUp ? 'تم الرفع ✓' : 'جاري الرفع… $uploaded من $n',
                  style: font(14, w8, allUp ? const Color(0xFF0F7B4A) : Palette.ink),
                ),
              ),
              Text('${seconds.toStringAsFixed(1)} ث', style: font(12, w7, Palette.slate)),
            ],
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: allUp ? _read_ / (n == 0 ? 1 : n) : sent,
              minHeight: 6,
              color: allUp ? const Color(0xFF0F7B4A) : brand.main,
              backgroundColor: Palette.line,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            took != null ? 'قُرئت كلّها ($n) في ${seconds.toStringAsFixed(1)} ثانية' : 'قُرئ $_read_ من $n',
            style: font(12, w6, Palette.slate),
          ),
        ],
      ),
    );
  }

  Widget _card(_Item item) {
    final missing = item.step == _Step.read ? item.missing : const <String>[];
    final red = missing.isNotEmpty || item.step == _Step.failed || item.error != null;
    return Tap(
      radius: 16,
      onTap: item.step == _Step.read ? () => _edit(item) : null,
      child: Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: red ? Palette.returnRed.withValues(alpha: .6) : Palette.line, width: red ? 1.4 : 1),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(10),
              child: SizedBox(
                width: 52,
                height: 64,
                child: item.image.bytes.isEmpty
                    ? Container(
                        color: brand.soft,
                        child: Icon(Icons.image_outlined, color: brand.main),
                      )
                    : Image.memory(item.image.bytes, fit: BoxFit.cover, cacheWidth: 160),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(child: _body(item, missing)),
            if (item.step == _Step.read && !creating)
              IconButton(
                tooltip: 'احذفها',
                visualDensity: VisualDensity.compact,
                onPressed: () => setState(() => items.remove(item)),
                icon: const Icon(Icons.close_rounded, size: 20, color: Palette.muted),
              ),
          ],
        ),
      ),
    );
  }

  Widget _body(_Item item, List<String> missing) {
    switch (item.step) {
      case _Step.waiting:
        return Text('بالانتظار', style: font(12.5, w6, Palette.muted));
      case _Step.uploading:
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('يُرفع… ${(item.sent * 100).round()}%', style: font(12.5, w7, Palette.ink)),
            const SizedBox(height: 6),
            LinearProgressIndicator(value: item.sent, minHeight: 4, color: brand.main, backgroundColor: Palette.line),
          ],
        );
      case _Step.reading:
        return Row(
          children: [
            SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: brand.main)),
            const SizedBox(width: 8),
            Text('رُفعت ✓ — تُقرأ…', style: font(12.5, w7, Palette.ink)),
          ],
        );
      case _Step.failed:
        return Text(item.error ?? 'تعذّرت قراءتها', style: font(12.5, w7, Palette.returnRed, height: 1.4));
      case _Step.read || _Step.creating || _Step.created:
        final name = item.text('recipient_name');
        final amount = item.text('cod_amount');
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              [name.isEmpty ? 'بلا اسم' : name, item.text('recipient_phone')].where((t) => t.isNotEmpty).join(' · '),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: font(13.5, w8, Palette.ink),
            ),
            Text(
              [item.governorateName, item.areaName].whereType<String>().join(' · ') +
                  (amount.isEmpty ? '' : ' — ${Thousands.group(amount.replaceAll(RegExp(r'\D'), ''))} د.ع'),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: font(12, w6, Palette.slate),
            ),
            if (missing.isNotEmpty)
              Text('ينقص: ${missing.join('، ')} — اضغط لتكمله', style: font(11.5, w8, Palette.returnRed)),
            if (item.error != null) Text(item.error!, style: font(11.5, w7, Palette.returnRed, height: 1.4)),
            if (item.step == _Step.creating) Text('يُنشأ…', style: font(11.5, w7, brand.main)),
            if (item.step == _Step.created)
              Text('أُنشئت ✓ ${item.number ?? ''}', style: font(11.5, w8, const Color(0xFF0F7B4A))),
          ],
        );
    }
  }
}

/// تكملة طلبٍ من صورة: ما قُرئ مكتوبٌ، ويُصحَّح أو يُكمَل
class _EditSheet extends StatefulWidget {
  const _EditSheet({required this.brand, required this.form, required this.item});

  final Brand brand;
  final CreateForm form;
  final _Item item;

  @override
  State<_EditSheet> createState() => _EditSheetState();
}

class _EditSheetState extends State<_EditSheet> {
  late final name = TextEditingController(text: widget.item.text('recipient_name'));
  late final phone = TextEditingController(text: widget.item.text('recipient_phone'));
  late final amount = TextEditingController(text: widget.item.text('cod_amount'));
  late final landmark = TextEditingController(text: widget.item.text('landmark'));
  late String? governorate = widget.item.text('governorate_id').isEmpty
      ? widget.form.home
      : widget.item.text('governorate_id');
  late String? area = widget.item.text('city_id').isEmpty ? null : widget.item.text('city_id');
  List<Choice> areas = [];

  @override
  void initState() {
    super.initState();
    _areas();
  }

  @override
  void dispose() {
    for (final c in [name, phone, amount, landmark]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _areas() async {
    final g = governorate;
    if (g == null) return;
    try {
      final list = await Api.instance.areas(g);
      if (mounted) setState(() => (areas = list, area = list.any((a) => a.key == area) ? area : null));
    } on ApiError {
      // تبقى المنطقة كما قُرئت
    }
  }

  void _save() {
    final item = widget.item;
    item.fields
      ..['recipient_name'] = name.text.trim()
      ..['recipient_phone'] = LatinDigits.convert(phone.text).replaceAll(RegExp(r'\D'), '')
      ..['cod_amount'] = LatinDigits.convert(amount.text).replaceAll(RegExp(r'\D'), '')
      ..['landmark'] = landmark.text.trim()
      ..['governorate_id'] = governorate ?? ''
      ..['city_id'] = area ?? '';
    item.governorateName = widget.form.governorates.where((g) => g.key == governorate).firstOrNull?.label;
    item.areaName = areas.where((a) => a.key == area).firstOrNull?.label ?? item.areaName;
    item.error = null;
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    return SheetFrame(
      title: 'أكمل الطلب',
      text: 'ما قُرئ من الصورة مكتوب — صحّحه أو أكمل ما ينقص.',
      children: [
        const FieldLabel('اسم الزبون'),
        InputBox(controller: name, hint: 'الاسم'),
        const FieldLabel('الهاتف'),
        InputBox(controller: phone, hint: '07xxxxxxxxx', keyboard: TextInputType.phone, ltr: true, maxLength: 11),
        const FieldLabel('المحافظة'),
        DropdownButtonFormField<String>(
          initialValue: governorate,
          isExpanded: true,
          items: [for (final g in widget.form.governorates) DropdownMenuItem(value: g.key, child: Text(g.label))],
          onChanged: (g) {
            setState(() => (governorate = g, area = null, areas = []));
            _areas();
          },
        ),
        const FieldLabel('المنطقة'),
        DropdownButtonFormField<String>(
          key: ValueKey('areas-$governorate-${areas.length}'),
          initialValue: area,
          isExpanded: true,
          hint: Text(areas.isEmpty ? 'تُحمَّل المناطق…' : 'اختر المنطقة', style: font(13, w5, Palette.muted)),
          items: [for (final a in areas) DropdownMenuItem(value: a.key, child: Text(a.label))],
          onChanged: (a) => setState(() => area = a),
        ),
        const FieldLabel('المبلغ'),
        InputBox(controller: amount, hint: '25000', keyboard: TextInputType.number, ltr: true, maxLength: 9),
        const FieldLabel('أقرب نقطة دالّة'),
        InputBox(controller: landmark, hint: 'اختياري'),
        const SizedBox(height: 14),
        SheetButton(brand: brand, label: 'احفظ', icon: Icons.check_rounded, onTap: _save),
      ],
    );
  }
}
