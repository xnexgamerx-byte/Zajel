/// ما يُسمع متتابعاً حتى «أوقف» (docs/plan/63): ما ثبت من الكلام، وما يُسمع الآن ولم يثبت.
///
/// سامع الهاتف يقطع الكلام: بعد سكتةٍ يبدأ مقطعاً جديداً ويُرسل كلماته وحدها، وقد يُعاد تشغيله
/// كلّه. فإن أُخذ ما يصل على أنه «كلّ ما قيل» مُحي ما قبل السكتة — وهذا ما كان يُرى «يحذف بسرعة».
/// فما يصل يُقارن بما قبله:
///  - يكمّله أو يصحّح آخره ⇒ يحلّ محلّه (السامع يراجع كلماته وهو يسمع)؛
///  - لا يشبهه ⇒ مقطعٌ جديد: يثبت القديم أوّلاً ثم يُكتب الجديد بعده.
/// وما ثبت لا يُمحى أبداً إلّا بيد التاجر في المربّع.
class Transcript {
  Transcript({DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final DateTime Function() _clock;

  String _committed = '';
  String _partial = '';
  DateTime? _heardAt;

  /// سكتةٌ أطول من هذا ثم كلامٌ لا يشبه ما قبله: مقطعٌ جديد ولو طال
  static const pause = Duration(milliseconds: 1500);

  String get text => _join(_committed, _partial);

  String get committed => _committed;

  bool get isEmpty => text.isEmpty;

  /// يبدأ السماع بعد ما في المربّع (ما كتبه التاجر بيده يبقى، والكلام يُكمَل بعده)
  void start(String typed) {
    _committed = _clean(typed);
    _partial = '';
    _heardAt = null;
  }

  /// نتيجةٌ من السامع: جزئيةٌ تتبدّل، أو نهائيةٌ تثبت
  void hear(String words, {required bool isFinal}) {
    final now = _clock();
    final heard = _clean(words);
    final gap = _heardAt == null ? Duration.zero : now.difference(_heardAt!);
    _heardAt = now;

    if (heard.isEmpty) {
      if (isFinal) commit();
      return;
    }
    // نهائيةٌ تُعاد بعد أن ثبتت (بعض الهواتف ترسلها مرّتين، أو أوّل السماع التالي)
    if (_partial.isEmpty && _endsWith(_committed, heard)) return;

    if (_partial.isNotEmpty && _isNewSegment(_partial, heard, gap)) {
      _committed = _join(_committed, _partial);
    }
    _partial = heard;
    if (isFinal) commit();
  }

  /// توقّف السامع (سكتة، «أوقف»، إعادة تشغيل): ما يُسمع الآن يثبت
  void commit() {
    if (_partial.isEmpty) return;
    _committed = _join(_committed, _partial);
    _partial = '';
  }

  /// التاجر عدّل المربّع بيده والسامع واقف: ما فيه هو الثابت
  void edited(String value) {
    _committed = _clean(value);
    _partial = '';
  }

  static bool _isNewSegment(String before, String now, Duration gap) {
    final a = before.split(' ');
    final b = now.split(' ');
    // كم من كلمات القديم في الجديد: المراجعة تُبقي أكثرها، والمقطع الجديد لا يُبقي منها شيئاً يُذكر
    final kept = a.where(b.contains).length;
    if (kept * 2 >= a.length) return false;
    // لا يشبهه: جديدٌ إن قصر عنه، أو جاء بعد سكتة، أو كان القديم جملةً لا كلمةً أولى تُصحَّح
    return b.length < a.length || gap >= pause || a.length >= 3;
  }

  static bool _endsWith(String text, String tail) => tail.isNotEmpty && (text == tail || text.endsWith(' $tail'));

  static String _clean(String s) => s.replaceAll(RegExp(r'\s+'), ' ').trim();

  static String _join(String a, String b) => a.isEmpty ? b : (b.isEmpty ? a : '$a $b');
}
