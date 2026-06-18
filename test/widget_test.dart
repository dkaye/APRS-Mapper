import 'package:flutter_test/flutter_test.dart';
import 'package:aprs_map/main.dart';
import 'package:aprs_map/remote_config.dart';

void main() {
  testWidgets('App smoke test', (WidgetTester tester) async {
    await tester.pumpWidget(AprsMapApp(config: RemoteConfig.defaults));
    expect(find.byType(AprsMapApp), findsOneWidget);
  });
}
