import 'package:flutter/material.dart';

import '../core/palette.dart';
import '../core/quick_replies.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

/// «الردود الجاهزة» من حسابها (docs/plan/81): جملٌ تظهر فوق حقل كلّ محادثة، تُكتب في الحقل بضغطة. تضيف
/// وتحذف وترتّب ما تريد، وتُحفظ على هاتفها.
class QuickRepliesScreen extends StatefulWidget {
  const QuickRepliesScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<QuickRepliesScreen> createState() => _QuickRepliesScreenState();
}

class _QuickRepliesScreenState extends State<QuickRepliesScreen> {
  late final List<String> replies = List.of(QuickReplies.items.value);
  final add = TextEditingController();

  Brand get brand => widget.brand;

  @override
  void dispose() {
    add.dispose();
    super.dispose();
  }

  void _add() {
    final text = add.text.trim();
    if (text.isEmpty || replies.contains(text)) return;
    setState(() => replies.add(text));
    add.clear();
  }

  Future<void> _save() async {
    await QuickReplies.save(replies);
    if (!mounted) return;
    toast(context, 'حُفظت الردود الجاهزة.');
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: SafeArea(
      child: Column(
        children: [
          const PageBar(title: 'الردود الجاهزة', subtitle: 'تظهر فوق حقل كلّ محادثة'),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
              children: [
                Row(
                  children: [
                    Expanded(
                      child: InputBox(controller: add, hint: 'ردٌّ جديد، مثلاً: تم التبليغ', maxLength: 200),
                    ),
                    const SizedBox(width: 8),
                    IconButton.filled(
                      tooltip: 'أضيفيه',
                      onPressed: _add,
                      style: IconButton.styleFrom(backgroundColor: brand.main),
                      icon: const Icon(Icons.add_rounded, color: Colors.white),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                WhiteCard(
                  padding: EdgeInsets.zero,
                  child: replies.isEmpty
                      ? Padding(
                          padding: const EdgeInsets.all(18),
                          child: Text(
                            'لا ردود: لا يظهر شيءٌ فوق الحقل.',
                            textAlign: TextAlign.center,
                            style: font(12.5, w6, Palette.muted),
                          ),
                        )
                      : ReorderableListView(
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          buildDefaultDragHandles: false,
                          onReorderItem: (from, to) => setState(() => replies.insert(to, replies.removeAt(from))),
                          children: [
                            for (final (i, r) in replies.indexed)
                              Material(
                                key: ValueKey(r),
                                type: MaterialType.transparency,
                                child: ListTile(
                                  leading: ReorderableDragStartListener(
                                    index: i,
                                    child: const Icon(Icons.drag_indicator_rounded, color: Palette.muted),
                                  ),
                                  title: Text(r, style: font(13.5, w7, Palette.ink)),
                                  trailing: IconButton(
                                    tooltip: 'احذفي',
                                    onPressed: () => setState(() => replies.removeAt(i)),
                                    icon: const Icon(Icons.delete_outline_rounded, color: Palette.returnRed),
                                  ),
                                ),
                              ),
                          ],
                        ),
                ),
                const SizedBox(height: 10),
                if (replies.join('|') != QuickReplies.suggested.join('|'))
                  TextButton.icon(
                    onPressed: () => setState(
                      () => replies
                        ..clear()
                        ..addAll(QuickReplies.suggested),
                    ),
                    icon: Icon(Icons.restart_alt_rounded, size: 18, color: brand.main),
                    label: Text('الردود المقترحة', style: font(13, w7, brand.main)),
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 14),
            child: HeroButton(brand: brand, label: 'احفظي الردود', icon: Icons.check_rounded, onTap: _save),
          ),
        ],
      ),
    ),
  );
}
