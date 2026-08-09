# Submitting to TestFlight (External Testing)

| Goal | Best approach |
|------|--------------|
| Install on your own device for testing | `flutter build ios --release` + `xcrun devicectl` |
| Submit to TestFlight for beta testers | `flutter build ios --release` → Xcode Archive → Organizer Distribute |
| Automated/CI builds | `flutter build ipa` + Transporter (or `xcrun altool`) |

## Prerequisites (one-time setup)

1. **Apple Distribution certificate with private key**
   - Open Keychain Access → menu bar → Keychain Access → Certificate Assistant → Request a Certificate from a Certificate Authority
   - Enter your Apple ID email, leave CA email blank, select **Saved to disk** → save the `.certSigningRequest` file
   - Go to [developer.apple.com](https://developer.apple.com) → Certificates → **+** → choose **Apple Distribution** → upload the `.certSigningRequest` → Download the `.cer`
   - Double-click the `.cer` to install it in Keychain
   - Verify: `security find-identity -v -p codesigning` — you should see "Apple Distribution: ..." listed as valid

2. **Homebrew rsync must NOT be installed**
   - Run: `brew uninstall rsync`
   - Xcode uses Apple's `/usr/bin/rsync` which supports `--extended-attributes`; Homebrew's rsync does not, causing "Copy failed"

---

## Every release: step-by-step

### 1. Bump the version number

In `pubspec.yaml`, update `version`:
```
version: 1.16.1+2
```
The part before `+` is the display version; the part after is the build number.
**The build number must be higher than any previous upload** — increment it each time.

**Bump the build number on every build, not just every release.** It costs nothing,
App Store Connect only requires it to increase, and it is the only thing that makes
one development build distinguishable from another — the Watch and the phone both
report the marketing version, so without it you cannot tell which build is running.

**It does not, on its own, get a new watch app onto the Watch during development.**
That was assumed here and does not hold: bumping from 15 to 18 and installing the
iPhone app with `devicectl` left the Watch on 15. Automatic propagation of the
embedded watch app appears to be part of the App Store / TestFlight install path, not
the development one. For a dev build, use one of:

- **iPhone → Watch app → My Watch → APRS Map → toggle *Show App on Apple Watch* off,
  then on.** Reliable; ignores version entirely.
- **Push straight to the Watch** (fast when it connects; see the watch-target build
  command in `README.md`). Needs the Watch awake, unlocked, and on the same WiFi as
  the Mac — otherwise the tunnel times out even though `devicectl list devices` still
  reports it as `available`.

The Watch's Settings page shows the build **time** next to the version, which is the
reliable way to confirm a push landed. (The marketing version — the part before `+` —
only moves at release, as does `WEB_VERSION` in `map/index.php` and the build in
`map/app_version.php`.)

This one line is the source of truth for **both** the iPhone app and the embedded
Apple Watch app. `ios/WatchApp/WatchApp.xcconfig` includes `Flutter/Generated.xcconfig`,
so the watch app's `CFBundleShortVersionString` / `CFBundleVersion` come from the same
place. There is nothing to bump separately — but step 2a verifies it, because an
embedded watch app whose version does not match the host app is rejected at upload.

### 2. Build

```bash
cd ~/marsaprs/app
flutter build ios --release
```

This produces `build/ios/iphoneos/Runner.app`. The same build can be installed directly on your device for testing (see [Installing on device](#installing-on-device) below) before uploading to TestFlight.

The build log must contain **`Watch companion app found.`** If it does not, the watch
app is not being detected and the build will fail later with WatchKit-vs-iOS-SDK errors
— see the troubleshooting table.

### 2a. Verify the embedded Watch app

Run this after any `pod install`, `flutter clean`, or plugin add/remove — those are the
operations that can disturb the hand-maintained watch target in `project.pbxproj`.

```bash
xcodebuild -list -project ios/Runner.xcodeproj | grep -q WatchApp && echo "target OK"

W=build/ios/iphoneos/Runner.app/Watch/WatchApp.app/Info.plist
/usr/libexec/PlistBuddy -c "Print :CFBundleShortVersionString" "$W"   # must match pubspec
/usr/libexec/PlistBuddy -c "Print :CFBundleVersion" "$W"              # must match pubspec
```

### 3. Archive in Xcode

- Open Xcode: `open ios/Runner.xcworkspace`
- Make sure the scheme target is **Any iOS Device (arm64)**, not a simulator
- Menu: **Product → Archive**
- The Organizer window opens automatically when archiving completes

### 4. Distribute to TestFlight

In the Organizer:
- Select the new archive → click **Distribute App**
- Choose **TestFlight & App Store** → **Next**
- Leave defaults (automatic signing) → **Next** → **Upload**
- Xcode uploads and confirms delivery — no separate Transporter step needed

### 5. Add to TestFlight external group

- Go to [appstoreconnect.apple.com](https://appstoreconnect.apple.com) → your app → **TestFlight**
- Wait for the build status to change from "Processing" to "Ready to Submit" (5–30 min)
- Under **External Testing**, select your group → **+** → add the new build
- Apple runs a brief **Beta Review** (usually same day); testers get an email when it's approved

---

## Installing on device (without TestFlight)

To install directly on your own device during development:

```bash
flutter build ios --release
xcrun devicectl device install app --device 00008130-000639093883401C build/ios/iphoneos/Runner.app
```

This is the same build from step 2 — no separate build needed.

---

## Alternative: command-line IPA build

If you prefer a fully command-line workflow (e.g. for CI), use `flutter build ipa` instead of steps 3–4 above:

```bash
flutter build ipa --release --export-options-plist=ExportOptions.plist
```

The IPA lands at `build/ios/ipa/*.ipa`. Upload it via **Transporter** (Mac App Store, free):
- Drag the `.ipa` onto the Transporter window → click **Deliver**

`app/ExportOptions.plist` (not `app/ios/`) is committed to the repo and configured for `app-store-connect` with team ID `KT84339238`.

---

## Troubleshooting

| Error | Cause | Fix |
|-------|-------|-----|
| `Copy failed` | Homebrew rsync installed | `brew uninstall rsync` |
| `Missing code-signing certificate` | No Distribution cert in Keychain | Check `security find-identity -v -p codesigning`; ensure Distribution cert has private key |
| `Signing requires a development team` | Team not set in Xcode | Xcode → Runner target → Signing & Capabilities → set Team |
| Build number rejected | Build number already used | Increment the number after `+` in `pubspec.yaml` |
| Organizer shows no devices | Simulator selected as target | Switch scheme to **Any iOS Device (arm64)** before archiving |
| `No simulator device ID has been set` | A watch companion exists, so simulator builds need an explicit device | `flutter devices`, then `flutter build ios --simulator -d <udid>` (device builds are unaffected) |
| WatchKit errors during an iOS build | `Watch companion app found.` missing from the log | `ios/WatchApp/Info.plist` must exist and contain `WKCompanionAppBundleIdentifier` = `org.marsaprs.aprsMap`; the target must be named `WatchApp` to match the directory |
| Watch app version differs from the iPhone app | `WatchApp.xcconfig` lost its `#include` of `Flutter/Generated.xcconfig` | Restore the include; re-run step 2a |
