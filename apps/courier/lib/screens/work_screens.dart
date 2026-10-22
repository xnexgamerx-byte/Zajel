import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'pickup_scan_screen.dart';

/// «اليوم» و«الاستلام» و«حصصي» (docs/plan/71): شاشات المندوب في الموقع نفسها، فوق الشريط.
Future<void> openToday(BuildContext context, Brand brand, {void Function(int id)? onShipment}) => Navigator.of(context)
    .push(
      MaterialPageRoute(
        builder: (_) => TodayScreen(brand: brand, onShipment: onShipment),
      ),
    );

Future<void> openPickups(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => PickupsScreen(brand: brand)));

Future<void> openShares(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => SharesScreen(brand: brand)));

/// صفحةٌ تُحمَّل ثم تُعرض، وتُسحب لتُعاد — هيكل الشاشات الثلاث
class _Loaded<T> extends StatefulWidget {
  const _Loaded({required this.brand, required this.title, this.subtitle, required this.load, required this.body});

  final Brand brand;
  final String title;
  final String? subtitle;
  final Future<T> Function() load;
  final List<Widget> Function(T data, Future<void> Function() reload) body;

  @override
  State<_Loaded<T>> createState() => _LoadedState<T>();
}

class _LoadedState<T> extends State<_Loaded<T>> {
  T? data;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await widget.load();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            PageBar(title: widget.title, subtitle: widget.subtitle),
            Expanded(
              child: d == null
                  ? Waiting(brand: widget.brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: widget.brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: widget.body(d, _load),
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

/// رقمٌ كبير وتحته ما يعنيه، في مربّعٍ أبيض
Widget _figure(String label, String value, Color color, {String? unit}) => Expanded(
  child: WhiteCard(
    padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: font(11.5, w6, Palette.slate)),
        const SizedBox(height: 2),
        Text.rich(
          TextSpan(
            children: [
              TextSpan(text: value, style: display(21, w7, color, height: 1.2)),
              if (unit != null) TextSpan(text: ' $unit', style: font(11, w7, Palette.slate)),
            ],
          ),
        ),
      ],
    ),
  ),
);

// ------------------------------------------------------------------------- اليوم

/// «اليوم»: ما سلّمه وما لم يُسلَّم وما أجّله، وما حصّله — كلٌّ يُفتح بشحنته
class TodayScreen extends StatelessWidget {
  const TodayScreen({super.key, required this.brand, this.onShipment});

  final Brand brand;
  final void Function(int id)? onShipment;

  @override
  Widget build(BuildContext context) => _Loaded<TodayPage>(
    brand: brand,
    title: 'اليوم',
    subtitle: 'ما سجّلته اليوم وما حصّلته',
    load: Api.instance.today,
    body: (d, _) => [
      Row(
        children: [
          _figure('سلّمت اليوم', money(d.delivered), Palette.ok),
          const SizedBox(width: 8),
          _figure('حصّلت اليوم', money(d.collected), Palette.ink, unit: 'د.ع'),
        ],
      ),
      if (d.notDelivered + d.postponed > 0) ...[
        const SizedBox(height: 8),
        Row(
          children: [
            _figure('لم تُسلَّم', money(d.notDelivered), Palette.warn),
            const SizedBox(width: 8),
            _figure('مؤجّلة', money(d.postponed), Palette.warn),
          ],
        ),
      ],
      const SectionTitle('ما سجّلته'),
      if (d.rows.isEmpty)
        const Empty(
          icon: Icons.today_outlined,
          title: 'لم تسجّل شيئاً اليوم بعد',
          text: 'ما تسجّله عند الباب يظهر هنا.',
        )
      else
        for (final r in d.rows) ...[_row(context, r), const SizedBox(height: 8)],
    ],
  );

  Widget _row(BuildContext context, TodayRow r) {
    final color = statusColor(r.row.status);
    return Tap(
      radius: 20,
      onTap: onShipment == null ? null : () => onShipment!(r.row.id),
      child: WhiteCard(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(r.row.recipient, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(14, w8, Palette.ink)),
                  Text(
                    [
                      [r.governorate, r.row.city].whereType<String>().where((t) => t.isNotEmpty).join(' · '),
                      when(r.at),
                    ].where((t) => t.isNotEmpty).join(' · '),
                    style: font(11.5, w6, Palette.slate),
                  ),
                  if (r.collected > 0)
                    Text('${money(r.collected)} د.ع', style: font(12.5, w8, Palette.ok, height: 1.5))
                  else if (r.row.failure != null)
                    Text(r.row.failure!, style: font(12, w7, Palette.warn, height: 1.5)),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Pill(r.row.statusLabel, color: color),
          ],
        ),
      ),
    );
  }
}

// ----------------------------------------------------------------------- الاستلام

/// «الاستلام»: طلبات التجّار المُسنَدة إليه — يتّصل بالتاجر، ويسجّل ما استلمه فعلاً
class PickupsScreen extends StatelessWidget {
  const PickupsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  Widget build(BuildContext context) => _Loaded<PickupsPage>(
    brand: brand,
    title: 'الاستلام',
    subtitle: 'طلبات استلامٍ مُسنَدة إليك',
    load: Api.instance.pickups,
    body: (page, reload) => [
      if (page.scanRequired)
        Container(
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: brand.softer,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: brand.border),
          ),
          child: Row(
            children: [
              Icon(Icons.qr_code_scanner_rounded, size: 20, color: brand.deep),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'تستلم بمسح باركود كلّ طرد: ما لم تمسحه يبقى عند التاجر.',
                  style: font(12.5, w7, Palette.ink, height: 1.5),
                ),
              ),
            ],
          ),
        ),
      if (page.items.isEmpty)
        const Empty(
          icon: Icons.inventory_2_outlined,
          title: 'ما عندك طلبات استلام',
          text: 'حين يُسنَد إليك طلب استلامٍ من تاجر يظهر هنا.',
        )
      else
        for (final p in page.items) ...[_card(context, p, reload, scan: page.scanRequired), const SizedBox(height: 10)],
    ],
  );

  Widget _card(BuildContext context, PickupItem p, Future<void> Function() reload, {bool scan = false}) => WhiteCard(
    padding: const EdgeInsets.all(14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(14)),
              child: const Icon(Icons.storefront_rounded, color: Colors.white, size: 21),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(p.merchant, style: display(16, w7, Palette.ink)),
                  Text(tag(p.number), style: font(11.5, w6, Palette.muted)),
                ],
              ),
            ),
            Column(
              children: [
                Text('${p.expected}', style: display(22, w7, brand.deep, height: 1.1)),
                Text('طرد متوقَّع', style: font(10.5, w6, Palette.slate)),
              ],
            ),
          ],
        ),
        const Divider(height: 22, color: Palette.line),
        if ((p.address ?? '').isNotEmpty) _line('العنوان', p.address!, Palette.ink),
        if ((p.landmark ?? '').isNotEmpty) _line('نقطة دالّة', p.landmark!, brand.deep),
        if ((p.notes ?? '').isNotEmpty) _line('ملاحظة', p.notes!, Palette.ink),
        if (p.at != null) _line('الموعد', when(p.at), Palette.ink),
        const SizedBox(height: 10),
        Row(
          children: [
            if ((p.phone ?? '').isNotEmpty) ...[
              Expanded(
                child: SheetButton(
                  brand: brand,
                  label: 'اتصل بالتاجر',
                  icon: Icons.call_rounded,
                  outlined: true,
                  onTap: () => launchUrl(Uri(scheme: 'tel', path: p.phone)),
                ),
              ),
              const SizedBox(width: 8),
            ],
            Expanded(
              child: SheetButton(
                brand: brand,
                label: scan ? 'امسح الطرود' : 'استلمت الطرود',
                icon: scan ? Icons.qr_code_scanner_rounded : Icons.check_rounded,
                onTap: () async {
                  // بمسح الشحنات (docs/plan/72): شاشة المسح، وإلّا العدد الفعلي
                  final message = scan
                      ? await Navigator.of(context).push<String>(
                          MaterialPageRoute(
                            builder: (_) => PickupScanScreen(brand: brand, pickup: p),
                          ),
                        )
                      : await showSheet<String>(context, _CompleteSheet(brand: brand, pickup: p));
                  if (message == null || !context.mounted) return;
                  toast(context, message);
                  await reload();
                },
              ),
            ),
          ],
        ),
      ],
    ),
  );

  Widget _line(String label, String value, Color color) => Padding(
    padding: const EdgeInsets.only(bottom: 6),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(width: 78, child: Text(label, style: font(12, w6, Palette.slate, height: 1.5))),
        Expanded(child: Text(value, style: font(13, w7, color, height: 1.5))),
      ],
    ),
  );
}

/// «استلمت الطرود»: العدد المستلم فعلاً — يُسجَّل استلام كلّ شحنات التاجر المعلّقة دفعةً واحدة
class _CompleteSheet extends StatefulWidget {
  const _CompleteSheet({required this.brand, required this.pickup});

  final Brand brand;
  final PickupItem pickup;

  @override
  State<_CompleteSheet> createState() => _CompleteSheetState();
}

class _CompleteSheetState extends State<_CompleteSheet> {
  late final count = TextEditingController(text: '${widget.pickup.expected}');
  final note = TextEditingController();
  bool busy = false;
  String? error;

  @override
  void dispose() {
    count.dispose();
    note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final n = int.tryParse(count.text.trim());
    if (n == null) return setState(() => error = 'اكتب عدد الطرود التي استلمتها.');
    setState(() => (busy = true, error = null));
    try {
      final message = await Api.instance.completePickup(widget.pickup.id, n, note: note.text);
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (busy = false, error = e.message));
    }
  }

  @override
  Widget build(BuildContext context) => SheetFrame(
    title: 'استلمت من ${widget.pickup.merchant}',
    text: 'يُسجَّل استلام كلّ شحنات هذا التاجر المعلّقة دفعةً واحدة.',
    children: [
      const FieldLabel('العدد المستلم فعلاً'),
      InputBox(
        controller: count,
        hint: '${widget.pickup.expected}',
        ltr: true,
        keyboard: TextInputType.number,
        formatters: [FilteringTextInputFormatter.digitsOnly],
      ),
      const FieldLabel('ملاحظة (اختياري)'),
      InputBox(controller: note, hint: 'مثلاً: طردان لم يكونا جاهزين', lines: 2, maxLength: 500),
      if (error != null) ErrorNote(error!),
      const SizedBox(height: 14),
      SheetButton(brand: widget.brand, label: 'سجّل الاستلام', busy: busy, onTap: _send),
    ],
  );
}

// --------------------------------------------------------------------------- حصصي

/// «حصصي»: ما احتُسب له من الاستلام طلباً طلباً، ودفعات أرباحه — يعترض على العدد ويؤكّد ما وصله
class SharesScreen extends StatelessWidget {
  const SharesScreen({super.key, required this.brand});

  final Brand brand;

  @override
  Widget build(BuildContext context) => _Loaded<SharesPage>(
    brand: brand,
    title: 'حصصي',
    subtitle: 'حصصك من الاستلام ودفعات أرباحك',
    load: Api.instance.shares,
    body: (d, reload) => [
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          gradient: brand.hero,
          borderRadius: BorderRadius.circular(22),
          boxShadow: brand.glow(.4),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('رصيدك المستحقّ الآن', style: font(12.5, w6, Colors.white.withValues(alpha: .88))),
            Text.rich(
              TextSpan(
                children: [
                  TextSpan(text: money(d.balance), style: display(28, w7, Colors.white, height: 1.2)),
                  TextSpan(text: ' د.ع', style: font(13, w7, Colors.white)),
                ],
              ),
            ),
          ],
        ),
      ),
      if (d.payouts.isNotEmpty) ...[
        const SectionTitle('دفعات أرباحي'),
        WhiteCard(
          child: Column(
            children: [
              for (final (i, p) in d.payouts.indexed) ...[
                if (i > 0) const Divider(height: 1, indent: 14, endIndent: 14, color: Palette.line),
                _payout(context, p, reload),
              ],
            ],
          ),
        ),
      ],
      const SectionTitle('حصصي من الاستلام'),
      if (d.shares.isEmpty)
        const Empty(
          icon: Icons.savings_outlined,
          title: 'لا حصص بعد',
          text: 'حصّتك من كلّ طلب استلامٍ تُكمله تظهر هنا.',
        )
      else
        for (final s in d.shares) ...[_share(context, s, reload), const SizedBox(height: 8)],
    ],
  );

  Widget _payout(BuildContext context, Payout p, Future<void> Function() reload) => Padding(
    padding: const EdgeInsets.fromLTRB(14, 10, 10, 10),
    child: Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(tag(p.number), style: font(13.5, w8, Palette.ink)),
              Text(
                [
                  p.date ?? '',
                  if (p.centre > 0) 'استحققتَ ${money(p.earned)}، وللمركز ${money(p.centre)}',
                ].where((t) => t.isNotEmpty).join(' · '),
                style: font(11.5, w6, Palette.slate, height: 1.4),
              ),
            ],
          ),
        ),
        Text(money(p.paid), style: font(14, w8, Palette.ink)),
        const SizedBox(width: 8),
        if (p.confirmed)
          const Pill('استلمتُها', color: Palette.ok)
        else
          SizedBox(
            height: 34,
            child: FilledButton(
              onPressed: () async {
                try {
                  final message = await Api.instance.confirmPayout(p.id);
                  if (context.mounted) toast(context, message);
                } on ApiError catch (e) {
                  if (context.mounted) toast(context, e.message, bad: true);
                }
                await reload();
              },
              style: FilledButton.styleFrom(
                backgroundColor: brand.main,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              child: Text('استلمتُها', style: font(12.5, w8, Colors.white)),
            ),
          ),
      ],
    ),
  );

  Widget _share(BuildContext context, Share s, Future<void> Function() reload) {
    final color = switch (s.status) {
      'objected' => Palette.warn,
      'rejected' => Palette.bad,
      'accrued' => Palette.info,
      _ => Palette.ok,
    };
    return WhiteCard(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(s.pickup == null ? '—' : tag(s.pickup!), style: font(13.5, w8, Palette.ink)),
                    Text(s.date ?? '', style: font(11.5, w6, Palette.slate)),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(money(s.amount), style: display(16, w7, Palette.ink)),
                  Text('${parcels(s.count)} × ${money(s.rate)}', style: font(11, w6, Palette.slate)),
                ],
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Pill(s.statusLabel, color: color),
              if ((s.note ?? '').isNotEmpty) ...[
                const SizedBox(width: 8),
                Expanded(child: Text(s.note!, style: font(11.5, w6, Palette.slate))),
              ],
            ],
          ),
          if (s.canObject)
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                onPressed: () async {
                  final message = await showSheet<String>(context, _ObjectSheet(brand: brand, share: s));
                  if (message == null || !context.mounted) return;
                  toast(context, message);
                  await reload();
                },
                icon: Icon(Icons.flag_outlined, size: 18, color: brand.deep),
                label: Text('العدد غير صحيح؟ اعترض', style: font(12.5, w8, brand.deep)),
              ),
            ),
        ],
      ),
    );
  }
}

class _ObjectSheet extends StatefulWidget {
  const _ObjectSheet({required this.brand, required this.share});

  final Brand brand;
  final Share share;

  @override
  State<_ObjectSheet> createState() => _ObjectSheetState();
}

class _ObjectSheetState extends State<_ObjectSheet> {
  late final count = TextEditingController(text: '${widget.share.count}');
  final reason = TextEditingController();
  bool busy = false;
  String? error;

  @override
  void dispose() {
    count.dispose();
    reason.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final n = int.tryParse(count.text.trim());
    if (n == null) return setState(() => error = 'اكتب كم طرداً جمعتَ فعلاً.');
    if (reason.text.trim().isEmpty) return setState(() => error = 'اكتب لماذا العدد غير صحيح.');
    setState(() => (busy = true, error = null));
    try {
      final message = await Api.instance.objectShare(widget.share.id, n, reason.text);
      if (mounted) Navigator.of(context).pop(message);
    } on ApiError catch (e) {
      if (mounted) setState(() => (busy = false, error = e.message));
    }
  }

  @override
  Widget build(BuildContext context) => SheetFrame(
    title: 'اعتراض على حصّة ${widget.share.pickup ?? ''}',
    text: 'احتُسب لك ${parcels(widget.share.count)}. يُبتّ في اعتراضك من إدارة الفرع.',
    children: [
      const FieldLabel('كم طرداً جمعتَ فعلاً؟'),
      InputBox(
        controller: count,
        hint: '${widget.share.count}',
        ltr: true,
        keyboard: TextInputType.number,
        formatters: [FilteringTextInputFormatter.digitsOnly],
      ),
      const FieldLabel('لماذا؟'),
      InputBox(controller: reason, hint: 'جمعتُ طردين إضافيين لم يُسجَّلا', maxLength: 255),
      if (error != null) ErrorNote(error!),
      const SizedBox(height: 14),
      SheetButton(brand: widget.brand, label: 'أرسل الاعتراض', busy: busy, onTap: _send),
    ],
  );
}
