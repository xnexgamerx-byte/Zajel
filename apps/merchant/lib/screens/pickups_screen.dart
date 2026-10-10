import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'create_screen.dart' show LatinDigits;

Future<void> openPickups(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => PickupsScreen(brand: brand)));

/// «طلبات الاستلام» (docs/plan/56): يطلب التاجر مندوباً يأخذ طروده — كم طرداً ومتى — ويرى طلباته
/// ومن أُسند إليه منها. طلبٌ مفتوحٌ واحد كما في البوابة.
class PickupsScreen extends StatefulWidget {
  const PickupsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<PickupsScreen> createState() => _PickupsScreenState();
}

class _PickupsScreenState extends State<PickupsScreen> {
  PickupsPage? data;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.pickups();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _new(PickupsPage d) async {
    final message = await showSheet<String>(context, _PickupSheet(brand: brand, page: d));
    if (message == null || !mounted) return;
    toast(context, message);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'طلبات الاستلام', subtitle: 'مندوبٌ يأتيك ويأخذ طرودك إلى الشركة'),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: [
                          if (d.open)
                            _openNote()
                          else
                            SheetButton(
                              brand: brand,
                              label: 'اطلب مندوب استلام',
                              icon: Icons.hail_rounded,
                              onTap: () => _new(d),
                            ),
                          const SectionTitle('طلباتك'),
                          if (d.items.isEmpty)
                            const Empty(
                              icon: Icons.inventory_2_outlined,
                              title: 'لا طلبات استلامٍ بعد',
                              text: 'جهّز طرودك ثم اطلب مندوباً يأخذها.',
                            ),
                          for (final p in d.items) ...[_card(p), const SizedBox(height: 10)],
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _openNote() => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: brand.softer,
      borderRadius: BorderRadius.circular(14),
      border: Border.all(color: brand.border),
    ),
    child: Row(
      children: [
        Icon(Icons.schedule_rounded, color: brand.main, size: 22),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            'طلبك مفتوح وينتظر المندوب. ولتغيير عدد الطرود اتّصل بالشركة.',
            style: font(12.5, w7, Palette.ink, height: 1.5),
          ),
        ),
      ],
    ),
  );

  Color _tone(String status) => switch (status) {
    'completed' => const Color(0xFF0F7B4A),
    'cancelled' => Palette.slate,
    'pending' => const Color(0xFFB45309),
    _ => const Color(0xFF1D4ED8),
  };

  Widget _card(Pickup p) => WhiteCard(
    padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text(p.number, textDirection: TextDirection.ltr, style: font(14, w8, Palette.ink)),
            const SizedBox(width: 8),
            Chip2(p.label, color: _tone(p.status)),
            const Spacer(),
            Text(when(p.at), style: font(11, w5, Palette.slate)),
          ],
        ),
        const SizedBox(height: 6),
        Text(
          [
            '${p.expected} طرد',
            if (p.actual != null) 'استُلم ${p.actual}',
            if (p.day != null) 'يوم ${p.day}',
          ].join(' · '),
          style: font(12.5, w7, Palette.ink),
        ),
        if (p.notes != null) Text(p.notes!, style: font(11.5, w5, Palette.slate, height: 1.5)),
        if (p.courier != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(
              children: [
                Icon(Icons.two_wheeler_rounded, size: 17, color: brand.main),
                const SizedBox(width: 6),
                Expanded(child: Text('المندوب: ${p.courier}', style: font(12, w7, Palette.ink))),
                if (p.courierPhone != null)
                  TextButton.icon(
                    onPressed: () => launchUrl(Uri.parse('tel:${p.courierPhone}')),
                    icon: Icon(Icons.call_rounded, size: 16, color: brand.main),
                    label: Text('اتّصل', style: font(12, w8, brand.main)),
                  ),
              ],
            ),
          ),
      ],
    ),
  );
}

class _PickupSheet extends StatefulWidget {
  const _PickupSheet({required this.brand, required this.page});

  final Brand brand;
  final PickupsPage page;

  @override
  State<_PickupSheet> createState() => _PickupSheetState();
}

class _PickupSheetState extends State<_PickupSheet> {
  final count = TextEditingController();
  final address = TextEditingController();
  final phone = TextEditingController();
  final notes = TextEditingController();

  /// ٠ اليوم · ١ غداً · ٢ بعد غد
  int day = 0;
  bool busy = false;
  String? error;

  @override
  void dispose() {
    for (final c in [count, address, phone, notes]) {
      c.dispose();
    }
    super.dispose();
  }

  String _date(int offset) {
    final d = DateTime.now().add(Duration(days: offset));
    return '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
  }

  Future<void> _send() async {
    final n = int.tryParse(count.text.trim());
    if (n == null || n < 1) {
      setState(() => error = 'كم طرداً عندك؟ اكتب العدد.');
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() => (busy = true, error = null));
    try {
      final message = await Api.instance.requestPickup(
        count: n,
        day: _date(day),
        address: address.text.trim(),
        phone: phone.text.trim(),
        notes: notes.text.trim(),
      );
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    return SheetFrame(
      title: 'اطلب مندوب استلام',
      text: 'يأتيك المندوب ويأخذ طرودك. العنوان والهاتف من حسابك ما لم تكتب غيرهما.',
      children: [
        const FieldLabel('عدد الطرود'),
        InputBox(
          controller: count,
          hint: 'مثلاً 12',
          keyboard: TextInputType.number,
          formatters: const [LatinDigits()],
          ltr: true,
          maxLength: 4,
          autofocus: true,
        ),
        const FieldLabel('متى؟'),
        Wrap(
          spacing: 6,
          children: [
            for (final (i, label) in ['اليوم', 'غداً', 'بعد غد'].indexed)
              ChoiceChip(
                label: Text(label, style: font(12.5, day == i ? w8 : w6, day == i ? brand.main : Palette.ink)),
                selected: day == i,
                selectedColor: brand.soft,
                onSelected: (_) => setState(() => day = i),
              ),
          ],
        ),
        const FieldLabel('العنوان'),
        InputBox(controller: address, hint: widget.page.address ?? 'عنوان الاستلام'),
        const FieldLabel('هاتف التواصل'),
        InputBox(
          controller: phone,
          hint: widget.page.phone ?? '07xxxxxxxxx',
          keyboard: TextInputType.phone,
          formatters: const [LatinDigits()],
          ltr: true,
          maxLength: 11,
        ),
        const FieldLabel('ملاحظة (اختياري)'),
        InputBox(controller: notes, hint: 'مثلاً: بعد العصر، الطرود عند الباب الخلفي', lines: 2, maxLength: 500),
        if (error != null) ErrorNote(error!),
        const SizedBox(height: 14),
        SheetButton(brand: brand, label: 'أرسل الطلب', busy: busy, onTap: _send),
      ],
    );
  }
}
