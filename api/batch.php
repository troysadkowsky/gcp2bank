<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require __DIR__ . '/db.php';

$cluster = isset($_GET['cluster']) ? (int)$_GET['cluster'] : 0;
$after = isset($_GET['after']) && $_GET['after'] !== '' ? $_GET['after'] : null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 2000;
$limit = max(1, min($limit, 100000));
// bucket=1 (or omitted) means raw per-second rows. bucket>1 means the caller
// only needs one sample per N seconds (e.g. because it's rendering a wide,
// zoomed-out window) — aggregate server-side so we're not shipping resolution
// nothing can display, which is what was driving fetch-limiting at high speed.
$bucket = isset($_GET['bucket']) ? (int)$_GET['bucket'] : 1;
$bucket = max(1, min($bucket, 21600)); // cap at 6h buckets
// optional explicit upper bound — a caller that already knows exactly which window
// it wants (e.g. "show this whole month right now") can pass this so the aggregated
// query scans only that window, instead of the generous multi-x-allowance bound below
// (sized for incremental streaming, where the caller doesn't know how far it'll get).
$before = isset($_GET['before']) && $_GET['before'] !== '' ? $_GET['before'] : null;

if ($cluster < 1 || $cluster > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_cluster']);
    exit;
}
if ($after !== null && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $after)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_after']);
    exit;
}
if ($before !== null && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $before)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_before']);
    exit;
}

try {
    $pdo = get_pdo();

    if ($bucket <= 1) {
        $sql = 'SELECT ts, network_coherence, active_devices, running_total
                FROM readings WHERE cluster_id = ?' . ($after ? ' AND ts > ?' : '') . '
                ORDER BY ts ASC LIMIT ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        $stmt->bindValue($i++, $cluster, PDO::PARAM_INT);
        if ($after) $stmt->bindValue($i++, $after, PDO::PARAM_STR);
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        // compact tuple form: [epoch_ms, coherence, active_devices, running_total]
        $out = [];
        foreach ($rows as $r) {
            $out[] = [strtotime($r[0] . ' UTC') * 1000, (float)$r[1], (int)$r[2], (float)$r[3]];
        }
        $body = json_encode($out);
        header('Content-Length: ' . strlen($body)); // lets the client track real download progress
        echo $body;
    } else {
        // one row per bucket: [bucket_end_epoch_ms, min_running_total, max_running_total,
        // avg_active_devices, avg_coherence]. bucket_end doubles as the resumability
        // cursor, so the caller's existing "after=last row's timestamp" logic just works.
        //
        // GROUP BY on a computed expression can't push LIMIT down through an indexed
        // range scan the way a plain ORDER BY can — MySQL was scanning (and grouping)
        // the entire rest of the cluster before applying LIMIT, taking 30+ seconds.
        // Bound the scan explicitly with an upper timestamp instead, sized generously
        // for the number of buckets requested, so it's a cheap indexed range either way.
        //
        // At coarse bucket sizes that bound still spans a huge fraction of the raw
        // 76M-row table (aggregating requires reading every row in range — there's no
        // index shortcut for AVG/MIN/MAX over arbitrary groups), so wide-zoom requests
        // instead read readings_hourly, a pre-aggregated 1-row-per-hour rollup that's
        // ~3600x smaller, and re-group that down to the requested bucket size.
        $useRollup = $bucket >= 3600;
        $table = $useRollup ? 'readings_hourly' : 'readings';
        $tsCol = $useRollup ? 'bucket_start' : 'ts';
        $srcMn = $useRollup ? 'mn' : 'running_total';
        $srcMx = $useRollup ? 'mx' : 'running_total';
        $srcDev = $useRollup ? 'avg_dev' : 'active_devices';
        $srcCoh = $useRollup ? 'avg_coh' : 'network_coherence';

        if ($after) {
            $afterUnix = strtotime($after . ' UTC');
        } else {
            $minStmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(MIN($tsCol)) FROM $table WHERE cluster_id = ?");
            $minStmt->execute([$cluster]);
            $afterUnix = ((int)$minStmt->fetchColumn()) - 1;
        }
        $upperUnix = $afterUnix + ($limit * $bucket * 3); // 3x allowance for gaps in coverage
        if ($before) {
            $upperUnix = min($upperUnix, strtotime($before . ' UTC'));
        }
        $upperBound = gmdate('Y-m-d H:i:s', $upperUnix);
        $afterBound = gmdate('Y-m-d H:i:s', $afterUnix);

        // alias deliberately not named "bucket_start" — that's a real column on
        // readings_hourly, and GROUP BY/ORDER BY resolve an alias matching a real
        // column name to the COLUMN, not the computed expression, silently grouping
        // per raw row instead of per target bucket.
        $sql = "SELECT
                  FLOOR(UNIX_TIMESTAMP($tsCol)/?)*? AS bkt,
                  MIN($srcMn) AS mn,
                  MAX($srcMx) AS mx,
                  AVG($srcDev) AS avg_dev,
                  AVG($srcCoh) AS avg_coh
                FROM $table
                WHERE cluster_id = ? AND $tsCol > ? AND $tsCol <= ?
                GROUP BY bkt
                ORDER BY bkt ASC
                LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $i = 1;
        $stmt->bindValue($i++, $bucket, PDO::PARAM_INT);
        $stmt->bindValue($i++, $bucket, PDO::PARAM_INT);
        $stmt->bindValue($i++, $cluster, PDO::PARAM_INT);
        $stmt->bindValue($i++, $afterBound, PDO::PARAM_STR);
        $stmt->bindValue($i++, $upperBound, PDO::PARAM_STR);
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        $out = [];
        foreach ($rows as $r) {
            $bucketEndMs = ((int)$r[0] + $bucket - 1) * 1000;
            $out[] = [$bucketEndMs, (float)$r[1], (float)$r[2], (float)$r[3], (float)$r[4]];
        }
        $body = json_encode($out);
        header('Content-Length: ' . strlen($body)); // lets the client track real download progress
        echo $body;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
