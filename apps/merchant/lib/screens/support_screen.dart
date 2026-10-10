import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';
import '../widgets/page.dart';

Future<void> openSupport(BuildContext context, Brand brand) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => SupportScreen(brand: brand)));

/// صورةٌ تُرفق برسالة — من معرض الهاتف، تحت حدّ الخادم (٥ ميغابايت)
Future<(Uint8List, String)?> _pickImage(BuildContext context) async {
  if (AppConfig.demo) return (Uint8List(0), 'demo.jpg');
  try {
    final image = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1800, imageQuality: 85);
    if (image == null) return null;
    return (await image.readAsBytes(), image.name.contains('.') ? image.name : '${image.name}.jpg');
  } on PlatformException {
    if (context.mounted) toast(context, 'تعذّر فتح الصور. اسمح للتطبيق بها من إعدادات الهاتف.', bad: true);
    return null;
  }
}

/// «الدعم» (docs/plan/56): محادثات التاجر مع الشركة كما في بوابته، وواتساب الدعم وهاتف الشكاوى.
class SupportScreen extends StatefulWidget {
  const SupportScreen({super.key, required this.brand});

  final Brand brand;

  @override
  State<SupportScreen> createState() => _SupportScreenState();
}

class _SupportScreenState extends State<SupportScreen> {
  SupportPage? data;
  String? error;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.support();
      if (mounted) setState(() => (data = d, error = null));
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _open(int id) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ThreadScreen(brand: brand, id: id),
      ),
    );
    _load();
  }

  Future<void> _new() async {
    final id = await showSheet<int>(context, _NewSheet(brand: brand));
    if (id == null || !mounted) return;
    toast(context, 'وصلت رسالتك، وستجد الردّ هنا.');
    await _open(id);
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            const PageBar(title: 'الدعم', subtitle: 'اسأل شركتك عن شحنةٍ أو حساب، والردّ يصلك هنا'),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : RefreshIndicator(
                      color: brand.main,
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(14, 4, 14, 32),
                        children: [
                          SheetButton(brand: brand, label: 'رسالة جديدة', icon: Icons.edit_rounded, onTap: _new),
                          if (d.whatsapp != null || d.complaints != null) ...[
                            const SizedBox(height: 8),
                            Row(
                              children: [
                                if (d.whatsapp != null)
                                  Expanded(
                                    child: SheetButton(
                                      brand: brand,
                                      label: 'واتساب الدعم',
                                      icon: Icons.chat_rounded,
                                      outlined: true,
                                      onTap: () =>
                                          launchUrl(Uri.parse(d.whatsapp!), mode: LaunchMode.externalApplication),
                                    ),
                                  ),
                                if (d.whatsapp != null && d.complaints != null) const SizedBox(width: 8),
                                if (d.complaints != null)
                                  Expanded(
                                    child: SheetButton(
                                      brand: brand,
                                      label: 'الشكاوى',
                                      icon: Icons.call_rounded,
                                      outlined: true,
                                      onTap: () => launchUrl(Uri.parse('tel:${d.complaints}')),
                                    ),
                                  ),
                              ],
                            ),
                          ],
                          const SectionTitle('محادثاتك'),
                          if (d.items.isEmpty)
                            const Empty(
                              icon: Icons.forum_outlined,
                              title: 'لا محادثات بعد',
                              text: 'اكتب لشركتك عن أيّ شحنةٍ أو حساب — والردّ يصلك هنا.',
                            ),
                          for (final c in d.items) ...[_row(c), const SizedBox(height: 8)],
                        ],
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(Conversation c) => Tap(
    radius: 16,
    onTap: () => _open(c.id),
    child: WhiteCard(
      padding: const EdgeInsets.fromLTRB(12, 11, 12, 11),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(c.subject, maxLines: 1, overflow: TextOverflow.ellipsis, style: font(13.5, w8, Palette.ink)),
                Text(
                  [
                    if (c.shipment != null) tag(c.shipment!),
                    c.closed ? 'مغلقة' : (c.staff ? 'ردّت الشركة' : 'بانتظار الردّ'),
                    when(c.at),
                  ].join(' · '),
                  style: font(11.5, w5, c.unread ? brand.main : Palette.slate),
                ),
              ],
            ),
          ),
          if (c.unread)
            Container(
              width: 10,
              height: 10,
              margin: const EdgeInsetsDirectional.only(start: 8),
              decoration: BoxDecoration(color: brand.main, shape: BoxShape.circle),
            ),
          const SizedBox(width: 8),
          const Chev(size: 9, stroke: 1.6),
        ],
      ),
    ),
  );
}

class _NewSheet extends StatefulWidget {
  const _NewSheet({required this.brand});

  final Brand brand;

  @override
  State<_NewSheet> createState() => _NewSheetState();
}

class _NewSheetState extends State<_NewSheet> {
  final subject = TextEditingController();
  final shipment = TextEditingController();
  final body = TextEditingController();
  (Uint8List, String)? file;
  bool busy = false;
  String? error;

  @override
  void dispose() {
    for (final c in [subject, shipment, body]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _send() async {
    if (subject.text.trim().length < 3) {
      setState(() => error = 'اكتب موضوع رسالتك.');
      return;
    }
    if (body.text.trim().isEmpty && file == null) {
      setState(() => error = 'اكتب رسالتك أو أرفق صورة.');
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() => (busy = true, error = null));
    try {
      final id = await Api.instance.startConversation(
        subject: subject.text.trim(),
        body: body.text.trim(),
        shipment: shipment.text.trim(),
        file: file?.$1,
        filename: file?.$2,
      );
      if (mounted) Navigator.of(context).pop(id);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) {
    final brand = widget.brand;
    return SheetFrame(
      title: 'رسالة جديدة',
      text: 'عن شحنةٍ بعينها اكتب رقم وصلها، فيراها الموظّف معها.',
      children: [
        const FieldLabel('الموضوع'),
        InputBox(controller: subject, hint: 'مثلاً: تأخّر طرد الكرادة', maxLength: 160, autofocus: true),
        const FieldLabel('رقم الوصل (اختياري)'),
        InputBox(controller: shipment, hint: 'ZA-20260122', ltr: true, maxLength: 40),
        const FieldLabel('رسالتك'),
        InputBox(controller: body, hint: 'اكتب ما تريد السؤال عنه…', lines: 3, maxLength: 2000),
        const SizedBox(height: 8),
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: TextButton.icon(
            onPressed: () async {
              final picked = await _pickImage(context);
              if (picked != null) setState(() => file = picked);
            },
            icon: Icon(
              file == null ? Icons.add_photo_alternate_outlined : Icons.check_circle_rounded,
              color: brand.main,
            ),
            label: Text(file == null ? 'أرفق صورة' : 'أُرفقت صورة — غيّرها', style: font(12.5, w8, brand.main)),
          ),
        ),
        if (error != null) ErrorNote(error!),
        const SizedBox(height: 10),
        SheetButton(brand: brand, label: 'أرسل', icon: Icons.send_rounded, busy: busy, onTap: _send),
      ],
    );
  }
}

/// المحادثة: رسائله في جهة، وردود الشركة باسم الموظّف في الأخرى، والصور فيها — ثم خانة الردّ
class ThreadScreen extends StatefulWidget {
  const ThreadScreen({super.key, required this.brand, required this.id});

  final Brand brand;
  final int id;

  @override
  State<ThreadScreen> createState() => _ThreadScreenState();
}

class _ThreadScreenState extends State<ThreadScreen> {
  final text = TextEditingController();
  final scroll = ScrollController();
  Thread? data;
  String? error;
  bool sending = false;

  Brand get brand => widget.brand;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    text.dispose();
    scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.instance.thread(widget.id);
      if (!mounted) return;
      setState(() => (data = d, error = null));
      // الأحدث أسفل، وتُفتح المحادثة على آخرها
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (scroll.hasClients) scroll.jumpTo(scroll.position.maxScrollExtent);
      });
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> _send({(Uint8List, String)? file}) async {
    final body = text.text.trim();
    if (body.isEmpty && file == null) return;
    setState(() => sending = true);
    try {
      await Api.instance.reply(widget.id, body, file: file?.$1, filename: file?.$2);
      text.clear();
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = data;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            PageBar(
              title: d?.subject ?? 'المحادثة',
              subtitle: d == null ? null : (d.shipment != null ? 'عن الشحنة ${tag(d.shipment!)}' : 'مع شركتك'),
            ),
            Expanded(
              child: d == null
                  ? Waiting(brand: brand, error: error, onRetry: _load)
                  : ListView(
                      controller: scroll,
                      padding: const EdgeInsets.fromLTRB(14, 4, 14, 12),
                      children: [for (final m in d.messages) _bubble(m)],
                    ),
            ),
            _composer(),
          ],
        ),
      ),
    );
  }

  Widget _bubble(Message m) {
    final mine = m.mine;
    return Align(
      // كالبوابة: رسائله إلى جهة النهاية، وردود الشركة إلى البداية
      alignment: mine ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * .78),
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.fromLTRB(12, 9, 12, 8),
        decoration: BoxDecoration(
          color: mine ? brand.main : Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: mine ? null : cardShadow,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (!mine && m.author != null) Text(m.author!, style: font(11, w8, brand.main)),
            if (m.file != null) ...[_file(m.file!, mine), const SizedBox(height: 4)],
            if (m.body.isNotEmpty) Text(m.body, style: font(13.5, w6, mine ? Colors.white : Palette.ink, height: 1.5)),
            Text(when(m.at), style: font(10, w5, mine ? Colors.white.withValues(alpha: .8) : Palette.muted)),
          ],
        ),
      ),
    );
  }

  Widget _file(Attachment f, bool mine) {
    if (f.image && f.url.isNotEmpty) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(10),
        child: Image.network(
          f.url,
          headers: Api.instance.fileHeaders,
          width: 220,
          fit: BoxFit.cover,
          errorBuilder: (_, _, _) => _fileName(f, mine),
        ),
      );
    }
    return _fileName(f, mine);
  }

  Widget _fileName(Attachment f, bool mine) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(Icons.attach_file_rounded, size: 16, color: mine ? Colors.white : brand.main),
      const SizedBox(width: 4),
      Flexible(child: Text('${f.name} · ${f.size}', style: font(12, w7, mine ? Colors.white : Palette.ink))),
    ],
  );

  Widget _composer() => Container(
    padding: const EdgeInsets.fromLTRB(8, 8, 8, 8),
    decoration: const BoxDecoration(color: Colors.white, boxShadow: cardShadow),
    child: Row(
      children: [
        IconButton(
          tooltip: 'أرفق صورة',
          onPressed: sending
              ? null
              : () async {
                  final picked = await _pickImage(context);
                  if (picked != null) await _send(file: picked);
                },
          icon: Icon(Icons.add_photo_alternate_outlined, color: brand.main),
        ),
        Expanded(
          child: TextField(
            controller: text,
            minLines: 1,
            maxLines: 4,
            maxLength: 2000,
            style: font(13.5, w6, Palette.ink, height: 1.4),
            decoration: InputDecoration(
              isDense: true,
              counterText: '',
              hintText: 'اكتب ردّك…',
              hintStyle: font(13, w5, Palette.muted),
              filled: true,
              fillColor: const Color(0xFFF6F8FB),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(20), borderSide: BorderSide.none),
              contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            ),
          ),
        ),
        const SizedBox(width: 6),
        Tooltip(
          message: 'أرسل',
          child: Tap(
            radius: 22,
            onTap: sending ? null : _send,
            child: Container(
              width: 44,
              height: 44,
              alignment: Alignment.center,
              decoration: BoxDecoration(color: brand.main, shape: BoxShape.circle),
              child: sending
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.send_rounded, size: 20, color: Colors.white),
            ),
          ),
        ),
      ],
    ),
  );
}
