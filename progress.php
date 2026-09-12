<?php
require __DIR__ . '/api/db.php';
$pdo = get_pdo();
$rows = $pdo->query("
  SELECT c.id, c.name, c.n_rows, COALESCE(lp.lines_done,0) AS done
  FROM clusters c LEFT JOIN load_progress lp ON lp.cluster_id = c.id
  ORDER BY c.id
")->fetchAll();

$totalExpected = array_sum(array_column($rows, 'n_rows'));
$totalDone = array_sum(array_column($rows, 'done'));

// Self-correcting rate estimate: compare against a snapshot saved on the
// previous page load, so the ETA tracks whatever the host is actually
// achieving right now rather than a fixed assumption.
$pdo->exec("CREATE TABLE IF NOT EXISTS progress_snapshot (
    id TINYINT UNSIGNED PRIMARY KEY,
    total_done BIGINT UNSIGNED NOT NULL,
    snapshot_time DATETIME NOT NULL,
    smoothed_rate DOUBLE NOT NULL DEFAULT 0
)");
// UNIX_TIMESTAMP() computed by MySQL itself on both sides, so this never
// depends on PHP's timezone assumption matching MySQL's
$prev = $pdo->query("SELECT *, UNIX_TIMESTAMP(snapshot_time) AS snap_unix,
                             UNIX_TIMESTAMP(NOW()) AS now_unix
                      FROM progress_snapshot WHERE id=1")->fetch();

$smoothedRate = $prev ? (float)$prev['smoothed_rate'] : 0;

if ($prev) {
    $dt = (int)$prev['now_unix'] - (int)$prev['snap_unix'];
    $dRows = $totalDone - (int)$prev['total_done'];
    // only fold in a new sample if enough time has passed for a meaningful
    // reading, and progress actually moved (avoids div-by-near-zero noise)
    if ($dt >= 5 && $dRows > 0) {
        $instantRate = $dRows / $dt;
        $alpha = $smoothedRate > 0 ? 0.3 : 1.0; // first sample: take it as-is
        $smoothedRate = $smoothedRate + ($instantRate - $smoothedRate) * $alpha;
    } elseif ($dRows <= 0 && $dt >= 30) {
        // no progress for a while (e.g. between loader calls) — decay toward 0
        // rather than keep showing a stale optimistic rate
        $smoothedRate *= 0.5;
    }
}

$stmt = $pdo->prepare("INSERT INTO progress_snapshot (id, total_done, snapshot_time, smoothed_rate)
                        VALUES (1, ?, NOW(), ?)
                        ON DUPLICATE KEY UPDATE total_done=?, snapshot_time=NOW(), smoothed_rate=?");
$stmt->execute([$totalDone, $smoothedRate, $totalDone, $smoothedRate]);

$remaining = max(0, $totalExpected - $totalDone);
$etaSeconds = $smoothedRate > 0 ? $remaining / $smoothedRate : null;

function fmt_duration($s) {
    if ($s === null) return 'calculating…';
    if ($s < 60) return round($s) . 's';
    $h = floor($s / 3600); $m = floor(($s % 3600) / 60);
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="15">
<title>GCP2 load progress</title>
<style>
  body{ font-family: ui-monospace, monospace; background:#0e0d14; color:#f2f1f6; padding:24px; }
  h1{ font-size:16px; font-weight:600; }
  table{ border-collapse:collapse; width:100%; max-width:900px; margin-top:16px; }
  td,th{ text-align:left; padding:4px 10px; font-size:13px; }
  th{ color:#9c9aa8; text-transform:uppercase; font-size:10px; }
  .bar-wrap{ background:#1a1a19; border-radius:3px; overflow:hidden; height:14px; width:200px; }
  .bar{ background:#6ee7c8; height:100%; }
  .pct{ font-variant-numeric: tabular-nums; }
  .done{ color:#6ee7c8; }
  .overall{ margin-top:20px; font-size:14px; }
</style>
</head>
<body>
<h1>GCP2 readings load progress</h1>
<p style="color:#9c9aa8;font-size:12px;">Auto-refreshes every 15s.</p>
<table>
<tr><th>Cluster</th><th>Rows loaded</th><th>Expected</th><th>Progress</th><th></th></tr>
<?php foreach ($rows as $r):
  $pct = $r['n_rows'] ? min(100, $r['done']/$r['n_rows']*100) : 0;
  $isDone = $r['done'] >= $r['n_rows'];
?>
<tr>
  <td><?= htmlspecialchars(str_replace('_',' ',$r['name'])) ?></td>
  <td class="pct"><?= number_format($r['done']) ?></td>
  <td class="pct"><?= number_format($r['n_rows']) ?></td>
  <td><div class="bar-wrap"><div class="bar" style="width:<?= $pct ?>%"></div></div></td>
  <td class="pct <?= $isDone ? 'done' : '' ?>"><?= number_format($pct,1) ?>%<?= $isDone ? ' ✓' : '' ?></td>
</tr>
<?php endforeach; ?>
</table>
<p class="overall">Overall: <?= number_format($totalDone) ?> / <?= number_format($totalExpected) ?>
  (<?= number_format($totalDone/$totalExpected*100, 2) ?>%)</p>
<p class="overall">Rate: <?= $smoothedRate > 0 ? number_format($smoothedRate) . ' rows/s' : 'measuring…' ?>
  &mdash; Est. time remaining: <strong><?= fmt_duration($etaSeconds) ?></strong></p>
<p style="color:#9c9aa8;font-size:11px;">Estimate assumes all source files are already uploaded and updates itself
  from actual observed progress between page loads — it'll adjust if the load speeds up, slows down, or pauses.</p>
</body>
</html>
