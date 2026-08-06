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

### 2. Build

```bash
cd ~/marsaprs/app
flutter build ios --release
```

This produces `build/ios/iphoneos/Runner.app`. The same build can be installed directly on your device for testing (see [Installing on device](#installing-on-device) below) before uploading to TestFlight.

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
flutter build ipa --release --export-options-plist=ios/ExportOptions.plist
```

The IPA lands at `build/ios/ipa/*.ipa`. Upload it via **Transporter** (Mac App Store, free):
- Drag the `.ipa` onto the Transporter window → click **Deliver**

`ios/ExportOptions.plist` is committed to the repo and configured for `app-store-connect` with team ID `KT84339238`.

---

## Troubleshooting

| Error | Cause | Fix |
|-------|-------|-----|
| `Copy failed` | Homebrew rsync installed | `brew uninstall rsync` |
| `Missing code-signing certificate` | No Distribution cert in Keychain | Check `security find-identity -v -p codesigning`; ensure Distribution cert has private key |
| `Signing requires a development team` | Team not set in Xcode | Xcode → Runner target → Signing & Capabilities → set Team |
| Build number rejected | Build number already used | Increment the number after `+` in `pubspec.yaml` |
| Organizer shows no devices | Simulator selected as target | Switch scheme to **Any iOS Device (arm64)** before archiving |
