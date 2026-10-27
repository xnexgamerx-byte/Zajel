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
import 'status_screen.dart';

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
          const SizedBox(height: 14),
          _pad(_search()),
          const SizedBox(height: 12),
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
    return top > 0 ? top + 12 : 36;
  }

  Widget _pad(Widget child) => Padding(padding: const EdgeInsets.symmetric(horizontal: 14), child: child);

  // ------------------------------------------------------------------ الترويسة

  /// التحيّة واسمه بخطٍّ واضحٍ غير ضخم، وصورته في الطرف الآخر، والجرس والدعم الفني بجانبها
  Widget _header(HomeData d) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(baghdadGreeting(), style: font(13, w6, Palette.muted, height: 1.3)),
                Text(
                  d.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: display(19, w8, Palette.ink, height: 1.3),
                ),
                Text(
                  'شحناتك مع ${widget.companyName}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(11.5, w6, Palette.slate, height: 1.4),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          // الدعم الفني مكان الإعدادات (docs/plan/79): «المزيد» في الشريط السفلي
          _round(Icons.support_agent_rounded, 'الدعم الفني', () => _open('support')),
          const SizedBox(width: 8),
          _bellButton(d.unread),
          const SizedBox(width: 8),
          _avatar(d),
        ],
      ),
    );
  }

  Widget _round(IconData icon, String tip, VoidCallback onTap) => Tooltip(
    message: tip,
    child: Tap(
      radius: 20,
      onTap: onTap,
      child: Container(
        width: 40,
        height: 40,
        decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
        child: Icon(icon, size: 20, color: Palette.ink),
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
          width: 44,
          height: 44,
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
          width: 40,
          height: 40,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle, boxShadow: cardShadow),
                child: const Icon(Icons.notifications_none_rounded, size: 20, color: Palette.ink),
              ),
              if (unread > 0)
                Positioned(
                  left: 24,
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
          radius: 14,
          onTap: () => _open('scan'),
          child: Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Palette.line),
              boxShadow: cardShadow,
            ),
            child: Icon(Icons.qr_code_scanner_rounded, size: 21, color: brand.main),
          ),
        ),
      ),
      const SizedBox(width: 8),
      Expanded(
        child: Tap(
          radius: 14,
          onTap: () => _open('shipments:search'),
          child: Container(
            height: 44,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
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
                    style: font(12, w5, Palette.muted),
                  ),
                ),
                const Icon(Icons.search_rounded, size: 21, color: Palette.slate),
              ],
            ),
          ),
        ),
      ),
    ],
  );

  // ------------------------------------------------------------------ الرقمان

  /// «سُلّمت اليوم» و«إجمالي الشحنات» في بطاقتين صغيرتين: الأيقونة والعنوان في سطر، والرقم وأعمدته في
  /// سطر — والأعمدة تمتلئ بنسبةٍ حقيقيّة: ما سُلّم من خرجة اليوم، ونسبة التسليم
  Widget _stats(Stats s) {
    final today = s.deliveredToday + s.courier;
    final rate = s.total == 0 ? 0 : (s.delivered * 100 / s.total).round();
    return Row(
      children: [
        Expanded(
          child: _stat(
            'سُلّمت اليوم',
            s.deliveredToday,
            Icons.local_shipping_outlined,
            today == 0 ? 0 : s.deliveredToday / today,
            s.courier == 0 ? 'لا شيء مع المندوب' : '${money(s.courier)} مع المندوب الآن',
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
    );
  }

  Widget _stat(String label, int value, IconData icon, double fill, String note, String screen) {
    return Tap(
      radius: 18,
      shadow: cardShadow,
      onTap: () => _open(screen),
      child: Ink(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color.lerp(Colors.white, brand.main, .06)!, Colors.white],
          ),
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: brand.main.withValues(alpha: .10)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 28,
                  height: 28,
                  decoration: BoxDecoration(color: brand.soft, shape: BoxShape.circle),
                  child: Icon(icon, size: 15, color: brand.main),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(12, w7, Palette.ink)),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(money(value), style: display(22, w8, Palette.ink, height: 1.2)),
                  ),
                ),
                _Bars(fill: fill, color: brand.main),
              ],
            ),
            Text(note, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(10.5, w6, Palette.slate)),
          ],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ للمعالجة

  /// ما لم يُسلَّم وينتظر قراره: شريطٌ أحمر واحد، وزرّه يفتح القرار
  Widget _processing(HomeData d) => Tap(
    radius: 16,
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

  /// مساحة زرّ الوسط واسمه، وقطر الزرّ نفسه: صغيران لتظهر الخطوط التي تصله بالحالات الأربع
  static const _halo = 74.0;
  static const _button = 44.0;

  /// أربع حالاتٍ حول زرّ «إنشاء شحنة» الأحمر، وخطوطٌ منحنية تصلها به — بقياس الشاشة نفسها
  Widget _hub(HomeData d) {
    final s = d.stats;
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
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
                  Text('رحلة شحناتك اليوم', style: display(14.5, w7, Palette.ink)),
                  const Spacer(),
                  const Chev(size: 8, left: true, stroke: 1.6),
                ],
              ),
            ),
          ),
          const SizedBox(height: 6),
          LayoutBuilder(
            builder: (context, box) {
              // البطاقة ربع العرض وزيادة، فيبقى بينها وبين الزرّ مكانٌ للخطوط
              final tile = (box.maxWidth * .3).clamp(84.0, 118.0);
              return SizedBox(
                height: 176,
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    Positioned.fill(
                      child: CustomPaint(
                        painter: _Links(Palette.muted, tile: tile, button: _button),
                      ),
                    ),
                    Column(
                      children: [
                        Expanded(
                          child: Row(
                            children: [
                              _state(
                                tile,
                                'قيد التوصيل',
                                s.inDelivery,
                                Icons.local_shipping_outlined,
                                StatusView.blue,
                                'status:open',
                              ),
                              const Spacer(),
                              _state(
                                tile,
                                'مسلّمة',
                                s.delivered,
                                Icons.check_circle_outline_rounded,
                                Palette.ok,
                                'status:delivered',
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 8),
                        Expanded(
                          child: Row(
                            children: [
                              _state(
                                tile,
                                'تحتاج انتباهك',
                                d.attention,
                                Icons.schedule_rounded,
                                Palette.postponed,
                                'status:attention',
                              ),
                              const Spacer(),
                              _state(
                                tile,
                                'راجع مؤكّد',
                                s.returns,
                                Icons.assignment_return_outlined,
                                Palette.returnRed,
                                'status:returns',
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    _createButton(),
                  ],
                ),
              );
            },
          ),
        ],
      ),
    );
  }

  /// حالةٌ في المحور (docs/plan/79): أيقونتها في دائرةٍ بلونها أعلاها، ثم اسمها، ثم عددها — في الوسط،
  /// وتفتح قائمتها وحدها
  Widget _state(double width, String label, int count, IconData icon, Color color, String screen) => SizedBox(
    width: width,
    child: Tap(
      radius: 16,
      onTap: () => _open(screen),
      child: Ink(
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
        decoration: BoxDecoration(
          color: Color.lerp(Colors.white, color, .05),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Color.lerp(Palette.line, color, .18)!),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 28,
              height: 28,
              decoration: BoxDecoration(color: Color.lerp(Colors.white, color, .14), shape: BoxShape.circle),
              child: Icon(icon, size: 16, color: color),
            ),
            const SizedBox(height: 3),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(label, maxLines: 1, style: font(10.5, w7, Palette.slate, height: 1.2)),
            ),
            Text(money(count), style: display(15.5, w8, Palette.ink, height: 1.2)),
          ],
        ),
      ),
    ),
  );

  /// الزرّ الأحمر في الوسط بلا حلقة، واسمه تحته: يدويّاً، أو من صورة، أو بصوتك
  Widget _createButton() => Tooltip(
    message: 'إنشاء شحنة',
    child: GestureDetector(
      onTap: _createSheet,
      behavior: HitTestBehavior.opaque,
      child: SizedBox(
        width: _halo,
        height: _halo,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: _button,
              height: _button,
              decoration: BoxDecoration(gradient: brand.hero, shape: BoxShape.circle, boxShadow: brand.glow(.45)),
              child: const Icon(Icons.add_rounded, color: Colors.white, size: 24),
            ),
            const SizedBox(height: 4),
            Text('إنشاء شحنة', maxLines: 1, style: font(10.5, w8, brand.deep, height: 1.1)),
          ],
        ),
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
  Widget _actions(HomeData d) => Row(
    children: [
      Expanded(
        child: _action('التفاصيل المالية', '${money(d.balance)} د.ع', Icons.account_balance_wallet_outlined, 'finance'),
      ),
      const SizedBox(width: 8),
      // «الطابعة» ظاهرةٌ في الرئيسية (docs/plan/63، 79): الجديدة تُطبع وتُلصق قبل أن يأتي المندوب
      Expanded(
        child: _action(
          'طباعة الطلبات',
          d.toPrint > 0
              ? '${money(d.toPrint)} للطباعة'
              : (d.printed > 0 ? '${money(d.printed)} مطبوعة' : 'وصولات ما أدخلته'),
          Icons.print_rounded,
          'labels',
          badge: d.toPrint,
        ),
      ),
      const SizedBox(width: 8),
      Expanded(child: _action('مندوب استلام', 'يمرّ عليك', Icons.hail_rounded, 'pickups')),
    ],
  );

  Widget _action(String title, String sub, IconData icon, String screen, {int badge = 0}) => Tap(
    radius: 18,
    shadow: cardShadow,
    onTap: () => _open(screen),
    child: Ink(
      height: 86,
      padding: const EdgeInsets.symmetric(horizontal: 4),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color.lerp(Colors.white, brand.main, .05)!, Colors.white],
        ),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: brand.main.withValues(alpha: .08)),
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Badge(
            isLabelVisible: badge > 0,
            backgroundColor: brand.main,
            label: Text(money(badge), style: font(9.5, w8, Colors.white, height: 1)),
            child: Container(
              width: 30,
              height: 30,
              decoration: BoxDecoration(color: brand.soft, shape: BoxShape.circle),
              child: Icon(icon, size: 16, color: brand.main),
            ),
          ),
          const SizedBox(height: 5),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(title, maxLines: 1, style: font(11.5, w8, Palette.ink, height: 1.3)),
          ),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(sub, maxLines: 1, style: font(10, w6, Palette.muted, height: 1.3)),
          ),
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
          Text('شحنات تحتاج انتباهك', style: display(14.5, w7, Palette.ink)),
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
            onTap: () => _open('status:attention'),
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
    // المؤجّلة صفراء (docs/plan/79)، والراجعة بلون الشركة
    final postponed = s.tone == 'yellow';
    final soft = postponed ? Palette.postponedSoft : brand.soft;
    final ink = postponed ? Palette.postponedInk : brand.deep;
    final why = [
      if (item.reason != null) item.reason!,
      if (item.attempts > 0) 'محاولات: ${item.attempts}',
      if (item.until != null) 'مؤجل إلى ${postponedText(item.until!)}',
    ].join(' · ');
    return Tap(
      radius: 20,
      shadow: cardShadow,
      onTap: s.id == 0 ? null : () => openShipment(context, brand, s.id),
      child: Ink(
        padding: const EdgeInsets.fromLTRB(12, 9, 8, 9),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
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
                      Icon(Icons.inventory_2_outlined, size: 15, color: Palette.slate),
                      const SizedBox(width: 6),
                      Text(s.number, textDirection: TextDirection.ltr, style: font(12.5, w8, Palette.ink, height: 1.2)),
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
                            child: Icon(Icons.copy_rounded, size: 13, color: Palette.muted),
                          ),
                        ),
                      ),
                      const Spacer(),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(color: soft, borderRadius: BorderRadius.circular(12)),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(s.status, style: font(10.5, w8, ink, height: 1.2)),
                            const SizedBox(width: 4),
                            Icon(
                              postponed ? Icons.schedule_rounded : Icons.error_outline_rounded,
                              size: 13,
                              color: ink,
                            ),
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
                    style: font(12, w7, Palette.ink, height: 1.35),
                  ),
                  if (why.isNotEmpty) Text(why, style: font(11, w7, postponed ? ink : brand.main, height: 1.45)),
                  Text('${money(s.amount)} د.ع · ${when(s.at)}', style: font(10.5, w6, Palette.muted, height: 1.4)),
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

/// أعمدةٌ متدرّجة صغيرة تمتلئ بلون الشركة بنسبةٍ حقيقيّة — وما بقي باهت
class _Bars extends StatelessWidget {
  const _Bars({required this.fill, required this.color});

  final double fill;
  final Color color;

  static const _n = 6;

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
            width: 4,
            height: 6 + i * 3.0,
            margin: const EdgeInsets.only(left: 2.5),
            decoration: BoxDecoration(
              color: i < on ? color.withValues(alpha: .45 + .55 * (i + 1) / _n) : color.withValues(alpha: .12),
              borderRadius: BorderRadius.circular(2),
            ),
          ),
      ],
    );
  }
}

/// خطوط الوصل كما في التصميم: من الحافّة الداخلية لكلّ حالةٍ خطٌّ أفقيّ، ينعطف بقوسٍ ناعم نحو الزرّ،
/// وتلتقي حالتا كلّ جانبٍ عند جنب الزرّ كقوسٍ «}» — رماديّةٌ رفيعة بلا نقاط
class _Links extends CustomPainter {
  const _Links(this.color, {required this.tile, required this.button});

  final Color color;
  final double tile;
  final double button;

  @override
  void paint(Canvas canvas, Size size) {
    final line = Paint()
      ..color = color.withValues(alpha: .45)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.4
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final c = Offset(size.width / 2, size.height / 2);
    // مركز الزرّ أعلى قليلاً من وسط البطاقة: اسمه تحته
    final buttonY = c.dy - 8;
    final r = button / 2;
    // نصف ارتفاع كلّ صفّ: صفّان بينهما 8
    final rowY = (size.height - 8) / 4 + 4;
    const bend = 12.0;

    for (final dx in [-1.0, 1.0]) {
      // حيث ينزل الخطّ عموديّاً: بعيداً عن الزرّ قليلاً، فيلتفّ إلى جنبه
      final rail = c.dx + dx * (r + 14);
      final meet = Offset(c.dx + dx * (r + 3), buttonY);
      for (final dy in [-1.0, 1.0]) {
        final from = Offset(c.dx + dx * (size.width / 2 - tile), c.dy + dy * rowY);
        final path = Path()
          ..moveTo(from.dx, from.dy)
          // أفقيّاً من البطاقة إلى قبيل المنعطف
          ..lineTo(rail + dx * bend, from.dy)
          // ينعطف نحو الوسط
          ..quadraticBezierTo(rail, from.dy, rail, from.dy - dy * bend)
          // عموديّاً إلى قبيل مستوى الزرّ
          ..lineTo(rail, buttonY + dy * bend)
          // ويلتفّ إلى جنب الزرّ
          ..quadraticBezierTo(rail, buttonY, meet.dx, meet.dy);
        canvas.drawPath(path, line);
      }
    }
  }

  @override
  bool shouldRepaint(covariant _Links old) => old.color != color || old.tile != tile || old.button != button;
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
