import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:geolocator/geolocator.dart';

import 'api.dart';
import 'config.dart';

/// «أشارك موقعي ما دام التطبيق مفتوحاً» (docs/plan/66 §القرار ١): كلّ دقيقتين والتطبيق أمام
/// المندوب، ويتوقّف حين يُغلق أو يُصغَّر — لا خدمة خلفية ولا إذن «دائماً». المفتاح في «المزيد».
class LocationShare with WidgetsBindingObserver {
  LocationShare._();

  static final instance = LocationShare._();

  static const _every = Duration(minutes: 2);
  static const _storage = FlutterSecureStorage();
  static const _key = 'share_location';

  /// مفتاح «المزيد» — مفعّلٌ ما لم يطفئه المندوب
  final enabled = ValueNotifier<bool>(true);

  /// رفض الهاتف الإذن، أو الموقع مطفأ فيه: يُقال في «المزيد» لماذا لا يُرسَل
  final problem = ValueNotifier<String?>(null);

  Timer? _timer;
  bool _started = false;

  /// بلا نظامٍ أو تحت الاختبار لا يُطلب إذنٌ ولا يُرسل شيء
  bool get _live => !AppConfig.demo && !AppConfig.testing;

  Future<void> start() async {
    if (_started) return;
    _started = true;
    if (_live) {
      enabled.value = await _storage.read(key: _key) != 'off';
    }
    WidgetsBinding.instance.addObserver(this);
    _resume();
  }

  void stop() {
    _started = false;
    _timer?.cancel();
    _timer = null;
    WidgetsBinding.instance.removeObserver(this);
  }

  Future<void> setEnabled(bool on) async {
    enabled.value = on;
    if (_live) await _storage.write(key: _key, value: on ? 'on' : 'off');
    on ? _resume() : _pause();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    state == AppLifecycleState.resumed ? _resume() : _pause();
  }

  void _resume() {
    if (!_started || !enabled.value || !_live || _timer != null) return;
    _send();
    _timer = Timer.periodic(_every, (_) => _send());
  }

  void _pause() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _send() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        problem.value = 'الموقع مطفأ في الهاتف — شغّله ليُرسل.';
        return;
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        problem.value = 'لم يُسمح للتطبيق بالموقع. اسمح به من إعدادات الهاتف.';
        _pause();
        return;
      }
      problem.value = null;
      final at = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 20)),
      );
      await Api.instance.location(at.latitude, at.longitude, at.accuracy);
    } catch (_) {
      // بلا شبكةٍ أو بلا إشارة: يُحاوَل في الدورة التالية
    }
  }

  /// موقع الهاتف الآن لإجراءٍ عند الباب — أو لا شيء إن لم يكن متاحاً سريعاً
  Future<({double lat, double lng})?> here() async {
    if (!_live || !enabled.value) return null;
    try {
      final permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) return null;
      final at = await Geolocator.getLastKnownPosition();
      return at == null ? null : (lat: at.latitude, lng: at.longitude);
    } catch (_) {
      return null;
    }
  }
}
