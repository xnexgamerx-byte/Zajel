package iq.wahaj.merchant_app

import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        createNotificationChannels()
    }

    /**
     * قناتا الإشعارات (docs/plan/86): الخادم يرسل إلى إحداهما بما اختاره التاجر — «updates» بصوتٍ ويظهر أعلى
     * الشاشة، و«updates_silent» بصمتٍ في شريط الإشعارات. أسماؤهما كما تظهر في إعدادات الهاتف.
     */
    private fun createNotificationChannels() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(NotificationManager::class.java) ?: return
        manager.createNotificationChannel(
            NotificationChannel("updates", "تحديثات الشحنات", NotificationManager.IMPORTANCE_HIGH).apply {
                description = "وصلت شحنتك، وللمعالجة، وتمت معالجتها — بصوت"
            },
        )
        manager.createNotificationChannel(
            NotificationChannel("updates_silent", "تحديثات الشحنات بصمت", NotificationManager.IMPORTANCE_LOW).apply {
                description = "التحديثات نفسها بلا صوت"
                setSound(null, null)
            },
        )
    }
}
