<?php
/**
 * iGate SDR self-noise dashboard.
 *
 * Reads the per-host reports uploaded by igate-selftest.sh and ranks the fleet
 * by the one number that predicts a deaf gate: the worst internal spur in the
 * APRS guard band (144.37-144.42 MHz), in dB over the noise floor. Low is good.
 * Calibration from a Pi Zero 2 W: ~+18 dB with the dongle in the case (deaf),
 * ~0-3 dB with it moved out of the case (healthy).
 */
$dir = __DIR__ . '/data';
$rows = [];
foreach (glob("$dir/*.json") ?: [] as $f) {
    $d = json_decode(@file_get_contents($f), true);
    if (is_array($d) && !empty($d['host'])) $rows[] = $d;
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
$best = null;
foreach ($rows as $r) {
    if (g($r, 'grade') === 'error') continue;
    if ($best === null || (float)g($r, 'aprs_guard_spur_db', 99) < (float)g($best, 'aprs_guard_spur_db', 99)) $best = $r;
}

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
<title>iGate SDR Self-Noise — Fleet</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
    margin: 0; background: #f3f4f6; color: #1f2933; font-size: 14px; }
  .wrap { max-width: 1000px; margin: 0 auto; padding: 24px 16px 60px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
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
</style>
</head>
<body>
<div class="wrap">
  <h1>iGate SDR Self-Noise — Fleet</h1>
  <div class="sub">Lower is better. The headline number is the worst internal spur in the APRS guard band
    (144.37&ndash;144.42&nbsp;MHz), in dB over the noise floor &mdash; that&rsquo;s what deafens a gate.</div>

<?php if (!$total): ?>
  <div class="card">No reports yet. Gates upload nightly during the auto-update; results appear here.</div>
<?php else: ?>
  <div class="summary">
    <div class="card"><div class="n"><?= $good ?>/<?= $total ?></div><div class="l">Gates GOOD</div></div>
    <?php if ($best): ?>
    <div class="card best"><div class="n" style="color:#1a7f37"><?= htmlspecialchars(number_format((float)g($best,'aprs_guard_spur_db',0),1)) ?> dB</div>
      <div class="l">Best &mdash; <?= htmlspecialchars(g($best,'host')) ?></div></div>
    <?php endif; ?>
  </div>

  <table>
    <thead><tr>
      <th>Gate</th><th>Grade</th><th>APRS-guard spur</th><th>vs best</th><th>Comb?</th>
      <th>Floor</th><th>Dongle</th><th>Board</th><th>iGate</th><th>Reported</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
      $grade = g($r, 'grade', '?'); $col = $COLOR[$grade] ?? '#6b7280';
      $spur = g($r, 'aprs_guard_spur_db'); $spur = is_numeric($spur) ? (float)$spur : null;
      $off  = g($r, 'aprs_guard_offset_khz');
      $vsbest = ($spur !== null && $best) ? $spur - (float)g($best,'aprs_guard_spur_db',0) : null;
      $isBest = $best && g($r,'host') === g($best,'host');
    ?>
      <tr<?= $isBest ? ' class="best"' : '' ?>>
        <td><strong><?= htmlspecialchars(g($r,'host','?')) ?></strong></td>
        <td><span class="pill" style="background:<?= $col ?>"><?= htmlspecialchars($grade) ?></span></td>
        <td class="num"><?= $spur !== null ? number_format($spur,1).' dB' : '—' ?>
          <?php if ($spur !== null && $off !== null): ?><span class="muted">@<?= htmlspecialchars(g($r,'aprs_guard_spur_mhz')) ?></span><?php endif; ?></td>
        <td class="num"><?= $vsbest !== null ? ($vsbest <= 0.05 ? '<span style="color:#1a7f37">best</span>' : '+'.number_format($vsbest,1)) : '—' ?></td>
        <td><?= g($r,'comb_detected') ? '<span style="color:#c0392b">yes</span>' : '<span class="muted">no</span>' ?></td>
        <td class="num muted"><?= htmlspecialchars(g($r,'floor_db','—')) ?></td>
        <td class="muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(g($r,'dongle','—')) ?></td>
        <td class="muted"><?= htmlspecialchars(str_replace('Raspberry Pi ','',(string)g($r,'pi_model','—'))) ?></td>
        <td class="muted"><?= htmlspecialchars(g($r,'igate_version','—')) ?></td>
        <td class="muted"><?= htmlspecialchars(age(g($r,'ts'))) ?></td>
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
    <p><strong>If a gate is BAD:</strong> the usual cause is the SDR dongle sitting inside the case next to the Pi,
      whose clock/power emissions couple in. Move the dongle out of the case on a short USB extension &mdash; that
      alone typically drops the spur ~15&nbsp;dB. A <code>Comb?&nbsp;yes</code> confirms self-noise (a regular comb of
      spurs across the band, which no real signal produces).</p>
    <p class="muted">The test max-holds across several sweeps with an occurrence filter, so it works with the
      antenna connected &mdash; one-off over-the-air signals are rejected, only always-present internal spurs count.
      Reports refresh nightly during each gate&rsquo;s auto-update.</p>
  </div>
</div>
</body>
</html>
