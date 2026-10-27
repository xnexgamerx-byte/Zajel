import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/api.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

Future<void> openStaff(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => StaffScreen(brand: brand)));

const _roleIcons = {
  'entry': Icons.edit_note_rounded,
  'accountant': Icons.account_balance_wallet_outlined,
  'follow': Icons.support_agent_rounded,
  'custom': Icons.tune_rounded,
};

/// «موظّفو المتجر» (docs/plan/87): يضيف صاحب المتجر موظّفيه — اسمٌ وهاتفٌ يدخل به وكلمة مرور — ويختار لكلٍّ
/// دوراً جاهزاً (إدخال، محاسب، متابعة) أو ما يشاء من الصلاحيات. والموظّف يدخل التطبيق نفسه، ولا يرى إلّا ما فُتح له.
class StaffScreen extends StatefulWidget {
  const StaffScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<StaffScreen> createState() => _StaffScreenState();
}

class _StaffScreenState extends State<StaffScreen> {
  StaffPage? data;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.staff();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _edit([StaffMember? member]) async {
    final d = data;
    if (d == null) return;
    final saved = await showSheet<bool>(context, _StaffSheet(brand: brand, page: d, member: member));
    if (saved == true) await _load();
  }

  String _roleLabel(StaffMember m) => data?.roles.where((r) => r.key == m.role).firstOrNull?.label ?? 'صلاحيات مخصّصة';

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      backgroundColor: Palette.bg,
      appBar: AppBar(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.white,
        centerTitle: true,
        title: Text('موظّفو المتجر', style: display(17, w8, Palette.ink)),
      ),
      floatingActionButton: d == null || d.staff.length >= d.max
          ? null
          : FloatingActionButton.extended(
              onPressed: () => _edit(),
              backgroundColor: brand.main,
              icon: const Icon(Icons.person_add_alt_1_rounded, color: Colors.white),
              label: Text('أضف موظّفاً', style: font(14, w8, Colors.white)),
            ),
      body: d == null
          ? Waiting(brand: brand, error: error, onRetry: _load)
          : RefreshIndicator(
              color: brand.main,
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(14, 14, 14, 100),
                children: [
                  WhiteCard(
                    padding: const EdgeInsets.all(14),
                    child: Text(
                      'لكلّ موظّفٍ دخوله بهاتفه وكلمة مروره، ويرى في التطبيق ما تفتحه له وحده: موظّف الإدخال لا يرى '
                      'المالية، والمحاسب لا يُدخل شحنات. وتغيّر صلاحياته متى شئت، أو توقفه.',
                      style: font(12.5, w6, Palette.slate, height: 1.6),
                    ),
                  ),
                  const SizedBox(height: 12),
                  if (d.staff.isEmpty)
                    WhiteCard(
                      padding: const EdgeInsets.symmetric(vertical: 26, horizontal: 16),
                      child: Column(
                        children: [
                          Icon(Icons.groups_2_outlined, size: 34, color: brand.main),
                          const SizedBox(height: 6),
                          Text('لا موظّفين بعد.', style: font(13.5, w7, Palette.slate)),
                        ],
                      ),
                    ),
                  for (final m in d.staff) ...[_card(m), const SizedBox(height: 8)],
                ],
              ),
            ),
    );
  }

  Widget _card(StaffMember m) => Tap(
    radius: 18,
    shadow: cardShadow,
    onTap: () => _edit(m),
    child: Ink(
      padding: const EdgeInsets.fromLTRB(12, 10, 10, 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Palette.line),
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(color: m.active ? brand.soft : Palette.line, shape: BoxShape.circle),
            child: Icon(
              _roleIcons[m.role] ?? Icons.person_rounded,
              size: 20,
              color: m.active ? brand.main : Palette.muted,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        m.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: font(14, w8, Palette.ink),
                      ),
                    ),
                    if (!m.active) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
                        decoration: BoxDecoration(color: Palette.line, borderRadius: BorderRadius.circular(10)),
                        child: Text('موقوف', style: font(10.5, w8, Palette.slate)),
                      ),
                    ],
                  ],
                ),
                Text(_roleLabel(m), style: font(12, w7, brand.deep, height: 1.4)),
                Text(
                  'يدخل بـ ${m.username}${m.lastLogin == null ? ' · لم يدخل بعد' : ' · آخر دخول ${when(m.lastLogin)}'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: font(11, w6, Palette.muted, height: 1.4),
                ),
              ],
            ),
          ),
          const Chev(size: 8, left: true, stroke: 1.6),
        ],
      ),
    ),
  );
}

/// إضافة موظّفٍ أو تعديله: بياناته، ثم دوره — والصلاحيات تحته تتبدّل بالدور، أو يختارها واحدةً واحدة
class _StaffSheet extends StatefulWidget {
  const _StaffSheet({required this.brand, required this.page, this.member});

  final Brand brand;
  final StaffPage page;
  final StaffMember? member;

  @override
  State<_StaffSheet> createState() => _StaffSheetState();
}

class _StaffSheetState extends State<_StaffSheet> {
  late final name = TextEditingController(text: widget.member?.name);
  late final phone = TextEditingController(text: widget.member?.phone);
  final password = TextEditingController();
  late Set<String> perms = {...?widget.member?.permissions};
  late bool active = widget.member?.active ?? true;
  bool busy = false;
  String? error;

  Brand get brand => widget.brand;
  bool get editing => widget.member != null;

  @override
  void initState() {
    super.initState();
    // موظّفٌ جديد: يبدأ «موظّف إدخال»، الأشيع
    if (!editing && widget.page.roles.isNotEmpty) perms = {...widget.page.roles.first.permissions};
  }

  @override
  void dispose() {
    name.dispose();
    phone.dispose();
    password.dispose();
    super.dispose();
  }

  String get role {
    for (final r in widget.page.roles) {
      if (r.permissions.length == perms.length && r.permissions.every(perms.contains)) return r.key;
    }
    return 'custom';
  }

  Future<void> _save() async {
    if (name.text.trim().isEmpty) return setState(() => error = 'اكتب اسم الموظّف.');
    if (phone.text.trim().isEmpty) return setState(() => error = 'اكتب هاتف الموظّف: به يدخل.');
    if (!editing && password.text.length < 6) {
      return setState(() => error = 'كلمة المرور ستّة أحرفٍ أو أكثر.');
    }
    if (password.text.isNotEmpty && password.text.length < 6) {
      return setState(() => error = 'كلمة المرور ستّة أحرفٍ أو أكثر.');
    }
    if (perms.isEmpty) return setState(() => error = 'اختر صلاحيةً واحدةً على الأقلّ.');
    setState(() => (busy = true, error = null));
    try {
      await Api.instance.saveStaff({
        'name': name.text.trim(),
        'phone': phone.text.trim(),
        if (password.text.isNotEmpty) 'password': password.text,
        // بترتيب القائمة، لا بترتيب اللمس
        'permissions': [
          for (final p in widget.page.permissions)
            if (perms.contains(p.key)) p.key,
        ],
        if (editing) 'active': active,
      }, id: widget.member?.id);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.fields.values.firstOrNull ?? e.message, busy = false));
    }
  }

  Future<void> _delete() async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text('حذف ${widget.member!.name}؟', style: font(16, w8, Palette.ink)),
        content: Text('يخرج من التطبيق ولا يدخل ثانيةً. الشحنات التي أدخلها تبقى.', style: font(13, w6, Palette.slate)),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('تراجع')),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text('احذفه', style: font(14, w8, Palette.returnRed)),
          ),
        ],
      ),
    );
    if (sure != true) return;
    setState(() => busy = true);
    try {
      await Api.instance.deleteStaff(widget.member!.id);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final current = role;
    return SheetFrame(
      title: editing ? 'تعديل ${widget.member!.name}' : 'موظّفٌ جديد',
      text: editing
          ? 'تتغيّر صلاحياته من طلبه التالي. كلمة المرور فارغةٌ تبقى كما هي.'
          : 'يدخل تطبيق التاجر بهاتفه وكلمة المرور هذه.',
      children: [
        const FieldLabel('الاسم'),
        InputBox(controller: name, hint: 'مثلاً: سجى علي'),
        const FieldLabel('الهاتف — يدخل به'),
        InputBox(
          controller: phone,
          hint: '07xxxxxxxxx',
          ltr: true,
          keyboard: TextInputType.phone,
          formatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9٠-٩+ ]'))],
        ),
        FieldLabel(editing ? 'كلمة مرورٍ جديدة (اختيارية)' : 'كلمة المرور'),
        InputBox(controller: password, hint: 'ستّة أحرفٍ أو أكثر', ltr: true, obscure: true),
        const SizedBox(height: 10),
        Text('الدور', style: font(12, w7, Palette.ink)),
        const SizedBox(height: 6),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final r in widget.page.roles)
              _roleChip(r.key, r.label, current == r.key, () => setState(() => perms = {...r.permissions})),
            _roleChip('custom', 'مخصّص', current == 'custom', null),
          ],
        ),
        const SizedBox(height: 10),
        Text('الصلاحيات', style: font(12, w7, Palette.ink)),
        const SizedBox(height: 4),
        Material(
          type: MaterialType.transparency,
          child: Column(
            children: [
              for (final p in widget.page.permissions)
                CheckboxListTile(
                  value: perms.contains(p.key),
                  onChanged: (on) => setState(() => on == true ? perms.add(p.key) : perms.remove(p.key)),
                  activeColor: brand.main,
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  title: Text(p.label, style: font(13.5, w8, Palette.ink)),
                  subtitle: Text(p.hint, style: font(11.5, w5, Palette.slate, height: 1.4)),
                ),
              if (editing)
                SwitchListTile(
                  value: active,
                  onChanged: (v) => setState(() => active = v),
                  activeThumbColor: brand.main,
                  contentPadding: EdgeInsets.zero,
                  title: Text('يدخل التطبيق', style: font(13.5, w8, Palette.ink)),
                  subtitle: Text(
                    active ? 'شغّال' : 'موقوف: يخرج من هاتفه ولا يدخل حتى تعيده',
                    style: font(11.5, w5, Palette.slate),
                  ),
                ),
            ],
          ),
        ),
        if (error != null) ErrorNote(error!),
        const SizedBox(height: 12),
        SheetButton(
          brand: brand,
          label: editing ? 'احفظ' : 'أضفه',
          icon: Icons.check_rounded,
          busy: busy,
          onTap: _save,
        ),
        if (editing) ...[
          const SizedBox(height: 8),
          SheetButton(
            brand: brand,
            label: 'احذف الموظّف',
            icon: Icons.delete_outline_rounded,
            outlined: true,
            onTap: busy ? null : _delete,
          ),
        ],
      ],
    );
  }

  Widget _roleChip(String key, String label, bool on, VoidCallback? onTap) => ChoiceChip(
    avatar: Icon(_roleIcons[key], size: 16, color: on ? Colors.white : brand.main),
    label: Text(label, style: font(12.5, w7, on ? Colors.white : Palette.ink)),
    selected: on,
    showCheckmark: false,
    selectedColor: brand.main,
    backgroundColor: Colors.white,
    side: BorderSide(color: on ? brand.main : Palette.line),
    onSelected: onTap == null ? null : (_) => onTap(),
  );
}
