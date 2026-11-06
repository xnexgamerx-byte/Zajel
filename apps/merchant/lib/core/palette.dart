import 'package:flutter/material.dart';

/// ألوان تصميم «وصل» (docs/plan/123): أرضيّةٌ رماديّة دافئة، وحبرٌ داكن، ونصٌّ ثانويّ يُقرأ على الأبيض والفاتح.
/// الأسماء كما كانت في تصميم ز (docs/plan/70) فتتبعه الشاشات كلّها.
class Palette {
  static const bg = Color(0xFFF5F3F2);
  static const ink = Color(0xFF1C1416);

  /// النصّ الثانويّ: ٧:١ على الأبيض
  static const slate = Color(0xFF5A5054);
  static const muted = Color(0xFF6A5F63);
  static const line = Color(0xFFECE6E8);
  static const pill = Color(0xFF6A5F63);
  static const returnRed = Color(0xFFB42318);
  static const eye = Color(0xFFF0EBED);
  static const shadow = Color(0x381C1416);

  /// المسلَّم والمؤكَّد: أخضر يُقرأ على الأبيض والزجاج الفاتح
  static const ok = Color(0xFF126E47);
  static const okSoft = Color(0xFFE0F2E9);

  /// المؤجّل (docs/plan/79): ذهبيٌّ داكنٌ يُقرأ، وخلفيّته صفراء فاتحة
  static const postponed = Color(0xFF8A5A00);
  static const postponedSoft = Color(0xFFFAEFD4);
  static const postponedInk = Color(0xFF8A5A00);
}

/// لون الحالة في «وصل» (docs/plan/123): حبرٌ يُقرأ على الأبيض ٤٫٥:١ وأكثر، ولونٌ فاتحٌ لخلفيّتها.
/// وهي ألوان الحالة لا الشركة: واحدةٌ لكلّ الشركات، ولون الشركة لأزرارها.
class Tone {
  const Tone(this.ink, this.tint);

  final Color ink;
  final Color tint;

  /// جديدة وبانتظار المندوب
  static const slate = Tone(Color(0xFF4F5866), Color(0xFFECEEF2));

  /// في المخزن وبين المراكز
  static const teal = Tone(Color(0xFF0F6A80), Color(0xFFDFF1F5));

  /// مع المندوب، وإعادة التوصيل
  static const blue = Tone(Color(0xFF2457A6), Color(0xFFE4ECF8));

  /// المؤجّلة
  static const amber = Tone(Color(0xFF8A5A00), Color(0xFFFAEFD4));

  /// للمعالجة: لم تُسلَّم وتنتظر قراره
  static const orange = Tone(Color(0xFFA34005), Color(0xFFFCEBDD));

  /// المسلّمة
  static const green = Tone(Color(0xFF126E47), Color(0xFFE0F2E9));

  /// الراجعة بأماكنها
  static const red = Tone(Color(0xFFB42318), Color(0xFFFBE5E1));

  /// من لون الحالة كما يرسله النظام (green · blue · yellow · urgent · amber · red · gray · slate)، والمخزن من اسمها:
  /// النظام يرسله رماديّاً كالجديدة
  static Tone of(String tone, [String status = '']) => switch (tone) {
    'green' => green,
    'blue' => blue,
    'yellow' => amber,
    'urgent' || 'amber' => orange,
    'red' => red,
    _ => status.contains('مخزن') ? teal : slate,
  };
}

/// درجات لون الشركة: الأحمر في التصميم، وغيره لشركةٍ أخرى بالنسب نفسها.
class Brand {
  Brand(this.main);

  factory Brand.hex(String? hex, {String fallback = '#B3202A'}) {
    Color parse(String h) => Color(int.parse('FF${h.replaceAll('#', '')}', radix: 16));
    try {
      return Brand(parse(hex == null || hex.isEmpty ? fallback : hex));
    } catch (_) {
      return Brand(parse(fallback));
    }
  }

  final Color main;

  HSLColor get _hsl => HSLColor.fromColor(main);

  Color _shift(double hue, double light, [double sat = 1]) {
    final h = _hsl;
    return h
        .withHue((h.hue + hue) % 360)
        .withLightness((h.lightness + light).clamp(0, 1))
        .withSaturation((h.saturation * sat).clamp(0, 1))
        .toColor();
  }

  /// الزرّ العائم وشارات «للمعالجة»: أفتح قليلاً نحو المرجانيّ
  Color get coral => _shift(7, .10);

  /// خلفية الأيقونات الفاتحة والمختار من الخيارات (في «وصل»: ١٢٪ من لون الشركة)
  Color get soft => Color.lerp(Colors.white, main, .12)!;
  Color get softer => Color.lerp(Colors.white, main, .06)!;
  Color get track => Color.lerp(Colors.white, main, .1)!;

  /// حدّ ما هو بلون الشركة الفاتح
  Color get border => Color.lerp(Colors.white, main, .28)!;

  /// الأرضيّة الداكنة لبطاقات «وصل» الكبيرة (الإعلان، المتاح للسحب، رأس «المزيد»): لون الشركة نحو الأسود
  Color get night => Color.lerp(main, Colors.black, .45)!;

  /// توهّج الزرّ الذي بلون الشركة تحته
  Color get halo => main.withValues(alpha: .35);

  /// اللون بصيغة #RRGGBB، لرسوم «وصل» التي تُلوَّن بلون الشركة
  String get hex => '#${(main.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0')}';

  /// تدرّج مربّعات الأيقونات الحمراء
  LinearGradient get tile => LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [_shift(7, .11), _shift(3, .02, .92)],
  );

  /// أغمق منه: نصٌّ بلون الشركة يُقرأ على الفاتح
  Color get deep => _shift(0, -.05);

  /// تدرّج الأزرار والبطاقات الحمراء الكبيرة في تصميم ز: من الأفتح إلى الأغمق
  LinearGradient get hero => LinearGradient(
    begin: AlignmentDirectional.topStart,
    end: AlignmentDirectional.bottomEnd,
    colors: [_shift(0, .07), main, _shift(-2, -.08)],
  );

  /// ظلّ ما هو بلون الشركة: متوهّجٌ بلونه لا رماديّ
  List<BoxShadow> glow([double alpha = .45]) => [
    BoxShadow(
      color: main.withValues(alpha: alpha),
      blurRadius: 22,
      spreadRadius: -8,
      offset: const Offset(0, 12),
    ),
  ];
}

/// خطّ قسم التاجر (docs/plan/123): «نوتو كوفي» — رسميٌّ واضح، بدل «القاهرة».
const kufi = 'NotoKufiArabic';

/// مقاسات الشاشات المكتوبة قبل «وصل» كما هي: «كوفي» أعرض من «القاهرة» بنحو ٨٪، فيُصغَّر بقدرها، فتبقى
/// السطور بعرضها القديم وارتفاع حروفها نفسه (كان ٠٫٩ بالقاهرة، docs/plan/78) — وأصغر خطٍّ لا ينزل عن ٩٫٥
const textScale = .83;

TextStyle font(double size, FontWeight weight, Color color, {double height = 1.25}) => TextStyle(
  fontFamily: kufi,
  fontSize: size * textScale < 9.5 ? (size < 9.5 ? size : 9.5) : size * textScale,
  fontWeight: weight,
  color: color,
  height: height,
  leadingDistribution: TextLeadingDistribution.even,
);

const w5 = FontWeight.w500;
const w6 = FontWeight.w600;
const w7 = FontWeight.w700;
const w8 = FontWeight.w800;

/// العناوين والأرقام الكبيرة: «القاهرة» نفسه، خطّ النظام كلّه، بوزنٍ أثقل درجةً (docs/plan/70)
TextStyle display(double size, FontWeight weight, Color color, {double height = 1.25}) => font(
  size,
  switch (weight) {
    FontWeight.w400 => w5,
    w5 => w6,
    w6 => w7,
    _ => w8,
  },
  color,
  height: height,
);

/// نصّ «وصل» بمقاسه في التصميم (docs/plan/123): ١٢ و١٤ و١٦ و٢٤، وسطرٌ مريحٌ للعربية. للشاشات المرسومة بـ«وصل»
TextStyle kf(double size, Color color, {bool bold = false, double? height}) => TextStyle(
  fontFamily: kufi,
  fontSize: size,
  fontWeight: bold ? w7 : FontWeight.w400,
  color: color,
  height: height ?? (size >= 24 ? 1.4 : 1.5),
  leadingDistribution: TextLeadingDistribution.even,
);

/// ظلّ البطاقات البيضاء في «وصل»: خطٌّ رفيعٌ قريب وظلٌّ بعيدٌ ناعم
const cardShadow = [
  BoxShadow(color: Color(0x0A1C1416), blurRadius: 2, offset: Offset(0, 1)),
  BoxShadow(color: Palette.shadow, blurRadius: 24, spreadRadius: -14, offset: Offset(0, 10)),
];

/// «#ZA-20260124» وسط نصٍّ عربي: معزولٌ من اليسار لليمين، وإلّا قفزت «#» إلى آخر الرقم
String tag(String number) => '\u2066#$number\u2069';

/// ١٧٥٠٠٠٠ ← «1,750,000» كما في التصميم
String money(int value) {
  final digits = value.abs().toString();
  final out = StringBuffer(value < 0 ? '-' : '');
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) out.write(',');
    out.write(digits[i]);
  }
  return out.toString();
}

/// موعدٌ بساعته، لا يومٌ وحده: منتصف الليل يعني «اليوم كلّه» (docs/plan/83)
bool hasHour(DateTime at) => at.hour != 0 || at.minute != 0;

const _weekdays = ['الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'];

/// موعد التأجيل بكلام الناس (docs/plan/86): «اليوم»، «غداً الساعة 9:00 م»، «الخميس 30/10» — و«فات موعده»
/// ليومٍ مضى
String postponedText(DateTime at) {
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day);
  final days = DateTime(at.year, at.month, at.day).difference(today).inDays;
  final day = switch (days) {
    < 0 => 'فات موعده ${at.day}/${at.month}',
    0 => 'اليوم',
    1 => 'غداً',
    _ => '${_weekdays[at.weekday - 1]} ${at.day}/${at.month}',
  };
  if (!hasHour(at)) return day;
  final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
  return '$day الساعة $h:${at.minute.toString().padLeft(2, '0')} ${at.hour < 12 ? 'ص' : 'م'}';
}

/// «اليوم 10:45 ص»، «أمس 04:15 م»، أو التاريخ
String when(DateTime? at) {
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
