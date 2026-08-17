/// The watch talking to marsaprs.org on its own.
///
/// Used only when the phone is unreachable. A Kotlin port of the slice of
/// `app/lib/messaging_client.dart` the watch needs — the same endpoints, the same JSON, and
/// the same contract that a 403 means the token is dead rather than that the request should
/// be retried.
///
/// Ordering is by `id` and never by `ts`, matching the server's `ORDER BY m.id` and the
/// Dart client. A device with a skewed clock must not be able to reorder a net's traffic.
///
/// Counterpart: `app/ios/WatchApp/Sources/WatchMessagingClient.swift`.
package org.w6sg.aprsmap.wear

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

sealed class MessagingError : Exception() {
    /// 403: the token is no longer valid, and never will be again.
    object AuthExpired : MessagingError()

    /// Could not reach the server at all.
    object Network : MessagingError()

    class Server(override val message: String) : MessagingError()
}

class MessagingClient(private val base: String, private val token: String) {

    /// Ten seconds, and no waiting for connectivity. The caller wants to fall back, and a
    /// request that parks itself until the radio comes up looks identical to one that hung.
    private val timeoutMs = 10_000

    private suspend fun post(action: String, body: JSONObject): JSONObject =
        withContext(Dispatchers.IO) {
            val url = runCatching { URL("$base/index.php?messaging=$action") }.getOrNull()
                ?: throw MessagingError.Network

            val payload = body.put("token", token).toString().toByteArray(Charsets.UTF_8)
            var connection: HttpURLConnection? = null
            val (status, text) = try {
                connection = (url.openConnection() as HttpURLConnection).apply {
                    requestMethod = "POST"
                    connectTimeout = timeoutMs
                    readTimeout = timeoutMs
                    doOutput = true
                    setRequestProperty("Content-Type", "application/json")
                }
                connection.outputStream.use { it.write(payload) }
                val code = connection.responseCode
                val stream = if (code in 200..299) connection.inputStream else connection.errorStream
                code to (stream?.bufferedReader()?.use { it.readText() } ?: "")
            } catch (e: IOException) {
                throw MessagingError.Network
            } finally {
                connection?.disconnect()
            }

            if (status == 403) throw MessagingError.AuthExpired
            val obj = runCatching { JSONObject(text) }.getOrNull()
                ?: throw MessagingError.Server("Bad response")
            obj.optString("error").takeIf { it.isNotEmpty() }?.let { throw MessagingError.Server(it) }
            obj
        }

    data class PollResult(val messages: List<WatchMessage>, val lastId: Int)

    suspend fun poll(sinceId: Int): PollResult {
        val obj = post("poll", JSONObject().put("since_id", sinceId))
        val raw = obj.optJSONArray("messages") ?: JSONArray()
        val messages = raw.objects().mapNotNull(WatchMessage::fromServer).sortedBy { it.id }
        return PollResult(messages, obj.optInt("last_id", sinceId))
    }

    /// Returns the new message id. Recipients create a thread, a conversation id continues
    /// one; the caller supplies exactly one of them.
    suspend fun send(text: String, conversationId: Int?, recipients: List<String>?): Int? {
        val body = JSONObject().put("text", text)
        conversationId?.let { body.put("conversation_id", it) }
        recipients?.let { body.put("recipients", JSONArray(it)) }
        val obj = post("send", body)
        return if (obj.has("id")) obj.optInt("id") else null
    }
}
