import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';

import '../core/api.dart';
import '../core/config.dart';
import '../core/models.dart';
import '../core/palette.dart';
import '../widgets/bits.dart';

/// «إنشاء بالذكاء الاصطناعي» و«إنشاء بالتسجيل الصوتي» (docs/plan/55): قارئ البوابة نفسه على
/// الخادم — يعود بما يملأ «طلب جديد»، ولا يُحفظ شيءٌ حتى يراجعه التاجر ويضغط «احفظ».
Future<OrderReading?> showAiReader(BuildContext context, Brand brand) => _sheet(context, _AiSheet(brand: brand));

/// [listening]: للخادم سماعٌ يحوّل التسجيل نصّاً؛ وإلّا فمايك لوحة المفاتيح يكتب الكلام
Future<OrderReading?> showVoiceReader(BuildContext context, Brand brand, {required bool listening}) =>
    _sheet(context, listening ? _VoiceSheet(brand: brand) : _DictationSheet(brand: brand));

Future<OrderReading?> _sheet(BuildContext context, Widget child) => showModalBottomSheet<OrderReading>(
  context: context,
  isScrollControlled: true,
  backgroundColor: Colors.white,
  shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
  builder: (_) => child,
);

// ------------------------------------------------------------------ أجزاء مشتركة

class _Frame extends StatelessWidget {
  const _Frame({required this.title, required this.text, required this.children});

  final String title;
  final String text;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.fromLTRB(16, 10, 16, 16 + MediaQuery.viewInsetsOf(context).bottom),
    child: SafeArea(
      top: false,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 38,
              height: 4,
              decoration: BoxDecoration(color: Palette.line, borderRadius: BorderRadius.circular(2)),
            ),
          ),
          const SizedBox(height: 14),
          Text(title, style: font(16, w8, Palette.ink)),
          const SizedBox(height: 2),
          Text(text, style: font(12, w5, Palette.slate, height: 1.5)),
          const SizedBox(height: 14),
          ...children,
        ],
      ),
    ),
  );
}

Widget _error(String message) => Container(
  margin: const EdgeInsets.only(top: 10),
  padding: const EdgeInsets.all(10),
  decoration: BoxDecoration(color: const Color(0xFFFDECEA), borderRadius: BorderRadius.circular(12)),
  child: Text(message, style: font(12.5, w6, Palette.returnRed, height: 1.5)),
);

Widget _button(
  Brand brand,
  String label, {
  required VoidCallback? onTap,
  IconData? icon,
  bool busy = false,
  bool outlined = false,
}) => Tap(
  radius: 14,
  onTap: busy ? null : onTap,
  child: Container(
    height: 50,
    alignment: Alignment.center,
    decoration: BoxDecoration(
      color: outlined ? Colors.white : brand.main,
      borderRadius: BorderRadius.circular(14),
      border: outlined ? Border.all(color: brand.main.withValues(alpha: .45)) : null,
    ),
    child: busy
        ? SizedBox(
            width: 22,
            height: 22,
            child: CircularProgressIndicator(strokeWidth: 2.4, color: outlined ? brand.main : Colors.white),
          )
        : Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (icon != null) ...[
                Icon(icon, size: 19, color: outlined ? brand.main : Colors.white),
                const SizedBox(width: 6),
              ],
              Text(label, style: font(14.5, w8, outlined ? brand.main : Colors.white)),
            ],
          ),
  ),
);

Widget _box(TextEditingController controller, String hint, {int lines = 5, bool autofocus = false}) => Container(
  decoration: BoxDecoration(
    color: const Color(0xFFF6F8FB),
    borderRadius: BorderRadius.circular(12),
    border: Border.all(color: Palette.line),
  ),
  child: TextField(
    controller: controller,
    autofocus: autofocus,
    minLines: lines,
    maxLines: lines + 3,
    maxLength: 5000,
    keyboardType: TextInputType.multiline,
    style: font(13.5, w6, Palette.ink, height: 1.5),
    decoration: InputDecoration(
      isDense: true,
      counterText: '',
      hintText: hint,
      hintMaxLines: 3,
      hintStyle: font(12.5, w5, Palette.muted, height: 1.5),
      border: InputBorder.none,
      contentPadding: const EdgeInsets.all(12),
    ),
  ),
);

// ------------------------------------------------------------------ بالذكاء الاصطناعي

class _AiSheet extends StatefulWidget {
  const _AiSheet({required this.brand});

  final Brand brand;

  @override
  State<_AiSheet> createState() => _AiSheetState();
}

class _AiSheetState extends State<_AiSheet> {
  final text = TextEditingController();

  /// ما يُقرأ الآن: «text» أو «image» — زرّه وحده يدور
  String? busy;
  String? error;

  Brand get brand => widget.brand;

  @override
  void dispose() {
    text.dispose();
    super.dispose();
  }

  Future<void> _run(String what, Future<OrderReading> Function() read) async {
    FocusScope.of(context).unfocus();
    setState(() => (busy = what, error = null));
    try {
      final reading = await read();
      if (mounted) Navigator.of(context).pop(reading);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = null));
    }
  }

  Future<void> _paste() async {
    final clip = await Clipboard.getData(Clipboard.kTextPlain);
    if (clip?.text?.trim().isNotEmpty == true) setState(() => text.text = clip!.text!.trim());
  }

  void _readText() {
    if (text.text.trim().isEmpty) {
      setState(() => error = 'الصق رسالة الزبون، أو اختر لقطة شاشةٍ لمحادثته.');
      return;
    }
    _run('text', () => Api.instance.readText(text.text.trim()));
  }

  Future<void> _readImage() async {
    final XFile? image;
    if (AppConfig.demo) {
      image = null;
    } else {
      try {
        // تحت حدّ الخادم (٦ ميغابايت) ولقطة الشاشة تبقى مقروءة
        image = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 2200, imageQuality: 88);
      } on PlatformException {
        if (mounted) setState(() => error = 'تعذّر فتح الصور. اسمح للتطبيق بها من إعدادات الهاتف.');
        return;
      }
      if (image == null) return;
    }
    await _run('image', () async {
      if (image == null) return Api.instance.readImage(Uint8List(0), 'demo.jpg');
      final name = image.name.contains('.') ? image.name : '${image.name}.jpg';
      return Api.instance.readImage(await image.readAsBytes(), name);
    });
  }

  @override
  Widget build(BuildContext context) => _Frame(
    title: 'إنشاء بالذكاء الاصطناعي',
    text: 'الصق رسالة زبونك أو اختر لقطة شاشةٍ لمحادثته — تمتلئ الشحنة وتراجعها قبل الحفظ.',
    children: [
      Stack(
        children: [
          _box(text, 'الصق الرسالة هنا…\nمثلاً: علي حسين 07712345678 بغداد الكرادة قرب ساحة كهرمانة 25 الف'),
          PositionedDirectional(
            end: 6,
            bottom: 6,
            child: TextButton.icon(
              onPressed: busy == null ? _paste : null,
              icon: Icon(Icons.content_paste_rounded, size: 16, color: brand.main),
              label: Text('الصق', style: font(12, w8, brand.main)),
            ),
          ),
        ],
      ),
      if (error != null) _error(error!),
      if (busy != null)
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: Text(
            busy == 'image' ? 'يقرأ لقطة الشاشة… قد يأخذ نصف دقيقة.' : 'يقرأ الطلب…',
            style: font(12, w6, Palette.slate),
          ),
        ),
      const SizedBox(height: 12),
      Row(
        children: [
          Expanded(
            flex: 5,
            child: _button(
              brand,
              'لقطة شاشة',
              icon: Icons.image_outlined,
              outlined: true,
              busy: busy == 'image',
              onTap: busy == null ? _readImage : null,
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            flex: 6,
            child: _button(
              brand,
              'اقرأ الطلب',
              icon: Icons.auto_awesome_rounded,
              busy: busy == 'text',
              onTap: busy == null ? _readText : null,
            ),
          ),
        ],
      ),
    ],
  );
}

// ------------------------------------------------------------------ بالتسجيل الصوتي

const _say = 'قل اسم الزبون ورقمه، والمحافظة والمنطقة وأقرب نقطة، والسعر — ثم «أوقف».';

/// تسجيلٌ لا ينقطع حتى «أوقف» (كما في البوابة، docs/plan/40)، ثم يُسمع على الخادم
class _VoiceSheet extends StatefulWidget {
  const _VoiceSheet({required this.brand});

  final Brand brand;

  @override
  State<_VoiceSheet> createState() => _VoiceSheetState();
}

class _VoiceSheetState extends State<_VoiceSheet> {
  /// دقيقتان تكفيان أيّ طلب، وتبقيان تحت حدّ الرفع
  static const _limit = Duration(minutes: 2);

  AudioRecorder? recorder;
  bool recording = false;
  bool sending = false;
  String? error;
  Duration elapsed = Duration.zero;
  Timer? clock;

  Brand get brand => widget.brand;

  @override
  void dispose() {
    clock?.cancel();
    final r = recorder;
    if (r != null) unawaited(r.cancel().whenComplete(r.dispose));
    super.dispose();
  }

  Future<void> _start() async {
    setState(() => error = null);
    if (AppConfig.demo) {
      _tick();
      return;
    }
    final r = recorder ??= AudioRecorder();
    try {
      if (!await r.hasPermission()) {
        setState(() => error = 'اسمح للتطبيق باستعمال المايك من إعدادات الهاتف، ثم أعد.');
        return;
      }
      // m4a على الهاتف، وwebm في المتصفّح — وكلاهما يسمعه الخادم
      final web = kIsWeb && !await r.isEncoderSupported(AudioEncoder.aacLc);
      final path = kIsWeb ? '' : '${(await getTemporaryDirectory()).path}/order.m4a';
      await r.start(RecordConfig(encoder: web ? AudioEncoder.opus : AudioEncoder.aacLc, numChannels: 1), path: path);
      _tick();
    } catch (_) {
      setState(() => error = 'تعذّر بدء التسجيل. تأكّد أنّ المايك غير مشغولٍ بتطبيقٍ آخر.');
    }
  }

  void _tick() {
    const step = Duration(milliseconds: 250);
    setState(() => (recording = true, elapsed = Duration.zero));
    clock = Timer.periodic(step, (_) {
      if (!mounted) return;
      setState(() => elapsed += step);
      if (elapsed >= _limit) _stop();
    });
  }

  Future<void> _stop() async {
    clock?.cancel();
    if (!recording) return;
    setState(() => (recording = false, sending = true));
    try {
      final OrderReading reading;
      if (AppConfig.demo) {
        reading = await Api.instance.listen(Uint8List(0), 'order.m4a');
      } else {
        final path = await recorder!.stop();
        if (path == null) throw ApiError('لم يُسجَّل شيء — اضغط المايك وتكلّم.');
        final file = XFile(path);
        final bytes = await file.readAsBytes();
        reading = await Api.instance.listen(bytes, kIsWeb ? 'order.webm' : 'order.m4a');
      }
      if (mounted) Navigator.of(context).pop(reading);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, sending = false));
    }
  }

  String get _time => '${elapsed.inMinutes}:${(elapsed.inSeconds % 60).toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final color = recording ? Palette.returnRed : brand.main;
    return _Frame(
      title: 'إنشاء بالتسجيل الصوتي',
      text: _say,
      children: [
        const SizedBox(height: 6),
        Center(
          child: Tooltip(
            message: recording ? 'أوقف' : 'تكلّم',
            child: Tap(
              radius: 50,
              onTap: sending ? null : (recording ? _stop : _start),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 250),
                width: 96,
                height: 96,
                decoration: BoxDecoration(
                  color: color,
                  shape: BoxShape.circle,
                  boxShadow: [
                    BoxShadow(
                      color: color.withValues(alpha: recording ? .45 : .25),
                      blurRadius: recording ? 26 : 14,
                      spreadRadius: recording ? 4 : 0,
                    ),
                  ],
                ),
                child: sending
                    ? const Padding(
                        padding: EdgeInsets.all(34),
                        child: CircularProgressIndicator(strokeWidth: 3, color: Colors.white),
                      )
                    : Icon(recording ? Icons.stop_rounded : Icons.mic_rounded, size: 44, color: Colors.white),
              ),
            ),
          ),
        ),
        const SizedBox(height: 12),
        Text(
          sending
              ? 'يسمع الطلب ويقرؤه…'
              : recording
              ? 'يسجّل $_time — اضغط لتوقف وترسل'
              : 'اضغط المايك وتكلّم',
          textAlign: TextAlign.center,
          style: font(13.5, w8, recording ? Palette.returnRed : Palette.ink),
        ),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(color: brand.soft, borderRadius: BorderRadius.circular(12)),
          child: Text(
            'مثلاً: «علي حسين، صفر سبعة سبعة واحد…، بغداد الكرادة قرب ساحة كهرمانة، خمسة وعشرين ألف»',
            style: font(12, w6, Palette.slate, height: 1.6),
          ),
        ),
        if (error != null) _error(error!),
      ],
    );
  }
}

/// بلا سماعٍ على الخادم: مايك لوحة المفاتيح يكتب الكلام، ويُقرأ نصّه كلاماً (spoken)
class _DictationSheet extends StatefulWidget {
  const _DictationSheet({required this.brand});

  final Brand brand;

  @override
  State<_DictationSheet> createState() => _DictationSheetState();
}

class _DictationSheetState extends State<_DictationSheet> {
  final text = TextEditingController();
  bool busy = false;
  String? error;

  @override
  void dispose() {
    text.dispose();
    super.dispose();
  }

  Future<void> _read() async {
    if (text.text.trim().isEmpty) {
      setState(() => error = 'اضغط المايك في لوحة المفاتيح وقل الطلب، أو اكتبه.');
      return;
    }
    FocusScope.of(context).unfocus();
    setState(() => (busy = true, error = null));
    try {
      final reading = await Api.instance.readText(text.text.trim(), spoken: true);
      if (mounted) Navigator.of(context).pop(reading);
    } on ApiError catch (e) {
      if (mounted) setState(() => (error = e.message, busy = false));
    }
  }

  @override
  Widget build(BuildContext context) => _Frame(
    title: 'إنشاء بالتسجيل الصوتي',
    text: 'اضغط 🎤 في لوحة المفاتيح. $_say',
    children: [
      _box(text, 'ما تقوله يُكتب هنا…', lines: 4, autofocus: true),
      if (error != null) _error(error!),
      const SizedBox(height: 12),
      _button(widget.brand, 'اقرأ الطلب', icon: Icons.auto_awesome_rounded, busy: busy, onTap: _read),
    ],
  );
}
