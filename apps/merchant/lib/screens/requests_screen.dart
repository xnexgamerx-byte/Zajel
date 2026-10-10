import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

Future<void> openRequests(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => RequestsScreen(brand: brand)));

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
            const PageBar(title: 'طلباتي', subtitle: 'كشف الراجع وإيصالاته، وطلباتك إلى الشركة'),
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
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('إيصال ${b.number}', style: font(13.5, w8, Palette.ink)),
              Text(
                '${b.count} شحنة · ${b.via}${b.fees > 0 ? ' · أجور راجع ${money(b.fees)} د.ع' : ''}',
                style: font(11.5, w5, Palette.slate, height: 1.5),
              ),
              Text(when(b.at), style: font(10.5, w5, Palette.muted)),
            ],
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
