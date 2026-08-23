<?php
/**
 * SDR self-noise dashboard — iGates and Transcribers.
 *
 * The URL stays under /igate/ because every deployed gate posts there and the link is in
 * people's bookmarks; the page is no longer iGate-only.
 *
 * Reads the per-host reports uploaded by sdr-selftest.sh and ranks the fleet
 * by the one number that predicts a deaf gate: the worst internal spur in the
 * APRS guard band (144.37-144.42 MHz), in dB over the noise floor. Low is good.
 * Calibration from a Pi Zero 2 W: ~+18 dB with the dongle in the case (deaf),
 * ~0-3 dB with it moved out of the case (healthy).
 */
$dir = __DIR__ . '/data';

// Per-row Delete: remove a gate's stored report. POST + redirect (PRG) so a page
// refresh doesn't repeat the delete. basename() confines the target to $dir, so a
// crafted host value can't escape the data directory. The row reappears on the
// gate's next self-test upload — this just clears stale/renamed entries.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $host   = basename((string) $_POST['delete']);
    $target = "$dir/$host.json";
    if ($host !== '' && is_file($target)) @unlink($target);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$rows = [];
foreach (glob("$dir/*.json") ?: [] as $f) {
    $d = json_decode(@file_get_contents($f), true);
    if (is_array($d) && !empty($d['host'])) $rows[] = normalise($d);
}

/** Accept both spellings of the guard-band keys.
 *
 *  The analyzer was shared with the Transcribers and its keys lost the "aprs_" prefix,
 *  since a Transcriber's guard band is around whatever voice channel it is on. Reports
 *  arrive in both spellings for as long as the fleet takes to update — and since that is
 *  a nightly cycle, for a while it is all of them. Reading either costs four lines;
 *  getting it wrong blanks the dashboard for a day and looks like the gates went down. */
function normalise(array $d): array
{
    foreach (['spur_db', 'spur_mhz', 'offset_khz', 'duty'] as $k) {
        if (!isset($d["aprs_guard_$k"]) && isset($d["guard_$k"])) $d["aprs_guard_$k"] = $d["guard_$k"];
        if (!isset($d["guard_$k"]) && isset($d["aprs_guard_$k"])) $d["guard_$k"] = $d["aprs_guard_$k"];
    }
    return $d;
}

function g($d, $k, $def = null) { return array_key_exists($k, $d) ? $d[$k] : $def; }
function grade_rank($grade) { return ['BAD' => 0, 'MARGINAL' => 1, 'GOOD' => 2][$grade] ?? -1; }

// Sort worst-first so problems surface at the top.
usort($rows, function ($a, $b) {
    $ga = grade_rank(g($a, 'grade')); $gb = grade_rank(g($b, 'grade'));
    if ($ga !== $gb) return $ga <=> $gb;
    return (float)g($b, 'aprs_guard_spur_db', 0) <=> (float)g($a, 'aprs_guard_spur_db', 0);
});

$total = count($rows);
$good = count(array_filter($rows, fn($r) => g($r, 'grade') === 'GOOD'));
// "Best" is the lowest APRS-guard spur (NOT the noise floor — floor just reflects
// how much RF the antenna is hearing, not gate health). Several gates commonly
// tie at 0.0 dB, so track the value and how many share it rather than crowning one.
$bestSpur = null;
foreach ($rows as $r) {
    if (g($r, 'grade') === 'error') continue;
    $s = (float)g($r, 'aprs_guard_spur_db', 99);
    if ($bestSpur === null || $s < $bestSpur) $bestSpur = $s;
}
$isBestSpur = fn($r) => $bestSpur !== null && g($r, 'grade') !== 'error'
    && abs((float)g($r, 'aprs_guard_spur_db', 99) - $bestSpur) < 0.05;
$bestCount = count(array_filter($rows, $isBestSpur));

function age($iso) {
    if (!$iso) return '—';
    $t = strtotime($iso); if (!$t) return '—';
    $s = time() - $t;
    if ($s < 3600) return round($s / 60) . 'm ago';
    if ($s < 86400) return round($s / 3600) . 'h ago';
    return round($s / 86400) . 'd ago';
}
$COLOR = ['GOOD' => '#1a7f37', 'MARGINAL' => '#9a6700', 'BAD' => '#c0392b'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SDR Self-Noise — Fleet</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
    margin: 0; background: #f3f4f6; color: #1f2933; font-size: 14px; }
  .wrap { max-width: 1000px; margin: 0 auto; padding: 24px 16px 60px; }
  /* Header row: the title and a way out. This page is linked from NetBird Admin and
     from bookmarks, so it needs a back that works for both — history.back() returns
     you wherever you came from, and the fallback link covers arriving here cold. */
  .hdr { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin: 0 0 4px; }
  h1 { font-size: 20px; margin: 0; }
  .back { font-size: 13px; color: #2563eb; text-decoration: none; white-space: nowrap; }
  .back:hover { text-decoration: underline; }
  .sub { color: #6b7280; font-size: 13px; margin-bottom: 18px; }
  .summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
  .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; }
  .card .n { font-size: 22px; font-weight: 700; }
  .card .l { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
  table { border-collapse: collapse; width: 100%; background: #fff; border: 1px solid #e5e7eb;
    border-radius: 8px; overflow: hidden; }
  th, td { text-align: left; padding: 9px 12px; border-bottom: 1px solid #eef0f2; white-space: nowrap; }
  th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; background: #fafbfc; }
  td.num { font-variant-numeric: tabular-nums; font-family: ui-monospace, Menlo, monospace; }
  tr:last-child td { border-bottom: none; }
  .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; color: #fff; font-weight: 700; font-size: 12px; }
  .best { outline: 2px solid #1a7f37; outline-offset: -2px; }
  .muted { color: #9aa5b1; }
  .note { margin-top: 16px; font-size: 12.5px; color: #6b7280; line-height: 1.5; max-width: 80ch; }
  code { background: #eef0f2; padding: .1em .35em; border-radius: 3px; font-size: .9em; }
  .del { font: inherit; font-size: 12px; border: 1px solid #d1d5db; background: #fff; color: #c0392b;
    border-radius: 6px; padding: 3px 10px; cursor: pointer; }
  .del:hover { background: #c0392b; color: #fff; border-color: #c0392b; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>SDR Self-Noise — Fleet</h1>
    <a class="back" href="javascript:history.back()">&larr; Back</a>
    <a class="back" href="/netbird/admin.php">NetBird Admin</a>
    <a class="back" href="https://marsaprs.org/">Map</a>
  </div>
  <div class="sub">Lower is better. The headline number is the worst internal spur in the APRS guard band
    (144.37&ndash;144.42&nbsp;MHz), in dB over the noise floor &mdash; that&rsquo;s what deafens a gate.</div>

<?php if (!$total): ?>
  <div class="card">No reports yet. Gates upload nightly during the auto-update; results appear here.</div>
<?php else: ?>
  <div class="summary">
    <div class="card"><div class="n"><?= $good ?>/<?= $total ?></div><div class="l">Gates GOOD</div></div>
    <?php if ($bestSpur !== null): ?>
    <div class="card best"><div class="n" style="color:#1a7f37"><?= htmlspecialchars(number_format($bestSpur, 1)) ?> dB</div>
      <div class="l">Best guard spur<?= $bestCount > 1 ? ' &mdash; '.$bestCount.' gates tied' : '' ?></div></div>
    <?php endif; ?>
  </div>

  <table>
    <thead><tr>
      <th>Receiver</th><th>Grade</th><th>Guard-band spur</th><th>vs best</th><th>Comb?</th>
      <th>Floor</th><th title="How far the noise floor climbs across the tuner's whole gain range">Rise</th>
      <th title="Whether this channel runs on a measured gain or the compiled-in fallback">Calibration</th>
      <th>Board</th><th>Version</th><th>Reported</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $grade = g($r, 'grade', '?'); $col = $COLOR[$grade] ?? '#6b7280';
      $spur = g($r, 'aprs_guard_spur_db'); $spur = is_numeric($spur) ? (float)$spur : null;
      $off  = g($r, 'aprs_guard_offset_khz');
      $vsbest = ($spur !== null && $bestSpur !== null) ? $spur - $bestSpur : null;
      $isBest = $isBestSpur($r);
    ?>
      <tr<?= $isBest ? ' class="best"' : '' ?>>
        <td><strong><?= htmlspecialchars(g($r,'callsign') ?: g($r,'host','?')) ?></strong>
          <?php $nm = g($r,'name'); if ($nm): ?><div class="muted" style="font-weight:normal;font-size:12px"><?= htmlspecialchars($nm) ?></div><?php endif; ?></td>
        <td><span class="pill" style="background:<?= $col ?>"><?= htmlspecialchars($grade) ?></span></td>
        <td class="num"><?= $spur !== null ? number_format($spur,1).' dB' : '—' ?>
          <?php if ($spur !== null && $off !== null): ?><span class="muted">@<?= htmlspecialchars(g($r,'aprs_guard_spur_mhz')) ?></span><?php endif; ?></td>
        <td class="num"><?= $vsbest !== null ? ($vsbest <= 0.05 ? '<span style="color:#1a7f37">best</span>' : '+'.number_format($vsbest,1)) : '—' ?></td>
        <td><?= g($r,'comb_detected') ? '<span style="color:#c0392b">yes</span>' : '<span class="muted">no</span>' ?></td>
        <td class="num muted"><?= htmlspecialchars(g($r,'floor_db','—')) ?></td>
<?php // Rise: bottom of the tuner's gain range to the top. A receiver hearing the band
      // climbs with the gain; one hearing only its own converter stays flat. Deliberately
      // NOT graded — the connected case is measured, the disconnected case never was, and
      // a threshold invented from half the evidence is what put the calibration ceiling
      // below a real site's knee and then blamed the antenna for the silence. ?>
        <td class="num muted"><?php $rise = g($r,'floor_rise_db');
          echo is_numeric($rise) ? (((float)$rise >= 0 ? '+' : '') . number_format((float)$rise,1) . ' dB') : '—'; ?></td>
        <td><?php $cal = (string)g($r,'calibration');
          $calCol = ['measured'=>'#1a7f37','unmeasured'=>'#9a6700','none'=>'#c0392b'][$cal] ?? null;
          if ($calCol): $cg = g($r,'cal_gain'); ?>
            <span class="pill" style="background:<?= $calCol ?>"><?= $cal === 'none' ? 'NEVER' : htmlspecialchars($cal) ?></span>
            <?php if (is_numeric($cg)): ?><span class="muted"><?= htmlspecialchars((string)$cg) ?>&nbsp;dB</span><?php endif; ?>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td class="muted"><?= htmlspecialchars(str_replace('Raspberry Pi ','',(string)g($r,'pi_model','—'))) ?></td>
        <td class="muted"><?= htmlspecialchars(g($r,'device_version', g($r,'igate_version','—'))) ?></td>
<?php // "Reported" age from _received (server time, TZ-aware). The gate's own ts
      // has no timezone and PHP runs in UTC, so parsing ts directly is off by the
      // gate's UTC offset. ?>
        <td class="muted"><?= htmlspecialchars(age(g($r,'_received') ?: g($r,'ts'))) ?></td>
        <td><form method="post" style="margin:0" onsubmit="return confirm('Remove ' + <?= htmlspecialchars(json_encode(g($r,'callsign') ?: g($r,'host','?')), ENT_QUOTES) ?> + ' from the dashboard? It reappears on the gate\'s next self-test.')"><input type="hidden" name="delete" value="<?= htmlspecialchars(g($r,'host',''), ENT_QUOTES) ?>"><button type="submit" class="del" title="Remove this gate from the dashboard">Delete</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

  <div class="note">
    <p><strong>Grades:</strong> <span style="color:#1a7f37">GOOD</span> &lt; 6&nbsp;dB &middot;
      <span style="color:#9a6700">MARGINAL</span> 6&ndash;15&nbsp;dB &middot;
      <span style="color:#c0392b">BAD</span> &gt; 15&nbsp;dB guard-band spur.
      A BAD gate has a self-generated birdie strong enough to capture the FM receiver and stop it decoding APRS.</p>
    <p><strong>Rise</strong> is how far the noise floor climbs from the bottom of the tuner's
      gain range to the top. A receiver hearing the band climbs with the gain — about
      11&ndash;15&nbsp;dB at a quiet 2&nbsp;m site. One hearing only its own converter stays
      flat. It is recorded rather than graded: a flat curve means either nothing is reaching
      the tuner or the site is very quiet, and no measurement here separates them.
      <em>The grade above cannot tell you this</em> &mdash; it measures internal spurs, and a
      receiver with nothing on its antenna port scores GOOD.</p>
    <p><strong>Calibration</strong> is for Transcriber channels.
      <span style="color:#1a7f37">measured</span> means the gain was found from this site's own
      noise floor; <span style="color:#9a6700">unmeasured</span> means no knee appeared and the
      channel is running the top of the sweep; <span style="color:#c0392b">NEVER</span> means it
      is running the compiled-in fallback, which is somebody else's site's number.</p>
    <p><strong>If a gate is BAD:</strong> the usual cause is the SDR dongle sitting inside the case next to the Pi,
      whose clock/power emissions couple in. Move the dongle out of the case on a short USB extension &mdash; that
      alone typically drops the spur ~15&nbsp;dB. A <code>Comb?&nbsp;yes</code> confirms self-noise (a regular comb of
      spurs across the band, which no real signal produces).</p>
    <p><strong>Floor</strong> is informational, not a ranking &mdash; &ldquo;Best&rdquo; is the lowest guard-band spur, not
      the lowest floor. A lower floor just means the receiver is hearing less ambient RF (quieter site or weaker
      antenna); it doesn&rsquo;t make a gate healthier.</p>
    <p class="muted">The test max-holds across several sweeps with an occurrence filter, so it works with the
      antenna connected &mdash; one-off over-the-air signals are rejected, only always-present internal spurs count.
      Reports refresh nightly during each gate&rsquo;s auto-update.</p>
  </div>
</div>
</body>
</html>
