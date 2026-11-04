import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import 'create_screen.dart';

/// ما انتهى إليه التعديل: حُفظ، أو حُذفت الشحنة (docs/plan/117)
enum EditResult { saved, deleted }

/// يفتح «تعديل الشحنة» فوق شاشتها، ويعود بما جرى — وnull إن رجع بلا شيء
Future<EditResult?> openEdit(BuildContext context, Brand brand, int id, String number) {
  return Navigator.of(context).push<EditResult>(
    MaterialPageRoute(
      builder: (_) => EditScreen(brand: brand, id: id, number: number),
    ),
  );
}

/// «تعديل الشحنة» (docs/plan/113): نموذج «طلب جديد» بقيمها الآن. قبل أن يستلمها المندوب كلّ شيء — والأجرة من التسعيرة —
/// وبعد الاستلام بيانات الزبون وعنوانه وملاحظاته وحدها، والوجهة والسعر للقراءة.
class EditScreen extends StatefulWidget {
  const EditScreen({super.key, required this.brand, required this.id, required this.number});

  final Brand brand;
  final int id;
  final String number;

  @override
  State<EditScreen> createState() => _EditScreenState();
}

class _EditScreenState extends State<EditScreen> {
  final name = TextEditingController();
  final phone = TextEditingController();
  final phoneAlt = TextEditingController();
  final address = TextEditingController();
  final landmark = TextEditingController();
  final cod = TextEditingController();
  final goods = TextEditingController();
  final notes = TextEditingController();

  CreateForm? form;
  ShipmentEdit? data;
  String? loadError;

  Choice? governorate;
  Choice? area;
  List<Choice> areas = [];
  bool areasLoading = false;
  int pieces = 1;
  String size = 'normal';
  String type = 'delivery';

  bool saving = false;
  bool deleting = false;
  Map<String, String> errors = {};
  String? error;

  Brand get brand => widget.brand;
  bool get full => data?.edit.full ?? false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [name, phone, phoneAlt, address, landmark, cod, goods, notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => loadError = null);
    try {
      final results = await Future.wait([Api.instance.createForm(), Api.instance.shipmentEdit(widget.id)]);
      if (!mounted) return;
      final f = results[0] as CreateForm;
      final d = results[1] as ShipmentEdit;
      final v = d.values;
      String text(String key) => v[key] == null ? '' : '${v[key]}';

      name.text = text('recipient_name');
      phone.text = text('recipient_phone');
      phoneAlt.text = text('recipient_phone_alt');
      address.text = text('address');
      landmark.text = text('landmark');
      goods.text = text('description');
      notes.text = text('notes');
      cod.text = Thousands.group('${v['cod_amount'] ?? 0}');
      pieces = (v['pieces_count'] as num?)?.toInt() ?? 1;
      size = v['size'] as String? ?? 'normal';
      type = v['type'] as String? ?? 'delivery';
      final gov = v['governorate_id'] == null ? null : Choice('${v['governorate_id']}', text('governorate'));
      final city = v['city_id'] == null ? null : Choice('${v['city_id']}', text('city'));

      setState(() => (form = f, data = d, governorate = gov, area = city));
      if (d.edit.full && gov != null) await _loadAreas(gov, keep: city);
    } on ApiError catch (e) {
      if (mounted) setState(() => loadError = e.message);
    }
  }

  Future<void> _loadAreas(Choice g, {Choice? keep}) async {
    setState(() => (governorate = g, area = keep, areas = [], areasLoading = true, errors.remove('governorate_id')));
    try {
      final list = await Api.instance.areas(g.key);
      if (mounted && governorate == g) setState(() => areas = list);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => areasLoading = false);
    }
  }

  bool _required(String field) => form?.required.contains(field) ?? false;

  int get _cod => int.tryParse(cod.text.replaceAll(RegExp(r'\D'), '')) ?? 0;

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    setState(() => (saving = true, errors = {}, error = null));
    String? orNull(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
    try {
      final message = await Api.instance.updateShipment(widget.id, {
        'recipient_name': name.text.trim(),
        'recipient_phone': phone.text.trim(),
        'recipient_phone_alt': orNull(phoneAlt),
        if (address.text.trim().isNotEmpty || (data?.values['address'] != null)) 'address': orNull(address),
        'landmark': orNull(landmark),
        'notes': orNull(notes),
        if (full) ...{
          'governorate_id': governorate?.key,
          'city_id': area?.key,
          'cod_amount': cod.text.trim().isEmpty ? null : _cod,
          'pieces_count': pieces,
          'description': orNull(goods),
          'size': size,
          'type': type,
        },
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(message, style: font(13, w6, Colors.white)),
          behavior: SnackBarBehavior.floating,
        ),
      );
      Navigator.of(context).pop(EditResult.saved);
    } on ApiError catch (e) {
      if (mounted) setState(() => (errors = e.fields, error = e.fields.isEmpty ? e.message : null));
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            _bar(),
            Expanded(child: _body()),
          ],
        ),
      ),
    );
  }

  Widget _bar() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 6),
      child: Row(
        children: [
          Tap(
            radius: 18,
            onTap: () => Navigator.of(context).pop(),
            child: Container(
              width: 36,
              height: 36,
              alignment: Alignment.center,
              decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
              child: const Chev(size: 11, stroke: 1.8),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('تعديل الشحنة', style: font(16, w8, Palette.ink, height: 1.2)),
                Text('#${widget.number}', textDirection: TextDirection.ltr, style: font(11, w6, Palette.slate)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _body() {
    final f = form;
    final d = data;
    if (f == null || d == null) {
      return Center(
        child: loadError == null
            ? CircularProgressIndicator(color: brand.main)
            : Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(loadError!, textAlign: TextAlign.center, style: font(14, w6, Palette.ink)),
                    const SizedBox(height: 12),
                    FilledButton(onPressed: _load, child: const Text('أعد المحاولة')),
                  ],
                ),
              ),
      );
    }

    if (d.edit.mode == null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Center(
          child: Text(
            d.edit.locked ?? 'لا تُعدَّل هذه الشحنة الآن.',
            textAlign: TextAlign.center,
            style: font(14, w6, Palette.ink, height: 1.6),
          ),
        ),
      );
    }

    final v = d.values;
    return GestureDetector(
      onTap: () => FocusScope.of(context).unfocus(),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(14, 4, 14, 40),
        children: [
          _hint(
            full
                ? 'لم يستلمها المندوب بعد: عدّل ما تشاء، والأجرة تُحسب من تسعيرتك.'
                : 'استلمها المندوب: تُعدَّل بيانات الزبون وعنوانه وملاحظاته، ويصل المندوبَ ما تغيّر.',
          ),
          const SizedBox(height: 10),
          if (error != null) ...[_alert(error!), const SizedBox(height: 10)],
          _section('الزبون', Icons.person_rounded, [
            _field(
              'اسم الزبون',
              name,
              'text',
              hint: 'اختياري — يُطبع على الوصل',
              required: _required('recipient_name'),
              key: 'recipient_name',
            ),
            _field('رقم الهاتف', phone, 'phone', hint: '07xxxxxxxxx', required: true, key: 'recipient_phone'),
            _field(
              'هاتف ثانوي',
              phoneAlt,
              'phone',
              hint: 'اختياري',
              required: _required('recipient_phone_alt'),
              key: 'recipient_phone_alt',
            ),
          ]),
          const SizedBox(height: 10),
          _section('العنوان', Icons.location_on_rounded, [
            if (full) ...[
              _picker('المحافظة', governorate?.label, 'governorate_id', () async {
                final g = await _choose('المحافظة', f.governorates, governorate);
                if (g != null && g.key != governorate?.key) await _loadAreas(g);
              }),
              if (areasLoading || areas.isNotEmpty)
                _picker('المنطقة', areasLoading ? 'تُحمَّل المناطق…' : area?.label, 'city_id', () async {
                  if (areasLoading) return;
                  final a = await _choose('المنطقة', areas, area, search: true);
                  if (a != null) setState(() => (area = a, errors.remove('city_id')));
                }),
            ] else
              _readOnly(
                'الوجهة',
                [v['governorate'], v['city']].whereType<String>().where((s) => s.isNotEmpty).join(' — '),
                'الطرد في طريقه إليها: تغييرها من الكول سنتر.',
              ),
            if (v['address'] != null && '${v['address']}'.isNotEmpty)
              _field('العنوان', address, 'text', required: _required('address'), key: 'address'),
            _field(
              'أقرب نقطة دالّة',
              landmark,
              'text',
              hint: 'اختياري — تساعد المندوب',
              required: _required('landmark'),
              key: 'landmark',
            ),
          ]),
          const SizedBox(height: 10),
          _section('الطلب', Icons.inventory_2_rounded, [
            if (full) ...[
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: _field(
                      'السعر مع التوصيل',
                      cod,
                      'money',
                      hint: 'ما يدفعه الزبون',
                      required: true,
                      key: 'cod_amount',
                    ),
                  ),
                  const SizedBox(width: 10),
                  _pieces(),
                ],
              ),
              _field(
                'نوع البضاعة',
                goods,
                'text',
                hint: 'مثلاً: ${f.goods}',
                required: _required('description'),
                key: 'description',
              ),
              _chips('حجم الطلب', f.sizes, size, (s) => setState(() => size = s)),
              _chips('نوع الطلب', f.types, type, (t) => setState(() => type = t)),
            ] else
              _readOnly(
                'السعر',
                '${Thousands.group('${v['cod_amount'] ?? 0}')} د.ع · ${v['pieces_count'] ?? 1} قطعة',
                'السعر على وصل المندوب: تغييره من الكول سنتر.',
              ),
            _field(
              'ملاحظات للمندوب',
              notes,
              'multiline',
              hint: 'اختياري — مثلاً: اتصل قبل الوصول',
              required: _required('notes'),
              key: 'notes',
            ),
          ]),
          const SizedBox(height: 14),
          _saveButton(),
          if (d.edit.deletable) ...[const SizedBox(height: 10), _deleteButton()],
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ الأقسام والحقول (كـ«طلب جديد»)

  Widget _hint(String text) => Container(
    padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
    decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(14)),
    child: Row(
      children: [
        Icon(Icons.edit_note_rounded, color: brand.main, size: 22),
        const SizedBox(width: 8),
        Expanded(child: Text(text, style: font(12, w6, Palette.ink, height: 1.5))),
      ],
    ),
  );

  Widget _alert(String text) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(color: const Color(0xFFFDECEA), borderRadius: BorderRadius.circular(12)),
    child: Text(text, style: font(12.5, w6, Palette.returnRed, height: 1.5)),
  );

  Widget _section(String title, IconData icon, List<Widget> children) {
    return WhiteCard(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              SoftIcon(
                brand: brand,
                size: 28,
                radius: 8,
                child: Icon(icon, size: 17, color: brand.main),
              ),
              const SizedBox(width: 8),
              Text(title, style: font(14, w8, Palette.ink)),
            ],
          ),
          const SizedBox(height: 10),
          for (final c in children) Padding(padding: const EdgeInsets.only(bottom: 10), child: c),
        ],
      ),
    );
  }

  Widget _label(String text, {bool required = false}) => Padding(
    padding: const EdgeInsets.only(bottom: 5, right: 2),
    child: Text.rich(
      TextSpan(
        text: text,
        style: font(12, w7, Palette.ink),
        children: [if (required) TextSpan(text: ' *', style: font(12, w8, brand.main))],
      ),
    ),
  );

  Widget _error(String key) {
    final message = errors[key];
    if (message == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 4, right: 2),
      child: Text(message, style: font(11, w6, Palette.returnRed, height: 1.4)),
    );
  }

  BoxDecoration _box(bool bad) => BoxDecoration(
    color: const Color(0xFFFBF6F5),
    borderRadius: BorderRadius.circular(12),
    border: Border.all(color: bad ? Palette.returnRed : Palette.line),
  );

  Widget _readOnly(String label, String value, String note) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      _label(label),
      Container(
        padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
        decoration: BoxDecoration(color: Palette.eye, borderRadius: BorderRadius.circular(12)),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(value, style: font(14, w7, Palette.ink)),
            const SizedBox(height: 2),
            Text(note, style: font(11, w5, Palette.slate, height: 1.4)),
          ],
        ),
      ),
    ],
  );

  Widget _field(
    String label,
    TextEditingController controller,
    String kind, {
    String hint = '',
    bool required = false,
    required String key,
  }) {
    final ltr = kind == 'phone' || kind == 'money';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _label(label, required: required),
        Container(
          decoration: _box(errors.containsKey(key)),
          child: TextField(
            controller: controller,
            onChanged: (_) {
              if (errors.containsKey(key)) setState(() => errors.remove(key));
            },
            keyboardType: switch (kind) {
              'phone' => TextInputType.phone,
              'money' => TextInputType.number,
              'multiline' => TextInputType.multiline,
              _ => TextInputType.text,
            },
            maxLines: kind == 'multiline' ? 3 : 1,
            minLines: 1,
            textDirection: ltr ? TextDirection.ltr : null,
            textAlign: ltr ? TextAlign.right : TextAlign.start,
            inputFormatters: switch (kind) {
              'phone' => [
                const LatinDigits(),
                FilteringTextInputFormatter.digitsOnly,
                LengthLimitingTextInputFormatter(11),
              ],
              'money' => [const LatinDigits(), const Thousands()],
              _ => null,
            },
            style: font(14, w6, Palette.ink, height: 1.4),
            decoration: InputDecoration(
              isDense: true,
              hintText: hint,
              hintTextDirection: TextDirection.rtl,
              hintStyle: font(12.5, w5, Palette.muted, height: 1.4),
              suffixText: kind == 'money' ? 'د.ع' : null,
              suffixStyle: font(11, w6, Palette.slate),
              border: InputBorder.none,
              contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
            ),
          ),
        ),
        _error(key),
      ],
    );
  }

  Widget _picker(String label, String? value, String key, VoidCallback onTap) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _label(label, required: true),
        Tap(
          radius: 12,
          onTap: onTap,
          child: Container(
            height: 46,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: _box(errors.containsKey(key)),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    value ?? 'اختر $label',
                    style: font(14, value == null ? w5 : w6, value == null ? Palette.muted : Palette.ink),
                  ),
                ),
                const Icon(Icons.keyboard_arrow_down_rounded, color: Palette.muted),
              ],
            ),
          ),
        ),
        _error(key),
      ],
    );
  }

  Future<Choice?> _choose(String title, List<Choice> options, Choice? current, {bool search = false}) {
    return showModalBottomSheet<Choice>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (context) => ChoiceSheet(
        brand: brand,
        title: title,
        options: options,
        current: options.where((o) => o.key == current?.key).firstOrNull,
        search: search,
      ),
    );
  }

  Widget _pieces() {
    Widget step(IconData icon, VoidCallback? onTap) => Tap(
      radius: 10,
      onTap: onTap,
      child: SizedBox(
        width: 32,
        height: 44,
        child: Icon(icon, size: 19, color: onTap == null ? Palette.line : brand.main),
      ),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _label('العدد', required: true),
        Container(
          height: 46,
          decoration: _box(errors.containsKey('pieces_count')),
          child: Row(
            children: [
              step(Icons.add_rounded, pieces < 255 ? () => setState(() => pieces++) : null),
              SizedBox(
                width: 26,
                child: Text('$pieces', textAlign: TextAlign.center, style: font(15, w8, Palette.ink)),
              ),
              step(Icons.remove_rounded, pieces > 1 ? () => setState(() => pieces--) : null),
            ],
          ),
        ),
      ],
    );
  }

  Widget _chips(String label, List<Choice> options, String value, ValueChanged<String> onPick) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _label(label),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final o in options)
              Tap(
                radius: 18,
                onTap: () => onPick(o.key),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 150),
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
                  decoration: BoxDecoration(
                    color: o.key == value ? brand.soft : Colors.white,
                    borderRadius: BorderRadius.circular(18),
                    border: Border.all(color: o.key == value ? brand.main : Palette.line),
                  ),
                  child: Text(
                    o.label,
                    style: font(12.5, o.key == value ? w8 : w6, o.key == value ? brand.main : Palette.slate),
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }

  /// «احذف الشحنة» (docs/plan/117): يُسأل أوّلاً، ولسببٍ اختياريّ يراه المكتب
  Future<void> _delete() async {
    FocusScope.of(context).unfocus();
    // السبب، أو null إن تراجع — الحوار يملك حقله فلا يُتلف وهو يُغلق
    final why = await showDialog<String>(
      context: context,
      builder: (_) => _DeleteDialog(number: widget.number),
    );
    if (why == null || !mounted) return;
    setState(() => (deleting = true, error = null));
    try {
      final message = await Api.instance.deleteShipment(widget.id, reason: why);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(message, style: font(13, w6, Colors.white)),
          behavior: SnackBarBehavior.floating,
        ),
      );
      Navigator.of(context).pop(EditResult.deleted);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => deleting = false);
    }
  }

  Widget _deleteButton() {
    return Tap(
      radius: 14,
      onTap: saving || deleting ? null : _delete,
      child: Container(
        height: 48,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: Palette.returnRed.withValues(alpha: .45)),
        ),
        child: deleting
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2.2, color: Palette.returnRed),
              )
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.delete_outline_rounded, color: Palette.returnRed, size: 20),
                  const SizedBox(width: 6),
                  Text('احذف الشحنة', style: font(14, w8, Palette.returnRed)),
                ],
              ),
      ),
    );
  }

  Widget _saveButton() {
    return Tap(
      radius: 14,
      onTap: saving ? null : _save,
      child: Container(
        height: 52,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          gradient: LinearGradient(colors: [brand.coral, brand.main]),
          boxShadow: [BoxShadow(color: brand.main.withValues(alpha: .28), blurRadius: 12, offset: const Offset(0, 5))],
        ),
        child: saving
            ? const SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
              )
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.check_rounded, color: Colors.white, size: 21),
                  const SizedBox(width: 6),
                  Text('حفظ التعديل', style: font(15, w8, Colors.white)),
                ],
              ),
      ),
    );
  }
}

/// «حذف الشحنة؟» بسببٍ اختياريّ: يعود بالسبب (فارغاً إن لم يُكتب)، وnull إن تراجع
class _DeleteDialog extends StatefulWidget {
  const _DeleteDialog({required this.number});

  final String number;

  @override
  State<_DeleteDialog> createState() => _DeleteDialogState();
}

class _DeleteDialogState extends State<_DeleteDialog> {
  final reason = TextEditingController();

  @override
  void dispose() {
    reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('حذف الشحنة #${widget.number}؟', style: font(16, w8, Palette.ink)),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('لا تُستلم ولا تُوصل بعدها.', style: font(13, w5, Palette.slate, height: 1.5)),
          const SizedBox(height: 10),
          TextField(
            controller: reason,
            maxLength: 255,
            style: font(14, w6, Palette.ink),
            decoration: InputDecoration(
              hintText: 'السبب — اختياري، مثلاً: ألغى الزبون',
              hintStyle: font(12.5, w5, Palette.muted),
              isDense: true,
              counterText: '',
            ),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('تراجع')),
        TextButton(
          onPressed: () => Navigator.of(context).pop(reason.text),
          child: Text('احذف', style: font(14, w8, Palette.returnRed)),
        ),
      ],
    );
  }
}
