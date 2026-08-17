/// The buzz.
///
/// watchOS ships named haptics — `.notification`, `.click`, `.success`, `.failure` — and
/// the watchOS port of this app leans on them heavily, because the haptic is the one
/// signal that survives a muted watch, a wrist turned away and no speaker at all. Wear OS
/// has no such vocabulary, only a vibrator and a duration, so the vocabulary is defined
/// here instead: the same five meanings, spelled as waveforms, in one place so that
/// "success" cannot come to mean two different things in two screens.
///
/// The distinction that carries real information is single versus double. A double buzz
/// means a net-wide call, and it is deliberately the only thing that uses one — an
/// operator can learn it without being told, and it costs no airtime.
package org.w6sg.aprsmap.wear

import android.content.Context
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

object Haptics {
    private val vibrator: Vibrator? by lazy {
        val context = WearApp.appContext
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            (context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager)?.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        }?.takeIf { it.hasVibrator() }
    }

    /// New traffic. A second buzz 300 ms later marks a broadcast, matching the gap the
    /// watchOS port uses so the two wrists feel the same.
    fun notification(double: Boolean = false) {
        if (double) waveform(longArrayOf(0, 60, 240, 60)) else oneShot(60)
    }

    /// A choice registered — picking a destination, tapping retry.
    fun click() = oneShot(20)

    /// It went out, or it was read.
    fun success() = waveform(longArrayOf(0, 30, 90, 60))

    /// It did not.
    fun failure() = waveform(longArrayOf(0, 90, 80, 90, 80, 90))

    /// The microphone opened.
    fun start() = oneShot(35)

    /// The microphone closed, or an announcement was cut off.
    fun stop() = oneShot(25)

    private fun oneShot(ms: Long) {
        val v = vibrator ?: return
        runCatching {
            v.vibrate(VibrationEffect.createOneShot(ms, VibrationEffect.DEFAULT_AMPLITUDE))
        }
    }

    private fun waveform(timings: LongArray) {
        val v = vibrator ?: return
        runCatching { v.vibrate(VibrationEffect.createWaveform(timings, -1)) }
    }
}
