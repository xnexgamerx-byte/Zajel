import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/location.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../core/whatsapp.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import '../widgets/whatsapp.dart';
import 'whatsapp_template_screen.dart';

/// «المزيد» (docs/plan/66، 71): المندوب وشركته، وشاشاته كلّها بحسب ما يعمله (توصيل، استلام، أو كلاهما)،
/// والإشعارات والمحادثة مع المكتب، ومشاركة الموقع، والخروج.
class MoreScreen extends StatefulWidget {
  const MoreScreen({
    super.key,
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
  final ValueNotifier<int> refresh;

  @override
  State<MoreScreen> createState() => _MoreScreenState();
}

class _MoreScreenState extends State<MoreScreen> {
  /// ما يعمله وما فيه جديد — من الرئيسية نفسها
  HomeData? home;

  Brand get brand => widget.brand;
  Session? get session => widget.session;
  Future<void> Function() get onLogout => widget.onLogout;
  void Function(String screen) get onOpen => widget.onOpen;

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
      final d = await Api.instance.home();
      if (mounted) setState(() => home = d);
    } on ApiError {
      // تبقى القائمة بشاشات التوصيل
    }
  }

  @override
  Widget build(BuildContext context) {
    final name = session?.name ?? '';
    final h = home;
    final delivers = h?.delivers ?? true;
    final picks = h?.picks ?? false;
    final kind = delivers && picks ? 'مندوب توصيل واستلام' : (picks ? 'مندوب استلام' : 'مندوب توصيل');
    final work = [
      if (delivers) ...[
        (Icons.format_list_bulleted_rounded, 'شحنات التوصيل', 'tasks', null),
        (Icons.assignment_return_outlined, 'الراجع بيدي', 'returns', null),
        (Icons.today_rounded, 'اليوم', 'today', null),
        (Icons.account_balance_wallet_outlined, 'حسابي', 'cash', null),
      ],
      if (picks) ...[
        (Icons.storefront_rounded, 'الاستلام', 'pickups', (h?.pickups ?? 0) > 0 ? '${h!.pickups}' : null),
        (Icons.savings_outlined, 'حصصي', 'shares', null),
      ],
    ];
    final talk = [
      (Icons.notifications_none_rounded, 'الإشعارات', 'notifications', (h?.unread ?? 0) > 0 ? '${h!.unread}' : null),
      if (h?.chat ?? false)
        (Icons.forum_outlined, 'المحادثة مع المكتب', 'chat', (h?.chatUnread ?? false) ? 'جديد' : null),
    ];
    final top = MediaQuery.paddingOf(context).top;
    return ListView(
      padding: EdgeInsets.fromLTRB(16, top > 0 ? top + 8 : 36, 16, 130),
      children: [
        Row(
          children: [
            const TabBack(),
            Text('المزيد', style: display(24, w7, Palette.ink)),
          ],
        ),
        const SizedBox(height: 12),
        WhiteCard(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 52,
                height: 52,
                alignment: Alignment.center,
                decoration: BoxDecoration(gradient: brand.hero, shape: BoxShape.circle, boxShadow: brand.glow(.35)),
                child: Text(
                  name.isEmpty ? 'م' : name.characters.first,
                  style: display(20, w7, Colors.white, height: 1),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(name, style: display(17, w7, Palette.ink)),
                    Text(
                      '${session?.companyName ?? AppConfig.companyName} · $kind',
                      style: font(12.5, w6, Palette.slate),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 8),
        // بأيّ نظامٍ هذه النسخة مربوطة: أوّل ما يُسأل حين لا يصل شيء
        if (AppConfig.demo)
          Text('نسخة عرضٍ ببياناتٍ تجريبية — لا تُرسل شيئاً للشركة.', style: font(12, w7, Palette.warn))
        else
          Row(
            children: [
              const Icon(Icons.link_rounded, size: 16, color: Palette.ok),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  'مربوط بنظام الشركة: ${Uri.parse(AppConfig.apiUrl).host}',
                  style: font(12, w6, Palette.slate),
                ),
              ),
            ],
          ),
        const SectionTitle('عملي'),
        _list(work),
        const SectionTitle('التواصل'),
        _list(talk),
        const SizedBox(height: 10),
        // رسالة واتساب للزبون: تُكتب مرّة وتُرسل بضغطة (docs/plan/74)
        WhiteCard(
          child: Material(
            type: MaterialType.transparency,
            child: ValueListenableBuilder<String>(
              valueListenable: WhatsAppTemplate.text,
              builder: (context, template, _) => ListTile(
                onTap: () =>
                    Navigator.of(context).push(MaterialPageRoute(builder: (_) => WhatsAppTemplateScreen(brand: brand))),
                leading: Container(
                  width: 34,
                  height: 34,
                  decoration: BoxDecoration(
                    color: whatsappGreen.withValues(alpha: .14),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Center(child: FaIcon(FontAwesomeIcons.whatsapp, size: 19, color: Color(0xFF128C4A))),
                ),
                title: Text('رسالة واتساب للزبون', style: font(14, w7, Palette.ink)),
                subtitle: Text(
                  template.isEmpty ? 'بلا رسالة — يُفتح واتساب فارغاً' : template,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(11.5, w6, Palette.muted),
                ),
                trailing: const Chev(size: 9, stroke: 1.6, color: Palette.muted),
              ),
            ),
          ),
        ),
        const SectionTitle('الموقع'),
        WhiteCard(
          padding: const EdgeInsets.fromLTRB(14, 12, 8, 12),
          child: ValueListenableBuilder<bool>(
            valueListenable: LocationShare.instance.enabled,
            builder: (context, on, _) => Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Padding(
                  padding: EdgeInsets.only(top: 2),
                  child: Icon(Icons.location_on_outlined, color: Palette.slate, size: 21),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('أشارك موقعي ما دام التطبيق مفتوحاً', style: font(14, w7, Palette.ink)),
                      const SizedBox(height: 3),
                      Text(
                        'يساعد التاجر والمكتب على معرفة وقت وصولك. لا يُرسل شيء وأنت خارج التطبيق.',
                        style: font(12, w5, Palette.slate, height: 1.5),
                      ),
                      ValueListenableBuilder<String?>(
                        valueListenable: LocationShare.instance.problem,
                        builder: (context, problem, _) => problem == null || !on
                            ? const SizedBox.shrink()
                            : Text(problem, style: font(12, w7, Palette.warn, height: 1.6)),
                      ),
                    ],
                  ),
                ),
                Switch(value: on, activeTrackColor: brand.main, onChanged: (v) => LocationShare.instance.setEnabled(v)),
              ],
            ),
          ),
        ),
        const SectionTitle('الحساب'),
        Text(
          'كلمة المرور واسم المستخدم يغيّرهما المكتب، وهما نفسهما في موقع الشركة.',
          style: font(12, w6, Palette.slate, height: 1.6),
        ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: () async {
            if (await confirm(context, 'تسجيل الخروج', 'تخرج من هذا الجهاز وحده.', 'خروج')) await onLogout();
          },
          icon: const Icon(Icons.logout_rounded, color: Palette.bad),
          label: Text('تسجيل الخروج من هذا الجهاز', style: font(14, w7, Palette.bad)),
          style: OutlinedButton.styleFrom(
            minimumSize: const Size.fromHeight(48),
            side: BorderSide(color: Palette.bad.withValues(alpha: .35)),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
        ),
        const SizedBox(height: 18),
        Text('${AppConfig.appName} · الإصدار 0.1.0', textAlign: TextAlign.center, style: font(11.5, w6, Palette.muted)),
      ],
    );
  }

  Widget _list(List<(IconData, String, String, String?)> items) => WhiteCard(
    child: Material(
      type: MaterialType.transparency,
      child: Column(
        children: [
          for (final (i, (icon, label, screen, badge)) in items.indexed) ...[
            if (i > 0) const Divider(height: 1, indent: 56, color: Palette.line),
            _item(icon, label, () => onOpen(screen), badge: badge),
          ],
        ],
      ),
    ),
  );

  Widget _item(IconData icon, String label, VoidCallback onTap, {String? badge}) => ListTile(
    onTap: onTap,
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
        if (badge != null) ...[
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
            decoration: BoxDecoration(color: brand.main, borderRadius: BorderRadius.circular(20)),
            child: Text(badge, style: font(11, w8, Colors.white)),
          ),
          const SizedBox(width: 8),
        ],
        const Chev(size: 9, stroke: 1.6, color: Palette.muted),
      ],
    ),
  );
}
