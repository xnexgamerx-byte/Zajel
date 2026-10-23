import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'notifications_screen.dart';
import 'shipment_screen.dart';

/// «صباح الخير» حتى الظهر بتوقيت بغداد، ثم «مساء الخير» — أيّاً كانت ساعة الهاتف (docs/plan/59)
String baghdadGreeting([DateTime? now]) =>
    (now ?? DateTime.now()).toUtc().add(const Duration(hours: 3)).hour < 12 ? 'صباح الخير' : 'مساء الخير';

/// رئيسية التاجر (docs/plan/76): التحيّة واسمه بخطٍّ كبير، والبحث والمسح، ورقما «سُلّمت اليوم» و«إجمالي
/// الشحنات»، وحالة الشحنات الأربع حول زرّ «إنشاء شحنة»، ثم المالية وطباعة الطلبات وطلب الاستلام، و«شحنات تحتاج
/// انتباهك» مكان آخر الشحنات.
///
/// القياسات مكبّرةً لتُقرأ على الهاتف (docs/plan/59): لا نصّ أصغر من ١١، والاسم الطويل ينكسر سطرين.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.brand, required this.companyName, this.onOpen, this.refresh});

  final Brand brand;

  /// اسم الشركة كما وصل من النظام
  final String companyName;

  /// يفتح شاشةً من شاشات التطبيق باسمها (shipments, create, finance…)
  final void Function(String screen)? onOpen;

  /// يُعاد التحميل حين يتغيّر — بعد حفظ شحنةٍ جديدة
  final Listenable? refresh;

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  HomeData? data;
  String? error;

  /// الصورة كما اختارها الآن — تظهر قبل أن يعود رابطها من النظام
  Uint8List? picked;
  bool uploading = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    widget.refresh?.addListener(_load);
    _load();
  }

  @override
  void dispose() {
    widget.refresh?.removeListener(_load);
    super.dispose();
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

  Future<void> _bell() async {
    await openNotifications(context, brand);
    _load();
  }

  // ------------------------------------------------------------------ صورته أو شعاره

  Future<void> _logo(HomeData d) async {
    final has = picked != null || d.logo != null;
    final action = await showSheet<String>(
      context,
      SheetFrame(
        title: has ? 'صورتك' : 'ضع صورتك أو شعار متجرك',
        text: 'تظهر في رأس رئيسيتك. صورةٌ مربّعة تبدو أجمل.',
        children: [
          SheetButton(
            brand: brand,
            label: has ? 'غيّرها' : 'اختر صورة',
            icon: Icons.add_photo_alternate_outlined,
            onTap: () => Navigator.of(context).pop('pick'),
          ),
          if (has) ...[
            const SizedBox(height: 8),
            SheetButton(
              brand: brand,
              label: 'احذفها',
              icon: Icons.delete_outline_rounded,
              outlined: true,
              onTap: () => Navigator.of(context).pop('delete'),
            ),
          ],
        ],
      ),
    );
    if (!mounted || action == null) return;
    if (action == 'delete') {
      setState(() => uploading = true);
      try {
        await Api.instance.deleteLogo();
        if (mounted) setState(() => picked = null);
        await _load();
      } on ApiError catch (e) {
        if (mounted) toast(context, e.message, bad: true);
      } finally {
        if (mounted) setState(() => uploading = false);
      }
      return;
    }

    Uint8List bytes;
    String name;
    // نسخة العرض على الويب بلا معرض صور؛ وعلى الهاتف تفتح المعرض كالحقيقية
    if (AppConfig.demo && kIsWeb) {
      bytes = (await rootBundle.load('assets/images/demo-avatar.jpg')).buffer.asUint8List();
      name = 'logo.jpg';
    } else {
      try {
        final image = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 600, imageQuality: 88);
        if (image == null) return;
        bytes = await image.readAsBytes();
        name = image.name.contains('.') ? image.name : '${image.name}.jpg';
      } on PlatformException {
        if (mounted) toast(context, 'تعذّر فتح الصور. اسمح للتطبيق بها من إعدادات الهاتف.', bad: true);
        return;
      }
    }
    setState(() => (picked = bytes, uploading = true));
    try {
      await Api.instance.uploadLogo(bytes, name);
      if (mounted) toast(context, 'حُفظت صورتك.');
      if (!AppConfig.demo) await _load();
    } on ApiError catch (e) {
      if (mounted) {
        setState(() => picked = null);
        toast(context, e.message, bad: true);
      }
    } finally {
      if (mounted) setState(() => uploading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    if (d == null) return Waiting(brand: brand, error: error, onRetry: _load);

    return RefreshIndicator(
      color: brand.main,
      onRefresh: _load,
      child: ListView(
        // تحت شريط الحالة؛ وفي نسخة العرض على الويب تحت شريط حالةٍ مرسوم
        padding: EdgeInsets.only(top: _top(context), bottom: 110),
        children: [
          // نسخة العرض على الهاتف تقول ذلك صراحةً: لا تُحسب على النظام
          if (AppConfig.demo && !kIsWeb) _pad(const DemoNote()),
          _header(d),
          const SizedBox(height: 16),
          _pad(_search()),
          const SizedBox(height: 14),
          _pad(_stats(d.stats)),
          // ما تعثّر ينتظر قراره: شريطٌ لا يُغفل عنه، ويختفي حين لا شيء
          if (d.processing > 0) ...[const SizedBox(height: 12), _pad(_processing(d))],
          const SizedBox(height: 14),
          _pad(_hub(d)),
          const SizedBox(height: 12),
          _pad(_actions(d)),
          if (d.banners.isNotEmpty) ...[const SizedBox(height: 14), _pad(_banner(d))],
          const SizedBox(height: 18),
          _pad(_attention(d)),
        ],
      ),
    );
  }

  static double _top(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return top > 0 ? top + 6 : 32;
  }

  Widget _pad(Widget child) => Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: child);

  // ------------------------------------------------------------------ الترويسة

  /// التحيّة كبيرةً واسمه تحتها بخطٍّ عريض، وصورته في الطرف الآخر، والجرس والإعدادات أعلاها
  Widget _header(HomeData d) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              _round(Icons.settings_outlined, 'حسابي', () => _open('account')),
              const SizedBox(width: 10),
              _bellButton(d.unread),
              const Spacer(),
              _avatar(d),
            ],
          ),
          const SizedBox(height: 8),
          Text(baghdadGreeting(), style: display(24, w6, Palette.muted, height: 1.2)),
          Text(d.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: display(30, w8, Palette.ink, height: 1.25)),
          Text(
            'شحناتك مع ${widget.companyName}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: font(14, w6, Palette.slate, height: 1.5),
          ),
        ],
      ),
    );
  }

  Widget _round(IconData icon, String tip, VoidCallback onTap) => Tooltip(
    message: tip,
    child: Tap(
      radius: 24,
      onTap: onTap,
      child: Container(
        width: 48,
        height: 48,
        decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
        child: Icon(icon, size: 23, color: Palette.ink),
      ),
    ),
  );

  Widget _avatar(HomeData d) {
    final Widget image;
    if (picked != null) {
      image = Image.memory(picked!, fit: BoxFit.cover);
    } else if (d.logo != null) {
      image = Image.network(
        d.logo!,
        headers: Api.instance.imageHeaders,
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => _emptyLogo(),
      );
    } else {
      image = _emptyLogo();
    }

    return Tooltip(
      message: 'صورتك أو شعارك',
      child: Tap(
        radius: 24,
        onTap: uploading ? null : () => _logo(d),
        child: Container(
          width: 48,
          height: 48,
          padding: const EdgeInsets.all(2),
          decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
          child: ClipOval(
            child: uploading
                ? Center(
                    child: SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: brand.main),
                    ),
                  )
                : image,
          ),
        ),
      ),
    );
  }

  /// بلا صورة: دائرةٌ فارغة بأيقونة كاميرا — يضغطها فيضع صورته
  Widget _emptyLogo() => Container(
    color: brand.softer,
    alignment: Alignment.center,
    child: Icon(Icons.add_a_photo_outlined, size: 21, color: brand.main),
  );

  Widget _bellButton(int unread) {
    return Tooltip(
      message: 'الإشعارات',
      child: Tap(
        radius: 22,
        onTap: _bell,
        child: SizedBox(
          width: 44,
          height: 44,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
                child: const Icon(Icons.notifications_none_rounded, size: 22, color: Palette.ink),
              ),
              if (unread > 0)
                Positioned(
                  left: 27,
                  top: -2,
                  child: Container(
                    constraints: const BoxConstraints(minWidth: 18),
                    height: 18,
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: brand.main,
                      borderRadius: BorderRadius.circular(9),
                      border: Border.all(color: Colors.white, width: 1.5),
                    ),
                    child: Text(unread > 9 ? '9+' : '$unread', style: font(10.5, w8, Colors.white, height: 1)),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ الإعلانات

  /// أطول قليلاً من نسبة الصورة (1095×336): تملؤه وتُقصّ أطرافها، وتنزلق وحدها كلّ خمس ثوانٍ
  Widget _banner(HomeData d) => ClipRRect(
    borderRadius: BorderRadius.circular(18),
    child: AspectRatio(
      aspectRatio: 1095 / 400,
      child: _Ads(brand: brand, banners: d.banners, companyName: widget.companyName, onOpen: _open),
    ),
  );

  // ------------------------------------------------------------------ البحث

  /// «ابحث برقم الشحنة أو اسم الزبون» يفتح «شحناتي» بالبحث، ومربّع المسح بجانبه
  Widget _search() => Row(
    children: [
      Tooltip(
        message: 'امسح باركود',
        child: Tap(
          radius: 16,
          onTap: () => _open('scan'),
          child: Container(
            width: 52,
            height: 52,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: Palette.line),
              boxShadow: cardShadow,
            ),
            child: const Icon(Icons.qr_code_scanner_rounded, size: 24, color: Palette.ink),
          ),
        ),
      ),
      const SizedBox(width: 10),
      Expanded(
        child: Tap(
          radius: 16,
          onTap: () => _open('shipments:search'),
          child: Container(
            height: 52,
            padding: const EdgeInsets.symmetric(horizontal: 14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: Palette.line),
              boxShadow: cardShadow,
            ),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    'ابحث برقم الشحنة أو اسم الزبون…',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: font(13, w5, Palette.muted),
                  ),
                ),
                const Icon(Icons.search_rounded, size: 24, color: Palette.ink),
              ],
            ),
          ),
        ),
      ),
    ],
  );

  // ------------------------------------------------------------------ الرقمان

  /// «سُلّمت اليوم» و«إجمالي الشحنات»: رقمٌ كبير، وأعمدةٌ تمتلئ بنسبةٍ حقيقيّة — ما سُلّم من خرجة اليوم،
  /// ونسبة التسليم من كلّ ما أرسل
  Widget _stats(Stats s) {
    final today = s.deliveredToday + s.courier;
    final rate = s.total == 0 ? 0 : (s.delivered * 100 / s.total).round();
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: _stat(
              'سُلّمت اليوم',
              s.deliveredToday,
              Icons.local_shipping_outlined,
              today == 0 ? 0 : s.deliveredToday / today,
              s.courier == 0 ? 'لا شيء مع المندوب الآن' : '${money(s.courier)} مع المندوب الآن',
              'shipments:delivered',
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: _stat(
              'إجمالي الشحنات',
              s.total,
              Icons.inventory_2_outlined,
              rate / 100,
              'نسبة التسليم $rate٪',
              'shipments',
            ),
          ),
        ],
      ),
    );
  }

  Widget _stat(String label, int value, IconData icon, double fill, String note, String screen) {
    return Tap(
      radius: 24,
      shadow: cardShadow,
      onTap: () => _open(screen),
      child: Ink(
        padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color.lerp(Colors.white, brand.main, .07)!, Colors.white],
          ),
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: brand.main.withValues(alpha: .10)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(color: brand.soft, shape: BoxShape.circle),
              child: Icon(icon, size: 23, color: brand.main),
            ),
            const SizedBox(height: 8),
            Text(label, style: font(13.5, w7, Palette.ink)),
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(money(value), style: display(30, w8, Palette.ink, height: 1.15)),
                  ),
                ),
                _Bars(fill: fill, color: brand.main),
              ],
            ),
            const SizedBox(height: 6),
            Text(note, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(11.5, w6, Palette.slate)),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ للمعالجة

  /// ما لم يُسلَّم وينتظر قراره: شريطٌ أحمر واحد، وزرّه يفتح القرار
  Widget _processing(HomeData d) => Tap(
    radius: 18,
    shadow: brand.glow(.35),
    onTap: () => _open('processing'),
    child: Ink(
      padding: const EdgeInsets.fromLTRB(14, 10, 10, 10),
      decoration: BoxDecoration(gradient: brand.hero, borderRadius: BorderRadius.circular(18)),
      child: Row(
        children: [
          const Icon(Icons.support_agent_rounded, color: Colors.white, size: 24),
          const SizedBox(width: 10),
          Expanded(
            child: Text('${money(d.processing)} لم يستلمها زبونها — قرّر فيها', style: font(13.5, w8, Colors.white)),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Text('عالجها', style: font(13, w8, brand.deep)),
          ),
        ],
      ),
    ),
  );

  // ------------------------------------------------------------------ حالة الشحنات

  /// أربع حالاتٍ حول زرّ «إنشاء شحنة» الأحمر، وخطوطٌ تصلها به
  Widget _hub(HomeData d) {
    final s = d.stats;
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(26),
        border: Border.all(color: Palette.line),
        boxShadow: cardShadow,
      ),
      child: Column(
        children: [
          Tap(
            radius: 10,
            onTap: () => _open('shipments'),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                children: [
                  Text('حالة الشحنات', style: display(17, w7, Palette.ink)),
                  const Spacer(),
                  const Chev(size: 9, left: true, stroke: 1.7),
                ],
              ),
            ),
          ),
          const SizedBox(height: 10),
          SizedBox(
            height: 250,
            child: Stack(
              alignment: Alignment.center,
              children: [
                const Positioned.fill(child: CustomPaint(painter: _Links(Palette.muted))),
                Column(
                  children: [
                    Expanded(
                      child: Row(
                        children: [
                          _state(
                            'قيد التوصيل',
                            s.inDelivery,
                            Icons.local_shipping_outlined,
                            brand.main,
                            'shipments:open',
                          ),
                          const Spacer(),
                          _state(
                            'مسلّمة',
                            s.delivered,
                            Icons.check_circle_outline_rounded,
                            Palette.ok,
                            'shipments:delivered',
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    Expanded(
                      child: Row(
                        children: [
                          _state('تحتاج انتباهك', d.attention, Icons.schedule_rounded, brand.main, 'attention'),
                          const Spacer(),
                          _state('راجع مؤكّد', s.returns, Icons.history_rounded, brand.main, 'shipments:returns'),
                        ],
                      ),
                    ),
                  ],
                ),
                _createButton(),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _state(String label, int count, IconData icon, Color color, String screen) => SizedBox(
    width: 112,
    child: Tap(
      radius: 20,
      onTap: () => _open(screen),
      child: Ink(
        decoration: BoxDecoration(
          color: const Color(0xFFFBF7F6),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: Palette.line),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 26, color: color),
            const SizedBox(height: 6),
            Text(label, maxLines: 1, style: font(12.5, w7, Palette.ink, height: 1.2)),
            Text(money(count), style: display(21, w8, Palette.ink, height: 1.25)),
          ],
        ),
      ),
    ),
  );

  /// الزرّ الأحمر في الوسط بحلقةٍ ورديّة: يدويّاً، أو من صورة، أو بصوتك
  Widget _createButton() => Tap(
    radius: 60,
    onTap: _createSheet,
    child: Container(
      width: 112,
      height: 112,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: Colors.white,
        boxShadow: [BoxShadow(color: brand.main.withValues(alpha: .18), blurRadius: 26, spreadRadius: 2)],
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            width: 58,
            height: 58,
            decoration: BoxDecoration(gradient: brand.hero, shape: BoxShape.circle, boxShadow: brand.glow(.5)),
            child: const Icon(Icons.add_box_outlined, color: Colors.white, size: 28),
          ),
          const SizedBox(height: 5),
          Text('إنشاء شحنة', style: font(12.5, w8, brand.deep, height: 1.2)),
        ],
      ),
    ),
  );

  Future<void> _createSheet() async {
    final screen = await showSheet<String>(
      context,
      SheetFrame(
        title: 'إنشاء شحنة',
        text: 'اكتبها، أو صوّر الطلب، أو قله بصوتك — وتتعبّى وحدها.',
        children: [
          for (final (label, icon, screen) in [
            ('أكتبها بنفسي', Icons.edit_note_rounded, 'create'),
            ('من صورة أو رسالة', Icons.photo_camera_rounded, 'create:ai'),
            ('بصوتي', Icons.mic_rounded, 'create:voice'),
          ]) ...[
            SheetButton(
              brand: brand,
              label: label,
              icon: icon,
              outlined: screen != 'create',
              onTap: () => Navigator.of(context).pop(screen),
            ),
            const SizedBox(height: 8),
          ],
        ],
      ),
    );
    if (screen != null) _open(screen);
  }

  // ------------------------------------------------------------------ الأدوات

  /// التفاصيل المالية برصيده، وطباعة الطلبات، وطلب مندوب استلام — والدعم في «المزيد»
  Widget _actions(HomeData d) => IntrinsicHeight(
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Expanded(
          child: _action(
            'التفاصيل المالية',
            '${money(d.balance)} د.ع',
            Icons.account_balance_wallet_outlined,
            'finance',
          ),
        ),
        const SizedBox(width: 8),
        // طباعة الوصولات ظاهرةٌ في الرئيسية (docs/plan/63): ما يُدخل يُطبع ويُلصق قبل أن يأتي المندوب
        Expanded(child: _action('طباعة الطلبات', 'وصولات ما أدخلته', Icons.print_rounded, 'labels')),
        const SizedBox(width: 8),
        Expanded(child: _action('طلب مندوب استلام', 'يمرّ عليك', Icons.local_shipping_outlined, 'pickups')),
      ],
    ),
  );

  Widget _action(String title, String sub, IconData icon, String screen) => Tap(
    radius: 22,
    shadow: cardShadow,
    onTap: () => _open(screen),
    child: Ink(
      padding: const EdgeInsets.fromLTRB(6, 14, 6, 12),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color.lerp(Colors.white, brand.main, .06)!, Colors.white],
        ),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: brand.main.withValues(alpha: .08)),
      ),
      child: Column(
        children: [
          Icon(icon, size: 28, color: brand.main),
          const SizedBox(height: 6),
          Text(title, textAlign: TextAlign.center, maxLines: 2, style: font(13, w8, Palette.ink, height: 1.3)),
          Text(sub, textAlign: TextAlign.center, maxLines: 1, style: font(11, w6, Palette.muted, height: 1.4)),
        ],
      ),
    ),
  );

  // ------------------------------------------------------------------ تحتاج انتباهك

  /// «شحنات تحتاج انتباهك» مكان «آخر الشحنات»: لماذا، وكم حاولنا، وإلى متى أُجّلت
  Widget _attention(HomeData d) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Row(
        children: [
          Text('شحنات تحتاج انتباهك', style: display(18, w7, Palette.ink)),
          if (d.attentionItems.isNotEmpty) ...[
            const SizedBox(width: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
              decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(10)),
              child: Text('${d.attentionItems.length}', style: font(11.5, w8, Colors.white)),
            ),
          ],
          const Spacer(),
          Tap(
            radius: 10,
            onTap: () => _open('attention'),
            child: Padding(
              padding: const EdgeInsets.all(4),
              child: Row(
                children: [
                  Text('عرض الكل', style: font(12.5, w7, Palette.slate)),
                  const SizedBox(width: 4),
                  const Chev(size: 8, left: true, stroke: 1.6),
                ],
              ),
            ),
          ),
        ],
      ),
      const SizedBox(height: 8),
      if (d.attentionItems.isEmpty)
        WhiteCard(
          padding: const EdgeInsets.symmetric(vertical: 22, horizontal: 16),
          child: Column(
            children: [
              const Icon(Icons.verified_rounded, color: Palette.ok, size: 30),
              const SizedBox(height: 6),
              Text('لا شيء يحتاج انتباهك الآن.', style: font(13, w7, Palette.slate)),
            ],
          ),
        )
      else
        for (final item in d.attentionItems.take(6)) ...[_card(item), const SizedBox(height: 8)],
    ],
  );

  Widget _card(AttentionItem item) {
    final s = item.row;
    final why = [
      if (item.reason != null) item.reason!,
      if (item.attempts > 0) 'محاولات: ${item.attempts}',
      if (item.until != null) 'مؤجّلة إلى ${when(item.until)}',
    ].join(' · ');
    return Tap(
      radius: 20,
      shadow: cardShadow,
      onTap: s.id == 0 ? null : () => openShipment(context, brand, s.id),
      child: Ink(
        padding: const EdgeInsets.fromLTRB(14, 12, 10, 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: Palette.line),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(Icons.inventory_2_outlined, size: 18, color: Palette.slate),
                      const SizedBox(width: 6),
                      Text(s.number, textDirection: TextDirection.ltr, style: font(14, w8, Palette.ink, height: 1.2)),
                      const SizedBox(width: 4),
                      Tooltip(
                        message: 'انسخ الرقم',
                        child: InkWell(
                          borderRadius: BorderRadius.circular(8),
                          onTap: () {
                            Clipboard.setData(ClipboardData(text: s.number));
                            toast(context, 'نُسخ ${s.number}');
                          },
                          child: const Padding(
                            padding: EdgeInsets.all(4),
                            child: Icon(Icons.copy_rounded, size: 15, color: Palette.muted),
                          ),
                        ),
                      ),
                      const Spacer(),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(12)),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(s.status, style: font(11.5, w8, brand.deep, height: 1.2)),
                            const SizedBox(width: 4),
                            Icon(Icons.error_outline_rounded, size: 15, color: brand.deep),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    [s.name, if (s.place.isNotEmpty) s.place].join(' · '),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: font(13, w7, Palette.ink, height: 1.35),
                  ),
                  if (why.isNotEmpty) Text(why, style: font(12, w7, brand.main, height: 1.45)),
                  Text('${money(s.amount)} د.ع · ${when(s.at)}', style: font(11.5, w6, Palette.muted, height: 1.4)),
                ],
              ),
            ),
            const SizedBox(width: 6),
            const Chev(size: 8, left: true, stroke: 1.6),
          ],
        ),
      ),
    );
  }
}

/// أعمدةٌ متدرّجة تمتلئ بلون الشركة بنسبةٍ حقيقيّة — وما بقي باهت
class _Bars extends StatelessWidget {
  const _Bars({required this.fill, required this.color});

  final double fill;
  final Color color;

  static const _n = 7;

  @override
  Widget build(BuildContext context) {
    final on = (fill.clamp(0, 1) * _n).round();
    return Row(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.end,
      textDirection: TextDirection.ltr,
      children: [
        for (var i = 0; i < _n; i++)
          Container(
            width: 5,
            height: 10 + i * 4.0,
            margin: const EdgeInsets.only(left: 3),
            decoration: BoxDecoration(
              color: i < on ? color.withValues(alpha: .45 + .55 * (i + 1) / _n) : color.withValues(alpha: .12),
              borderRadius: BorderRadius.circular(3),
            ),
          ),
      ],
    );
  }
}

/// خطوطٌ منحنية تصل الحالات الأربع بزرّ الوسط
class _Links extends CustomPainter {
  const _Links(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color.withValues(alpha: .45)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.4
      ..strokeCap = StrokeCap.round;
    final c = Offset(size.width / 2, size.height / 2);
    const tile = 112.0;
    for (final dx in [-1.0, 1.0]) {
      for (final dy in [-1.0, 1.0]) {
        // من حافّة البطاقة الداخلية إلى حافّة الدائرة
        final from = Offset(c.dx + dx * (size.width / 2 - tile), c.dy + dy * size.height / 4);
        final to = Offset(c.dx + dx * 34, c.dy + dy * 44);
        final path = Path()
          ..moveTo(from.dx, from.dy)
          ..cubicTo(to.dx, from.dy, to.dx, from.dy, to.dx, to.dy);
        canvas.drawPath(path, paint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant _Links old) => old.color != color;
}

/// الإعلانات تنزلق بالإصبع، وتتقلّب وحدها كلّ خمس ثوانٍ، ونقاطها أسفلها. إعلانات الشركة من
/// «إعلانات التطبيق» في النظام؛ وإلى أن ترفعها: صورة التوصيل وشريحتان بلونها.
class _Ads extends StatefulWidget {
  const _Ads({required this.brand, required this.banners, required this.companyName, required this.onOpen});

  final Brand brand;
  final List<AdBanner> banners;
  final String companyName;
  final void Function(String screen) onOpen;

  @override
  State<_Ads> createState() => _AdsState();
}

class _AdsState extends State<_Ads> {
  final controller = PageController();
  Timer? timer;
  int page = 0;

  List<Widget> get slides => widget.banners.isNotEmpty
      ? [
          for (final b in widget.banners)
            Image.network(
              b.image,
              headers: Api.instance.imageHeaders,
              fit: BoxFit.cover,
              errorBuilder: (_, _, _) => Image.asset('assets/images/banner.jpg', fit: BoxFit.cover),
            ),
        ]
      : [
          Image.asset('assets/images/banner.jpg', fit: BoxFit.cover),
          _slide('اطلب مندوب استلام', 'مندوبنا يأتيك ويأخذ طرودك من باب محلّك', Icons.hail_rounded, 'pickups'),
          _slide(
            'تابع شحناتك لحظة بلحظة',
            'كلّ حالةٍ تصلك أوّلاً بأوّل، وأرسل التتبّع لزبونك',
            Icons.local_shipping_rounded,
            'shipments',
          ),
        ];

  @override
  void initState() {
    super.initState();
    // في الاختبار لا مؤقّت يبقى بعده
    if (!AppConfig.testing) {
      timer = Timer.periodic(const Duration(seconds: 5), (_) {
        if (!mounted || !controller.hasClients) return;
        final next = (page + 1) % slides.length;
        controller.animateToPage(next, duration: const Duration(milliseconds: 450), curve: Curves.easeOut);
      });
    }
  }

  @override
  void dispose() {
    timer?.cancel();
    controller.dispose();
    super.dispose();
  }

  Widget _slide(String title, String text, IconData icon, String screen) {
    final brand = widget.brand;
    return GestureDetector(
      onTap: () => widget.onOpen(screen),
      child: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: AlignmentDirectional.centerStart,
            end: AlignmentDirectional.centerEnd,
            colors: [brand.main, brand.coral],
          ),
        ),
        padding: const EdgeInsets.fromLTRB(18, 12, 18, 22),
        child: Row(
          children: [
            Expanded(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(title, style: font(19, w8, Colors.white, height: 1.3)),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    text,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: font(12, w6, Colors.white.withValues(alpha: .92), height: 1.45),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 10),
            Icon(icon, size: 54, color: Colors.white.withValues(alpha: .9)),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final all = slides;
    return Stack(
      children: [
        PageView(controller: controller, onPageChanged: (i) => setState(() => page = i), children: all),
        if (all.length > 1)
          PositionedDirectional(
            end: 16,
            bottom: 9,
            child: Row(
              children: [
                for (var i = 0; i < all.length; i++)
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 250),
                    width: i == page ? 16 : 7,
                    height: 7,
                    margin: const EdgeInsets.symmetric(horizontal: 2),
                    decoration: BoxDecoration(
                      color: i == page ? widget.brand.main : Colors.white.withValues(alpha: .9),
                      borderRadius: BorderRadius.circular(4),
                      boxShadow: const [BoxShadow(color: Color(0x33000000), blurRadius: 3)],
                    ),
                  ),
              ],
            ),
          ),
      ],
    );
  }
}

/// «نسخة عرض»: بياناتٌ تجريبية، لا رفع ولا محادثات تصل الشركة
class DemoNote extends StatelessWidget {
  const DemoNote({super.key});

  @override
  Widget build(BuildContext context) => Container(
    margin: const EdgeInsets.only(bottom: 8),
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
    decoration: BoxDecoration(color: const Color(0xFFFFF4E5), borderRadius: BorderRadius.circular(12)),
    child: Text(
      'نسخة عرض ببياناتٍ تجريبية — غير مربوطة بالنظام. لا يصل منها شيءٌ للشركة.',
      style: font(12, w7, const Color(0xFF9A5B00), height: 1.5),
    ),
  );
}
