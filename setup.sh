#!/usr/bin/env bash
# Run this once after: flutter create . --project-name static_map --org com.yourorg --platforms ios,android
set -e

echo "Patching iOS Info.plist with location permissions..."
PLIST="ios/Runner/Info.plist"
if [ -f "$PLIST" ]; then
  # Insert before the closing </dict> tag
  ENTRY='	<key>NSLocationWhenInUseUsageDescription<\/key>\n\t<string>Shows your location on the map.<\/string>'
  if ! grep -q "NSLocationWhenInUseUsageDescription" "$PLIST"; then
    sed -i '' "s/<\/dict>/$ENTRY\n<\/dict>/" "$PLIST"
    echo "  Added NSLocationWhenInUseUsageDescription"
  else
    echo "  Already present — skipping"
  fi
else
  echo "  $PLIST not found — run flutter create first"
fi

echo "Patching Android AndroidManifest.xml with location permissions..."
MANIFEST="android/app/src/main/AndroidManifest.xml"
if [ -f "$MANIFEST" ]; then
  FINE='    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"\/>'
  COARSE='    <uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION"\/>'
  if ! grep -q "ACCESS_FINE_LOCATION" "$MANIFEST"; then
    sed -i '' "s/<manifest/<manifest/" "$MANIFEST"   # no-op to test sed works
    # Insert after the opening <manifest ...> line
    sed -i '' "/<manifest/a\\
$FINE\\
$COARSE
" "$MANIFEST"
    echo "  Added location permissions"
  else
    echo "  Already present — skipping"
  fi
else
  echo "  $MANIFEST not found — run flutter create first"
fi

echo ""
echo "Done. Next steps:"
echo "  flutter pub get"
echo "  flutter run -d <device>"
