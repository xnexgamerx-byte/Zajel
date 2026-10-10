import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'scan_screen.dart';
import 'shipment_screen.dart';

Future<void> openRequests(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => RequestsScreen(brand: brand)));

/// إيصال راجعٍ بشحناته (docs/plan/61) — من «طلباتي» أو بمسح رمزه المطبوع
Future<void> openReturnReceipt(BuildContext context, Brand brand, int id) => Navigator.of(context).push(
  MaterialPageRoute(
    builder: (_) => ReturnReceiptScreen(brand: brand, id: id),
  ),
);

/// «طلباتي» (docs/plan/56): طلب كشف الراجع، وإيصالات الراجع التي سُلّمت للتاجر بـ«وصلتني»، وطلباته
/// كلّها (والدفع منها يُطلب من «المالية») بإلغاء المفتوح منها — كما في البوابة.
class RequestsScreen extends StatefulWidget {
  const RequestsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<RequestsScreen> createState() => _RequestsScreenState();
}

class _RequestsScreenState extends State<RequestsScreen> {
  RequestsPage? data;
  String? error;

  /// ما يُرسل الآن — زرّه وحده يدور
  int? busy;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.requests();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _act(int key, Future<String> Function() call) async {
    setState(() => busy = key);
    try {
      final message = await call();
      if (mounted) toast(context, message);
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => busy = null);
    }
  }

  /// رمز إيصال الراجع المطبوع يُمسح فيُفتح الإيصال بشحناته و«وصلتني»
  Future<void> _scanReceipt() async {
    final result = await Navigator.of(context)
        .push<ScanResult>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => ScanScreen(brand: brand)));
    if (!mounted || result?.receipt == null) return;
    await _open(result!.receipt!);
  }

  Future<void> _open(int id) async {
    await openReturnReceipt(context, brand, id);
    if (mounted) await _load();
  }

  Future<void> _returns() async {
    final message = await showSheet<String>(context, _ReturnsSheet(brand: brand, count: data!.returning));
    if (message == null || !mounted) return;
    toast(context, message);
    _load();
  }

  Future<void> _received(ReturnReceipt b) async {
    if (!await confirm(
      context,
      'وصلتك رواجع ${b.number}؟',
      '${b.count} شحنة ${b.via}. يُسجَّل أنّك استلمتها.',
      'وصلتني',
    )) {
      return;
    }
    await _act(-b.id, () => Api.instance.confirmReturns(b.id));
  }

  Future<void> _cancel(MerchantRequestRow r) async {
    if (!await confirm(context, 'إلغاء ${r.label}؟', 'الطلب ${r.number} لم يُعالج بعد، ويُلغى الآن.', 'ألغِه')) return;
    await _act(r.id, () => Api.instance.cancelRequest(r.id));
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            PageBar(
              title: 'طلباتي',
              subtitle: 'كشف الراجع وإيصالاته، وطلباتك إلى الشركة',
              trailing: IconButton(
                tooltip: 'امسح رمز إيصال الراجع',
                onPressed: _scanReceipt,
                icon: Icon(Icons.qr_code_scanner_rounded, color: brand.main),
              ),
            ),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: [
                          _returning(d),
                          if (d.batches.isNotEmpty) ...[
                            const SectionTitle('إيصالات الراجع'),
                            for (final b in d.batches) ...[_batch(b), const SizedBox(height: 10)],
                          ],
                          const SectionTitle('طلباتك'),
                          if (d.requests.isEmpty)
                            const Empty(
                              icon: Icons.assignment_outlined,
                              title: 'لا طلبات بعد',
                              text: 'اطلب كشف راجعك من هنا، والمحاسبة من «المالية».',
                            ),
                          for (final r in d.requests) ...[_request(r), const SizedBox(height: 10)],
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _returning(RequestsPage d) => WhiteCard(
    padding: const EdgeInsets.all(12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            SoftIcon(
              brand: brand,
              size: 40,
              radius: 12,
              child: const Icon(Icons.undo_rounded, size: 22, color: Palette.returnRed),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('راجعك عندنا', style: font(12, w6, Palette.slate)),
                  Text(
                    d.returning == 0 ? 'لا شيء الآن' : '${d.returning} شحنة',
                    style: font(18, w8, d.returning == 0 ? Palette.slate : Palette.returnRed, height: 1.2),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        SheetButton(
          brand: brand,
          label: 'اطلب كشف راجع',
          icon: Icons.receipt_long_rounded,
          onTap: d.returning == 0 ? null : _returns,
        ),
      ],
    ),
  );

  Widget _batch(ReturnReceipt b) => WhiteCard(
    padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
    child: Row(
      children: [
        Expanded(
          child: Tap(
            onTap: () => _open(b.id),
            radius: 8,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('إيصال ${b.number}', style: font(13.5, w8, brand.main)),
                Text(
                  '${b.count} شحنة · ${b.via}${b.fees > 0 ? ' · أجور راجع ${money(b.fees)} د.ع' : ''}',
                  style: font(11.5, w5, Palette.slate, height: 1.5),
                ),
                Text('${when(b.at)} · اضغط لترى شحناته', style: font(10.5, w5, Palette.muted)),
              ],
            ),
          ),
        ),
        if (b.received != null)
          Chip2('وصلتك', color: const Color(0xFF0F7B4A))
        else
          FilledButton(
            onPressed: busy == null ? () => _received(b) : null,
            style: FilledButton.styleFrom(backgroundColor: brand.main, visualDensity: VisualDensity.compact),
            child: busy == -b.id
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : Text('وصلتني', style: font(12.5, w8, Colors.white)),
          ),
      ],
    ),
  );

  Color _tone(String status) => switch (status) {
    'open' => const Color(0xFFB45309),
    'handled' => const Color(0xFF0F7B4A),
    _ => Palette.slate,
  };

  Widget _request(MerchantRequestRow r) => WhiteCard(
    padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text(r.label, style: font(13.5, w8, Palette.ink)),
            const SizedBox(width: 6),
            Text(r.number, textDirection: TextDirection.ltr, style: font(11.5, w6, Palette.slate)),
            const Spacer(),
            Chip2(r.state, color: _tone(r.status)),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          [
            if (r.amount != null) '${money(r.amount!)} د.ع',
            ?r.method,
            if (r.type == 'returns') r.courier ? 'مع مندوب الاستلام' : 'من المخزن',
            ?r.result,
            when(r.at),
          ].join(' · '),
          style: font(11.5, w5, Palette.slate, height: 1.5),
        ),
        if (r.note != null) Text(r.note!, style: font(11.5, w5, Palette.ink, height: 1.5)),
        if (r.status == 'open')
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: TextButton(
              onPressed: busy == null ? () => _cancel(r) : null,
              child: busy == r.id
                  ? SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: brand.main))
                  : Text('ألغِ الطلب', style: font(12, w8, Palette.returnRed)),
            ),
          ),
      ],
    ),
  );
}

class _ReturnsSheet extends StatefulWidget {
  const _ReturnsSheet({required this.brand, required this.count});

  final Brand brand;
  final int count;

  @override
  State<_ReturnsSheet> createState() => _ReturnsSheetState();
}

class _ReturnsSheetState extends State<_ReturnsSheet> {
  final note = TextEditingController();
  bool courier = true;
  bool busy = false;
  String? error;

  @override
  void dispose() {
    note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => (busy = true, error = null));
    try {
      final message = await Api.instance.requestReturns(courier: courier, note: note.text.trim());
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    return SheetFrame(
      title: 'اطلب كشف راجع',
      text: 'تجمع الشركة راجعك (${widget.count} شحنة) بإيصالٍ وتسلّمه لك.',
      children: [
        SwitchListTile(
          value: courier,
          onChanged: (v) => setState(() => courier = v),
          activeThumbColor: brand.main,
          contentPadding: EdgeInsets.zero,
          title: Text('مع مندوب الاستلام', style: font(13.5, w7, Palette.ink)),
          subtitle: Text(
            courier ? 'يحمله مندوبك حين يأتي لطرودك' : 'تستلمه بنفسك من المخزن',
            style: font(11.5, w5, Palette.slate),
          ),
        ),
        const FieldLabel('ملاحظة (اختياري)'),
        InputBox(controller: note, hint: 'مثلاً: أحتاج الراجع قبل الخميس', lines: 2, maxLength: 500),
        if (error != null) ErrorNote(error!),
        const SizedBox(height: 14),
        SheetButton(brand: brand, label: 'أرسل الطلب', busy: busy, onTap: _send),
      ],
    );
  }
}

/// تفاصيل إيصال الراجع (docs/plan/61): شحناته واحدةً واحدة — المستلم والوجهة والمبلغ وسبب الرجوع
/// وأجرته — و«وصلتني» إن لم يؤكَّد. أرقامها تفتح شحناتها.
class ReturnReceiptScreen extends StatefulWidget {
  const ReturnReceiptScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<ReturnReceiptScreen> createState() => _ReturnReceiptScreenState();
}

class _ReturnReceiptScreenState extends State<ReturnReceiptScreen> {
  ReturnReceiptDetail? data;
  String? error;
  bool confirming = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.returnReceipt(widget.id);
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _confirm() async {
    setState(() => confirming = true);
    try {
      final message = await Api.instance.confirmReturns(widget.id);
      if (mounted) toast(context, message);
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => confirming = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            PageBar(
              title: d == null ? 'إيصال الراجع' : 'إيصال ${d.receipt.number}',
              subtitle: d == null ? null : '${d.receipt.count} شحنة · ${d.receipt.via}',
            ),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                      children: [
                        _summary(d),
                        const SizedBox(height: 10),
                        if (d.receipt.received == null) ...[
                          SheetButton(
                            brand: brand,
                            label: 'وصلتني هذه الرواجع',
                            icon: Icons.check_circle_rounded,
                            busy: confirming,
                            onTap: _confirm,
                          ),
                          const SizedBox(height: 10),
                        ],
                        const SectionTitle('شحنات الإيصال'),
                        WhiteCard(
                          child: Material(
                            type: MaterialType.transparency,
                            child: Column(
                              children: [
                                for (final (i, l) in d.lines.indexed) ...[
                                  if (i > 0) const Divider(height: 1, color: Palette.line),
                                  _line(l),
                                ],
                              ],
                            ),
                          ),
                        ),
                      ],
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _summary(ReturnReceiptDetail d) {
    Widget row(String label, String value, {Color? color}) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(child: Text(label, style: font(13, w6, Palette.slate))),
          Text(value, style: font(13.5, w8, color ?? Palette.ink)),
        ],
      ),
    );
    final received = d.receipt.received;
    return WhiteCard(
      padding: const EdgeInsets.all(14),
      child: Column(
        children: [
          row('عدد الشحنات', '${d.receipt.count}'),
          row('التسليم', d.receipt.via),
          if (d.receipt.at != null) row('سُلّم', when(d.receipt.at)),
          row('أجور الراجع', '${money(d.receipt.fees)} د.ع', color: Palette.returnRed),
          const Divider(color: Palette.line),
          row(
            'الاستلام',
            received == null ? 'لم تؤكّده بعد' : 'وصلتك ${when(received)}',
            color: received == null ? const Color(0xFFB45309) : const Color(0xFF0F7B4A),
          ),
          if ((d.note ?? '').isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('ملاحظة: ${d.note}', style: font(11.5, w6, Palette.slate)),
            ),
        ],
      ),
    );
  }

  Widget _line(ReturnLine l) => InkWell(
    onTap: () => l.id > 0 ? openShipment(context, brand, l.id) : openShipmentNumber(context, brand, l.number),
    child: Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 10, 10),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('#${l.number}', textDirection: TextDirection.ltr, style: font(13, w8, brand.main)),
                Text(
                  [l.name, l.place].where((t) => t.isNotEmpty).join(' · '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(12, w6, Palette.ink),
                ),
                Text(
                  'المبلغ ${money(l.amount)}${l.reason == null ? '' : ' · ${l.reason}'}',
                  maxLines: 2,
                  style: font(11, w5, Palette.slate),
                ),
              ],
            ),
          ),
          Text('${money(l.fee)} د.ع', style: font(13, w8, Palette.returnRed)),
        ],
      ),
    ),
  );
}
