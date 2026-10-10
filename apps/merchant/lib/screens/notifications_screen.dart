import 'package:flutter/material.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';
import 'support_screen.dart' show ThreadScreen;

Future<void> openNotifications(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => NotificationsScreen(brand: brand)));

/// الجرس (docs/plan/59): إعلانات الشركة للتاجر — يُفتح فيُقرأ ما فيه — وردود الشركة على محادثاته،
/// يفتح الردّ محادثته. والجديد بلون الشركة.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<AppNotice>? items;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.notifications();
      if (mounted) setState(() => (items = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _open(AppNotice n) async {
    if (n.kind != 'support') return;
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ThreadScreen(brand: brand, id: n.id),
      ),
    );
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final list = items;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'الإشعارات', subtitle: 'إعلانات شركتك، وردودها على رسائلك'),
            Expanded(
              child: list == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: [
                          if (list.isEmpty)
                            const Empty(
                              icon: Icons.notifications_none_rounded,
                              title: 'لا إشعارات',
                              text: 'ما تعلنه شركتك وما تردّ به على رسائلك يصلك هنا.',
                            ),
                          for (final n in list) ...[_card(n), const SizedBox(height: 10)],
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _card(AppNotice n) => Tap(
    radius: 16,
    onTap: n.kind == 'support' ? () => _open(n) : null,
    child: Container(
      padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: cardShadow,
        border: n.fresh ? Border.all(color: brand.main.withValues(alpha: .6), width: 1.4) : null,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SoftIcon(
            brand: brand,
            size: 38,
            radius: 11,
            child: Icon(
              n.kind == 'support' ? Icons.forum_rounded : Icons.campaign_rounded,
              size: 20,
              color: brand.main,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(child: Text(n.title, style: font(14, w8, Palette.ink, height: 1.4))),
                    if (n.fresh) Chip2('جديد', color: brand.main),
                  ],
                ),
                const SizedBox(height: 3),
                Text(n.body, style: font(13, w5, Palette.slate, height: 1.6)),
                const SizedBox(height: 4),
                Text(when(n.at), style: font(11, w5, Palette.muted)),
              ],
            ),
          ),
          if (n.kind == 'support') ...[const SizedBox(width: 6), const Chev(size: 9, stroke: 1.6)],
        ],
      ),
    ),
  );
}
