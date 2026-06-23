# Submitting to TestFlight (External Testing)

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

3. **ExportOptions.plist** — save this file permanently at `/Users/doug/aprs-map/ios/ExportOptions.plist`:
   ```xml
   <?xml version="1.0" encoding="UTF-8"?>
   <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
   <plist version="1.0">
   <dict>
       <key>method</key>
       <string>app-store-connect</string>
       <key>teamID</key>
       <string>KT84339238</string>
       <key>signingStyle</key>
       <string>automatic</string>
       <key>signingCertificate</key>
       <string>Apple Distribution</string>
       <key>stripSwiftSymbols</key>
       <true/>
       <key>uploadSymbols</key>
       <true/>
   </dict>
   </plist>
   ```

---

## Every release: step-by-step

### 1. Bump the version number

In `pubspec.yaml`, update `version`:
```
version: 1.16.0+1
```
The part before `+` is the display version; the part after is the build number.
**The build number must be higher than any previous upload** — increment it each time.

### 2. Build the IPA

```bash
cd /Users/doug/aprs-map
flutter build ipa --release --export-options-plist=ios/ExportOptions.plist
```

This archives and exports in one step. The IPA lands at `build/ios/ipa/*.ipa`.

### 3. Upload via Transporter

- Open **Transporter** (Mac App Store, free, by Apple)
- Drag `build/ios/ipa/*.ipa` onto the Transporter window
- Click **Deliver**
- Wait for "Package Delivered" confirmation

### 4. Add to TestFlight external group

- Go to [appstoreconnect.apple.com](https://appstoreconnect.apple.com) → your app → **TestFlight**
- Wait for the build status to change from "Processing" to "Ready to Submit" (5–30 min)
- Under **External Testing**, select your group → **+** → add the new build
- Apple runs a brief **Beta Review** (usually same day); testers get an email when it's approved

---

## Troubleshooting

| Error | Cause | Fix |
|-------|-------|-----|
| `Copy failed` | Homebrew rsync installed | `brew uninstall rsync` |
| `Missing code-signing certificate` | Wrong export method or no Distribution cert | Check `security find-identity -v -p codesigning`; ensure Distribution cert has private key |
| `Signing requires a development team` | Team not set in Xcode | Xcode → Runner target → Signing & Capabilities → set Team |
| Build number rejected | Build number already used | Increment the number after `+` in `pubspec.yaml` |
