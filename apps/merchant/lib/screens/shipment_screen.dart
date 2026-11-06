import 'dart:math' as math;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/access.dart';
import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/seen.dart';
import '../widgets/bits.dart';
import '../widgets/design.dart';
import '../widgets/page.dart';
import '../widgets/whatsapp.dart';
import 'edit_screen.dart';
import 'support_screen.dart';

/// حُذفت شحنةٌ من تعديلها (docs/plan/117): تُعاد القوائم المفتوحة — الرئيسية و«شحناتي» والطابعة والحالات — فلا تبقى فيها
final shipmentsChanged = ValueNotifier<int>(0);

/// يفتح الشحنة فوق الشاشة الحالية — من «شحناتي» أو من آخر الشحنات في الرئيسية
void openShipment(BuildContext context, Brand brand, int id) {
  Navigator.of(context).push(
    MaterialPageRoute(
      builder: (_) => ShipmentScreen(brand: brand, id: id),
    ),
  );
  // فتحها: يذهب لونها من القوائم (docs/plan/123) — بعد أن تبدأ الشاشة بالظهور، فيُرى ذوبانه حين يرجع
  Seen.open(id);
}

/// رقم وصلٍ في أيّ مكان يفتح شحنته (docs/plan/61): يُبحث برقمه في «شحناتي» ويُفتح ما طابقه حرفاً
Future<void> openShipmentNumber(BuildContext context, Brand brand, String number) async {
  final messenger = ScaffoldMessenger.of(context);
  final clean = number.replaceAll(RegExp(r'[^0-9A-Za-z-]'), '');
  try {
    final page = await Api.instance.shipments(q: clean);
    final hit = page.items.where((r) => r.number == clean).firstOrNull;
    if (!context.mounted) return;
    if (hit == null) {
      messenger
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text('لا شحنة لك برقم $clean'), behavior: SnackBarBehavior.floating));
      return;
    }
    openShipment(context, brand, hit.id);
  } on ApiError catch (e) {
    messenger
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(e.message), behavior: SnackBarBehavior.floating));
  }
}

/// أرقام الوصولات في نصٍّ (رسالة، إشعار) تصير روابط تفتح شحناتها
class LinkedText extends StatefulWidget {
  const LinkedText(this.text, {super.key, required this.brand, required this.style, this.linkColor});

  final String text;
  final Brand brand;
  final TextStyle style;
  final Color? linkColor;

  /// رقم وصلٍ: ستّة أرقامٍ فأكثر، لا جزءاً من رقم هاتف (07…) ولا من مبلغ بفواصل
  static final pattern = RegExp(r'(?<![0-9,])(?!07[0-9]{9}\b)[0-9]{6,9}(?![0-9,])');

  @override
  State<LinkedText> createState() => _LinkedTextState();
}

class _LinkedTextState extends State<LinkedText> {
  final taps = <TapGestureRecognizer>[];

  @override
  void dispose() {
    for (final t in taps) {
      t.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    for (final t in taps) {
      t.dispose();
    }
    taps.clear();
    final spans = <InlineSpan>[];
    var at = 0;
    for (final m in LinkedText.pattern.allMatches(widget.text)) {
      if (m.start > at) spans.add(TextSpan(text: widget.text.substring(at, m.start)));
      final number = m.group(0)!;
      final tap = TapGestureRecognizer()..onTap = () => openShipmentNumber(context, widget.brand, number);
      taps.add(tap);
      spans.add(
        TextSpan(
          text: number,
          recognizer: tap,
          style: TextStyle(
            color: widget.linkColor ?? widget.brand.main,
            fontWeight: FontWeight.w800,
            decoration: TextDecoration.underline,
          ),
        ),
      );
      at = m.end;
    }
    if (at < widget.text.length) spans.add(TextSpan(text: widget.text.substring(at)));
    return Text.rich(TextSpan(style: widget.style, children: spans));
  }
}

/// الشحنة في «وصل» (docs/plan/123): تذكرةٌ بختم حالتها وزبونها والمطلوب منه، وكود التسليم، ولوحة القرار إن لم تُسلَّم،
/// ومسارها خطوةً خطوة، وعنوانها وحسابها — كبوابة التاجر في الموقع. ومربّع «تعديل» الأحمر في رأسها ما دامت تُعدَّل.
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

  @override
  Widget build(BuildContext context) {
    final d = data;
    final top = MediaQuery.paddingOf(context).top;
    return Scaffold(
      backgroundColor: Palette.bg,
      body: Column(
        children: [
          SizedBox(height: top > 0 ? top : 28),
          _bar(d),
          Expanded(
            child: d == null
                ? Waiting(brand: brand, error: error, onRetry: _load)
                : RefreshIndicator(
                    color: brand.main,
                    onRefresh: _load,
                    child: ListView(
                      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
                      children: [
                        Rise(dy: 18, child: _ticket(d)),
                        if (d.deliveryCode != null) ...[const SizedBox(height: 12), _code(d.deliveryCode!)],
                        if (_processing(d)) ...[
                          const SizedBox(height: 16),
                          Rise(
                            delay: 280,
                            child: _Decide(brand: brand, detail: d, onDone: _decided),
                          ),
                        ] else if (decided != null) ...[
                          const SizedBox(height: 16),
                          _done(decided!),
                        ] else if (d.failureReason != null) ...[
                          const SizedBox(height: 12),
                          _failure(d),
                        ],
                        if (d.location != null) ...[const SizedBox(height: 16), _map(d.location!)],
                        const SizedBox(height: 24),
                        _timeline(d),
                        const SizedBox(height: 16),
                        _recipient(d),
                        const SizedBox(height: 12),
                        _money(d),
                        const SizedBox(height: 12),
                        // «اسأل عن الشحنة» (docs/plan/61): خياراتٌ ثم محادثةٌ مع المتابعة عن هذا الوصل
                        SheetButton(
                          brand: brand,
                          label: 'اسأل عن الشحنة',
                          icon: Icons.support_agent_rounded,
                          outlined: true,
                          onTap: () => askAboutShipment(context, brand, d.row.number),
                        ),
                      ],
                    ),
                  ),
          ),
          if (d != null) _footer(d),
        ],
      ),
    );
  }

  /// لم تُسلَّم وتنتظر قراره (docs/plan/54): لوحة القرار داخل الشحنة (docs/plan/123)
  bool _processing(ShipmentDetail d) => d.row.tone == 'urgent' && Access.can('processing');

  /// ما قرّره للتوّ من داخل الشحنة: يبقى ظاهراً بعد أن تتبدّل حالتها
  String? decided;

  void _decided(String message) {
    setState(() => decided = message);
    Seen.open(widget.id);
    shipmentsChanged.value++;
    _load();
  }

  // ------------------------------------------------------------------ الترويسة

  /// رجوعٌ، و«وصل N» ومتى أُنشئت، وفي الطرف مربّع «تعديل» الأحمر ما دامت تُعدَّل (docs/plan/113) — وإلّا نسخ رابط التتبّع
  Widget _bar(ShipmentDetail? d) {
    final edit = d?.edit?.mode != null;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
      child: Row(
        children: [
          SquareButton(tip: 'رجوع', onTap: () => Navigator.of(context).pop(), child: const BackArrow()),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              children: [
                Text(
                  d == null ? 'الشحنة' : 'وصل \u2066${d.row.number}\u2069',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: kf(16, Palette.ink, bold: true),
                ),
                if (d?.createdAt != null)
                  Text(
                    'أُنشئت ${when(d!.createdAt)}${d.reference == null ? '' : ' · رقمك ${d.reference}'}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: kf(12, Palette.muted),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          if (edit)
            Semantics(
              button: true,
              label: 'تعديل الشحنة',
              excludeSemantics: true,
              onTap: () => _edit(d!),
              child: Tooltip(
                message: 'تعديل الشحنة',
                child: Tap(
                  radius: 16,
                  shadow: [
                    BoxShadow(color: brand.halo, blurRadius: 18, spreadRadius: -10, offset: const Offset(0, 10)),
                  ],
                  onTap: () => _edit(d!),
                  child: Ink(
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(16)),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(Icons.edit_outlined, size: 18, color: Colors.white),
                        const SizedBox(height: 2),
                        Text('تعديل', style: kf(12, Colors.white, bold: true, height: 1.3)),
                      ],
                    ),
                  ),
                ),
              ),
            )
          else
            SquareButton(
              tip: 'نسخ رابط التتبّع',
              onTap: d == null ? null : () => _copy(d.trackingUrl, 'نُسخ رابط التتبّع.'),
              child: const Icon(Icons.ios_share_rounded, size: 19, color: Palette.ink),
            ),
        ],
      ),
    );
  }

  Future<void> _edit(ShipmentDetail d) async {
    switch (await openEdit(context, brand, d.row.id, d.row.number)) {
      case EditResult.saved:
        _load();
      case EditResult.deleted:
        shipmentsChanged.value++;
        if (mounted) Navigator.of(context).pop();
      case null:
    }
  }

  // ------------------------------------------------------------------ التذكرة

  /// تذكرة الشحنة: ختم حالتها بلونها، والزبون ومنطقته وهاتفه بزرّي الاتصال وواتساب، ثم بعد الخطّ المثقّب المطلوب منه
  /// والقطع
  Widget _ticket(ShipmentDetail d) {
    final r = d.recipient;
    final name = ((r['name'] as String?) ?? '').trim();
    final phone = ((r['phone'] as String?) ?? '').trim();
    final tone = Tone.of(d.row.tone, d.row.status);
    return DecoratedBox(
      decoration: const BoxDecoration(
        boxShadow: [BoxShadow(color: Color(0x1A1C1416), blurRadius: 24, offset: Offset(0, 10))],
      ),
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.fromLTRB(20, 20, 20, 16),
            decoration: const BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
            ),
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                PositionedDirectional(
                  end: -2,
                  top: 0,
                  child: _Stamp(text: d.row.status, color: tone.ink),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('الزبون', style: kf(12, Palette.muted)),
                    const SizedBox(height: 4),
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 96),
                      child: Text(name.isEmpty ? 'بلا اسم' : name, style: kf(24, Palette.ink, bold: true)),
                    ),
                    if (d.row.place.isNotEmpty) Text(d.row.place, style: kf(14, Palette.muted)),
                    if (phone.isNotEmpty) Text(phone, textDirection: TextDirection.ltr, style: kf(14, Palette.ink)),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        Expanded(
                          child: _wide(
                            'اتصال',
                            Icons.call_outlined,
                            brand.main,
                            Colors.white,
                            phone.isEmpty ? null : () => _launch(Uri(scheme: 'tel', path: phone)),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: _wide(
                            'واتساب',
                            Icons.chat_outlined,
                            brand.soft,
                            brand.main,
                            whatsappUri(phone) == null ? null : () => openWhatsApp(phone),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ],
            ),
          ),
          // الخطّ المثقّب بين نصفي التذكرة
          SizedBox(
            height: 16,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                const Positioned.fill(child: ColoredBox(color: Colors.white)),
                Positioned(
                  left: 20,
                  right: 20,
                  top: 7,
                  child: CustomPaint(painter: _Dashes(), size: const Size(0, 2)),
                ),
                const Positioned(left: -8, top: 0, child: _Notch()),
                const Positioned(right: -8, top: 0, child: _Notch()),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
            decoration: const BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(24)),
            ),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('المطلوب من الزبون', style: kf(12, Palette.muted)),
                      Text.rich(
                        TextSpan(
                          children: [
                            TextSpan(text: money(d.row.amount), style: kf(24, Palette.ink, bold: true)),
                            TextSpan(text: ' د.ع', style: kf(14, Palette.muted)),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text('القطع', style: kf(12, Palette.muted)),
                    Text('${r['pieces'] ?? 1}', style: kf(16, Palette.ink, bold: true)),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _wide(String label, IconData icon, Color fill, Color ink, VoidCallback? onTap) => Tap(
    radius: 14,
    onTap: onTap,
    child: Opacity(
      opacity: onTap == null ? .5 : 1,
      child: Ink(
        height: 48,
        decoration: BoxDecoration(color: fill, borderRadius: BorderRadius.circular(14)),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 16, color: ink),
            const SizedBox(width: 8),
            Text(label, style: kf(14, ink, bold: true)),
          ],
        ),
      ),
    ),
  );

  // ------------------------------------------------------------------ تنبيهات

  Widget _code(String code) => Container(
    padding: const EdgeInsets.fromLTRB(16, 12, 8, 12),
    decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(20)),
    child: Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('كود التسليم', style: kf(14, brand.main, bold: true)),
              Text('أرسله لزبونك؛ لا يُسلَّم الطرد إلّا به.', style: kf(12, Palette.slate)),
            ],
          ),
        ),
        Text(
          code,
          textDirection: TextDirection.ltr,
          style: kf(24, Palette.ink, bold: true, height: 1).copyWith(letterSpacing: 5),
        ),
        IconButton(
          onPressed: () => _copy(code, 'نُسخ كود التسليم.'),
          icon: Icon(Icons.copy_rounded, size: 18, color: brand.main),
          tooltip: 'نسخ',
        ),
      ],
    ),
  );

  Widget _failure(ShipmentDetail d) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(color: Tone.orange.tint, borderRadius: BorderRadius.circular(20)),
    child: Row(
      children: [
        Icon(Icons.error_outline_rounded, color: Tone.orange.ink, size: 20),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            'آخر محاولة لم تنجح: ${d.failureReason} · المحاولات ${d.attempts}',
            style: kf(12, Tone.orange.ink, bold: true),
          ),
        ),
      ],
    ),
  );

  /// ما قرّره للتوّ: علامة صحٍّ تُرسم، ونصّ النظام
  Widget _done(String message) => Rise(
    dy: 16,
    child: Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: Tone.green.tint, borderRadius: BorderRadius.circular(24)),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle),
            child: const Icon(Icons.check_rounded, size: 22, color: Palette.ok),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('سُجّل قرارك', style: kf(16, Palette.ok, bold: true)),
                Text(message, style: kf(12, Palette.ink)),
              ],
            ),
          ),
        ],
      ),
    ),
  );

  // ------------------------------------------------------------------ المسار

  /// الشحنة على الخريطة (docs/plan/61): نقطةٌ ممتلئة من هاتف المندوب، وحلقةٌ تقريبية بمحافظتها
  Widget _map(ShipmentLocation at) {
    final point = LatLng(at.lat, at.lng);
    return WhiteCard(
      radius: 24,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ClipRRect(
            borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
            child: SizedBox(
              height: 190,
              child: AppConfig.testing
                  ? const ColoredBox(color: Palette.eye)
                  : FlutterMap(
                      options: MapOptions(
                        initialCenter: point,
                        initialZoom: at.exact ? 14 : 9,
                        interactionOptions: const InteractionOptions(
                          flags: InteractiveFlag.pinchZoom | InteractiveFlag.drag,
                        ),
                      ),
                      children: [
                        TileLayer(
                          urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                          userAgentPackageName: 'com.wahaj.merchant',
                        ),
                        CircleLayer(
                          circles: [
                            CircleMarker(
                              point: point,
                              radius: at.exact ? 9 : 22,
                              color: at.exact ? brand.main : brand.main.withValues(alpha: .15),
                              borderColor: brand.main,
                              borderStrokeWidth: 3,
                            ),
                          ],
                        ),
                      ],
                    ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 14),
            child: Text(
              at.exact
                  ? 'آخر موقعٍ أرسله المندوب وهي معه${at.at != null ? ' — ${when(at.at!)}' : ''}'
                  : 'تقريبيّ: محافظتها — يدقّ حين يحدّثها المندوب من هاتفه',
              style: kf(12, Palette.slate),
            ),
          ),
        ],
      ),
    );
  }

  /// «مسار الشحنة» (docs/plan/123): خطٌّ بلون الشركة يُرسم من أوّل خطوةٍ إلى الآن، وكلّ خطوةٍ بدائرةٍ تظهر واحدةً بعد
  /// واحدة؛ والأخيرة هي «الآن» بحلقةٍ تنبض، وتحتها مع من هي، وموعد المؤجّلة، وأين الراجع
  Widget _timeline(ShipmentDetail d) {
    final steps = d.timeline;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('مسار الشحنة', style: kf(16, Palette.ink, bold: true)),
        const SizedBox(height: 12),
        for (var i = 0; i < steps.length; i++)
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  width: 20,
                  child: Stack(
                    alignment: Alignment.topCenter,
                    children: [
                      if (i < steps.length - 1)
                        Positioned(
                          top: 12,
                          bottom: 0,
                          child: Rise(
                            delay: 200 + i * 150,
                            dy: 0,
                            child: Container(width: 2, color: brand.main),
                          ),
                        ),
                      if (i > 0) Positioned(top: 0, height: 12, child: Container(width: 2, color: brand.main)),
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Rise(
                          delay: 260 + i * 150,
                          scale: true,
                          child: i == steps.length - 1 ? _Now(color: brand.main) : _Dot(color: brand.main),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 16),
                    child: Rise(
                      delay: 300 + i * 150,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(steps[i].title, style: kf(14, Palette.ink, bold: i == steps.length - 1)),
                          if (steps[i].at != null) Text(when(steps[i].at), style: kf(12, Palette.muted)),
                          if (steps[i].note != null && steps[i].note!.isNotEmpty)
                            Text(steps[i].note!, style: kf(12, Palette.slate)),
                          if (i == steps.length - 1) ...[
                            if (d.courier != null)
                              Text('مع المندوب: ${d.courier}', style: kf(12, brand.main, bold: true)),
                            // موعد المؤجّلة (docs/plan/86)، وأين الراجع الآن (docs/plan/87)
                            if (d.row.until != null) ...[
                              const SizedBox(height: 4),
                              PostponedChip(d.row.until!, size: 12),
                            ],
                            if (d.row.returnPlace != null) ReturnPlace(d.row.returnPlace!, size: 12),
                          ],
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  // ------------------------------------------------------------------ الزبون

  Widget _recipient(ShipmentDetail d) {
    final r = d.recipient;
    String? text(String k) => (r[k] as String?)?.trim().isEmpty ?? true ? null : r[k] as String;
    final place = [text('governorate'), text('city')].whereType<String>().join(' — ');
    return _Card(
      title: 'الزبون والعنوان',
      child: Column(
        children: [
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

  Widget _line(String label, String value, {bool ltr = false, bool strong = false}) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(width: 118, child: Text(label, style: kf(12, Palette.muted))),
        Expanded(
          child: Text(
            value,
            textDirection: ltr ? TextDirection.ltr : null,
            textAlign: TextAlign.start,
            style: kf(14, strong ? brand.main : Palette.ink, bold: strong),
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
          Expanded(child: Text(label, style: kf(14, color ?? Palette.slate))),
          Text(
            '${money(amount)} د.ع',
            textDirection: TextDirection.ltr,
            style: kf(14, color ?? Palette.ink, bold: true),
          ),
        ],
      ),
    );

    return _Card(
      title: 'حساب الشحنة',
      child: Column(
        children: [
          row('المطلوب من الزبون', v('cod')),
          if (v('collected') > 0) row('المحصَّل فعلاً', v('collected'), color: Palette.ok),
          // الأجور والمستحقّ لمن يرى المالية (docs/plan/87)
          if (m['hidden'] != true) ...[
            row('أجرة التوصيل', v('delivery_fee')),
            if (v('cod_fee') > 0) row('عمولة التحصيل', v('cod_fee')),
            if (v('return_fee') > 0) row('أجرة الراجع', v('return_fee'), color: Tone.amber.ink),
            const Divider(height: 18, color: Palette.line),
            Row(
              children: [
                Expanded(child: Text(owed ? 'لك' : 'عليك', style: kf(16, Palette.ink, bold: true))),
                Text(
                  '${money(v('due'))} د.ع',
                  textDirection: TextDirection.ltr,
                  style: kf(16, owed ? brand.main : Palette.returnRed, bold: true),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ أسفل الشاشة

  /// «أرسل التتبّع للزبون» داكناً، وبجانبه «عدّلها» ما دامت تُعدَّل، وإلّا نسخ الرابط
  Widget _footer(ShipmentDetail d) {
    final phone = (d.recipient['phone'] as String?) ?? '';
    final message = 'تتبّع شحنتك ${d.row.number}: ${d.trackingUrl}';
    final send = whatsappUri(phone, text: message);
    final bottom = MediaQuery.paddingOf(context).bottom;
    return Container(
      padding: EdgeInsets.fromLTRB(16, 12, 16, bottom > 0 ? bottom + 8 : 20),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Palette.line)),
      ),
      child: Row(
        children: [
          Expanded(
            child: Tap(
              radius: 16,
              onTap: send == null ? null : () => _launch(send),
              child: Opacity(
                opacity: send == null ? .5 : 1,
                child: Ink(
                  height: 52,
                  decoration: BoxDecoration(color: Palette.ink, borderRadius: BorderRadius.circular(16)),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.send_rounded, size: 16, color: Colors.white),
                      const SizedBox(width: 8),
                      Flexible(
                        child: Text(
                          'أرسل التتبّع للزبون',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: kf(14, Colors.white, bold: true),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Tap(
            radius: 16,
            onTap: d.edit?.mode != null ? () => _edit(d) : () => _copy(d.trackingUrl, 'نُسخ رابط التتبّع.'),
            child: Ink(
              height: 52,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: const Color(0xFFDCD4D8)),
              ),
              child: Center(
                child: Text(d.edit?.mode != null ? 'عدّلها' : 'نسخ الرابط', style: kf(14, Palette.ink, bold: true)),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// ختم الحالة على التذكرة: إطارٌ بلونها مائلٌ قليلاً، ينطبع حين تظهر
class _Stamp extends StatelessWidget {
  const _Stamp({required this.text, required this.color});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Rise(
    delay: 300,
    scale: true,
    child: Transform.rotate(
      angle: -10 * math.pi / 180,
      child: Container(
        constraints: const BoxConstraints(maxWidth: 120),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
        decoration: BoxDecoration(
          border: Border.all(color: color, width: 2),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis, style: kf(14, color, bold: true)),
      ),
    ),
  );
}

/// نصف دائرةٍ بلون الأرضيّة على طرفي الخطّ المثقّب
class _Notch extends StatelessWidget {
  const _Notch();

  @override
  Widget build(BuildContext context) => Container(
    width: 16,
    height: 16,
    decoration: const BoxDecoration(color: Palette.bg, shape: BoxShape.circle),
  );
}

class _Dashes extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = const Color(0xFFE4DDE0)
      ..strokeWidth = 1.5;
    for (var x = 0.0; x < size.width; x += 8) {
      canvas.drawLine(Offset(x, 0), Offset(math.min(x + 4, size.width), 0), paint);
    }
  }

  @override
  bool shouldRepaint(covariant _Dashes old) => false;
}

/// خطوةٌ مضت: دائرةٌ ممتلئة بلون الشركة وعلامة صحّ
class _Dot extends StatelessWidget {
  const _Dot({required this.color});

  final Color color;

  @override
  Widget build(BuildContext context) => Container(
    width: 20,
    height: 20,
    decoration: BoxDecoration(color: color, shape: BoxShape.circle),
    child: const Icon(Icons.check_rounded, size: 12, color: Colors.white),
  );
}

/// «الآن»: حلقةٌ بلون الشركة تنبض حولها — وبلا نبضٍ لمن أطفأ الحركة
class _Now extends StatefulWidget {
  const _Now({required this.color});

  final Color color;

  @override
  State<_Now> createState() => _NowState();
}

class _NowState extends State<_Now> with SingleTickerProviderStateMixin {
  late final c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1800));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final still = (MediaQuery.maybeDisableAnimationsOf(context) ?? false) || AppConfig.testing;
    if (!still && !c.isAnimating) c.repeat();
  }

  @override
  void dispose() {
    c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
    animation: c,
    builder: (_, _) => Container(
      width: 20,
      height: 20,
      decoration: BoxDecoration(
        color: Colors.white,
        shape: BoxShape.circle,
        border: Border.all(color: widget.color, width: 2),
        boxShadow: [
          BoxShadow(
            color: widget.color.withValues(alpha: .45 * (1 - c.value)),
            spreadRadius: 12 * c.value,
          ),
        ],
      ),
    ),
  );
}

/// لوحة القرار داخل الشحنة التي لم تُسلَّم (docs/plan/54، 123): سبب الفشل ومحاولاته، ثم ثلاثة خياراتٍ بلون حالتها —
/// أعد التوصيل، أو أجّلها (بموعد)، أو أرجعها — وما قاله الزبون (اختياري)، ثم زرّ تثبيتٍ يقول ما سيحدث، فلا يُسجَّل
/// قرارٌ بلمسةٍ خاطئة. بطريق «للمعالجة» نفسه، ويُكتب باسمه
class _Decide extends StatefulWidget {
  const _Decide({required this.brand, required this.detail, required this.onDone});

  final Brand brand;
  final ShipmentDetail detail;

  /// سُجّل: رسالة النظام
  final ValueChanged<String> onDone;

  @override
  State<_Decide> createState() => _DecideState();
}

class _DecideState extends State<_Decide> {
  String? action;
  late DateTime until = _day(1);
  final note = TextEditingController();
  bool saving = false;
  String? error;

  static DateTime _day(int n) {
    final now = DateTime.now();
    return DateTime(now.year, now.month, now.day + n);
  }

  static const _options = [
    ('redeliver', 'أعد التوصيل', 'تخرج مع المندوب مرّةً أخرى', Icons.replay_rounded),
    ('postpone', 'أجّلها', 'إلى يومٍ اتّفقت عليه مع زبونك', Icons.event_outlined),
    ('return', 'أرجعها', 'تعود إليك ولا تُحاوَل ثانيةً', Icons.undo_rounded),
  ];

  static Tone _tone(String a) => switch (a) {
    'redeliver' => Tone.blue,
    'postpone' => Tone.amber,
    _ => Tone.red,
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
    final a = action;
    if (a == null) return;
    setState(() => (saving = true, error = null));
    try {
      final message = await Api.instance.process(
        widget.detail.row.id,
        a,
        until: a == 'postpone' ? _date(until) : null,
        note: note.text.trim(),
      );
      if (mounted) widget.onDone(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, saving = false));
    }
  }

  String get _button {
    final a = action;
    if (a == null) return 'اختر قرارك';
    if (a == 'postpone') {
      final days = DateTime(until.year, until.month, until.day).difference(_day(0)).inDays;
      final when = switch (days) {
        1 => 'إلى الغد',
        2 => 'إلى بعد غد',
        _ => 'إلى ${until.day}/${until.month}',
      };
      return 'ثبّت: أجّلها $when';
    }
    return 'ثبّت: ${_options.firstWhere((o) => o.$1 == a).$2}';
  }

  @override
  Widget build(BuildContext context) {
    final d = widget.detail;
    final brand = widget.brand;
    final tries = d.attempts <= 1 ? 'محاولة واحدة' : (d.attempts == 2 ? 'محاولتان' : '${d.attempts} محاولات');
    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24), boxShadow: cardShadow),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            color: Tone.orange.tint,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(color: Tone.orange.ink, shape: BoxShape.circle),
                  child: const Icon(Icons.priority_high_rounded, size: 20, color: Colors.white),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('لم تُسلَّم، وتنتظر قرارك', style: kf(14, Tone.orange.ink, bold: true)),
                      Text.rich(
                        TextSpan(
                          children: [
                            if (d.failureReason != null)
                              TextSpan(text: '${d.failureReason} · ', style: kf(12, Palette.ink, bold: true)),
                            TextSpan(text: tries, style: kf(12, Palette.slate)),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                for (final (i, o) in _options.indexed) ...[
                  if (i > 0) const SizedBox(height: 8),
                  Rise(delay: 360 + i * 60, child: _option(o)),
                ],
                if (action == 'postpone') ...[const SizedBox(height: 10), Rise(child: _days())],
                if (action != null) ...[
                  const SizedBox(height: 10),
                  InputBox(controller: note, hint: 'ما قاله الزبون (اختياري)', maxLength: 255),
                ],
                if (error != null) ErrorNote(error!),
                const SizedBox(height: 12),
                Tap(
                  radius: 16,
                  onTap: action == null || saving ? null : _save,
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 300),
                    height: 52,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: action == null
                          ? const Color(0xFFECE6E8)
                          : (action == 'return' ? Palette.returnRed : brand.main),
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: saving
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                          )
                        : Text(_button, style: kf(16, action == null ? Palette.slate : Colors.white, bold: true)),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _option((String, String, String, IconData) o) {
    final (key, label, sub, icon) = o;
    final on = action == key;
    final tone = _tone(key);
    return Semantics(
      selected: on,
      button: true,
      label: '$label، $sub',
      excludeSemantics: true,
      onTap: () => setState(() => action = key),
      child: Tap(
        radius: 18,
        onTap: () => setState(() => action = key),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 250),
          constraints: const BoxConstraints(minHeight: 64),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            color: on ? tone.tint : Colors.white,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: on ? tone.ink : Palette.line, width: 1.5),
          ),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: tone.tint, borderRadius: BorderRadius.circular(14)),
                child: Icon(icon, size: 18, color: tone.ink),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label, style: kf(14, Palette.ink, bold: true)),
                    Text(sub, style: kf(12, Palette.slate)),
                  ],
                ),
              ),
              AnimatedContainer(
                duration: const Duration(milliseconds: 250),
                width: 22,
                height: 22,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: on ? tone.ink : const Color(0xFFB9AFB4), width: 2),
                ),
                child: Center(
                  child: AnimatedScale(
                    scale: on ? 1 : 0,
                    duration: const Duration(milliseconds: 300),
                    curve: const Cubic(.2, .9, .3, 1.4),
                    child: Container(
                      width: 10,
                      height: 10,
                      decoration: BoxDecoration(color: tone.ink, shape: BoxShape.circle),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// مواعيد التأجيل: غداً، بعد غد، بعد 3 أيام — أو يومٌ آخر من التقويم
  Widget _days() {
    Widget chip(String label, bool on, VoidCallback onTap) => Expanded(
      child: Tap(
        radius: 14,
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 250),
          height: 44,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: on ? Tone.amber.ink : Colors.white,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: on ? Tone.amber.ink : const Color(0xFFE7D9B8), width: 1.5),
          ),
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(label, style: kf(14, on ? Colors.white : Palette.ink, bold: true)),
          ),
        ),
      ),
    );
    final known = [1, 2, 3].map(_day).toList();
    final other = !known.contains(DateTime(until.year, until.month, until.day));
    return Row(
      children: [
        chip('غداً', until == known[0], () => setState(() => until = known[0])),
        const SizedBox(width: 8),
        chip('بعد غد', until == known[1], () => setState(() => until = known[1])),
        const SizedBox(width: 8),
        chip('بعد 3 أيام', until == known[2], () => setState(() => until = known[2])),
        const SizedBox(width: 8),
        chip(other ? '${until.day}/${until.month}' : 'يومٌ آخر', other, _pick),
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
    radius: 24,
    padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: kf(16, Palette.ink, bold: true)),
        const SizedBox(height: 8),
        child,
      ],
    ),
  );
}
