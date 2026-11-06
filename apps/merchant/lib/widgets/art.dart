import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../core/palette.dart';
import 'art_data.dart';

/// رسمٌ من رسوم «وصل» (docs/plan/123) بلون الشركة: الحالات (طردٌ ومنصّةٌ وسيارةٌ وطابعة)، والإعلانات، والرؤوس،
/// وخيارات الإدخال. مرسومةٌ هندسياً في ملف التصميم ومنقولةٌ كما هي (art_data.dart).
class Art extends StatelessWidget {
  const Art(this.name, {super.key, required this.brand, this.width, this.height});

  /// اسم الرسم: created · pickup · warehouse · courier · redelivery · postponed · processing · delivered ·
  /// return_courier · return_hub · returns · returnsAll · printer، أو ad_van · ad_stack · ad_printer ·
  /// scene_hub · cash · scan · ai · voice
  final String name;
  final Brand brand;
  final double? width;
  final double? height;

  /// الرسم بلون الشركة مرّةً واحدة لكلّ لون
  static final _painted = <String, String>{};

  static String? source(String name, String hex) {
    final raw = stateArt[name] ?? sceneArt[name];
    if (raw == null) return null;
    return _painted.putIfAbsent('$name$hex', () => polish(raw.replaceAll('{{accent}}', hex)));
  }

  /// أوجه الصناديق والسيارات والطابعة بتدرّجٍ خفيف من الضوء إلى الظلّ، بدل اللون المسطّح
  static const _faces = {
    '#F3D5A8': ('#FAE6C4', '#EDC992'), // سطح الصندوق
    '#E0B07A': ('#E9C08E', '#D6A46C'), // وجهه الأيسر
    '#C38F57': ('#CE9C64', '#B5804A'), // وجهه الأيمن
    '#E9EEF5': ('#F5F8FB', '#DEE5EE'),
    '#C6D1E0': ('#D3DCE8', '#B5C2D3'),
    '#CBC4C8': ('#D8D2D5', '#BBB2B7'),
  };

  static final _shadow = RegExp(r'<ellipse ([^>]*?)fill="#1C1416" fill-opacity="([.\d]+)"></ellipse>');

  /// تحسين الرسم (docs/plan/123): الظلّ تحت كلّ رسمٍ ناعمٌ يذوب نحو أطرافه بدل بقعةٍ رماديةٍ مسطّحة، ومركزه أغمق
  /// قليلاً حيث يلامس الجسم الأرض؛ وأوجه الأجسام بتدرّجٍ خفيف، وحافّةٌ فاتحةٌ رفيعة على سطح الصندوق
  static String polish(String svg) {
    final defs = StringBuffer();
    final shades = <String>{};
    var out = svg.replaceAllMapped(_shadow, (m) {
      final o = double.tryParse(m.group(2)!) ?? .12;
      final id = 'wsh${(o * 100).round()}';
      if (shades.add(id)) {
        defs.write(
          '<radialGradient id="$id" cx=".5" cy=".5" r=".5">'
          '<stop offset="0" stop-color="#1C1416" stop-opacity="${(o * 1.7).toStringAsFixed(2)}"/>'
          '<stop offset=".55" stop-color="#1C1416" stop-opacity="${(o * .75).toStringAsFixed(2)}"/>'
          '<stop offset="1" stop-color="#1C1416" stop-opacity="0"/></radialGradient>',
        );
      }
      return '<ellipse ${m.group(1)}fill="url(#$id)"></ellipse>';
    });
    var i = 0;
    for (final MapEntry(key: flat, value: (light, dark)) in _faces.entries) {
      if (!out.contains('fill="$flat"')) continue;
      final id = 'wf${i++}';
      defs.write(
        '<linearGradient id="$id" x1="0" y1="0" x2="0" y2="1">'
        '<stop offset="0" stop-color="$light"/><stop offset="1" stop-color="$dark"/></linearGradient>',
      );
      out = out.replaceAll('fill="$flat"', 'fill="url(#$id)"');
    }
    // حافّة الضوء على سطح الصندوق
    if (_faces.containsKey('#F3D5A8') && svg.contains('fill="#F3D5A8"')) {
      out = out.replaceAll(
        'fill="url(#wf0)"></path>',
        'fill="url(#wf0)" stroke="#FFF6E6" stroke-width=".6" stroke-linejoin="round"></path>',
      );
    }
    if (defs.isEmpty) return out;
    final open = out.indexOf('>') + 1;
    return '${out.substring(0, open)}<defs>$defs</defs>${out.substring(open)}';
  }

  @override
  Widget build(BuildContext context) {
    final svg = source(name, brand.hex);
    if (svg == null) return SizedBox(width: width, height: height);
    return SvgPicture.string(svg, width: width, height: height, fit: BoxFit.contain, excludeFromSemantics: true);
  }
}
