import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/location.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/whatsapp.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import '../widgets/whatsapp.dart';
import 'scan_screen.dart';
import 'shipment_sheets.dart';

/// الشحنة عند الباب (docs/plan/66): الزبون والعنوان والمبلغ، ثم سُلّمت · لم تُسلَّم · مؤجلة — بلا كود
/// تسليم (docs/plan/76). المبلغ يُعرض ولا يُعدَّل: تغييره طلبٌ إلى الكول سنتر.
class ShipmentScreen extends StatefulWidget {
  const ShipmentScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<ShipmentScreen> createState() => _ShipmentScreenState();
}

class _ShipmentScreenState extends State<ShipmentScreen> {
  ShipmentDetail? s;
  String? error;
  bool busy = false;

  /// ما مُسح من الطرد قبل التسجيل (docs/plan/72) — يُرسل مع الإجراء ويطابقه النظام
  String? scanned;
  String? scanError;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.shipment(widget.id);
      if (mounted) setState(() => (s = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  // ---------------------------------------------------------------- الإجراءات

  Future<void> _deliver({bool partial = false}) async {
    final d = s!;
    final amount = partial ? d.partialAmount! : d.amount;
    final ok = await confirm(
      context,
      partial ? 'واصل جزئي' : 'سُلّمت الشحنة؟',
      partial
          ? 'قبضت ${money(amount)} د.ع كما اعتمد الكول سنتر، والباقي يرجع معك للمخزن.'
          : 'قبضت ${money(amount)} د.ع من الزبون وسلّمته الشحنة.',
      partial ? 'نعم، واصل جزئي' : 'نعم، سُلّمت',
    );
    if (ok) await _act(partial ? 'partially_delivered' : 'delivered');
  }

  Future<void> _notDelivered({required bool postpone}) async {
    final picked = await showReasons(context, brand, postpone: postpone);
    if (picked != null) {
      await _act(postpone ? 'postponed' : 'failed_attempt', reasonId: picked.$1, note: picked.$2, until: picked.$3);
    }
  }

  Future<void> _act(String action, {int? reasonId, String? note, DateTime? until}) async {
    if (busy) return;
    setState(() => busy = true);
    final messenger = ScaffoldMessenger.of(context);
    final navigator = Navigator.of(context);
    try {
      final here = await LocationShare.instance.here();
      final done = await Api.instance.act(
        widget.id,
        action,
        reasonId: reasonId,
        note: note,
        scan: scanned,
        until: until,
        lat: here?.lat,
        lng: here?.lng,
      );
      messenger
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text(done.message, style: font(13, w6, Colors.white)),
            backgroundColor: statusColor(done.shipment.status),
            behavior: SnackBarBehavior.floating,
          ),
        );
      // سُجّلت: إلى الشحنة التالية في «مهامي»
      if (mounted) navigator.pop(true);
    } on ApiError catch (e) {
      if (!mounted) return;
      if (e.fields.containsKey('scan')) {
        // ما مُسح ليس هذا الطرد: يُمسح من جديد
        setState(() => (scanned = null, scanError = e.fields['scan']));
      } else {
        toast(context, e.message, bad: true);
        // لم تعد بيده (سحبها المكتب أو سُجّلت من الموقع): تُعرض بحالها الآن
        if (e.fields.containsKey('action')) _load();
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  /// الماسح نفسه، يعود بما قرأه نصّاً — أو يُكتب الرقم في الماسح
  Future<void> _scanParcel() async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(fullscreenDialog: true, builder: (_) => ScanScreen(brand: brand, returnCode: true)),
    );
    if (code != null && mounted) setState(() => (scanned = code, scanError = null));
  }

  Future<void> _ticket() async {
    final made = await showTicketSheet(context, brand, s!);
    if (made != null && mounted) {
      toast(context, made);
      _load();
    }
  }

  Future<void> _call(String phone) => launchUrl(Uri(scheme: 'tel', path: phone));

  Future<void> _map() async {
    final d = s!;
    final uri = d.lat != null && d.lng != null
        ? Uri.parse('geo:${d.lat},${d.lng}?q=${d.lat},${d.lng}')
        : Uri.parse('geo:0,0?q=${Uri.encodeComponent([d.place, d.landmark].whereType<String>().join(' '))}');
    if (!await launchUrl(uri) && mounted) toast(context, 'لا يوجد تطبيق خرائط على الهاتف.', bad: true);
  }

  // ---------------------------------------------------------------- الشاشة

  @override
  Widget build(BuildContext context) {
    final d = s;
    return Scaffold(
      body: SafeArea(
        child: d == null
            ? Column(
                children: [
                  const PageBar(title: 'الشحنة'),
                  Expanded(
                    child: Waiting(brand: brand, error: error, onRetry: _load),
                  ),
                ],
              )
            : Column(
                children: [
                  PageBar(
                    title: tag(d.number),
                    subtitle: d.merchant == null ? null : 'من: ${d.merchant}',
                    trailing: Pill(d.statusLabel, color: statusColor(d.status), size: 12),
                  ),
                  Expanded(
                    child: RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                        children: [
                          // مؤجّلةٌ بموعدها، أو لم تُسلَّم وتنتظر قرار المعالجة (docs/plan/75)
                          if (d.status == 'postponed' || d.waiting) ...[_stateCard(d), const SizedBox(height: 12)],
                          if (d.needsScan && d.canAct) ...[_scanCard(d), const SizedBox(height: 12)],
                          _customer(d),
                          const SizedBox(height: 12),
                          _amount(d),
                          if (_hasDetails(d)) ...[const SizedBox(height: 12), _details(d)],
                          if (!d.canAct && !d.canResume && !d.waiting) ...[
                            const SizedBox(height: 12),
                            Text(
                              'لا إجراء على هذه الشحنة الآن: حالتها «${d.statusLabel}».',
                              textAlign: TextAlign.center,
                              style: font(12.5, w6, Palette.slate, height: 1.6),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  if (d.canAct) _actions(d),
                  if (d.canResume) _resumeBar(d),
                ],
              ),
      ),
    );
  }

  Widget _customer(ShipmentDetail d) => WhiteCard(
    padding: const EdgeInsets.all(16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(d.recipient.isEmpty ? 'الزبون' : d.recipient, style: font(17, w8, Palette.ink)),
                  Directionality(
                    textDirection: TextDirection.ltr,
                    child: Text(d.phone, style: font(13, w6, Palette.slate)),
                  ),
                  if ((d.phoneAlt ?? '').isNotEmpty)
                    Row(
                      children: [
                        Tap(
                          radius: 6,
                          onTap: () => _call(d.phoneAlt!),
                          child: Text('رقم ثانٍ: ${d.phoneAlt}', style: font(12, w7, brand.main, height: 1.6)),
                        ),
                        const SizedBox(width: 6),
                        WhatsAppButton(
                          phone: d.phoneAlt,
                          size: 24,
                          tooltip: 'واتساب الرقم الثاني',
                          text: () => WhatsAppTemplate.fill(WhatsAppTemplate.text.value, d),
                        ),
                      ],
                    ),
                ],
              ),
            ),
            if (d.phone.isNotEmpty) ...[
              // واتساب الزبون ورسالته جاهزة من القالب (docs/plan/74)
              WhatsAppButton(
                phone: d.phone,
                size: 48,
                tooltip: 'واتساب الزبون',
                text: () => WhatsAppTemplate.fill(WhatsAppTemplate.text.value, d),
              ),
              const SizedBox(width: 8),
              Tooltip(
                message: 'اتصل بالزبون',
                child: Tap(
                  radius: 24,
                  onTap: () => _call(d.phone),
                  child: Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(gradient: brand.tile, shape: BoxShape.circle),
                    child: const Icon(Icons.call_rounded, color: Colors.white, size: 22),
                  ),
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Padding(
              padding: EdgeInsets.only(top: 2),
              child: Icon(Icons.location_on_outlined, size: 20, color: Palette.slate),
            ),
            const SizedBox(width: 6),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(d.place, style: font(14, w7, Palette.ink, height: 1.4)),
                  if ((d.landmark ?? '').isNotEmpty)
                    Text(d.landmark!, style: font(12.5, w6, Palette.slate, height: 1.5)),
                ],
              ),
            ),
            OutlinedButton(
              onPressed: _map,
              style: OutlinedButton.styleFrom(
                visualDensity: VisualDensity.compact,
                side: const BorderSide(color: Palette.line),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              child: Text('افتح الخريطة', style: font(12, w7, Palette.ink)),
            ),
          ],
        ),
        if ((d.merchantPhone ?? '').isNotEmpty) ...[
          const Divider(height: 22, color: Palette.line),
          Row(
            children: [
              Expanded(child: Text('التاجر: ${d.merchant ?? ''}', style: font(12.5, w6, Palette.slate))),
              WhatsAppButton(phone: d.merchantPhone, size: 30, tooltip: 'واتساب التاجر'),
              const SizedBox(width: 6),
              Tap(
                radius: 8,
                onTap: () => _call(d.merchantPhone!),
                child: Padding(
                  padding: const EdgeInsets.all(4),
                  child: Text('اتصل بالتاجر', style: font(12, w8, brand.main)),
                ),
              ),
            ],
          ),
        ],
      ],
    ),
  );

  Widget _amount(ShipmentDetail d) {
    final t = d.ticket;
    return WhiteCard(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('المبلغ المطلوب قبضه', style: font(12.5, w6, Palette.slate)),
          Text.rich(
            TextSpan(
              children: [
                TextSpan(text: money(d.amount), style: font(28, w8, Palette.ink)),
                TextSpan(text: ' د.ع', style: font(15, w7, Palette.ink)),
              ],
            ),
          ),
          if (d.canAct) ...[
            const SizedBox(height: 6),
            Text(
              'المبلغ لا يتغيّر عند الباب. إن قال الزبون مبلغاً آخر، اطلب تغيير المبلغ من الكول سنتر.',
              style: font(12, w6, Palette.slate, height: 1.55),
            ),
            if (t != null) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: (t.open ? Palette.warn : (t.status == 'approved' ? Palette.ok : Palette.bad)).withValues(
                    alpha: .08,
                  ),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  switch (t.status) {
                    'open' =>
                      'طلبك ${t.number} (${t.kindLabel}: ${money(t.requested)}) ينتظر جواب الكول سنتر. لا تسلّم بالمبلغ الجديد حتى يُعتمد.',
                    'approved' =>
                      d.partialAmount != null
                          ? 'اعتُمد واصلٌ جزئي بمبلغ ${money(d.partialAmount!)} د.ع — سجّله بزرّ «واصل جزئي».'
                          : 'اعتُمد طلبك ${t.number}: المبلغ صار ${money(d.amount)} د.ع.',
                    _ => 'رُفض طلبك ${t.number}: المبلغ يبقى ${money(d.amount)} د.ع.',
                  },
                  style: font(
                    12.5,
                    w7,
                    t.open ? Palette.warn : (t.status == 'approved' ? Palette.ok : Palette.bad),
                    height: 1.55,
                  ),
                ),
              ),
            ],
            if (t == null || t.status == 'rejected') ...[
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: busy ? null : _ticket,
                icon: Icon(Icons.edit_note_rounded, color: brand.main, size: 19),
                label: Text('اطلب تغيير المبلغ', style: font(13, w7, brand.main)),
                style: OutlinedButton.styleFrom(
                  side: BorderSide(color: brand.border),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }

  bool _hasDetails(ShipmentDetail d) =>
      (d.notes ?? '').isNotEmpty || (d.description ?? '').isNotEmpty || d.fragile || d.allowOpen || d.attempts > 0;

  Widget _details(ShipmentDetail d) => WhiteCard(
    padding: const EdgeInsets.all(16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if ((d.notes ?? '').isNotEmpty) ...[
          Text('ملاحظة التاجر', style: font(12, w7, Palette.slate)),
          Text(d.notes!, style: font(13.5, w7, Palette.ink, height: 1.55)),
          const SizedBox(height: 8),
        ],
        if ((d.description ?? '').isNotEmpty)
          Text('المحتوى: ${d.description} · ${d.pieces} قطعة', style: font(12.5, w6, Palette.slate, height: 1.6)),
        if (d.attempts > 0)
          Text(
            'محاولات سابقة: ${d.attempts}${d.failure == null ? '' : ' · آخر سبب: ${d.failure}'}',
            style: font(12.5, w6, Palette.warn, height: 1.6),
          ),
        if (d.fragile || d.allowOpen) ...[
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            children: [
              if (d.fragile) const Pill('قابل للكسر', color: Palette.warn),
              if (d.allowOpen) const Pill('يُسمح بفتحها', color: Palette.ok),
            ],
          ),
        ],
      ],
    ),
  );

  /// «امسح الطرد»: لا يُسجَّل شيءٌ عند الباب قبل مسحه (docs/plan/72)
  Widget _scanCard(ShipmentDetail d) {
    final done = scanned != null;
    return Tap(
      radius: 20,
      onTap: _scanParcel,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: done ? Palette.okSoft : brand.softer,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: done ? const Color(0xFFC6E5CF) : brand.border),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Container(
                  width: 42,
                  height: 42,
                  decoration: BoxDecoration(
                    gradient: done ? null : brand.hero,
                    color: done ? Palette.ok : null,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(
                    done ? Icons.check_rounded : Icons.qr_code_scanner_rounded,
                    color: Colors.white,
                    size: 22,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        done ? 'مسحت الطرد' : 'امسح الطرد قبل التسجيل',
                        style: font(14.5, w8, done ? Palette.ok : Palette.ink),
                      ),
                      Text(
                        done
                            ? 'يتحقّق النظام منه حين تسجّل. اضغط لتمسح من جديد.'
                            : 'باركود الوصل الملصق عليه، أو اكتب رقمه.',
                        style: font(12, w6, Palette.slate, height: 1.4),
                      ),
                    ],
                  ),
                ),
                if (!done) Chev(size: 10, stroke: 1.7, color: brand.deep),
              ],
            ),
            if (scanError != null) ...[
              const SizedBox(height: 8),
              Text(scanError!, style: font(12.5, w7, Palette.returnRed, height: 1.5)),
            ],
          ],
        ),
      ),
    );
  }

  /// بطاقة الحال: المؤجّلة بموعدها أصفر، وما ينتظر قرار المعالجة أحمر — بلونَي أزرارهما
  Widget _stateCard(ShipmentDetail d) {
    final postponed = d.status == 'postponed';
    final color = postponed ? OutcomeColors.postponed : OutcomeColors.returned;
    final ink = OutcomeColors.ink(color);
    final until = d.until;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: OutcomeColors.soft(color),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: color.withValues(alpha: .5)),
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(14)),
            child: Icon(
              postponed ? Icons.event_repeat_rounded : Icons.hourglass_top_rounded,
              color: Colors.white,
              size: 23,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  postponed ? (until == null ? 'مؤجّلة بلا موعد' : 'موعدها ${appointmentLabel(until)}') : 'لم تُسلَّم',
                  style: font(15, w8, ink),
                ),
                Text(
                  postponed
                      ? 'ابقها معك. يذكّرك التطبيق في موعدها، فاضغط «أوصلها الآن» وسجّلها عند الباب.'
                      : 'تنتظر قرار الكول سنتر: إعادة توصيل أو تأجيل أو إرجاع. ابقها معك حتى يُقرَّر.',
                  style: font(12, w6, Palette.slate, height: 1.5),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// «أوصلها الآن»: المؤجّلة بيده تعود للتوصيل فيُسجَّل عليها عند الباب
  Widget _resumeBar(ShipmentDetail d) => Container(
    padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
    decoration: const BoxDecoration(
      color: Colors.white,
      boxShadow: [BoxShadow(color: Palette.shadow, blurRadius: 12, offset: Offset(0, -2))],
    ),
    child: _Outcome(
      label: 'أوصلها الآن',
      icon: Icons.local_shipping_rounded,
      color: OutcomeColors.delivered,
      filled: true,
      busy: busy,
      onTap: busy ? null : () => _resume(d),
    ),
  );

  Future<void> _resume(ShipmentDetail d) async {
    setState(() => busy = true);
    try {
      final done = await Api.instance.resume(d.id);
      if (!mounted) return;
      toast(context, done.message);
      setState(() => s = done.shipment);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Widget _actions(ShipmentDetail d) {
    // لا شيء قبل مسح الطرد إن طُلب (docs/plan/72)
    final scanMissing = d.needsScan && scanned == null;
    final partial = d.actions.contains('partially_delivered') && d.partialAmount != null;
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
      decoration: const BoxDecoration(
        color: Colors.white,
        boxShadow: [BoxShadow(color: Palette.shadow, blurRadius: 12, offset: Offset(0, -2))],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // «سُلّمت» بلا كود تسليم (docs/plan/76) — ولا تُفعَّل قبل مسح الطرد إن فُتح المسح. والألوان ألوان النتيجة
          // نفسها (docs/plan/74): سُلّمت أخضر، لم تُسلَّم (راجع) أحمر، مؤجلة أصفر — تُعرف قبل أن تُقرأ
          _Outcome(
            label: d.ticket?.open == true ? 'سُلّمت بالمبلغ الأصلي' : 'سُلّمت',
            icon: Icons.check_circle_rounded,
            color: OutcomeColors.delivered,
            filled: true,
            busy: busy,
            onTap: busy || scanMissing ? null : () => _deliver(),
          ),
          if (partial) ...[
            const SizedBox(height: 8),
            _Outcome(
              label: 'واصل جزئي بـ ${money(d.partialAmount!)}',
              icon: Icons.call_split_rounded,
              color: OutcomeColors.delivered,
              onTap: busy || scanMissing ? null : () => _deliver(partial: true),
            ),
          ],
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: _Outcome(
                  label: 'لم تُسلَّم',
                  icon: Icons.assignment_return_rounded,
                  color: OutcomeColors.returned,
                  onTap: busy || scanMissing ? null : () => _notDelivered(postpone: false),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _Outcome(
                  label: 'مؤجلة',
                  icon: Icons.event_repeat_rounded,
                  color: OutcomeColors.postponed,
                  onTap: busy || scanMissing ? null : () => _notDelivered(postpone: true),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// زرّ نتيجةٍ بلونها: الممتلئ لـ«سُلّمت»، والباقي بخلفيةٍ فاتحة من لونه وحدٍّ منه
class _Outcome extends StatelessWidget {
  const _Outcome({
    required this.label,
    required this.icon,
    required this.color,
    required this.onTap,
    this.filled = false,
    this.busy = false,
  });

  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback? onTap;
  final bool filled;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final fg = filled ? Colors.white : OutcomeColors.ink(color);
    return Tap(
      radius: 16,
      onTap: onTap,
      shadow: filled && onTap != null
          ? [
              BoxShadow(
                color: color.withValues(alpha: .45),
                blurRadius: 18,
                spreadRadius: -6,
                offset: const Offset(0, 8),
              ),
            ]
          : null,
      child: Opacity(
        opacity: onTap == null && !busy ? .45 : 1,
        child: Ink(
          height: filled ? 54 : 50,
          decoration: BoxDecoration(
            gradient: filled ? LinearGradient(colors: [color, Color.lerp(color, Colors.black, .18)!]) : null,
            color: filled ? null : OutcomeColors.soft(color),
            borderRadius: BorderRadius.circular(16),
            border: filled ? null : Border.all(color: color.withValues(alpha: .55), width: 1.3),
          ),
          child: busy
              ? Center(
                  child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: fg)),
                )
              : Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(icon, size: filled ? 22 : 19, color: fg),
                    const SizedBox(width: 7),
                    Text(label, style: font(filled ? 16 : 14.5, w8, fg)),
                  ],
                ),
        ),
      ),
    );
  }
}
