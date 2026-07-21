/// Full-screen password entry form shown when the event requires authentication
/// before the map is accessible.
import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'map_config.dart';
import 'map_screen.dart';
import 'mobile_session.dart';
import 'remote_config.dart';

class PasswordGateScreen extends StatefulWidget {
  final RemoteConfig config;

  const PasswordGateScreen({super.key, required this.config});

  @override
  State<PasswordGateScreen> createState() => _PasswordGateScreenState();
}

class _PasswordGateScreenState extends State<PasswordGateScreen> {
  static const _prefsPwKey   = 'event_pw';
  static const _prefsNameKey = 'event_pw_name';

  bool   _checking    = true;
  bool   _required    = false;
  String _eventName   = '';
  String _error       = '';
  bool   _submitting  = false;

  final _controller = TextEditingController();

  @override
  void initState() {
    super.initState();
    _checkPasswordStatus();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _checkPasswordStatus() async {
    String? eventName;
    bool required = false;

    try {
      final response = await http
          .get(Uri.parse('${MapConfig.serverBaseUrl}/index.php?json'))
          .timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        final json = jsonDecode(response.body) as Map<String, dynamic>;
        eventName = json['default_event'] as String? ?? '';
        required  = json['password_required'] as bool? ?? false;
      }
    } catch (_) {}

    if (!required) { _goToMap(); return; }

    // Pre-fill stored password only if it still matches what the server expects
    final prefs = await SharedPreferences.getInstance();
    final storedName = prefs.getString(_prefsNameKey);
    final storedPw   = prefs.getString(_prefsPwKey);
    if (storedName == eventName && storedPw != null && storedPw.isNotEmpty) {
      final ok = await MobileSession.authEventPassword(storedPw);
      if (ok) {
        _controller.text = storedPw;
      } else {
        await prefs.remove(_prefsPwKey);
        await prefs.remove(_prefsNameKey);
      }
    }

    if (!mounted) return;
    setState(() {
      _checking   = false;
      _required   = true;
      _eventName  = eventName ?? '';
    });
  }

  Future<void> _submit() async {
    final pw = _controller.text.trim();
    if (pw.isEmpty) return;
    setState(() { _submitting = true; _error = ''; });

    final ok = await MobileSession.authEventPassword(pw);
    if (!mounted) return;

    if (ok) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_prefsPwKey,   pw);
      await prefs.setString(_prefsNameKey, _eventName);
      _goToMap();
    } else {
      setState(() { _submitting = false; _error = 'Incorrect password — please try again.'; });
    }
  }

  void _goToMap() {
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => MapScreen(config: widget.config)),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_checking || !_required) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    return Scaffold(
      backgroundColor: const Color(0xFF1A2A3A),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Container(
              constraints: const BoxConstraints(maxWidth: 380),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                boxShadow: const [BoxShadow(color: Colors.black45, blurRadius: 32, offset: Offset(0, 8))],
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Container(
                    decoration: const BoxDecoration(
                      color: Color(0xFF2C3E50),
                      borderRadius: BorderRadius.vertical(top: Radius.circular(12)),
                    ),
                    padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('MARS APRS Tracker',
                          style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
                        if (_eventName.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(_eventName,
                            style: const TextStyle(color: Colors.white70, fontSize: 13)),
                        ],
                      ],
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(24, 24, 24, 28),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const Text('Event Password',
                          style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600,
                              color: Color(0xFF555555), letterSpacing: 0.8)),
                        const SizedBox(height: 8),
                        Theme(
                          data: Theme.of(context).copyWith(
                            textSelectionTheme: const TextSelectionThemeData(
                              selectionHandleColor: Colors.transparent,
                            ),
                          ),
                          child: TextField(
                            controller: _controller,
                            autofocus: true,
                            textInputAction: TextInputAction.done,
                            autocorrect: false,
                            enableSuggestions: false,
                            autofillHints: const [],
                            onSubmitted: (_) => _submitting ? null : _submit(),
                            decoration: InputDecoration(
                              border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
                              contentPadding: const EdgeInsets.symmetric(horizontal: 13, vertical: 11),
                            ),
                          ),
                        ),
                        if (_error.isNotEmpty) ...[
                          const SizedBox(height: 8),
                          Text(_error, style: const TextStyle(color: Color(0xFFC0392B), fontSize: 13)),
                        ],
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: _submitting ? null : _submit,
                          style: FilledButton.styleFrom(
                            backgroundColor: const Color(0xFF2980B9),
                            padding: const EdgeInsets.symmetric(vertical: 13),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
                          ),
                          child: _submitting
                              ? const SizedBox(width: 20, height: 20,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                              : const Text('Enter', style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
                        ),
                        // Android only: iOS ignores SystemNavigator.pop() (Apple
                        // disallows programmatic exit), so a Cancel link there would
                        // be a no-op.
                        if (Platform.isAndroid) ...[
                          const SizedBox(height: 4),
                          Center(
                            child: TextButton(
                              onPressed: _submitting ? null : () => SystemNavigator.pop(),
                              child: const Text('Cancel',
                                  style: TextStyle(fontSize: 14, color: Color(0xFF888888))),
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
