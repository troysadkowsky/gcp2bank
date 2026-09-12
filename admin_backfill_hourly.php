<?php
// One-time resumable backfill of readings_hourly (1 row per cluster per UTC hour)
// from the raw readings table. Wide-zoom views (6mo/12mo/all-time) aggregate over
// this instead of scanning tens of millions of raw rows per request.
// Processes bounded day-range chunks per call (progress persisted after each
// chunk) so a single cluster can span multiple invocations if needed, same
// resumable pattern as admin_load.php.

$SECRET = require __DIR__ . '/admin_secret.php';
if (($_GET['token'] ?? '') !== $SECRET) {
    http_response_code(403);
    exit('forbidden');
}

set_time_limit(0);
ignore_user_abort(true);

$cluster = (int)($_GET['cluster'] ?? 0);
if ($cluster < 1 || $cluster > 19) {
    http_response_code(400);
    echo json_encode(['error' => 'bad cluster']);
    exit;
}

header('Content-Type: application/json');

$cfg = require __DIR__ . '/db_config.php';
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4",
    $cfg['user'], $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("SET time_zone = '+00:00'"); // UNIX_TIMESTAMP()/FROM_UNIXTIME() below must read/write UTC

$stmt = $pdo->prepare("SELECT last_bucket_start FROM hourly_progress WHERE cluster_id=?");
$stmt->execute([$cluster]);
$lastBucketStart = $stmt->fetchColumn();

if ($lastBucketStart === false || $lastBucketStart === null) {
    $minStmt = $pdo->prepare("SELECT MIN(ts) FROM readings WHERE cluster_id=?");
    $minStmt->execute([$cluster]);
    $firstTs = $minStmt->fetchColumn();
    if ($firstTs === null) {
        echo json_encode(['error' => 'no rows for cluster', 'cluster' => $cluster]);
        exit;
    }
    $cursorUnix = strtotime($firstTs . ' UTC') - 1;
} else {
    $cursorUnix = strtotime($lastBucketStart . ' UTC');
}

$maxStmt = $pdo->prepare("SELECT MAX(ts) FROM readings WHERE cluster_id=?");
$maxStmt->execute([$cluster]);
$lastTs = $maxStmt->fetchColumn();
$endUnix = strtotime($lastTs . ' UTC');

$budgetSeconds = 170;
$chunkDays = 30;
$chunkSeconds = $chunkDays * 86400;
$start = microtime(true);

$insertSql = "INSERT INTO readings_hourly (cluster_id, bucket_start, mn, mx, avg_dev, avg_coh)
              SELECT ?,
                     FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ts)/3600)*3600),
                     MIN(running_total), MAX(running_total),
                     AVG(active_devices), AVG(network_coherence)
              FROM readings
              WHERE cluster_id = ? AND ts > ? AND ts <= ?
              GROUP BY FLOOR(UNIX_TIMESTAMP(ts)/3600)
              ON DUPLICATE KEY UPDATE mn=VALUES(mn), mx=VALUES(mx),
                                      avg_dev=VALUES(avg_dev), avg_coh=VALUES(avg_coh)";
$insertStmt = $pdo->prepare($insertSql);
$progStmt = $pdo->prepare("INSERT INTO hourly_progress (cluster_id, last_bucket_start) VALUES (?,?)
                            ON DUPLICATE KEY UPDATE last_bucket_start=?");

$chunksThisRun = 0;
$rowsThisRun = 0;
$finished = false;

while ((microtime(true) - $start) < $budgetSeconds) {
    if ($cursorUnix >= $endUnix) { $finished = true; break; }
    $chunkEndUnix = min($cursorUnix + $chunkSeconds, $endUnix);
    $afterBound = gmdate('Y-m-d H:i:s', $cursorUnix);
    $upperBound = gmdate('Y-m-d H:i:s', $chunkEndUnix);

    $insertStmt->execute([$cluster, $cluster, $afterBound, $upperBound]);
    $rowsThisRun += $insertStmt->rowCount();

    $cursorUnix = $chunkEndUnix;
    $progStmt->execute([$cluster, gmdate('Y-m-d H:i:s', $cursorUnix), gmdate('Y-m-d H:i:s', $cursorUnix)]);
    $chunksThisRun++;
}

echo json_encode([
    'cluster' => $cluster,
    'chunks_this_run' => $chunksThisRun,
    'hourly_rows_written' => $rowsThisRun,
    'cursor_reached' => gmdate('Y-m-d H:i:s', $cursorUnix),
    'end_target' => gmdate('Y-m-d H:i:s', $endUnix),
    'finished' => $finished,
    'elapsed_s' => round(microtime(true) - $start, 2),
]);
