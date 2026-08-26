/// The download prompt has to keep its buttons reachable on a small phone.
///
/// It was a centered Column, which clips what does not fit — and what does not fit on
/// a short screen is the bottom of the column, where "Download Map" and "Skip" are.
/// The question stayed on screen with no visible way to answer it. These pump the real
/// screen at the two sizes that matter and check the buttons can actually be reached.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:aprs_map/download_screen.dart';
import 'package:aprs_map/remote_config.dart';

/// The device the prompt was reported unusable on: a W8Pro, 240x320 physical at 140
/// dpi, which is roughly 274x366 logical pixels. Not a phone-sized phone at all, and
/// that is the point — it is a quarter of the height a modern handset gives you, and
/// the layout has to survive it rather than assume it away.
const _kSmallPhone = Size(240, 320);
const _kSmallPhoneDpr = 140 / 160;

/// Room to spare, to prove the fix did not cost the centering on a normal phone.
const _kTallPhone = Size(414, 896);

Future<void> _pumpPrompt(WidgetTester tester, Size size, {double dpr = 1.0}) async {
  tester.view
    ..physicalSize = size
    ..devicePixelRatio = dpr;
  addTearDown(tester.view.reset);

  SharedPreferences.setMockInitialValues({});
  await tester.pumpWidget(MaterialApp(
    home: DownloadScreen(config: RemoteConfig.fromJson(const {})),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('small screen: both buttons can be reached', (tester) async {
    await _pumpPrompt(tester, _kSmallPhone, dpr: _kSmallPhoneDpr);

    expect(find.text('Download Offline Map?'), findsOneWidget);
    // Present in the tree is not the same as reachable — the old layout had these too,
    // laid out past the bottom edge where no finger could get at them.
    expect(find.byType(Scrollable), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Download Map'), 100);
    await tester.scrollUntilVisible(find.text('Skip — use online map only'), 100);

    final screen = tester.view.physicalSize.height / tester.view.devicePixelRatio;
    for (final label in ['Download Map', 'Skip — use online map only']) {
      final box = tester.getRect(find.text(label));
      expect(box.bottom, lessThanOrEqualTo(screen),
          reason: '"$label" is still off the bottom of the W8Pro screen');
    }
  });

  testWidgets('small screen: nothing overflows', (tester) async {
    await _pumpPrompt(tester, _kSmallPhone, dpr: _kSmallPhoneDpr);
    // A RenderFlex overflow is reported as an exception rather than a failure, so it
    // has to be asked for explicitly or the test passes over a striped yellow bar.
    expect(tester.takeException(), isNull);
  });

  testWidgets('tall screen: still centered, and no scrolling needed', (tester) async {
    await _pumpPrompt(tester, _kTallPhone);

    final screen = tester.view.physicalSize.height / tester.view.devicePixelRatio;
    final button = tester.getRect(find.text('Download Map'));
    expect(button.bottom, lessThan(screen));

    // Centered means the space above the first item and below the last are close to
    // equal. Anything that pinned the content to the top would sail past the check
    // above and fail here.
    final icon = tester.getRect(find.byIcon(Icons.download_for_offline_outlined));
    final last = tester.getRect(find.text('Skip — use online map only'));
    expect((icon.top - (screen - last.bottom)).abs(), lessThan(24),
        reason: 'content is no longer vertically centered when there is room');
  });
}
