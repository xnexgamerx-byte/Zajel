import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/location.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/digits.dart';
import '../widgets/page.dart';
import 'shipment_sheets.dart';

/// الشحنة عند الباب (docs/plan/66): الزبون والعنوان والمبلغ، والكود إن كانت بكود، ثم
/// سُلّمت · لم تُسلَّم · مؤجلة. المبلغ يُعرض ولا يُعدَّل: تغييره طلبٌ إلى الكول سنتر.
class ShipmentScreen extends StatefulWidget {
  const ShipmentScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<ShipmentScreen> createState() => _ShipmentScreenState();
}

class _ShipmentScreenState extends State<ShipmentScreen> {
  final code = TextEditingController();
  ShipmentDetail? s;
  String? error;
  String? codeError;
  bool busy = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    code.addListener(() => setState(() => codeError = null));
    _load();
  }

  @override
  void dispose() {
    code.dispose();
    super.dispose();
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
    if (d.needsCode && code.text.trim().length < 4) {
      setState(() => codeError = 'اطلب كود التسليم من الزبون واكتبه هنا.');
      return;
    }
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
    if (picked != null) await _act(postpone ? 'postponed' : 'failed_attempt', reasonId: picked.$1, note: picked.$2);
  }

  Future<void> _act(String action, {int? reasonId, String? note}) async {
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
        code: code.text.trim(),
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
      if (e.fields.containsKey('delivery_code')) {
        setState(() => codeError = e.fields['delivery_code']);
      } else {
        toast(context, e.message, bad: true);
        // لم تعد بيده (سحبها المكتب أو سُجّلت من الموقع): تُعرض بحالها الآن
        if (e.fields.containsKey('action')) _load();
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
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
                          _customer(d),
                          const SizedBox(height: 12),
                          _amount(d),
                          if (d.needsCode && d.canAct) ...[const SizedBox(height: 12), _code()],
                          if (_hasDetails(d)) ...[const SizedBox(height: 12), _details(d)],
                          if (!d.canAct) ...[
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
                    Tap(
                      radius: 6,
                      onTap: () => _call(d.phoneAlt!),
                      child: Text('رقم ثانٍ: ${d.phoneAlt}', style: font(12, w7, brand.main, height: 1.6)),
                    ),
                ],
              ),
            ),
            if (d.phone.isNotEmpty)
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

  Widget _code() => WhiteCard(
    padding: const EdgeInsets.all(16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('كود التسليم', style: font(15, w8, Palette.ink)),
        Text('اطلبه من الزبون قبل التسليم', style: font(12, w6, Palette.slate)),
        const SizedBox(height: 12),
        Container(
          height: 58,
          decoration: BoxDecoration(
            color: Palette.field,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: codeError != null ? Palette.bad : Palette.line,
              width: codeError != null ? 1.4 : 1,
            ),
          ),
          child: TextField(
            controller: code,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            textDirection: TextDirection.ltr,
            maxLength: 10,
            inputFormatters: const [LatinDigits()],
            style: font(24, w8, Palette.ink).copyWith(letterSpacing: 10),
            decoration: InputDecoration(
              counterText: '',
              hintText: '····',
              hintStyle: font(24, w8, Palette.muted).copyWith(letterSpacing: 10),
              border: InputBorder.none,
              contentPadding: const EdgeInsets.symmetric(vertical: 12),
            ),
          ),
        ),
        if (codeError != null) ErrorNote(codeError!),
        const SizedBox(height: 8),
        Text(
          'خمس محاولات خاطئة في الساعة، ثم يُقفل الكود',
          textAlign: TextAlign.center,
          style: font(11.5, w6, Palette.slate),
        ),
      ],
    ),
  );

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

  Widget _actions(ShipmentDetail d) {
    final codeMissing = d.needsCode && code.text.trim().length < 4;
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
          // «سُلّمت» لا تُفعَّل قبل كود التسليم إن كانت الشحنة بكود (docs/plan/66)
          SheetButton(
            brand: brand,
            label: d.ticket?.open == true ? 'سُلّمت بالمبلغ الأصلي' : 'سُلّمت',
            icon: Icons.check_circle_outline_rounded,
            busy: busy,
            onTap: codeMissing ? null : () => _deliver(),
          ),
          if (partial) ...[
            const SizedBox(height: 8),
            SheetButton(
              brand: brand,
              outlined: true,
              label: 'واصل جزئي بـ ${money(d.partialAmount!)}',
              onTap: busy || codeMissing ? null : () => _deliver(partial: true),
            ),
          ],
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(child: _secondary('لم تُسلَّم', Palette.warn, () => _notDelivered(postpone: false))),
              const SizedBox(width: 10),
              Expanded(child: _secondary('مؤجلة', Palette.ink, () => _notDelivered(postpone: true))),
            ],
          ),
        ],
      ),
    );
  }

  Widget _secondary(String label, Color color, VoidCallback onTap) => SizedBox(
    height: 48,
    child: OutlinedButton(
      onPressed: busy ? null : onTap,
      style: OutlinedButton.styleFrom(
        side: BorderSide(color: color.withValues(alpha: .4)),
        backgroundColor: color == Palette.warn ? Palette.warn.withValues(alpha: .06) : Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      child: Text(label, style: font(14, w8, color)),
    ),
  );
}
