import 'package:flutter/material.dart';

/// ألوان تصميم ز (docs/plan/70): خلفيةٌ دافئة، وحبرٌ نبيذيّ داكن، والأحمر الداكن من لون الشركة.
class Palette {
  static const bg = Color(0xFFFBF6F5);
  static const ink = Color(0xFF26181A);
  static const slate = Color(0xFF5C4644);
  static const muted = Color(0xFF7A6260);
  static const line = Color(0xFFF0E3E1);
  static const pill = Color(0xFF7A6260);
  static const returnRed = Color(0xFFB42318);
  static const eye = Color(0xFFF5ECEA);
  static const shadow = Color(0x24781E24);

  /// المسلَّم والمؤكَّد: أخضر هادئ
  static const ok = Color(0xFF1F6B35);
  static const okSoft = Color(0xFFE6F4EA);

  /// المؤجّل أصفر (docs/plan/79): ذهبيٌّ داكنٌ يُقرأ على الأبيض، وخلفيّته صفراء فاتحة
  static const postponed = Color(0xFFC99A06);
  static const postponedSoft = Color(0xFFFFF4CC);
  static const postponedInk = Color(0xFF7A5A00);
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

  /// خلفية الأيقونات الفاتحة وبطاقة «إنشاء شحنة» المختارة
  Color get soft => Color.lerp(Colors.white, main, .085)!;
  Color get softer => Color.lerp(Colors.white, main, .045)!;
  Color get track => Color.lerp(Colors.white, main, .075)!;
  Color get border => Color.lerp(Colors.white, main, .24)!;

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

/// خطّ «القاهرة» بمقاسات التصميم.
/// النصّ كلّه أصغر قليلاً من قياسه المكتوب (docs/plan/78): كان كبيراً على الهاتف في كلّ مكان — وأصغر
/// خطٍّ لا ينزل عن ٩٫٥ فيبقى يُقرأ
const textScale = .9;

TextStyle font(double size, FontWeight weight, Color color, {double height = 1.25}) => TextStyle(
  fontFamily: 'Cairo',
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

/// ظلّ البطاقات البيضاء: خطٌّ رفيعٌ قريب وظلٌّ بعيدٌ ناعمٌ بلونٍ دافئ
const cardShadow = [
  BoxShadow(color: Color(0x0A26181A), blurRadius: 2, offset: Offset(0, 1)),
  BoxShadow(color: Palette.shadow, blurRadius: 24, spreadRadius: -10, offset: Offset(0, 12)),
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
