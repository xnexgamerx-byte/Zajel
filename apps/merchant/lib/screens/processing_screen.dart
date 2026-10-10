import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import 'shipment_screen.dart';

/// يفتح «للمعالجة» فوق الشاشة الحالية — من بطاقتها في الرئيسية
Future<void> openProcessing(BuildContext context, Brand brand, {VoidCallback? onChanged}) => Navigator.of(context).push(
  MaterialPageRoute(
    builder: (_) => ProcessingScreen(brand: brand, onChanged: onChanged),
  ),
);

/// «للمعالجة» (docs/plan/54): شحناتٌ لم يستلمها زبونه — يتّصل به ويقرّر: إعادة توصيل، أو تأجيلٌ إلى
/// موعد، أو إرجاع. القرار بطريق البوابة نفسه ويُكتب باسمه. وتاجرٌ لم تُفعّل له الشركة المعالجة يراها
/// ويتّصل بزبونه، والقرار لموظّفيها.
class ProcessingScreen extends StatefulWidget {
  const ProcessingScreen({super.key, required this.brand, this.onChanged});

  final Brand brand;

  /// قرارٌ سُجّل: تُعاد الرئيسية و«شحناتي»
  final VoidCallback? onChanged;

  @override
  State<ProcessingScreen> createState() => _ProcessingScreenState();
}

class _ProcessingScreenState extends State<ProcessingScreen> {
  ProcessingPage? data;
  String? error;

  /// ما قرّر فيه التاجر من هذه الصفحة — يُنقص عدّاد العنوان
  int decided = 0;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.processing();
      if (mounted) setState(() => (data = d, error = null, decided = 0));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  void _toast(String text, {bool bad = false}) => ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(
        content: Text(text, style: font(13, w6, Colors.white)),
        backgroundColor: bad ? Palette.returnRed : null,
        behavior: SnackBarBehavior.floating,
      ),
    );

  Future<void> _launch(Uri uri) async {
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) _toast('تعذّر فتحه على هذا الجهاز.');
  }

  Future<void> _decide(ProcessingItem item, String action) async {
    final result = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => _DecideSheet(brand: brand, item: item, action: action),
    );
    if (result == null || !mounted) return;
    _toast(result);
    setState(() {
      data?.items.removeWhere((i) => i.row.id == item.row.id);
      decided++;
    });
    widget.onChanged?.call();
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            _bar(d),
            Expanded(
              child: d == null
                  ? Center(
                      child: error == null
                          ? CircularProgressIndicator(color: brand.main)
                          : Padding(
                              padding: const EdgeInsets.all(24),
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text(error!, textAlign: TextAlign.center, style: font(14, w6, Palette.ink)),
                                  const SizedBox(height: 12),
                                  FilledButton(onPressed: _load, child: const Text('أعد المحاولة')),
                                ],
                              ),
                            ),
                    )
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: [
                          if (!d.allowed) ...[_notAllowed(), const SizedBox(height: 10)],
                          if (d.items.isEmpty) _empty(),
                          for (final item in d.items) ...[_card(item, d.allowed), const SizedBox(height: 10)],
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _bar(ProcessingPage? d) => Padding(
    padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
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
              Text('للمعالجة', style: font(18, w8, Palette.ink, height: 1.2)),
              Text(
                d == null
                    ? 'شحناتٌ لم يستلمها زبونك'
                    : '${_count(d.total - decided, 'شحنة واحدة', 'شحنتان', 'شحنات', 'شحنة')} لم يستلمها زبونك — اتّصل به ثم قرّر',
                style: font(11.5, w5, Palette.slate, height: 1.3),
              ),
            ],
          ),
        ),
      ],
    ),
  );

  Widget _notAllowed() => Container(
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(color: brand.softer, borderRadius: BorderRadius.circular(14)),
    child: Text(
      'تعالج شركتك هذه الشحنات وتخبرك بما تقرّر. تستطيع الاتّصال بزبونك من هنا — ولتقرّر بنفسك اطلب من الشركة تفعيل «المعالجة» لحسابك.',
      style: font(12, w6, Palette.slate, height: 1.6),
    ),
  );

  Widget _empty() => Padding(
    padding: const EdgeInsets.only(top: 80),
    child: Column(
      children: [
        SoftIcon(
          brand: brand,
          size: 56,
          radius: 18,
          child: Icon(Icons.task_alt_rounded, color: brand.main, size: 30),
        ),
        const SizedBox(height: 12),
        Text('لا شيء ينتظر قرارك', style: font(15, w8, Palette.ink)),
        const SizedBox(height: 4),
        Text('كلّ شحناتك تسير كما يجب.', style: font(12.5, w5, Palette.slate)),
      ],
    ),
  );

  Widget _card(ProcessingItem item, bool allowed) {
    final waited = item.waiting >= 24
        ? 'تنتظر منذ ${_count(item.waiting ~/ 24, 'يوم', 'يومين', 'أيام', 'يوماً')}'
        : 'منذ ${_count(item.waiting, 'ساعة', 'ساعتين', 'ساعات', 'ساعة')}';
    return WhiteCard(
      padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Tap(
                  radius: 8,
                  onTap: () => openShipment(context, brand, item.row.id),
                  child: Text.rich(
                    TextSpan(
                      text: '${tag(item.row.number)}  ',
                      style: font(13.5, w8, brand.main),
                      children: [TextSpan(text: item.row.name, style: font(13.5, w7, Palette.ink))],
                    ),
                    textDirection: TextDirection.rtl,
                  ),
                ),
              ),
              Text('${money(item.row.amount)} د.ع', style: font(13, w8, Palette.ink)),
            ],
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              if (item.reason != null) _chip(item.reason!, brand.coral),
              if (item.attempts > 1) _chip('المحاولة ${item.attempts}', Palette.returnRed),
              Text(
                '${item.row.area} · $waited',
                style: font(11.5, w6, item.waiting >= 24 ? Palette.returnRed : Palette.slate),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              _contact(Icons.call_rounded, 'اتّصل', () => _launch(Uri(scheme: 'tel', path: item.phone))),
              const SizedBox(width: 6),
              _contact(
                Icons.chat_rounded,
                'واتساب',
                () => _launch(
                  Uri.parse(
                    'https://wa.me/${item.phone.startsWith('0') ? '964${item.phone.substring(1)}' : item.phone}',
                  ),
                ),
              ),
            ],
          ),
          if (allowed) ...[
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(flex: 4, child: _action('إعادة توصيل', () => _decide(item, 'redeliver'), filled: true)),
                const SizedBox(width: 6),
                Expanded(flex: 3, child: _action('تأجيل', () => _decide(item, 'postpone'))),
                const SizedBox(width: 6),
                Expanded(flex: 3, child: _action('إرجاع', () => _decide(item, 'return'), danger: true)),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _chip(String text, Color color) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
    decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(12)),
    child: Text(text, style: font(10.5, w8, Colors.white, height: 1.3)),
  );

  Widget _contact(IconData icon, String label, VoidCallback onTap) => Tap(
    radius: 10,
    onTap: onTap,
    child: Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(color: const Color(0xFFF2F5F9), borderRadius: BorderRadius.circular(10)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: brand.main),
          const SizedBox(width: 5),
          Text(label, style: font(12, w7, Palette.ink)),
        ],
      ),
    ),
  );

  Widget _action(String label, VoidCallback onTap, {bool filled = false, bool danger = false}) {
    final color = danger ? Palette.returnRed : brand.main;
    return Tap(
      radius: 12,
      onTap: onTap,
      child: Container(
        height: 40,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: filled ? color : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: filled ? color : color.withValues(alpha: .45)),
        ),
        child: Text(label, style: font(13, w8, filled ? Colors.white : color)),
      ),
    );
  }
}

/// القرار: ما قاله الزبون، وموعد التأجيل، ثم «أكّد»
class _DecideSheet extends StatefulWidget {
  const _DecideSheet({required this.brand, required this.item, required this.action});

  final Brand brand;
  final ProcessingItem item;
  final String action;

  @override
  State<_DecideSheet> createState() => _DecideSheetState();
}

class _DecideSheetState extends State<_DecideSheet> {
  final note = TextEditingController();
  DateTime until = DateTime.now().add(const Duration(days: 1));
  bool saving = false;
  String? error;

  static const _titles = {
    'redeliver': ('إعادة توصيل', 'تخرج مع المندوب من جديد.'),
    'postpone': ('تأجيل', 'إلى اليوم الذي اتّفقت عليه مع زبونك.'),
    'return': ('إرجاع', 'ترجع إليك ولا تُحاوَل ثانيةً.'),
  };

  @override
  void dispose() {
    note.dispose();
    super.dispose();
  }

  String _date(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _pick() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: until,
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: now.add(const Duration(days: 59)),
    );
    if (picked != null) setState(() => until = picked);
  }

  Future<void> _save() async {
    setState(() => (saving = true, error = null));
    try {
      final message = await Api.instance.process(
        widget.item.row.id,
        widget.action,
        until: widget.action == 'postpone' ? _date(until) : null,
        note: note.text.trim(),
      );
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, saving = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    final (title, sub) = _titles[widget.action]!;
    final danger = widget.action == 'return';
    final today = DateTime.now();
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 10, 16, MediaQuery.viewInsetsOf(context).bottom + 18),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 38,
              height: 4,
              decoration: BoxDecoration(color: Palette.line, borderRadius: BorderRadius.circular(2)),
            ),
          ),
          const SizedBox(height: 14),
          Text('$title — ${tag(widget.item.row.number)}', style: font(16, w8, Palette.ink)),
          const SizedBox(height: 2),
          Text(sub, style: font(12, w5, Palette.slate)),
          if (widget.action == 'postpone') ...[
            const SizedBox(height: 14),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final (label, days) in [('غداً', 1), ('بعد يومين', 2), ('بعد أسبوع', 7)])
                  _dateChip(label, today.add(Duration(days: days))),
                ActionChip(
                  label: Text('يومٌ آخر: ${_date(until)}', style: font(12, w7, Palette.ink)),
                  avatar: const Icon(Icons.calendar_month_rounded, size: 16),
                  onPressed: _pick,
                ),
              ],
            ),
          ],
          const SizedBox(height: 14),
          Container(
            decoration: BoxDecoration(
              color: const Color(0xFFF6F8FB),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Palette.line),
            ),
            child: TextField(
              controller: note,
              maxLength: 255,
              maxLines: 2,
              minLines: 1,
              style: font(13.5, w6, Palette.ink, height: 1.4),
              decoration: InputDecoration(
                isDense: true,
                counterText: '',
                hintText: 'ما قاله الزبون (اختياري)',
                hintStyle: font(12.5, w5, Palette.muted),
                border: InputBorder.none,
                contentPadding: const EdgeInsets.all(12),
              ),
            ),
          ),
          if (error != null) ...[
            const SizedBox(height: 8),
            Text(error!, style: font(12, w6, Palette.returnRed, height: 1.5)),
          ],
          const SizedBox(height: 14),
          Tap(
            radius: 14,
            onTap: saving ? null : _save,
            child: Container(
              height: 50,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: danger ? Palette.returnRed : brand.main,
                borderRadius: BorderRadius.circular(14),
              ),
              child: saving
                  ? const SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                    )
                  : Text('أكّد: $title', style: font(15, w8, Colors.white)),
            ),
          ),
        ],
      ),
    );
  }

  Widget _dateChip(String label, DateTime day) {
    final picked = _date(day) == _date(until);
    return ChoiceChip(
      label: Text(label, style: font(12, picked ? w8 : w6, picked ? widget.brand.main : Palette.ink)),
      selected: picked,
      selectedColor: widget.brand.soft,
      onSelected: (_) => setState(() => until = day),
    );
  }
}

/// العدد بتمييزه العربي: ١ «ساعة» · ٢ «ساعتين» · ٣–١٠ «ساعات» · ١١+ «ساعة»
String _count(int n, String one, String two, String few, String many) => switch (n) {
  1 => one,
  2 => two,
  >= 3 && <= 10 => '$n $few',
  _ => '$n $many',
};
