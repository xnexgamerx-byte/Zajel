import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'create_screen.dart';
import 'finance_screen.dart';
import 'home_screen.dart';
import 'import_screen.dart';
import 'labels_screen.dart';
import 'pickups_screen.dart';
import 'processing_screen.dart';
import 'receive_screen.dart';
import 'reader_sheets.dart';
import 'requests_screen.dart';
import 'returns_screen.dart';
import 'scan_screen.dart';
import 'shipments_screen.dart';
import 'support_screen.dart';
import 'waybills_screen.dart';

/// هيكل التطبيق: الصفحات الخمس والشريط السفلي العائم بزرّ «طلب جديد» في وسطه.
class Shell extends StatefulWidget {
  const Shell({super.key, required this.brand, required this.session, required this.onLogout});

  final Brand brand;
  final Session? session;
  final Future<void> Function() onLogout;

  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> {
  int tab = 0;

  /// شريحة «شحناتي» التي تفتحها الرئيسية (عدّادٌ أو بطاقةٌ أو «عرض الكل»)
  final shipmentsFilter = ValueNotifier<String>('all');

  /// بحث الرئيسية يفتح «شحناتي» والمؤشّر في خانة البحث (docs/plan/76)
  final searchNow = ValueNotifier<int>(0);

  /// حُفظت شحنةٌ جديدة: تُعاد الرئيسية و«شحناتي» بأرقامها
  final refresh = ValueNotifier<int>(0);

  /// الوصل المطبوع الممسوح بالزرّ الأوسط — يُكتب عليه «طلب جديد»
  final scanned = ValueNotifier<String?>(null);

  /// ما قرأه الذكاء الاصطناعي أو سُمع — يملأ «طلب جديد» (docs/plan/55)
  final reading = ValueNotifier<OrderReading?>(null);

  Brand get brand => widget.brand;

  @override
  void dispose() {
    shipmentsFilter.dispose();
    searchNow.dispose();
    refresh.dispose();
    scanned.dispose();
    reading.dispose();
    super.dispose();
  }

  void _open(String screen) {
    final parts = screen.split(':');
    // «للمعالجة» شاشةٌ وحدها (docs/plan/54): يقرّر التاجر فيها، ثم تُعاد الأرقام
    if (parts.first == 'processing') {
      openProcessing(context, brand, onChanged: () => refresh.value++);
      return;
    }
    // الأدوات السريعة (docs/plan/56): شاشاتٌ فوق الشريط
    final tool = switch (screen) {
      'pickups' => openPickups,
      'requests' => openRequests,
      'support' => openSupport,
      'waybills' => openWaybills,
      'import' => openImport,
      'labels' => openLabels,
      'returns' => openReturns,
      _ => null,
    };
    if (tool != null) {
      tool(context, brand).then((_) => refresh.value++);
      return;
    }
    // من الرئيسية (docs/plan/76): المسح، و«حسابي» من زرّ الإعدادات
    if (screen == 'scan') {
      _scan();
      return;
    }
    if (screen == 'account') {
      setState(() => tab = 4);
      return;
    }
    if (screen == 'create:ai' || screen == 'create:voice') {
      _read(voice: screen == 'create:voice');
      return;
    }
    final target = switch (parts.first) {
      'shipments' || 'attention' => 1,
      'create' => 2,
      'finance' => 3,
      _ => 4,
    };
    if (target == 1) {
      shipmentsFilter.value = switch (parts.length > 1 ? parts[1] : parts.first) {
        'delivered' => 'delivered',
        'open' => 'open',
        'returns' => 'returns',
        'processing' => 'processing',
        'attention' => 'attention',
        _ => 'all',
      };
    }
    setState(() => tab = target);
    if (parts.length > 1 && parts[1] == 'search') searchNow.value++;
  }

  /// «إنشاء بالذكاء الاصطناعي» و«بالتسجيل الصوتي»: يُقرأ الطلب ثم يُفتح «طلب جديد» مملوءاً
  Future<void> _read({required bool voice}) async {
    final messenger = ScaffoldMessenger.of(context);
    CreateForm form;
    try {
      form = await Api.instance.createForm();
    } on ApiError catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message), behavior: SnackBarBehavior.floating));
      return;
    }
    if (!mounted) return;
    if (!form.reading) {
      messenger.showSnackBar(
        const SnackBar(
          content: Text('القراءة بالذكاء الاصطناعي غير مفعّلة لشركتك — أدخل الطلب يدوياً.'),
          behavior: SnackBarBehavior.floating,
        ),
      );
      setState(() => tab = 2);
      return;
    }
    final result = voice
        ? await showVoiceReader(context, brand, listening: form.listening)
        : await showAiReader(context, brand);
    if (!mounted || result == null) return;
    reading.value = result;
    setState(() => tab = 2);
  }

  /// الزرّ الأوسط (docs/plan/52): يمسح الوصل المطبوع بالكاميرا ثم يفتح «طلب جديد» عليه.
  /// شركةٌ بلا وصولاتٍ مطبوعة: يفتح النموذج مباشرة
  Future<void> _scan() async {
    bool waybills;
    try {
      waybills = (await Api.instance.createForm()).waybills;
    } on ApiError {
      waybills = false;
    }
    if (!mounted) return;
    if (!waybills) {
      setState(() => tab = 2);
      return;
    }

    final result = await Navigator.of(context)
        .push<ScanResult>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => ScanScreen(brand: brand)));
    if (!mounted || result == null) return;
    if (result.receipt != null) {
      openReturnReceipt(context, brand, result.receipt!);
      return;
    }
    // باركود وصل حسابٍ أو إيصال راجع (docs/plan/69): يُؤكَّد استلامه
    if (result.receive != null) {
      await openReceive(context, brand, code: result.receive);
      refresh.value++;
      return;
    }
    scanned.value = result.code;
    setState(() => tab = 2);
  }

  @override
  Widget build(BuildContext context) {
    final pages = [
      HomeScreen(
        brand: brand,
        companyName: widget.session?.companyName ?? AppConfig.companyName,
        onOpen: _open,
        refresh: refresh,
      ),
      ShipmentsScreen(brand: brand, filter: shipmentsFilter, refresh: refresh, focusSearch: searchNow),
      CreateScreen(
        brand: brand,
        onCreated: () => refresh.value++,
        scanned: scanned,
        onScan: _scan,
        reading: reading,
        onRead: ({required bool voice}) => _read(voice: voice),
      ),
      FinanceScreen(brand: brand, refresh: refresh),
      _Account(brand: brand, session: widget.session, onLogout: widget.onLogout, onOpen: _open, refresh: refresh),
    ];

    // زرّ رجوع الهاتف: من أيّ صفحةٍ إلى الرئيسية، ومن الرئيسية يخرج
    return PopScope(
      canPop: tab == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) setState(() => tab = 0);
      },
      child: ShellNav(home: () => setState(() => tab = 0), child: _scaffold(pages)),
    );
  }

  Widget _scaffold(List<Widget> pages) {
    return Scaffold(
      extendBody: true,
      body: Stack(
        children: [
          IndexedStack(index: tab, children: pages),
          if (AppConfig.demo) const _DemoStatusBar(),
        ],
      ),
      bottomNavigationBar: _NavBar(brand: brand, index: tab, onTap: (i) => i == 2 ? _scan() : setState(() => tab = i)),
    );
  }
}

/// الشريط السفلي (docs/plan/76): أبيض بحافّةٍ علويّة مدوّرة، والصفحة المفتوحة على خلفيةٍ ورديّة فاتحة
/// بأيقونتها واسمها بلون الشركة.
class _NavBar extends StatelessWidget {
  const _NavBar({required this.brand, required this.index, required this.onTap});

  final Brand brand;
  final int index;
  final ValueChanged<int> onTap;

  static const _items = [
    ('الرئيسية', Icons.home_outlined, Icons.home_rounded),
    ('شحناتي', Icons.inventory_2_outlined, Icons.inventory_2_rounded),
    ('إنشاء شحنة', Icons.add_circle_outline_rounded, Icons.add_circle_rounded),
    ('المالية', Icons.account_balance_wallet_outlined, Icons.account_balance_wallet_rounded),
    ('المزيد', Icons.more_horiz_rounded, Icons.more_horiz_rounded),
  ];

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return Container(
      padding: EdgeInsets.fromLTRB(8, 8, 8, bottom > 0 ? bottom : 10),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
        border: Border(top: BorderSide(color: Palette.line)),
        boxShadow: [BoxShadow(color: Color(0x2226181A), blurRadius: 28, spreadRadius: -14, offset: Offset(0, -10))],
      ),
      child: SizedBox(
        height: 64,
        child: Row(children: [for (var i = 0; i < _items.length; i++) Expanded(child: _item(i))]),
      ),
    );
  }

  Widget _item(int i) {
    final (label, icon, filled) = _items[i];
    final active = i == index;
    final color = active ? brand.main : Palette.slate;
    return Tooltip(
      message: label,
      child: GestureDetector(
        onTap: () => onTap(i),
        behavior: HitTestBehavior.opaque,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          margin: const EdgeInsets.symmetric(horizontal: 3),
          decoration: BoxDecoration(
            color: active ? brand.soft : Colors.transparent,
            borderRadius: BorderRadius.circular(18),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(active ? filled : icon, size: 25, color: color),
              const SizedBox(height: 4),
              Text(label, maxLines: 1, style: font(11.5, active ? w8 : w6, color, height: 1)),
            ],
          ),
        ),
      ),
    );
  }
}

/// «حسابي» (تصميم ز): بطاقة المتجر، ثم الأدوات كلّها — أوّلها «كشوفات الرواجع» و«استلام راجع» بمسح
/// الإيصال (docs/plan/69) — والخروج.
class _Account extends StatefulWidget {
  const _Account({
    required this.brand,
    required this.session,
    required this.onLogout,
    required this.onOpen,
    required this.refresh,
  });

  final Brand brand;
  final Session? session;
  final Future<void> Function() onLogout;
  final void Function(String screen) onOpen;
  final Listenable refresh;

  @override
  State<_Account> createState() => _AccountState();
}

class _AccountState extends State<_Account> {
  RequestsPage? returns;

  static const _items = [
    ('كشوفات الرواجع', Icons.assignment_return_rounded, 'returns'),
    ('استلام راجع بالباركود', Icons.qr_code_scanner_rounded, 'receive'),
    ('طباعة وصولات الطلبات المدخلة', Icons.print_rounded, 'labels'),
    ('للمعالجة', Icons.schedule_rounded, 'processing'),
    ('طلبات الاستلام', Icons.hail_rounded, 'pickups'),
    ('طلباتي', Icons.assignment_outlined, 'requests'),
    ('الدعم', Icons.headset_mic_outlined, 'support'),
    ('دفاتر وصولاتٍ فارغة للطباعة', Icons.receipt_long_outlined, 'waybills'),
    ('رفع شحنات من ملف', Icons.upload_file_outlined, 'import'),
  ];

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.refresh.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.refresh.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await Api.instance.requests();
      if (mounted) setState(() => returns = r);
    } on ApiError {
      // تبقى الأدوات ظاهرةً بلا الكشوفات
    }
  }

  Future<void> _scan() async {
    final done = await openReceive(context, brand);
    if (done != null) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.session;
    final name = session?.name ?? '';
    final top = MediaQuery.paddingOf(context).top;
    final waiting = returns?.batches.where((b) => b.received == null).length ?? 0;
    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      child: ListView(
        // تحت شريط الحالة؛ وفي نسخة العرض على الويب تحت شريط حالةٍ مرسوم
        padding: EdgeInsets.fromLTRB(16, top > 0 ? top + 6 : 34, 16, 130),
        children: [
          Text('حسابي', style: display(28, w7, Palette.ink, height: 1.3)),
          const SizedBox(height: 10),
          WhiteCard(
            radius: 24,
            padding: const EdgeInsets.all(14),
            child: Row(
              children: [
                Container(
                  width: 56,
                  height: 56,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    gradient: brand.hero,
                    borderRadius: BorderRadius.circular(18),
                    boxShadow: brand.glow(),
                  ),
                  child: Text(
                    name.isEmpty ? '؟' : name.characters.first,
                    style: display(26, w7, Colors.white, height: 1),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(name, style: display(18, w7, Palette.ink)),
                      if (session != null) Text(session.companyName, style: font(12.5, w6, Palette.muted)),
                      // بأيّ نظامٍ هذه النسخة مربوطة: أوّل ما يُسأل حين لا يصل شيء
                      if (!AppConfig.demo)
                        Text(
                          'مربوط بنظام الشركة: ${Uri.parse(AppConfig.apiUrl).host}',
                          style: font(11.5, w6, Palette.ok),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          if (AppConfig.demo) ...[const SizedBox(height: 8), const DemoNote()],
          const SizedBox(height: 12),
          WhiteCard(
            radius: 24,
            padding: const EdgeInsets.symmetric(vertical: 4),
            // ListTile يرسم ضغطته على Material — والبطاقة لونٌ فوقه
            child: Material(
              type: MaterialType.transparency,
              child: Column(
                children: [
                  for (final (i, (label, icon, screen)) in _items.indexed) ...[
                    if (i > 0) const Divider(height: 1, indent: 60, color: Palette.line),
                    ListTile(
                      onTap: () => screen == 'receive' ? _scan() : widget.onOpen(screen),
                      leading: Container(
                        width: 34,
                        height: 34,
                        decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(12)),
                        child: Icon(icon, size: 18, color: brand.deep),
                      ),
                      title: Text(label, style: font(14, w7, Palette.ink)),
                      trailing: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          // ما ينتظر استلامه من الرواجع
                          if (screen == 'returns' && waiting > 0) ...[
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
                              decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(20)),
                              child: Text('$waiting بانتظارك', style: font(11, w8, Colors.white)),
                            ),
                            const SizedBox(width: 8),
                          ],
                          const Chev(size: 9, stroke: 1.6, color: Palette.muted),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: widget.onLogout,
            style: OutlinedButton.styleFrom(
              minimumSize: const Size.fromHeight(48),
              side: BorderSide(color: brand.border),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
            ),
            icon: Icon(Icons.logout_rounded, color: brand.deep),
            label: Text('تسجيل الخروج', style: font(14, w7, brand.deep)),
          ),
        ],
      ),
    );
  }
}

/// شريط الحالة في نسخة العرض على الويب — لتطابق اللقطة ملف التصميم
class _DemoStatusBar extends StatelessWidget {
  const _DemoStatusBar();

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.paddingOf(context).top > 0) return const SizedBox.shrink();
    return Positioned(
      top: 0,
      left: 0,
      right: 0,
      height: 28,
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(29, 5, 24, 0),
          child: Row(
            children: [
              Text('9:41', style: font(15, w7, Colors.black, height: 1)),
              const Spacer(),
              const Icon(Icons.signal_cellular_alt_rounded, size: 17, color: Colors.black),
              const SizedBox(width: 4),
              const Icon(Icons.wifi_rounded, size: 17, color: Colors.black),
              const SizedBox(width: 5),
              Container(
                width: 24,
                height: 11.5,
                padding: const EdgeInsets.all(1.5),
                decoration: BoxDecoration(
                  border: Border.all(color: Colors.black45, width: 1),
                  borderRadius: BorderRadius.circular(3.5),
                ),
                child: Container(
                  decoration: BoxDecoration(color: Colors.black, borderRadius: BorderRadius.circular(2)),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
