import 'package:flutter/services.dart';

/// «٠٧٨٠» ← «0780»: لوحة الهاتف العربية تكتب أرقاماً لا يفهمها النظام
class LatinDigits extends TextInputFormatter {
  const LatinDigits();

  static String convert(String s) => s.replaceAllMapped(RegExp('[٠-٩۰-۹]'), (m) {
    final c = m[0]!.codeUnitAt(0);
    return String.fromCharCode(0x30 + (c >= 0x06F0 ? c - 0x06F0 : c - 0x0660));
  });

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final text = convert(newValue.text);
    return newValue.copyWith(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}

/// «25000» ← «25 000» كما في الموقع: المبلغ يُقرأ بلمحة
class Thousands extends TextInputFormatter {
  const Thousands();

  static String group(String digits) {
    final out = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) out.write(' ');
      out.write(digits[i]);
    }
    return out.toString();
  }

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final digits = newValue.text.replaceAll(RegExp(r'\D'), '');
    final capped = digits.length > 9 ? digits.substring(0, 9) : digits;
    final text = group(capped.replaceFirst(RegExp(r'^0+(?=\d)'), ''));
    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}
