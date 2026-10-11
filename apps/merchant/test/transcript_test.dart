import 'package:flutter_test/flutter_test.dart';
import 'package:merchant_app/core/transcript.dart';

/// السماع حتى «أوقف» (docs/plan/63): ما قيل لا يُمحى حين يقطع سامع الهاتف الكلام أو يُعاد تشغيله
void main() {
  late DateTime now;
  late Transcript t;

  void after(int ms) => now = now.add(Duration(milliseconds: ms));

  setUp(() {
    now = DateTime(2026, 10, 11, 12);
    t = Transcript(clock: () => now)..start('');
  });

  test('الجزئيات تكبر وتُصحَّح فتحلّ محلّ ما قبلها', () {
    t.hear('علي', isFinal: false);
    after(300);
    t.hear('علي من بغدات', isFinal: false);
    after(300);
    t.hear('علي من بغداد الكرادة', isFinal: false);
    expect(t.text, 'علي من بغداد الكرادة');
    after(300);
    t.hear('علي من بغداد الكرادة', isFinal: true);
    expect(t.text, 'علي من بغداد الكرادة');
    expect(t.committed, 'علي من بغداد الكرادة');
  });

  test('مقطعٌ جديد بعد سكتة لا يمحو ما قبله — هذا ما كان «يحذف بسرعة»', () {
    t.hear('علي من بغداد الكرادة', isFinal: false);
    after(2000);
    // السامع بدأ مقطعاً جديداً ويُرسل كلماته وحدها
    t.hear('رقمه', isFinal: false);
    after(300);
    t.hear('رقمه صفر سبعة سبعة', isFinal: false);
    expect(t.text, 'علي من بغداد الكرادة رقمه صفر سبعة سبعة');
  });

  test('مقطعٌ جديد أقصر بلا سكتة يُعرف أيضاً', () {
    t.hear('علي من بغداد الكرادة قرب الجسر', isFinal: false);
    after(200);
    t.hear('المبلغ', isFinal: false);
    expect(t.text, 'علي من بغداد الكرادة قرب الجسر المبلغ');
  });

  test('تصحيح الكلمة الأولى سريعاً لا يكرّرها', () {
    t.hear('على', isFinal: false);
    after(250);
    t.hear('علي من', isFinal: false);
    expect(t.text, 'علي من');
  });

  test('إعادة تشغيل السامع: يثبت ما سُمع ويُكمَل بعده، والنهائية المكرّرة لا تتكرّر', () {
    t.hear('زينب من البصرة', isFinal: false);
    t.commit(); // توقّف بعد سكتة
    after(400);
    t.hear('زينب من البصرة', isFinal: true); // بعض الهواتف تعيدها
    expect(t.text, 'زينب من البصرة');
    after(400);
    t.hear('العشار المبلغ خمسة وعشرين ألف', isFinal: false);
    expect(t.text, 'زينب من البصرة العشار المبلغ خمسة وعشرين ألف');
    t.hear('العشار المبلغ خمسة وعشرين ألف', isFinal: true);
    t.commit();
    expect(t.committed, 'زينب من البصرة العشار المبلغ خمسة وعشرين ألف');
  });

  test('نتيجةٌ فارغة لا تمحو شيئاً', () {
    t.hear('حسين كاظم', isFinal: false);
    after(300);
    t.hear('', isFinal: false);
    expect(t.text, 'حسين كاظم');
    t.hear('', isFinal: true);
    expect(t.committed, 'حسين كاظم');
  });

  test('ما كتبه التاجر بيده يبقى، والكلام يُكمَل بعده', () {
    t.start('  محمد  جاسم ');
    t.hear('الدورة', isFinal: true);
    expect(t.text, 'محمد جاسم الدورة');
    t.edited('محمد جاسم الدورة قرب المدرسة');
    t.hear('المبلغ ثلاثين', isFinal: false);
    expect(t.text, 'محمد جاسم الدورة قرب المدرسة المبلغ ثلاثين');
  });

  test('حديثٌ طويل بمقاطع كثيرة يبقى كلّه بترتيبه', () {
    const parts = ['اسمه أحمد ناصر', 'من بغداد الشعب', 'رقمه صفر سبعة سبعة صفر', 'المبلغ واحد وأربعين ألف'];
    for (final part in parts) {
      final words = part.split(' ');
      for (var i = 1; i <= words.length; i++) {
        t.hear(words.take(i).join(' '), isFinal: false);
        after(250);
      }
      t.hear(part, isFinal: true);
      after(1800);
    }
    expect(t.text, parts.join(' '));
  });
}
