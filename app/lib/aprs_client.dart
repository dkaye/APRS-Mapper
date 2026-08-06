/// Low-level APRS-IS TCP client used during kiosk mode.
/// Connects to noam.aprs2.net:14580, sends the login handshake, and
/// yields raw APRS packet lines to the caller via a Stream.
import 'dart:io';

class AprsClient {
  static const String _host = 'noam.aprs2.net';
  static const int _port = 14580;

  /// Connect to APRS-IS, send login + position packet, close.
  /// Login and packet arrive in the same TCP stream so the server
  /// authenticates before processing the packet — no banner ACK needed.
  static Future<void> sendPosition({
    required String callsign,
    required int passcode,
    required double lat,
    required double lon,
    String comment = 'Mobile',
  }) async {
    Socket? socket;
    try {
      socket = await Socket.connect(_host, _port,
          timeout: const Duration(seconds: 10));

      final login =
          'user $callsign pass $passcode vers AprsTopo 2.0\r\n';
      final packet =
          '$callsign>APRS,TCPIP*:!${_fmtLat(lat)}/${_fmtLon(lon)}>$comment\r\n';

      socket.write(login);
      socket.write(packet);
      await socket.flush();

      // Give the server time to receive the data before closing
      await Future<void>.delayed(const Duration(milliseconds: 800));
    } catch (_) {
      // Non-fatal — the upload timer will retry on the next interval
    } finally {
      await socket?.close();
    }
  }

  static String _fmtLat(double lat) {
    final d = lat.abs().floor();
    final m = (lat.abs() - d) * 60;
    return '${d.toString().padLeft(2, '0')}${m.toStringAsFixed(2).padLeft(5, '0')}${lat >= 0 ? 'N' : 'S'}';
  }

  static String _fmtLon(double lon) {
    final d = lon.abs().floor();
    final m = (lon.abs() - d) * 60;
    return '${d.toString().padLeft(3, '0')}${m.toStringAsFixed(2).padLeft(5, '0')}${lon >= 0 ? 'E' : 'W'}';
  }
}
