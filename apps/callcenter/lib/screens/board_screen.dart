import 'dart:async';

import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/follow_up.dart';
import '../widgets/page.dart';
import 'search_screen.dart';

/// خانةٌ من «حالة الشحنات» (docs/plan/80): اسمها ولونها وأيقونتها، وما فيها بكلامه
class BoardView {
  const BoardView(this.kind, this.title, this.text, this.icon, this.color);

  final String kind;
  final String title;
  final String text;
  final IconData icon;
  final Color color;

  /// المؤجّل أصفر، ونصّه على الأبيض ذهبيٌّ داكن
  static const yellow = Queue.postponed;
  static const yellowInk = Queue.postponedInk;
  static const yellowSoft = Queue.postponedSoft;

  static const all = [
    BoardView(
      'in_delivery',
      'قيد التوصيل',
      'مع المندوب أوّل مرّة، في طريقها إلى الزبون',
      Icons.local_shipping_rounded,
      Palette.info,
    ),
    BoardView(
      'postponed',
      'مؤجل',
      'اتُّفق مع الزبون على موعد — ما فات موعده أو اليوم أوّلاً',
      Icons.event_rounded,
      yellow,
    ),
    BoardView(
      'redelivery',
      'إعادة توصيل',
      'عولجت فعادت للتوصيل: مع مندوبها، أو بالمخزن تنتظره',
      Icons.replay_rounded,
      Queue.couriers,
    ),
    BoardView(
      'returns',
      'راجع مؤكد',
      'تأكّد رجوعها لتاجرها — ما زال بيد المندوب أوّلاً، ويُعاد للتوصيل إن اقتنع الزبون',
      Icons.assignment_return_rounded,
      Palette.returnRed,
    ),
  ];

  static BoardView of(String kind) => all.firstWhere((v) => v.kind == kind, orElse: () => all.first);

  /// نصٌّ بلون الخانة على الأبيض: الأصفر يُغمَّق ليُقرأ
  Color get ink => color == yellow ? yellowInk : color;
  Color get soft => color == yellow ? yellowSoft : Color.lerp(Colors.white, color, .1)!;
}

Future<String?> openBoard(BuildContext context, Brand brand, String kind) => Navigator.of(context).push<String>(
  MaterialPageRoute(
    builder: (_) => BoardScreen(brand: brand, view: BoardView.of(kind)),
  ),
);

/// قائمة خانةٍ وحدها (docs/plan/80): رأسٌ بلونها وعددها، وبحثٌ فيها، وبطاقاتٌ تُفتح منها الشحنة
class BoardScreen extends StatefulWidget {
  const BoardScreen({super.key, required this.brand, required this.view});

  final Brand brand;
  final BoardView view;

  @override
  State<BoardScreen> createState() => _BoardScreenState();
}

class _BoardScreenState extends State<BoardScreen> {
  final search = TextEditingController();
  Timer? debounce;
  BoardPage? page;
  String? error;
  int ticket = 0;

  BoardView get view => widget.view;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    debounce?.cancel();
    search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final mine = ++ticket;
    try {
      final p = await Api.instance.boardList(view.kind, q: search.text);
      if (mounted && mine == ticket) setState(() => (page = p, error = null));
    } on ApiError catch (e) {
      if (mounted && mine == ticket) setState(() => error = e.message);
    }
  }

  void _typed(String _) {
    debounce?.cancel();
    debounce = Timer(const Duration(milliseconds: 400), _load);
  }

  Future<void> _open(FoundShipment s) async {
    final message = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => ShipmentScreen(brand: widget.brand, id: s.id),
      ),
    );
    if (message != null && mounted) toast(context, message);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final p = page;
    return Scaffold(
      body: Column(
        children: [
          _hero(context, p),
          Transform.translate(
            offset: const Offset(0, -22),
            child: Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: _search()),
          ),
          Expanded(
            child: p == null
                ? Waiting(brand: widget.brand, error: error, onRetry: _load)
                : RefreshIndicator(
                    color: view.color,
                    onRefresh: _load,
                    child: p.items.isEmpty
                        ? ListView(
                            padding: const EdgeInsets.symmetric(horizontal: 30),
                            children: [
                              const SizedBox(height: 30),
                              Icon(view.icon, size: 46, color: view.color.withValues(alpha: .45)),
                              const SizedBox(height: 10),
                              Text(
                                search.text.isEmpty
                                    ? 'لا شحنات «${view.title}» الآن.'
                                    : 'لا شحنة تطابق «${search.text}».',
                                textAlign: TextAlign.center,
                                style: font(13, w6, Palette.slate, height: 1.6),
                              ),
                            ],
                          )
                        : ListView.separated(
                            padding: const EdgeInsets.fromLTRB(16, 0, 16, 28),
                            itemCount: p.items.length,
                            separatorBuilder: (_, _) => const SizedBox(height: 8),
                            itemBuilder: (_, i) => _card(p.items[i]),
                          ),
                  ),
          ),
        ],
      ),
    );
  }

  /// الرأس بلون الخانة: رجوعٌ، وأيقونتها، واسمها وكلامها، وعددها كبيراً
  Widget _hero(BuildContext context, BoardPage? p) {
    final top = MediaQuery.paddingOf(context).top;
    return Container(
      padding: EdgeInsets.fromLTRB(16, top + 8, 16, 38),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: AlignmentDirectional.topStart,
          end: AlignmentDirectional.bottomEnd,
          colors: [view.color, Color.lerp(view.color, Colors.black, .3)!],
        ),
        borderRadius: const BorderRadius.vertical(bottom: Radius.circular(28)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Tooltip(
                message: 'رجوع',
                child: Tap(
                  radius: 18,
                  onTap: () => Navigator.of(context).pop(),
                  child: Container(
                    width: 36,
                    height: 36,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: Colors.white.withValues(alpha: .18), shape: BoxShape.circle),
                    child: const Chev(size: 11, stroke: 1.8, color: Colors.white),
                  ),
                ),
              ),
              const Spacer(),
              Text('حالة الشحنات', style: font(12, w7, Colors.white.withValues(alpha: .85))),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .18),
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white.withValues(alpha: .35)),
                ),
                child: Icon(view.icon, color: Colors.white, size: 26),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(view.title, style: display(20, w7, Colors.white, height: 1.25)),
                    Text(view.text, style: font(11.5, w6, Colors.white.withValues(alpha: .88), height: 1.45)),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Column(
                children: [
                  Text(p == null ? '—' : money(p.count), style: display(28, w7, Colors.white, height: 1.1)),
                  Text('شحنة', style: font(11, w7, Colors.white.withValues(alpha: .85))),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _search() => Container(
    height: 44,
    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), boxShadow: cardShadow),
    child: TextField(
      controller: search,
      onChanged: _typed,
      textInputAction: TextInputAction.search,
      style: font(13, w6, Palette.ink),
      decoration: InputDecoration(
        hintText: 'ابحثي في «${view.title}» بالرقم أو الهاتف أو الاسم',
        hintStyle: font(12, w5, Palette.muted),
        prefixIcon: Icon(Icons.search_rounded, color: view.ink, size: 21),
        border: InputBorder.none,
        contentPadding: const EdgeInsets.symmetric(vertical: 12),
      ),
    ),
  );

  /// بطاقة الشحنة: شريطٌ بلون الخانة، والزبون ومنطقته، وما يخصّ الخانة (الموعد، أو بيد المندوب)، والرقم والمبلغ
  Widget _card(FoundShipment s) {
    final extra = [
      if (s.withCourier == true) 'بيد المندوب ${s.courier ?? ''}'.trim(),
      if (s.withCourier == false) 'استُلم في المخزن',
      if (s.withCourier == null && (s.courier ?? '').isNotEmpty && s.status != 'postponed') 'مع ${s.courier}',
      if ((s.reason ?? '').isNotEmpty) s.reason!,
    ];
    return Tap(
      radius: 18,
      shadow: cardShadow,
      onTap: () => _open(s),
      child: Ink(
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                width: 5,
                margin: const EdgeInsets.symmetric(vertical: 12),
                decoration: BoxDecoration(
                  color: view.color,
                  borderRadius: const BorderRadiusDirectional.horizontal(end: Radius.circular(4))
                      .resolve(Directionality.of(context)),
                ),
              ),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              s.recipient,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: font(14, w8, Palette.ink, height: 1.3),
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
                            decoration: BoxDecoration(color: view.soft, borderRadius: BorderRadius.circular(20)),
                            child: Text(s.statusLabel, style: font(10.5, w8, view.ink, height: 1.2)),
                          ),
                        ],
                      ),
                      const SizedBox(height: 3),
                      Text(
                        [s.place, if ((s.merchant ?? '').isNotEmpty) s.merchant!].join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: font(12, w6, Palette.slate, height: 1.4),
                      ),
                      if (extra.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 2),
                          child: Text(extra.join(' · '), maxLines: 2, style: font(11.5, w7, view.ink, height: 1.4)),
                        ),
                      // «مؤجل إلى غداً الساعة 9» و«نور: اتّصلتُ ولم تردّ» (docs/plan/83)
                      if (s.until != null)
                        Padding(padding: const EdgeInsets.only(top: 5), child: PostponedChip(s.until!)),
                      if (s.staffNote != null)
                        Padding(padding: const EdgeInsets.only(top: 5), child: StaffNoteChip(s.staffNote)),
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                            decoration: BoxDecoration(color: Palette.eye, borderRadius: BorderRadius.circular(8)),
                            child: Text(tag(s.number), style: font(10.5, w7, Palette.slate, height: 1.3)),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              when(s.since),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: font(10.5, w5, Palette.muted, height: 1.3),
                            ),
                          ),
                          Text(money(s.amount), style: display(15, w7, Palette.ink, height: 1.2)),
                          const SizedBox(width: 3),
                          Text('د.ع', style: font(10.5, w6, Palette.slate, height: 1.2)),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// «أنجزتِ اليوم» بتفاصيله (docs/plan/80): ما عالجته بقراره — إعادة توصيل، مؤجل، راجع مؤكد — وما قاله
/// الزبون، أو تذاكر المبلغ التي اعتمدتها أو رفضتها للمناديب. وكلٌّ يفتح شحنته.
class DoneScreen extends StatefulWidget {
  const DoneScreen({super.key, required this.brand, required this.kind, this.action});

  final Brand brand;

  /// processed · tickets
  final String kind;

  /// يبدأ على قرارٍ واحد: redeliver · postpone · return
  final String? action;

  @override
  State<DoneScreen> createState() => _DoneScreenState();
}

class _DoneScreenState extends State<DoneScreen> {
  List<DoneItem>? items;
  String? error;
  late String? action = widget.action;

  static const _actions = [
    ('redeliver', 'إعادة توصيل', Queue.couriers),
    ('postpone', 'مؤجل', BoardView.yellow),
    ('return', 'راجع مؤكد', Palette.returnRed),
  ];

  bool get tickets => widget.kind == 'tickets';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.done(widget.kind);
      if (mounted) setState(() => (items = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Color _color(String? a) => switch (a) {
    'redeliver' || 'approved' => Queue.couriers,
    'postpone' => BoardView.yellowInk,
    'return' || 'rejected' => Palette.returnRed,
    _ => Palette.slate,
  };

  @override
  Widget build(BuildContext context) {
    final all = items;
    final shown = all == null
        ? null
        : [
            for (final d in all)
              if (action == null || d.action == action) d,
          ];
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            PageBar(
              title: tickets ? 'تذاكر المبلغ التي حسمتِها' : 'ما عالجتِه اليوم',
              subtitle: tickets
                  ? 'طلبات المناديب لتغيير المبلغ: اعتمدتِها أو رفضتِها'
                  : 'قراراتك على ما لم يُسلَّم، وما قاله الزبون',
            ),
            if (!tickets && all != null)
              SizedBox(
                height: 40,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  children: [
                    _chip(null, 'الكل', all.length, widget.brand.main),
                    for (final (a, label, color) in _actions)
                      _chip(a, label, all.where((d) => d.action == a).length, color),
                  ],
                ),
              ),
            Expanded(
              child: shown == null
                  ? Waiting(brand: widget.brand, error: error, onRetry: _load)
                  : shown.isEmpty
                  ? Empty(
                      icon: Icons.task_alt_rounded,
                      title: 'لا شيء بعد',
                      text: tickets ? 'لم تحسمي تذكرة مبلغٍ اليوم.' : 'لم تعالجي شحنةً بهذا القرار اليوم.',
                    )
                  : RefreshIndicator(
                      color: widget.brand.main,
                      onRefresh: _load,
                      child: ListView.separated(
                        padding: const EdgeInsets.fromLTRB(14, 8, 14, 24),
                        itemCount: shown.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 8),
                        itemBuilder: (_, i) => _row(shown[i]),
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _chip(String? a, String label, int count, Color color) {
    final on = action == a;
    return Padding(
      padding: const EdgeInsetsDirectional.only(end: 6),
      child: ChoiceChip(
        selected: on,
        showCheckmark: false,
        onSelected: (_) => setState(() => action = a),
        selectedColor: color,
        backgroundColor: Colors.white,
        side: BorderSide(color: on ? color : Palette.line),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        label: Text('$label $count', style: font(12.5, w8, on ? Colors.white : Palette.ink)),
      ),
    );
  }

  Widget _row(DoneItem d) {
    final color = _color(d.action);
    return Tap(
      radius: 18,
      shadow: cardShadow,
      onTap: d.shipmentId == null
          ? null
          : () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => ShipmentScreen(brand: widget.brand, id: d.shipmentId!),
              ),
            ),
      child: Ink(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(13)),
              child: Icon(
                switch (d.action) {
                  'redeliver' => Icons.replay_rounded,
                  'postpone' => Icons.event_rounded,
                  'approved' => Icons.check_circle_rounded,
                  'rejected' => Icons.cancel_rounded,
                  _ => Icons.assignment_return_rounded,
                },
                size: 20,
                color: color,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          d.recipient ?? d.title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: font(13.5, w8, Palette.ink),
                        ),
                      ),
                      Text(d.label, style: font(11.5, w8, color)),
                    ],
                  ),
                  Text(
                    [
                      if (d.number != null) tag(d.number!),
                      if (tickets) d.title,
                      if (d.by != null) 'المندوب ${d.by}',
                      when(d.at),
                    ].join(' · '),
                    maxLines: 2,
                    style: font(11, w6, Palette.muted, height: 1.45),
                  ),
                  if ((d.note ?? '').isNotEmpty) Text(d.note!, style: font(12, w7, Palette.ink, height: 1.45)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
