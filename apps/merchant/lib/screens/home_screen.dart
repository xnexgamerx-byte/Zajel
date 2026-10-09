import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';

/// رئيسية التاجر — كما في ملف التصميم قياساً بقياس (docs/plan/48 §التصميم):
/// التحيّة، الإعلانات، الرصيد، العدّادات الأربعة، إنشاء شحنة بثلاث طرق، الأدوات
/// السريعة، «للمعالجة» و«تحتاج انتباهك»، ثم آخر الشحنات.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.brand, required this.companyName, this.onOpen});

  final Brand brand;

  /// «حسابي مع الزاجل»: اسم الشركة كما وصل من النظام
  final String companyName;

  /// يفتح شاشةً من شاشات التطبيق باسمها (shipments, create, finance…)
  final void Function(String screen)? onOpen;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  HomeData? data;
  String? error;
  bool hidden = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.home();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  void _open(String screen) => widget.onOpen?.call(screen);

  @override
  Widget build(BuildContext context) {
    final d = data;
    if (d == null) {
      return Center(
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
      );
    }

    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      child: ListView(
        // تحت شريط الحالة؛ وفي نسخة العرض على الويب تحت شريط حالةٍ مرسوم بارتفاع التصميم
        padding: EdgeInsets.only(top: _top(context), bottom: 100),
        children: [
          _header(d),
          const SizedBox(height: 5.5),
          _pad(_banner(d)),
          const SizedBox(height: 6),
          _pad(_balance(d)),
          const SizedBox(height: 5.5),
          _pad(_stats(d.stats)),
          const SizedBox(height: 6),
          _pad(_create()),
          const SizedBox(height: 10),
          _pad(_tools()),
          const SizedBox(height: 6.5),
          _pad(_alerts(d)),
          const SizedBox(height: 6),
          _pad(_recent(d)),
        ],
      ),
    );
  }

  static double _top(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return top > 0 ? top + 2 : 28;
  }

  Widget _pad(Widget child) => Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: child);

  // ------------------------------------------------------------------ الترويسة

  Widget _header(HomeData d) {
    return SizedBox(
      height: 35,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 15),
        child: Row(
          children: [
            // سهم الحساب وصورة التاجر
            Container(
              width: 17,
              height: 17,
              decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
              child: const Icon(Icons.keyboard_arrow_down_rounded, size: 14, color: Palette.ink),
            ),
            _avatar(d),
            const SizedBox(width: 6),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    '${d.greeting}، ${d.name}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: font(11, w8, Palette.ink, height: 1.15),
                  ),
                  const SizedBox(height: 1),
                  Text('هنا ملخص أعمالك اليوم', style: font(7.8, w5, Palette.slate, height: 1.15)),
                ],
              ),
            ),
            _bell(d.unread),
          ],
        ),
      ),
    );
  }

  Widget _avatar(HomeData d) {
    final initial = d.name.isEmpty ? '؟' : d.name.characters.first;
    return Container(
      width: 34,
      height: 34,
      padding: const EdgeInsets.all(1.5),
      decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
      child: ClipOval(
        child: AppConfig.demo
            ? Image.asset('assets/images/demo-avatar.jpg', fit: BoxFit.cover)
            : Container(
                color: brand.soft,
                alignment: Alignment.center,
                child: Text(initial, style: font(15, w8, brand.main, height: 1)),
              ),
      ),
    );
  }

  Widget _bell(int unread) {
    return SizedBox(
      width: 35,
      height: 35,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Container(
            width: 35,
            height: 35,
            decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
            child: const Icon(Icons.notifications_none_rounded, size: 21, color: Palette.ink),
          ),
          if (unread > 0)
            Positioned(
              left: 23.5,
              top: 0,
              child: Container(
                constraints: const BoxConstraints(minWidth: 12.5),
                height: 12.5,
                padding: const EdgeInsets.symmetric(horizontal: 3),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: brand.main,
                  borderRadius: BorderRadius.circular(7),
                  border: Border.all(color: Colors.white, width: 1),
                ),
                child: Text(unread > 9 ? '9+' : '$unread', style: font(7.5, w8, Colors.white, height: 1)),
              ),
            ),
        ],
      ),
    );
  }

  // ------------------------------------------------------------------ الإعلانات

  Widget _banner(HomeData d) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(14),
      child: SizedBox(
        height: 112,
        child: d.banners.isEmpty
            // إعلان الشركة الافتراضي إلى أن ترفع إعلاناتها («إعلانات التطبيق» في النظام)
            ? Image.asset('assets/images/banner.jpg', fit: BoxFit.cover, width: double.infinity)
            : _Ads(brand: brand, banners: d.banners),
      ),
    );
  }

  // ------------------------------------------------------------------ الرصيد

  Widget _balance(HomeData d) {
    final amount = money(d.balance);
    return WhiteCard(
      height: 48,
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: Row(
          children: [
            const SizedBox(width: 10),
            SolidIcon(brand: brand, icon: Icons.account_balance_wallet_outlined, size: 34, iconSize: 20, radius: 9),
            const SizedBox(width: 17),
            Expanded(
              child: Row(
                children: [
                  Flexible(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerLeft,
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                d.owed ? 'لك عند الشركة' : 'عليك للشركة',
                                style: font(9.6, w7, Palette.ink, height: 1.1),
                              ),
                              const SizedBox(width: 5),
                              const Chev(size: 7),
                            ],
                          ),
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            crossAxisAlignment: CrossAxisAlignment.baseline,
                            textBaseline: TextBaseline.alphabetic,
                            children: [
                              Text('د.ع', style: font(11.5, w7, Palette.ink, height: 1.1)),
                              const SizedBox(width: 11),
                              Text(
                                hidden ? '••••••' : amount,
                                style: font(19, w8, d.owed ? brand.main : Palette.ink, height: 1.1),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(width: 9),
                  GestureDetector(
                    onTap: () => setState(() => hidden = !hidden),
                    child: Container(
                      width: 26,
                      height: 26,
                      decoration: const BoxDecoration(color: Palette.eye, shape: BoxShape.circle),
                      child: Icon(
                        hidden ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                        size: 15,
                        color: Palette.ink,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Tap(
              radius: 15,
              onTap: () => _open('finance'),
              child: Container(
                width: 91,
                height: 26,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(15),
                  border: Border.all(color: brand.coral.withValues(alpha: .75), width: 1),
                ),
                child: Directionality(
                  textDirection: TextDirection.rtl,
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Chev(size: 7.5, color: brand.main, stroke: 1.6),
                      const SizedBox(width: 6),
                      Flexible(
                        child: FittedBox(
                          fit: BoxFit.scaleDown,
                          child: Text('عرض التفاصيل', style: font(10.2, w7, brand.main, height: 1)),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(width: 9),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ العدّادات

  Widget _stats(Stats s) {
    double of(int v) => s.total == 0 ? 0 : v / s.total;
    final tiles = [
      _stat('إجمالي الشحنات', s.total, of(s.delivered + s.returns), Icons.inventory_2_rounded, 'shipments'),
      _stat('مسلمة', s.delivered, of(s.delivered), Icons.check_box_rounded, 'delivered'),
      _stat('قيد التوصيل', s.inDelivery, of(s.inDelivery), Icons.local_shipping_rounded, 'open'),
      _stat('راجع مؤكدة', s.returns, of(s.returns), Icons.undo_rounded, 'returns'),
    ];
    return Row(
      children: [
        for (var i = 0; i < tiles.length; i++) ...[if (i > 0) const SizedBox(width: 5), Expanded(child: tiles[i])],
      ],
    );
  }

  Widget _stat(String label, int value, double ratio, IconData icon, String screen) {
    return Tap(
      radius: 12,
      onTap: () => _open('shipments:$screen'),
      child: WhiteCard(
        height: 68.5,
        radius: 12,
        padding: const EdgeInsets.fromLTRB(9, 5, 9, 0),
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Stack(
            children: [
              Positioned(
                left: 0,
                top: 0,
                child: SoftIcon(
                  brand: brand,
                  child: Icon(icon, size: 17, color: brand.main),
                ),
              ),
              Positioned(
                right: 0,
                top: 0,
                height: 27,
                left: 30,
                child: Align(
                  alignment: Alignment.centerRight,
                  // الاسم الطويل يصغر ليتّسع كما في التصميم، لا يُقطع
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerRight,
                    child: Text(label, textDirection: TextDirection.rtl, style: font(9, w7, Palette.ink, height: 1)),
                  ),
                ),
              ),
              Positioned(left: 0, top: 36, child: Text(money(value), style: font(15.3, w8, Palette.ink, height: 1))),
              Positioned(
                left: 0,
                right: 0,
                top: 52.5,
                child: _Bar(ratio: ratio, brand: brand),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ إنشاء شحنة

  Widget _create() {
    // بطاقة ١١٥٫٥ نقطة: العنوان، ثم البطاقات الثلاث على بعد ٣٤٫٥ من أعلاها بارتفاع ٧٨
    return WhiteCard(
      height: 115.5,
      child: Stack(
        children: [
          Positioned(right: 8, top: 5, child: Text('إنشاء شحنة', style: font(10.3, w8, Palette.ink, height: 1.2))),
          Positioned(
            right: 8,
            top: 18.5,
            child: Text('أختر الطريقة الأنسب لك', style: font(8.5, w5, Palette.slate, height: 1.2)),
          ),
          Positioned(
            left: 5,
            right: 5,
            top: 34.5,
            height: 78,
            child: Row(
              children: [
                Expanded(child: _option('إنشاء شحنة', 'إدخال يدوي', _boxPlus(), 'create', highlighted: true)),
                const SizedBox(width: 5),
                Expanded(
                  child: _option(
                    'إنشاء بالذكاء الاصطناعي',
                    'من وصف بسيط للطلب',
                    Icon(Icons.auto_awesome_rounded, size: 25, color: brand.main),
                    'create:ai',
                  ),
                ),
                const SizedBox(width: 5),
                Expanded(
                  child: _option(
                    'إنشاء بالتسجيل الصوتي',
                    'قل بيانات الشحنة',
                    Icon(Icons.mic_rounded, size: 25, color: brand.main),
                    'create:voice',
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _boxPlus() => SizedBox(
    width: 26,
    height: 26,
    child: Stack(
      clipBehavior: Clip.none,
      children: [
        Icon(Icons.inventory_2_rounded, size: 23, color: brand.main),
        Positioned(
          right: -2,
          bottom: -1,
          child: Container(
            width: 12,
            height: 12,
            decoration: BoxDecoration(
              color: brand.main,
              shape: BoxShape.circle,
              border: Border.all(color: Colors.white, width: 1.2),
            ),
            child: const Icon(Icons.add_rounded, size: 9, color: Colors.white),
          ),
        ),
      ],
    ),
  );

  Widget _option(String title, String sub, Widget icon, String screen, {bool highlighted = false}) {
    return Tap(
      radius: 12,
      onTap: () => _open(screen),
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: highlighted ? brand.border : Palette.line, width: 1),
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: highlighted ? [brand.softer, brand.soft] : [Colors.white, const Color(0xFFFBFCFD)],
          ),
        ),
        child: Stack(
          children: [
            Positioned(
              top: 4,
              left: 0,
              right: 0,
              child: Center(
                child: SoftIcon(brand: brand, size: 37, radius: 11, child: icon),
              ),
            ),
            Positioned(
              top: 44,
              right: 7,
              left: 7,
              child: Row(
                children: [
                  const Chev(size: 7),
                  const SizedBox(width: 3),
                  Expanded(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      child: Text(title, style: font(8.1, w8, Palette.ink, height: 1.2)),
                    ),
                  ),
                  const SizedBox(width: 6),
                ],
              ),
            ),
            Positioned(
              top: 59.5,
              left: 4,
              right: 4,
              child: Text(
                sub,
                textAlign: TextAlign.center,
                maxLines: 1,
                style: font(8, w5, Palette.slate, height: 1.2),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ أدوات سريعة

  Widget _tools() {
    final items = [
      ('طلبات الاستلام', Icons.hail_rounded, 'pickups'),
      ('وصلات للطباعة', Icons.print_outlined, 'waybills'),
      ('رفع شحنات من ملف', Icons.upload_file_outlined, 'import'),
      ('الدعم', Icons.headset_mic_outlined, 'support'),
      ('طلباتي', Icons.assignment_outlined, 'requests'),
      ('حسابي مع ${widget.companyName}', Icons.account_balance_wallet_outlined, 'finance'),
    ];
    Widget row(int from) => Row(
      children: [
        for (var i = from; i < from + 3; i++) ...[
          if (i > from) const SizedBox(width: 4),
          Expanded(child: _tool(items[i].$1, items[i].$2, items[i].$3)),
        ],
      ],
    );

    // بطاقة ١٠٧٫٥: العنوان، ثم صفّان بارتفاع ٤٠ يفصلهما ٣
    return WhiteCard(
      height: 107.5,
      child: Stack(
        children: [
          Positioned(right: 8, top: 1.5, child: Text('أدوات سريعة', style: font(10.4, w8, Palette.ink, height: 1.2))),
          Positioned(left: 6, right: 6, top: 19.5, height: 40, child: row(0)),
          Positioned(left: 6, right: 6, top: 62.5, height: 40, child: row(3)),
        ],
      ),
    );
  }

  Widget _tool(String label, IconData icon, String screen) {
    return Tap(
      radius: 12,
      onTap: () => _open(screen),
      child: Container(
        height: 40,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: Palette.line, width: 1),
        ),
        child: Row(
          children: [
            const SizedBox(width: 11),
            const Chev(size: 7),
            const SizedBox(width: 5),
            Expanded(
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerRight,
                child: Text(label, style: font(9.4, w6, Palette.ink, height: 1.2)),
              ),
            ),
            const SizedBox(width: 4),
            SolidIcon(brand: brand, icon: icon, size: 25, iconSize: 15.5),
            const SizedBox(width: 9),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ للمعالجة / تحتاج انتباهك

  Widget _alerts(HomeData d) {
    return SizedBox(
      height: 76.5,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: _alert(
              'للمعالجة',
              d.processing,
              'شحنات لم يستلمها زبونك\nتتطلب تواصلك واتخاذ الإجراء المناسب',
              Icons.schedule_rounded,
              'processing',
            ),
          ),
          const SizedBox(width: 6),
          Expanded(
            child: _alert(
              'تحتاج انتباهك',
              d.attention,
              'شحنات تعثرت، أحياناً مكالمة منك\nللزبون تحل ما لا تحلة محاولة ثانية',
              Icons.priority_high_rounded,
              'attention',
            ),
          ),
        ],
      ),
    );
  }

  Widget _alert(String title, int count, String text, IconData icon, String screen) {
    return Tap(
      onTap: () => _open(screen),
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          gradient: LinearGradient(
            begin: Alignment.topRight,
            end: Alignment.bottomLeft,
            colors: [Color.lerp(Colors.white, brand.main, .07)!, Color.lerp(Colors.white, brand.main, .025)!],
          ),
          boxShadow: cardShadow,
        ),
        child: Stack(
          children: [
            const Positioned(right: 10, top: 13, child: Chev(size: 7)),
            Positioned(right: 29, top: 5, child: Text(title, style: font(9.8, w8, brand.main, height: 1.2))),
            Positioned(right: 29, top: 21, child: Text(money(count), style: font(14.2, w8, Palette.ink, height: 1.2))),
            Positioned(
              right: 27,
              left: 6,
              top: 41,
              child: Text(
                text,
                maxLines: 2,
                softWrap: false,
                overflow: TextOverflow.fade,
                style: font(8.6, w5, Palette.slate, height: 1.45),
              ),
            ),
            Positioned(
              left: 8,
              top: 5,
              child: Container(
                width: 31,
                height: 31,
                alignment: Alignment.center,
                decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(10)),
                child: SolidIcon(brand: brand, icon: icon, size: 22, iconSize: 14.5, radius: 7),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ آخر الشحنات

  Widget _recent(HomeData d) {
    return WhiteCard(
      padding: const EdgeInsets.only(top: 6, bottom: 4),
      child: Column(
        children: [
          SizedBox(
            height: 15,
            child: Row(
              children: [
                const SizedBox(width: 8),
                Expanded(child: Text('تحتاج انتباهك', style: font(9.8, w8, Palette.ink, height: 1.2))),
                GestureDetector(
                  onTap: () => _open('shipments'),
                  child: Row(
                    children: [
                      Text('عرض الكل', style: font(9.4, w8, brand.main, height: 1.2)),
                      const SizedBox(width: 4),
                      Chev(size: 7.5, color: brand.main, left: true, stroke: 1.7),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
              ],
            ),
          ),
          if (d.recent.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 20),
              child: Text('لا شحنات بعد — أنشئ أوّل شحنة من الزرّ الأحمر.', style: font(9, w5, Palette.slate)),
            ),
          for (var i = 0; i < d.recent.length; i++) ...[
            const Divider(height: 0.5, thickness: 0.5, indent: 8, endIndent: 8, color: Color(0xFFE9EDF2)),
            _row(d.recent[i]),
          ],
        ],
      ),
    );
  }

  Widget _row(RecentShipment s) {
    return SizedBox(
      height: 30,
      child: Row(
        children: [
          const SizedBox(width: 11),
          const Chev(size: 7.5),
          const SizedBox(width: 22),
          SizedBox(
            width: 72,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  s.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(7.4, w7, Palette.ink, height: 1.1),
                ),
                const SizedBox(height: 3),
                Text(
                  s.area,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(7.6, w5, Palette.slate, height: 1.1),
                ),
              ],
            ),
          ),
          const SizedBox(width: 2),
          SizedBox(
            width: 47,
            child: FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerRight,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.baseline,
                textBaseline: TextBaseline.alphabetic,
                children: [
                  Text(money(s.amount), style: font(10.6, w8, brand.main, height: 1)),
                  const SizedBox(width: 3),
                  Text('د.ع', style: font(9.5, w6, Palette.ink, height: 1)),
                ],
              ),
            ),
          ),
          const SizedBox(width: 11),
          SizedBox(width: 62, child: Text(_when(s.at), maxLines: 1, style: font(7.9, w5, Palette.slate, height: 1))),
          const SizedBox(width: 9),
          Expanded(
            child: Text(
              '#${s.number}',
              maxLines: 1,
              overflow: TextOverflow.fade,
              softWrap: false,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.right,
              style: font(7.9, w5, Palette.slate, height: 1),
            ),
          ),
          const SizedBox(width: 6),
          Container(
            width: 55,
            height: 18,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: s.urgent ? brand.coral : Palette.pill,
              borderRadius: BorderRadius.circular(9),
            ),
            child: Text(
              s.status,
              maxLines: 1,
              overflow: TextOverflow.fade,
              softWrap: false,
              style: font(6.9, w8, Colors.white, height: 1),
            ),
          ),
          const SizedBox(width: 8),
        ],
      ),
    );
  }

  /// «اليوم 10:45 ص»، «أمس 04:15 م»، أو التاريخ
  static String _when(DateTime? at) {
    if (at == null) return '';
    final now = DateTime.now();
    final day = DateTime(at.year, at.month, at.day);
    final today = DateTime(now.year, now.month, now.day);
    final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
    final time = '${h.toString().padLeft(2, '0')}:${at.minute.toString().padLeft(2, '0')} ${at.hour < 12 ? 'ص' : 'م'}';
    if (day == today) return 'اليوم $time';
    if (day == today.subtract(const Duration(days: 1))) return 'أمس $time';
    return '${at.day}/${at.month} $time';
  }
}

/// شريط النسبة تحت كل عدّاد
class _Bar extends StatelessWidget {
  const _Bar({required this.ratio, required this.brand});

  final double ratio;
  final Brand brand;

  @override
  Widget build(BuildContext context) => Container(
    height: 4.5,
    decoration: BoxDecoration(color: brand.track, borderRadius: BorderRadius.circular(3)),
    child: FractionallySizedBox(
      alignment: Alignment.centerLeft,
      widthFactor: ratio.clamp(0, 1).toDouble(),
      child: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(colors: [brand.main, brand.coral]),
          borderRadius: BorderRadius.circular(3),
        ),
      ),
    ),
  );
}

/// إعلانات الشركة تنزلق، ونقاطها أسفل اليسار كما في التصميم
class _Ads extends StatefulWidget {
  const _Ads({required this.brand, required this.banners});

  final Brand brand;
  final List<AdBanner> banners;

  @override
  State<_Ads> createState() => _AdsState();
}

class _AdsState extends State<_Ads> {
  int page = 0;

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        PageView(
          onPageChanged: (i) => setState(() => page = i),
          children: [
            for (final b in widget.banners)
              Image.network(
                b.image,
                headers: Api.instance.imageHeaders,
                fit: BoxFit.cover,
                errorBuilder: (_, _, _) => Image.asset('assets/images/banner.jpg', fit: BoxFit.cover),
              ),
          ],
        ),
        if (widget.banners.length > 1)
          Positioned(
            left: 20,
            bottom: 11,
            child: Directionality(
              textDirection: TextDirection.ltr,
              child: Row(
                children: [
                  for (var i = 0; i < widget.banners.length; i++)
                    Container(
                      width: 6,
                      height: 6,
                      margin: const EdgeInsets.only(right: 3),
                      decoration: BoxDecoration(
                        color: i == page ? widget.brand.main : Colors.white.withValues(alpha: .85),
                        shape: BoxShape.circle,
                      ),
                    ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
