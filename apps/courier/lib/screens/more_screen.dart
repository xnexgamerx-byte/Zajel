import 'package:flutter/material.dart';

import '../core/config.dart';
import '../core/location.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

/// «المزيد» (docs/plan/66): المندوب وشركته، والراجع بيده، ومشاركة الموقع، والخروج.
/// الإشعارات والمحادثة مع المكتب في مرحلةٍ لاحقة — لا يُعرض هنا ما لا يعمل بعد.
class MoreScreen extends StatelessWidget {
  const MoreScreen({
    super.key,
    required this.brand,
    required this.session,
    required this.onLogout,
    required this.onOpen,
  });

  final Brand brand;
  final Session? session;
  final Future<void> Function() onLogout;
  final void Function(String screen) onOpen;

  @override
  Widget build(BuildContext context) {
    final name = session?.name ?? '';
    return SafeArea(
      bottom: false,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 130),
        children: [
          Row(
            children: [
              const TabBack(),
              Text('المزيد', style: font(22, w8, Palette.ink)),
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
                  decoration: BoxDecoration(gradient: brand.tile, shape: BoxShape.circle),
                  child: Text(name.isEmpty ? 'م' : name.characters.first, style: font(20, w8, Colors.white, height: 1)),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(name, style: font(17, w8, Palette.ink)),
                      Text(
                        '${session?.companyName ?? AppConfig.companyName} · مندوب توصيل',
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
          WhiteCard(
            child: Material(
              type: MaterialType.transparency,
              child: Column(
                children: [
                  _item(Icons.format_list_bulleted_rounded, 'شحنات التوصيل', () => onOpen('tasks')),
                  const Divider(height: 1, indent: 56, color: Palette.line),
                  _item(Icons.assignment_return_outlined, 'الراجع بيدي', () => onOpen('returns')),
                  const Divider(height: 1, indent: 56, color: Palette.line),
                  _item(Icons.account_balance_wallet_outlined, 'حسابي', () => onOpen('cash')),
                ],
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
                  Switch(
                    value: on,
                    activeTrackColor: brand.main,
                    onChanged: (v) => LocationShare.instance.setEnabled(v),
                  ),
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
          Text(
            '${AppConfig.appName} · الإصدار 0.1.0',
            textAlign: TextAlign.center,
            style: font(11.5, w6, Palette.muted),
          ),
        ],
      ),
    );
  }

  Widget _item(IconData icon, String label, VoidCallback onTap) => ListTile(
    onTap: onTap,
    leading: SoftIcon(
      brand: brand,
      size: 34,
      radius: 10,
      child: Icon(icon, size: 19, color: brand.main),
    ),
    title: Text(label, style: font(14, w7, Palette.ink)),
    trailing: const Chev(size: 9, stroke: 1.6),
  );
}
