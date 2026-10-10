import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import 'shipment_screen.dart';

/// «طلب جديد» (docs/plan/52): نموذج بوابة التاجر نفسه — الأساسي الهاتف والعنوان والسعر،
/// والباقي اختياري. وتحت السعر «يصلك» من تسعيرته قبل أن يحفظ، وبعد الحفظ بطاقة الشحنة
/// والنموذج فارغٌ للتالية على المحافظة نفسها.
class CreateScreen extends StatefulWidget {
  const CreateScreen({super.key, required this.brand, this.onCreated, this.scanned, this.onScan});

  final Brand brand;

  /// الوصل المطبوع الذي مُسح بالزرّ الأوسط: يُكتب الطلب عليه
  final ValueNotifier<String?>? scanned;

  /// يفتح الكاميرا لمسح وصلٍ (التالي، أو بدل الممسوح)
  final VoidCallback? onScan;

  /// حُفظت شحنة: تُحدَّث الرئيسية و«شحناتي»
  final VoidCallback? onCreated;

  @override
  State<CreateScreen> createState() => _CreateScreenState();
}

class _CreateScreenState extends State<CreateScreen> {
  final name = TextEditingController();
  final phone = TextEditingController();
  final phoneAlt = TextEditingController();
  final landmark = TextEditingController();
  final cod = TextEditingController();
  final goods = TextEditingController();
  final notes = TextEditingController();
  final waybill = TextEditingController();
  final scroll = ScrollController();

  CreateForm? form;
  String? loadError;

  Choice? governorate;
  Choice? area;
  List<Choice> areas = [];
  bool areasLoading = false;
  int pieces = 1;
  String size = 'normal';
  String type = 'delivery';

  Quote? quote;
  Timer? quoteTimer;
  int quoteTicket = 0;

  bool saving = false;
  Map<String, String> errors = {};
  String? error;
  Created? created;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    cod.addListener(_requote);
    widget.scanned?.addListener(_useScanned);
    _load();
    _useScanned();
  }

  /// وصلٌ ممسوح: يُكتب في حقله، ويُفتح النموذج من أعلاه لبيانات طلبه
  void _useScanned() {
    final code = widget.scanned?.value;
    if (code == null) {
      // تُرك الوصل («بلا وصل» أو حُفظ عليه): لا يبقى رقمه في الحقل للطلب التالي
      if (lastScanned != null && waybill.text == lastScanned) setState(waybill.clear);
      lastScanned = null;
      return;
    }
    lastScanned = code;
    setState(() => (waybill.text = code, created = null, errors.remove('waybill')));
    if (scroll.hasClients) scroll.jumpTo(0);
  }

  String? lastScanned;

  bool get _onWaybill => waybill.text.isNotEmpty && waybill.text == widget.scanned?.value;

  @override
  void dispose() {
    quoteTimer?.cancel();
    widget.scanned?.removeListener(_useScanned);
    for (final c in [name, phone, phoneAlt, landmark, cod, goods, notes, waybill]) {
      c.dispose();
    }
    scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final f = await Api.instance.createForm();
      if (!mounted) return;
      setState(() => (form = f, loadError = null));
      final home = f.governorates.where((g) => g.key == f.home).firstOrNull ?? f.governorates.firstOrNull;
      if (home != null) await _pickGovernorate(home);
    } on ApiError catch (e) {
      if (mounted) setState(() => loadError = e.message);
    }
  }

  Future<void> _pickGovernorate(Choice g) async {
    setState(() => (governorate = g, area = null, areas = [], areasLoading = true, errors.remove('governorate_id')));
    try {
      final list = await Api.instance.areas(g.key);
      if (mounted && governorate == g) setState(() => areas = list);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => areasLoading = false);
    }
    _requote();
  }

  int get _cod => int.tryParse(cod.text.replaceAll(RegExp(r'\D'), '')) ?? 0;

  /// «يصلك» بعد أن يهدأ الكتابة: أجرة التوصيل من تسعيرته لهذه الوجهة وهذا الحجم
  void _requote() {
    quoteTimer?.cancel();
    final g = governorate;
    if (g == null) return;
    quoteTimer = Timer(const Duration(milliseconds: 450), () async {
      final mine = ++quoteTicket;
      try {
        final q = await Api.instance.quote(governorate: g.key, area: area?.key, cod: _cod, size: size);
        if (mounted && mine == quoteTicket) setState(() => quote = q);
      } on ApiError {
        if (mounted && mine == quoteTicket) setState(() => quote = null);
      }
    });
  }

  bool _required(String field) => form?.required.contains(field) ?? false;

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    setState(() => (saving = true, errors = {}, error = null));
    try {
      final made = await Api.instance.createShipment({
        'recipient_name': name.text.trim(),
        'recipient_phone': phone.text.trim(),
        if (phoneAlt.text.trim().isNotEmpty) 'recipient_phone_alt': phoneAlt.text.trim(),
        'governorate_id': governorate?.key,
        if (area != null) 'city_id': area!.key,
        if (landmark.text.trim().isNotEmpty) 'landmark': landmark.text.trim(),
        'cod_amount': cod.text.trim().isEmpty ? null : _cod,
        'pieces_count': pieces,
        if (goods.text.trim().isNotEmpty) 'description': goods.text.trim(),
        'size': size,
        'type': type,
        if (notes.text.trim().isNotEmpty) 'notes': notes.text.trim(),
        if (waybill.text.trim().isNotEmpty) 'waybill': waybill.text.trim(),
      });
      if (!mounted) return;
      // نموذجٌ فارغ للتالية على المحافظة والمنطقة نفسيهما — الطلبات تُدخَل متتابعة
      for (final c in [name, phone, phoneAlt, landmark, cod, goods, notes, waybill]) {
        c.clear();
      }
      setState(() => (created = made, pieces = 1, size = 'normal', type = 'delivery'));
      // الوصل استُعمل: التالي يُمسح من جديد
      widget.scanned?.value = null;
      widget.onCreated?.call();
      scroll.animateTo(0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
    } on ApiError catch (e) {
      if (mounted) setState(() => (errors = e.fields, error = e.fields.isEmpty ? e.message : null));
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final f = form;
    final top = MediaQuery.paddingOf(context).top;
    if (f == null) {
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

    return GestureDetector(
      onTap: () => FocusScope.of(context).unfocus(),
      child: ListView(
        controller: scroll,
        padding: EdgeInsets.fromLTRB(14, top > 0 ? top + 6 : 34, 14, 150),
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 1),
            child: Text('طلب جديد', style: font(20, w8, Palette.ink, height: 1.2)),
          ),
          const SizedBox(height: 2),
          Text('الأساسي: الهاتف والعنوان والسعر — والباقي اختياري.', style: font(12, w5, Palette.slate)),
          const SizedBox(height: 12),
          if (created != null) ...[_done(created!), const SizedBox(height: 10)],
          if (_onWaybill) ...[_onWaybillBanner(), const SizedBox(height: 10)],
          if (error != null) ...[_alert(error!), const SizedBox(height: 10)],
          _section('الزبون', Icons.person_rounded, [
            _field(
              'اسم الزبون',
              name,
              'name',
              hint: _required('recipient_name') ? 'مثلاً: طه محمد' : 'اختياري — يُطبع على الوصل',
              required: _required('recipient_name'),
              key: 'recipient_name',
            ),
            _field(
              'رقم الهاتف',
              phone,
              'phone',
              hint: '07xxxxxxxxx',
              required: true,
              key: 'recipient_phone',
              phoneInput: true,
            ),
            _field(
              'هاتف ثانوي',
              phoneAlt,
              'phone',
              hint: 'اختياري',
              required: _required('recipient_phone_alt'),
              key: 'recipient_phone_alt',
              phoneInput: true,
            ),
          ]),
          const SizedBox(height: 10),
          _section('العنوان', Icons.location_on_rounded, [
            _picker('المحافظة', governorate?.label, 'governorate_id', () async {
              final g = await _choose('المحافظة', f.governorates, governorate);
              if (g != null && g != governorate) await _pickGovernorate(g);
            }),
            if (areasLoading || areas.isNotEmpty)
              _picker('المنطقة', areasLoading ? 'تُحمَّل المناطق…' : area?.label, 'city_id', () async {
                if (areasLoading) return;
                final a = await _choose('المنطقة', areas, area, search: true);
                if (a != null) {
                  setState(() => (area = a, errors.remove('city_id')));
                  _requote();
                }
              }),
            _field(
              'أقرب نقطة دالّة',
              landmark,
              'text',
              hint: _required('landmark') ? 'مثلاً: قرب جامع الرحمن' : 'اختياري — تساعد المندوب',
              required: _required('landmark'),
              key: 'landmark',
            ),
          ]),
          const SizedBox(height: 10),
          _section('الطلب', Icons.inventory_2_rounded, [
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
            if (quote != null && governorate != null) _quote(quote!, priced: cod.text.trim().isNotEmpty),
            _field(
              'نوع البضاعة',
              goods,
              'text',
              hint: 'مثلاً: ${f.goods}',
              required: _required('description'),
              key: 'description',
            ),
            _chips('حجم الطلب', f.sizes, size, (v) {
              setState(() => size = v);
              _requote();
            }),
            _chips('نوع الطلب', f.types, type, (v) => setState(() => type = v)),
            _field(
              'ملاحظات للمندوب',
              notes,
              'multiline',
              hint: 'اختياري — تُطبع على الوصل، مثلاً: اتصل قبل الوصول',
              required: _required('notes'),
              key: 'notes',
            ),
          ]),
          if (f.waybills && !_onWaybill) ...[
            const SizedBox(height: 10),
            _section('رقم الوصل المطبوع', Icons.receipt_long_rounded, [
              _field(
                'إن لصقت على الطرد وصلاً مطبوعاً',
                waybill,
                'digits',
                hint: 'اختياري — اكتب رقم الوصل',
                key: 'waybill',
              ),
            ]),
          ],
          const SizedBox(height: 14),
          _saveButton(),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ الأقسام والحقول

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

  Widget _error(String? key) {
    final message = key == null ? null : errors[key];
    if (message == null) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 4, right: 2),
      child: Text(message, style: font(11, w6, Palette.returnRed, height: 1.4)),
    );
  }

  BoxDecoration _box(bool bad) => BoxDecoration(
    color: const Color(0xFFF6F8FB),
    borderRadius: BorderRadius.circular(12),
    border: Border.all(color: bad ? Palette.returnRed : Palette.line),
  );

  Widget _field(
    String label,
    TextEditingController controller,
    String kind, {
    String hint = '',
    bool required = false,
    String? key,
    bool phoneInput = false,
  }) {
    final ltr = phoneInput || kind == 'money' || kind == 'digits';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _label(label, required: required),
        Container(
          decoration: _box(key != null && errors.containsKey(key)),
          child: TextField(
            controller: controller,
            onChanged: (_) {
              if (key != null && errors.containsKey(key)) setState(() => errors.remove(key));
            },
            keyboardType: switch (kind) {
              'phone' => TextInputType.phone,
              'money' || 'digits' => TextInputType.number,
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
              'digits' => [const LatinDigits(), LengthLimitingTextInputFormatter(20)],
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

  /// قائمة اختيارٍ من أسفل الشاشة، ببحثٍ للمناطق الكثيرة
  Future<Choice?> _choose(String title, List<Choice> options, Choice? current, {bool search = false}) {
    return showModalBottomSheet<Choice>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (context) =>
          _ChoiceSheet(brand: brand, title: title, options: options, current: current, search: search),
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

  /// قبل كتابة السعر: الأجرة وحدها — لا «عليك» على مبلغٍ لم يُكتب
  Widget _quote(Quote q, {required bool priced}) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 9, 12, 9),
      decoration: BoxDecoration(color: brand.softer, borderRadius: BorderRadius.circular(12)),
      child: Row(
        children: [
          Expanded(
            child: Text.rich(
              TextSpan(
                text: 'أجرة التوصيل ',
                style: font(12, w6, Palette.slate),
                children: [TextSpan(text: money(q.fees), style: font(12.5, w8, Palette.ink))],
              ),
            ),
          ),
          if (priced)
            Text.rich(
              TextSpan(
                text: q.due < 0 ? 'عليك ' : 'يصلك ',
                style: font(12, w6, Palette.slate),
                children: [
                  TextSpan(
                    text: '${money(q.due.abs())} د.ع',
                    style: font(14, w8, q.due < 0 ? Palette.returnRed : const Color(0xFF0F7B4A)),
                  ),
                ],
              ),
            ),
        ],
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
                  Text('حفظ الشحنة', style: font(15, w8, Colors.white)),
                ],
              ),
      ),
    );
  }

  Widget _alert(String text) => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(color: const Color(0xFFFDECEA), borderRadius: BorderRadius.circular(12)),
    child: Text(text, style: font(12.5, w6, Palette.returnRed, height: 1.5)),
  );

  /// المحفوظة للتوّ: رقمها وما يصله منها، وتُفتح بلمسة
  Widget _done(Created c) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
      decoration: BoxDecoration(
        color: const Color(0xFFE9F7EF),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFBFE6CF)),
      ),
      child: Row(
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle),
            child: const Icon(Icons.check_rounded, color: Color(0xFF0F7B4A), size: 21),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text.rich(
                  TextSpan(
                    text: 'حُفظت الشحنة ',
                    style: font(13, w7, const Color(0xFF0F7B4A)),
                    children: [TextSpan(text: tag(c.row.number), style: font(13, w8, Palette.ink))],
                  ),
                ),
                Text(
                  '${c.row.name} · ${money(c.row.amount)} د.ع · يصلك ${money(c.due)}'
                  '${c.waybill == null ? '' : ' · على الوصل ${c.waybill}'}',
                  style: font(11.5, w5, Palette.slate, height: 1.4),
                ),
              ],
            ),
          ),
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (c.row.id > 0)
                TextButton(
                  onPressed: () => openShipment(context, brand, c.row.id),
                  child: Text('افتحها', style: font(12.5, w8, brand.main)),
                ),
              if (c.waybill != null && widget.onScan != null)
                TextButton.icon(
                  onPressed: widget.onScan,
                  icon: Icon(Icons.qr_code_scanner_rounded, size: 17, color: brand.main),
                  label: Text('امسح التالي', style: font(12, w8, brand.main)),
                ),
            ],
          ),
        ],
      ),
    );
  }

  /// الطلب يُكتب على وصلٍ ممسوح: رقمه، وتغييره بمسحٍ آخر، أو تركه
  Widget _onWaybillBanner() {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 6, 8),
      decoration: BoxDecoration(
        color: brand.softer,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: brand.border),
      ),
      child: Row(
        children: [
          SolidIcon(brand: brand, icon: Icons.qr_code_2_rounded, size: 32, iconSize: 19, radius: 9),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('على الوصل المطبوع', style: font(11.5, w6, Palette.slate)),
                Text(
                  waybill.text,
                  textDirection: TextDirection.ltr,
                  style: font(17, w8, Palette.ink, height: 1.2).copyWith(letterSpacing: 1.5),
                ),
              ],
            ),
          ),
          if (widget.onScan != null)
            TextButton(
              onPressed: widget.onScan,
              child: Text('امسح غيره', style: font(12, w8, brand.main)),
            ),
          IconButton(
            tooltip: 'بلا وصل',
            onPressed: () => widget.scanned?.value = null,
            icon: const Icon(Icons.close_rounded, size: 19, color: Palette.muted),
          ),
        ],
      ),
    );
  }
}

/// قائمة المحافظات أو المناطق، ببحثٍ يطابق أيّ جزءٍ من الاسم
class _ChoiceSheet extends StatefulWidget {
  const _ChoiceSheet({
    required this.brand,
    required this.title,
    required this.options,
    required this.current,
    required this.search,
  });

  final Brand brand;
  final String title;
  final List<Choice> options;
  final Choice? current;
  final bool search;

  @override
  State<_ChoiceSheet> createState() => _ChoiceSheetState();
}

class _ChoiceSheetState extends State<_ChoiceSheet> {
  String q = '';

  /// «الكراده» تجد «الكرادة»، و«اعظمية» تجد «الأعظمية»
  static String _key(String s) => s
      .replaceAll(RegExp('[أإآ]'), 'ا')
      .replaceAll('ة', 'ه')
      .replaceAll('ى', 'ي')
      .replaceAll(RegExp(r'^ال'), '')
      .replaceAll(' ', '');

  @override
  Widget build(BuildContext context) {
    final shown = q.isEmpty ? widget.options : widget.options.where((o) => _key(o.label).contains(_key(q))).toList();
    final height = MediaQuery.sizeOf(context).height * (widget.search ? .78 : .6);
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(
        height: height,
        child: Column(
          children: [
            const SizedBox(height: 8),
            Container(
              width: 38,
              height: 4,
              decoration: BoxDecoration(color: Palette.line, borderRadius: BorderRadius.circular(2)),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              child: Row(children: [Text('اختر ${widget.title}', style: font(16, w8, Palette.ink))]),
            ),
            if (widget.search)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 0, 14, 8),
                child: Container(
                  height: 44,
                  decoration: BoxDecoration(color: const Color(0xFFF6F8FB), borderRadius: BorderRadius.circular(12)),
                  child: TextField(
                    autofocus: true,
                    onChanged: (v) => setState(() => q = v.trim()),
                    style: font(13.5, w6, Palette.ink),
                    decoration: InputDecoration(
                      hintText: 'ابحث عن ${widget.title}',
                      hintStyle: font(12.5, w5, Palette.muted),
                      prefixIcon: const Icon(Icons.search_rounded, color: Palette.muted, size: 20),
                      border: InputBorder.none,
                      contentPadding: const EdgeInsets.symmetric(vertical: 12),
                    ),
                  ),
                ),
              ),
            Expanded(
              child: shown.isEmpty
                  ? Center(child: Text('لا نتائج', style: font(13, w6, Palette.slate)))
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(8, 0, 8, 16),
                      itemCount: shown.length,
                      separatorBuilder: (_, _) =>
                          const Divider(height: 1, color: Palette.line, indent: 12, endIndent: 12),
                      itemBuilder: (context, i) {
                        final o = shown[i];
                        final picked = o.key == widget.current?.key;
                        return ListTile(
                          dense: true,
                          title: Text(
                            o.label,
                            style: font(14, picked ? w8 : w6, picked ? widget.brand.main : Palette.ink),
                          ),
                          trailing: picked ? Icon(Icons.check_rounded, color: widget.brand.main) : null,
                          onTap: () => Navigator.of(context).pop(o),
                        );
                      },
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

/// «٠٧٨٠» ← «0780»: لوحة الهاتف العربية تكتب أرقاماً لا يفهمها النظام
class LatinDigits extends TextInputFormatter {
  const LatinDigits();

  static String convert(String s) => s.replaceAllMapped(RegExp('[٠-٩۰-۹]'), (m) {
    final c = m[0]!.codeUnitAt(0);
    return String.fromCharCode(0x30 + (c >= 0x06F0 ? c - 0x06F0 : c - 0x0660));
  });

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final text = convert(newValue.text);
    return newValue.copyWith(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}

/// «25000» ← «25 000» كما في الموقع: المبلغ يُقرأ بلمحة
class Thousands extends TextInputFormatter {
  const Thousands();

  static String group(String digits) {
    final out = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) out.write(' ');
      out.write(digits[i]);
    }
    return out.toString();
  }

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final digits = newValue.text.replaceAll(RegExp(r'\D'), '');
    final capped = digits.length > 9 ? digits.substring(0, 9) : digits;
    final text = group(capped.replaceFirst(RegExp(r'^0+(?=\d)'), ''));
    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}
