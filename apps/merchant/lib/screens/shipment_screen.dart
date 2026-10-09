import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';

/// يفتح الشحنة فوق الشاشة الحالية — من «شحناتي» أو من آخر الشحنات في الرئيسية
void openShipment(BuildContext context, Brand brand, int id) {
  Navigator.of(context).push(
    MaterialPageRoute(
      builder: (_) => ShipmentScreen(brand: brand, id: id),
    ),
  );
}

/// الشحنة: حالتها، كود التسليم، سبب التعثّر، مسارها، زبونها، وحسابها — كبوابة التاجر في الموقع.
class ShipmentScreen extends StatefulWidget {
  const ShipmentScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<ShipmentScreen> createState() => _ShipmentScreenState();
}

class _ShipmentScreenState extends State<ShipmentScreen> {
  ShipmentDetail? data;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.shipment(widget.id);
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  void _toast(String text) => ScaffoldMessenger.of(context).showSnackBar(
    SnackBar(
      content: Text(text, style: font(13, w6, Colors.white)),
      behavior: SnackBarBehavior.floating,
    ),
  );

  Future<void> _copy(String text, String done) async {
    await Clipboard.setData(ClipboardData(text: text));
    _toast(done);
  }

  Future<void> _launch(Uri uri) async {
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) _toast('تعذّر فتحه على هذا الجهاز.');
  }

  /// 07801234567 ← 9647801234567 لرابط واتساب
  static String _intl(String phone) => phone.startsWith('0') ? '964${phone.substring(1)}' : phone;

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
                          if (d.deliveryCode != null) ...[_code(d.deliveryCode!), const SizedBox(height: 8)],
                          if (d.failureReason != null) ...[_failure(d), const SizedBox(height: 8)],
                          _timeline(d),
                          const SizedBox(height: 8),
                          _recipient(d),
                          const SizedBox(height: 8),
                          _money(d),
                          const SizedBox(height: 12),
                          _share(d),
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ الترويسة

  Widget _bar(ShipmentDetail? d) {
    return Padding(
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
                Text(
                  d == null ? 'الشحنة' : '#${d.row.number}',
                  textDirection: TextDirection.ltr,
                  style: font(16, w8, Palette.ink, height: 1.2),
                ),
                if (d?.createdAt != null)
                  Text(
                    'أُنشئت ${when(d!.createdAt)}${d.reference == null ? '' : ' · رقمك ${d.reference}'}',
                    style: font(11, w5, Palette.slate, height: 1.3),
                  ),
              ],
            ),
          ),
          if (d != null) StatusPill(brand: brand, row: d.row, height: 24, size: 10.5, width: 84),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ تنبيهات

  Widget _code(String code) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(14)),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('كود التسليم', style: font(12, w8, brand.main)),
                Text('أرسله لزبونك؛ لا يُسلَّم الطرد إلّا به.', style: font(11, w5, Palette.slate, height: 1.4)),
              ],
            ),
          ),
          Text(
            code,
            textDirection: TextDirection.ltr,
            style: font(22, w8, Palette.ink, height: 1).copyWith(letterSpacing: 5),
          ),
          const SizedBox(width: 6),
          IconButton(
            onPressed: () => _copy(code, 'نُسخ كود التسليم.'),
            icon: Icon(Icons.copy_rounded, size: 20, color: brand.main),
            tooltip: 'نسخ',
          ),
        ],
      ),
    );
  }

  Widget _failure(ShipmentDetail d) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      decoration: BoxDecoration(color: const Color(0xFFFFF4E5), borderRadius: BorderRadius.circular(14)),
      child: Row(
        children: [
          const Icon(Icons.error_outline_rounded, color: Color(0xFFB45309), size: 22),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'آخر محاولة لم تنجح: ${d.failureReason} · المحاولات ${d.attempts}',
              style: font(12, w6, const Color(0xFF92400E), height: 1.5),
            ),
          ),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ المسار

  Widget _timeline(ShipmentDetail d) {
    final steps = d.timeline;
    return _Card(
      title: 'مسار الشحنة',
      child: Column(
        children: [
          for (var i = 0; i < steps.length; i++)
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SizedBox(
                    width: 18,
                    child: Column(
                      children: [
                        Container(width: 2, height: 6, color: i == 0 ? Colors.transparent : Palette.line),
                        Container(
                          width: 10,
                          height: 10,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: i == steps.length - 1 ? brand.main : const Color(0xFFC5CEDA),
                            boxShadow: i == steps.length - 1
                                ? [BoxShadow(color: brand.main.withValues(alpha: .25), spreadRadius: 3)]
                                : null,
                          ),
                        ),
                        Expanded(
                          child: Container(width: 2, color: i == steps.length - 1 ? Colors.transparent : Palette.line),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  steps[i].title,
                                  style: font(12.5, i == steps.length - 1 ? w8 : w6, Palette.ink, height: 1.3),
                                ),
                              ),
                              Text(when(steps[i].at), style: font(10.5, w5, Palette.slate, height: 1.3)),
                            ],
                          ),
                          if (steps[i].note != null && steps[i].note!.isNotEmpty)
                            Text(steps[i].note!, style: font(11, w5, Palette.slate, height: 1.4)),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ الزبون

  Widget _recipient(ShipmentDetail d) {
    final r = d.recipient;
    final phone = (r['phone'] as String?) ?? '';
    String? text(String k) => (r[k] as String?)?.trim().isEmpty ?? true ? null : r[k] as String;
    final place = [text('governorate'), text('city')].whereType<String>().join(' — ');

    return _Card(
      title: 'الزبون والعنوان',
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(text('name') ?? 'بلا اسم', style: font(14, w8, Palette.ink, height: 1.3)),
                    Text(phone, textDirection: TextDirection.ltr, style: font(12.5, w6, Palette.slate, height: 1.4)),
                  ],
                ),
              ),
              _round(Icons.call_rounded, 'اتصال', () => _launch(Uri(scheme: 'tel', path: phone))),
              const SizedBox(width: 8),
              _round(Icons.chat_rounded, 'واتساب', () => _launch(Uri.parse('https://wa.me/${_intl(phone)}'))),
            ],
          ),
          const Divider(height: 20, color: Palette.line),
          if (text('phone_alt') != null) _line('هاتف آخر', text('phone_alt')!, ltr: true),
          if (place.isNotEmpty) _line('المنطقة', place),
          if (text('address') != null) _line('العنوان', text('address')!),
          _line('نقطة دالّة', text('landmark') ?? '—', strong: text('landmark') != null),
          _line('القطع · النوع · الحجم', '${r['pieces']} · ${r['type']} · ${r['size']}'),
          if (text('goods') != null) _line('نوع البضاعة', text('goods')!),
        ],
      ),
    );
  }

  Widget _round(IconData icon, String tip, VoidCallback onTap) => Tooltip(
    message: tip,
    child: Tap(
      radius: 20,
      onTap: onTap,
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(gradient: brand.tile, shape: BoxShape.circle),
        child: Icon(icon, size: 19, color: Colors.white),
      ),
    ),
  );

  Widget _line(String label, String value, {bool ltr = false, bool strong = false}) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(width: 118, child: Text(label, style: font(11.5, w5, Palette.slate, height: 1.5))),
        Expanded(
          child: Text(
            value,
            textDirection: ltr ? TextDirection.ltr : null,
            textAlign: TextAlign.start,
            style: font(12.5, strong ? w8 : w6, strong ? brand.main : Palette.ink, height: 1.5),
          ),
        ),
      ],
    ),
  );

  // ------------------------------------------------------------------ الحساب

  Widget _money(ShipmentDetail d) {
    final m = d.money;
    int v(String k) => (m[k] as int?) ?? 0;
    final owed = m['owed'] as bool? ?? true;

    Widget row(String label, int amount, {Color? color}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: font(12, w5, color ?? Palette.slate))),
          Text('${money(amount)} د.ع', textDirection: TextDirection.ltr, style: font(12.5, w7, color ?? Palette.ink)),
        ],
      ),
    );

    return _Card(
      title: 'حساب الشحنة',
      child: Column(
        children: [
          row('المطلوب من الزبون', v('cod')),
          if (v('collected') > 0) row('المحصَّل فعلاً', v('collected'), color: const Color(0xFF15803D)),
          row('أجرة التوصيل', v('delivery_fee')),
          if (v('cod_fee') > 0) row('عمولة التحصيل', v('cod_fee')),
          if (v('return_fee') > 0) row('أجرة الراجع', v('return_fee'), color: const Color(0xFFB45309)),
          const Divider(height: 18, color: Palette.line),
          Row(
            children: [
              Expanded(child: Text(owed ? 'لك' : 'عليك', style: font(14, w8, Palette.ink))),
              Text(
                '${money(v('due'))} د.ع',
                textDirection: TextDirection.ltr,
                style: font(18, w8, owed ? brand.main : const Color(0xFFB91C1C)),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ مشاركة التتبّع

  Widget _share(ShipmentDetail d) {
    final phone = (d.recipient['phone'] as String?) ?? '';
    final message = 'تتبّع شحنتك ${d.row.number}: ${d.trackingUrl}';
    return Row(
      children: [
        Expanded(
          child: SizedBox(
            height: 46,
            child: FilledButton.icon(
              onPressed: phone.isEmpty
                  ? null
                  : () => _launch(Uri.parse('https://wa.me/${_intl(phone)}?text=${Uri.encodeComponent(message)}')),
              style: FilledButton.styleFrom(
                backgroundColor: brand.main,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
              ),
              icon: const Icon(Icons.send_rounded, size: 18),
              label: Text('أرسل التتبّع للزبون', style: font(13, w8, Colors.white)),
            ),
          ),
        ),
        const SizedBox(width: 8),
        SizedBox(
          height: 46,
          child: OutlinedButton.icon(
            onPressed: () => _copy(d.trackingUrl, 'نُسخ رابط التتبّع.'),
            style: OutlinedButton.styleFrom(
              side: BorderSide(color: brand.border),
              backgroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
            ),
            icon: Icon(Icons.link_rounded, size: 18, color: brand.main),
            label: Text('نسخ الرابط', style: font(13, w7, brand.main)),
          ),
        ),
      ],
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) => WhiteCard(
    padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: font(13.5, w8, Palette.ink)),
        const SizedBox(height: 10),
        child,
      ],
    ),
  );
}
