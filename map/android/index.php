<?php
/**
 * Landing page for the Android app download: https://marsaprs.org/android/
 *
 * Shows what the current build is and how to install it. Sideloaded APKs trip
 * Android's unknown-sources warning, so the install steps are on the page the
 * user is already looking at rather than somewhere they have to go find.
 */
require_once __DIR__ . '/_apk.php';

// Two independent products from one directory: the phone app and the Wear OS
// companion. The watch section is hidden entirely when no watch build has been
// uploaded, rather than showing a dead button.
$apk  = apk_latest(APK_PHONE);
$wear = apk_latest(APK_WEAR);
$sha     = $apk  ? apk_sha256($apk)  : null;
$wearSha = $wear ? apk_sha256($wear) : null;
header('Cache-Control: no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MARS APRS — Android App</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
         background: #f3f4f6; color: #222; line-height: 1.55; padding: 24px 16px; }
  .box { background: #fff; max-width: 620px; margin: 0 auto; border: 1px solid #e5e7eb;
         border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,.06); overflow: hidden; }
  .hdr { background: #2c3e50; color: #fff; padding: 20px 26px; }
  .hdr h1 { font-size: 18px; font-weight: 700; }
  .hdr p  { font-size: 13px; opacity: .8; margin-top: 3px; }
  .body { padding: 22px 26px 26px; }
  .btn { display: block; text-align: center; background: #2980b9; color: #fff; text-decoration: none;
         font-size: 16px; font-weight: 700; padding: 14px; border-radius: 7px; margin: 4px 0 6px; }
  .btn:hover { background: #2471a3; }
  .meta { font-size: 12.5px; color: #666; text-align: center; margin-bottom: 20px; }
  h2 { font-size: 14px; margin: 20px 0 8px; color: #2c3e50; }
  ol { margin-left: 20px; font-size: 14px; }
  ol li { margin-bottom: 6px; }
  .note { background: #e8f4fd; border: 1px solid #90caf9; border-radius: 7px;
          padding: 11px 13px; font-size: 13px; color: #1565c0; margin-top: 18px; }
  .sha { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 10.5px;
         color: #888; word-break: break-all; margin-top: 18px; text-align: center; }
  a.plain { color: #2980b9; }
  .none { background: #fdecea; border: 1px solid #f5b7b1; color: #922b21;
          border-radius: 7px; padding: 14px; font-size: 14px; }
  .rule { border: 0; border-top: 1px solid #e5e7eb; margin: 28px 0 22px; }
  h2.section { font-size: 16px; margin: 0 0 2px; }
  .sub { font-size: 13px; color: #666; margin-bottom: 14px; }
  .btn.alt { background: #5b6b7a; }
  .btn.alt:hover { background: #4a5866; }
  .warn { background: #fff8e1; border: 1px solid #ffe082; border-radius: 7px;
          padding: 11px 13px; font-size: 13px; color: #7a5b00; margin: 0 0 16px; }
  ol ul { margin: 5px 0 0 18px; }
  ol ul li { margin-bottom: 3px; }
  code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12.5px;
         background: #f3f4f6; padding: 1px 4px; border-radius: 3px; }
</style>
</head>
<body>
<div class="box">
  <div class="hdr">
    <h1>MARS APRS Tracker — Android</h1>
    <p>Marin Amateur Radio Society</p>
  </div>
  <div class="body">
<?php if (!$apk): ?>
    <div class="none">No Android build is currently available. Please check back later.</div>
<?php else: ?>
    <a class="btn" href="download.php">Download the App</a>
    <div class="meta">
      Version <?= htmlspecialchars($apk['version']) ?> (build <?= $apk['build'] ?>) &middot;
      <?= htmlspecialchars(apk_human_size($apk['size'])) ?> &middot;
      <?= htmlspecialchars(date('F j, Y', $apk['mtime'])) ?>
    </div>

    <h2>Installing</h2>
    <ol>
      <li>Tap <strong>Download the App</strong> above on your Android phone or tablet.</li>
      <li>When the download finishes, open it. Android will warn that the app is from an
          unknown source &mdash; tap <strong>More details</strong>, then <strong>Install anyway</strong>.</li>
      <li>Tap <strong>Open</strong> when the install completes.</li>
      <li>Allow <strong>location</strong> when asked. The first time you tap Share Location it will
          also ask for <strong>notification</strong> and <strong>battery</strong> permissions &mdash; grant both,
          or background location sharing will stop when the screen locks.</li>
    </ol>
    <p style="font-size:14px;margin-top:10px">You will need the <strong>event password</strong> from the
       event coordinator the first time you open the app.</p>

    <div class="note">
      Already have the app? Just download and install over the top &mdash; your settings are kept.
      <br>iPhone or iPad instead? The app is distributed through TestFlight; see the
      <a class="plain" href="/userguide.html#getting-the-app">User Guide</a>.
    </div>

    <div class="sha">SHA-256<br><?= htmlspecialchars($sha ?: 'unavailable') ?></div>
<?php endif; ?>
<?php if ($wear): ?>

    <hr class="rule">
    <h2 class="section">Watch App &mdash; Wear OS</h2>
    <div class="sub">Read and answer net messages from your wrist. Requires Wear OS 3.0 or
       later (Pixel Watch, Galaxy Watch4 and newer) and the phone app above.</div>

    <a class="btn alt" href="watch.php">Download the Watch App</a>
    <div class="meta">
      Version <?= htmlspecialchars($wear['version']) ?> (build <?= $wear['build'] ?>) &middot;
      <?= htmlspecialchars(apk_human_size($wear['size'])) ?> &middot;
      <?= htmlspecialchars(date('F j, Y', $wear['mtime'])) ?>
    </div>

    <div class="warn">
      <strong>This one is not a tap-to-install.</strong> A Wear OS watch has no browser and no
      file manager, so the app cannot be downloaded on the watch itself &mdash; it has to be
      pushed across from your phone with the watch in developer mode. Allow about 15 minutes
      the first time. Downloading the file on the phone and tapping it will <em>not</em> work:
      the phone correctly refuses to install a watch app.
    </div>

    <h2>Installing on the watch</h2>
    <ol>
      <li>On the <strong>phone</strong>, install <strong>Wear Installer 2</strong> from the Play Store
          (free). This is the tool that does the transfer.</li>
      <li>On the <strong>watch</strong>, open <strong>Settings &rarr; System &rarr; About &rarr; Versions</strong>
          and tap <strong>Build number</strong> seven times. It will say developer mode is on.</li>
      <li>On the <strong>watch</strong>, go to <strong>Settings &rarr; Developer options</strong> and turn on
          <strong>ADB debugging</strong> and <strong>Wireless debugging</strong> (some watches call it
          <em>Debug over Wi&#8209;Fi</em>). Leave that screen up &mdash; it shows the IP address, and on
          newer watches a pairing code, that the installer will ask for.</li>
      <li>Put the watch and the phone on the <strong>same Wi&#8209;Fi network</strong>.</li>
      <li>On the phone, tap <strong>Download the Watch App</strong> above and let it save.</li>
      <li>Open Wear Installer 2, let it connect to the watch, choose the option to install a
          file you already have, and pick <strong><?= htmlspecialchars($wear['file']) ?></strong>
          from your Downloads. Follow its pairing prompts.</li>
      <li>On the watch, open <strong>APRS Map</strong> from the app list. Start the phone app too
          &mdash; the watch reaches the net through it.</li>
    </ol>

    <div class="note">
      <strong>Have a computer with <code>adb</code> installed?</strong> Steps 2&ndash;4 are the same, then
      <code>adb connect &lt;watch-ip&gt;:5555</code> and
      <code>adb install <?= htmlspecialchars($wear['file']) ?></code>.
      <br><br>The watch does <strong>not</strong> share your location &mdash; that stays the phone's job.
      It does messaging only: read, reply by voice or canned message, and hear new traffic
      announced.
    </div>

    <div class="sha">SHA-256<br><?= htmlspecialchars($wearSha ?: 'unavailable') ?></div>
<?php endif; ?>
  </div>
</div>
</body>
</html>
