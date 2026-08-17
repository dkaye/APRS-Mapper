/// The tracker token, kept encrypted on the watch.
///
/// This is a bearer token: whoever holds it can read and send this operator's net traffic.
/// Putting a copy on a second device widens the blast radius of a lost watch, so it is
/// stored more carefully than the phone stores its own copy — an AES256-GCM file with its
/// key in the platform keystore, which is the closest Android offers to the Keychain's
/// `ThisDeviceOnly`. `allowBackup="false"` in the manifest is the other half: it keeps the
/// file out of Android Auto Backup, which would otherwise copy it to Google's servers and
/// restore it onto a watch this operator may no longer own.
///
/// It is wiped the moment sharing stops or the server rejects it. Counterpart:
/// `app/ios/WatchApp/Sources/TokenStore.swift`.
package org.w6sg.aprsmap.wear

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

object TokenStore {
    private const val FILE = "aprs_wear_secret"
    private const val KEY = "tracker_token"

    private var cached: SharedPreferences? = null

    /// Null when the keystore itself is unavailable.
    ///
    /// That happens — a watch restored from a backup carries the encrypted file but not
    /// the key that opens it, and every read then throws. Falling back to plaintext would
    /// be worse than having no token: this app is fully usable relayed through the phone,
    /// and the only thing lost is the direct-poll path. So the failure is absorbed here and
    /// the corrupt file deleted, which is also what lets the next context from the phone
    /// re-establish a working store.
    private fun prefs(context: Context = WearApp.appContext): SharedPreferences? {
        cached?.let { return it }
        return try {
            val key = MasterKey.Builder(context)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()
            EncryptedSharedPreferences.create(
                context,
                FILE,
                key,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            ).also { cached = it }
        } catch (e: Exception) {
            Log.w(TAG, "encrypted store unavailable, dropping it: ${e.message}")
            context.deleteSharedPreferences(FILE)
            null
        }
    }

    fun save(token: String) {
        if (token.isEmpty()) {
            wipe()
            return
        }
        prefs()?.edit()?.putString(KEY, token)?.apply()
    }

    fun load(): String? = prefs()?.getString(KEY, null)?.ifEmpty { null }

    fun wipe() {
        prefs()?.edit()?.remove(KEY)?.apply()
    }

    private const val TAG = "AprsWear"
}
