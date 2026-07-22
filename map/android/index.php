<?php
/**
 * Landing page for the Android app download: https://marsaprs.org/android/
 *
 * Shows what the current build is and how to install it. Sideloaded APKs trip
 * Android's unknown-sources warning, so the install steps are on the page the
 * user is already looking at rather than somewhere they have to go find.
 */
require_once __DIR__ . '/_apk.php';

$apk = apk_latest();
$sha = null;
if ($apk) {
    // Cache the digest — hashing 60+ MB on a Pi for every page view is wasteful,
    // and the file never changes once published.
    $cache = $apk['path'] . '.sha256';
    if (is_readable($cache) && filemtime($cache) >= $apk['mtime']) {
        $sha = trim((string)file_get_contents($cache));
    } else {
        $sha = hash_file('sha256', $apk['path']);
        @file_put_contents($cache, $sha . "\n");
    }
}
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
  </div>
</div>
</body>
</html>
