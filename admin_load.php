<?php
// Resumable server-side bulk loader. Streams a gzip-compressed CSV directly
// (no separate decompression step) and inserts it via batched multi-row
// INSERT statements (LOAD DATA isn't available: LOCAL is blocked by PHP's
// ini config, non-LOCAL needs the FILE privilege which this account doesn't
// have). Persists progress so repeated calls resume cleanly.

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

$dataDir = __DIR__ . '/data';
$gzPath = sprintf('%s/cluster_%02d.csv.gz', $dataDir, $cluster);
if (!file_exists($gzPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'gz not found', 'path' => $gzPath]);
    exit;
}

$cfg = require __DIR__ . '/db_config.php';
$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4",
    $cfg['user'], $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("CREATE TABLE IF NOT EXISTS load_progress (
    cluster_id TINYINT UNSIGNED PRIMARY KEY,
    lines_done BIGINT UNSIGNED NOT NULL DEFAULT 0
)");
$stmt = $pdo->prepare("SELECT lines_done FROM load_progress WHERE cluster_id=?");
$stmt->execute([$cluster]);
$linesDone = (int)($stmt->fetchColumn() ?: 0);
$startLinesDone = $linesDone;

$budgetSeconds = 170;
$chunkSize = 250000;   // rows between progress checkpoints
$subBatchSize = 5000;  // rows per actual INSERT statement
$start = microtime(true);

$gz = gzopen($gzPath, 'rb');
if (!$gz) {
    http_response_code(500);
    echo json_encode(['error' => 'gzopen failed']);
    exit;
}

// fast-forward past already-loaded lines (resumed runs pay this cost)
$skipped = 0;
while ($skipped < $linesDone && !gzeof($gz)) {
    gzgets($gz);
    $skipped++;
}
$skipTime = microtime(true) - $start;

$placeholders = rtrim(str_repeat('(?,?,?,?,?),', $subBatchSize), ',');
$insertSql = "INSERT IGNORE INTO readings
               (cluster_id, ts, network_coherence, active_devices, running_total)
               VALUES $placeholders";
$insertStmt = $pdo->prepare($insertSql);

function flush_batch($pdo, $insertStmt, $subBatchSize, array $rows) {
    $n = count($rows);
    if ($n === 0) return 0;
    if ($n === $subBatchSize) {
        $params = [];
        foreach ($rows as $r) { foreach ($r as $v) $params[] = $v; }
        $insertStmt->execute($params);
        return $insertStmt->rowCount();
    } else {
        // final partial sub-batch: build a fresh statement sized to match
        $ph = rtrim(str_repeat('(?,?,?,?,?),', $n), ',');
        $stmt = $pdo->prepare("INSERT IGNORE INTO readings
            (cluster_id, ts, network_coherence, active_devices, running_total)
            VALUES $ph");
        $params = [];
        foreach ($rows as $r) { foreach ($r as $v) $params[] = $v; }
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}

$newLines = 0;
$actuallyInserted = 0;
$chunksThisRun = 0;
$linesInChunk = 0;
$subBatch = [];

while (!gzeof($gz) && (microtime(true) - $start) < $budgetSeconds) {
    $line = gzgets($gz);
    if ($line === false) break;
    $line = rtrim($line, "\n");
    if ($line === '') continue;
    $parts = explode(',', $line);
    if (count($parts) !== 5) continue;
    $subBatch[] = $parts;

    if (count($subBatch) >= $subBatchSize) {
        $actuallyInserted += flush_batch($pdo, $insertStmt, $subBatchSize, $subBatch);
        $linesInChunk += count($subBatch);
        $newLines += count($subBatch);
        $subBatch = [];
    }

    if ($linesInChunk >= $chunkSize) {
        $linesDone += $linesInChunk;
        $upd = $pdo->prepare("INSERT INTO load_progress (cluster_id, lines_done) VALUES (?,?)
                               ON DUPLICATE KEY UPDATE lines_done=?");
        $upd->execute([$cluster, $linesDone, $linesDone]);
        $linesInChunk = 0;
        $chunksThisRun++;
    }
}
// flush any remainder
if ($subBatch) {
    $actuallyInserted += flush_batch($pdo, $insertStmt, $subBatchSize, $subBatch);
    $linesInChunk += count($subBatch);
    $newLines += count($subBatch);
}
if ($linesInChunk > 0) {
    $linesDone += $linesInChunk;
    $upd = $pdo->prepare("INSERT INTO load_progress (cluster_id, lines_done) VALUES (?,?)
                           ON DUPLICATE KEY UPDATE lines_done=?");
    $upd->execute([$cluster, $linesDone, $linesDone]);
}

$finished = gzeof($gz);
gzclose($gz);

echo json_encode([
    'cluster' => $cluster,
    'started_at_lines' => $startLinesDone,
    'chunks_this_run' => $chunksThisRun,
    'lines_read' => $newLines,
    'rows_actually_inserted' => $actuallyInserted,
    'lines_done_total' => $linesDone,
    'finished' => $finished,
    'skip_time_s' => round($skipTime, 2),
    'elapsed_s' => round(microtime(true) - $start, 2),
]);
