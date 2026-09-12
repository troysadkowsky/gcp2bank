<?php
// Notable decline periods for one cluster: a classic drawdown scan (the same
// concept a "underwater period" chart uses for a portfolio) over the hourly
// rollup — for each hour, track the running peak-so-far of the running total;
// whenever the current hour is below that peak we're "in a decline," ending
// when a new all-time high is set. Reports the deepest N such periods.
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require __DIR__ . '/db.php';

$cluster = isset($_GET['cluster']) ? (int)$_GET['cluster'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
$limit = max(1, min($limit, 20));

if ($cluster < 1 || $cluster > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_cluster']);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT bucket_start, mx FROM readings_hourly WHERE cluster_id = ? ORDER BY bucket_start ASC');
    $stmt->execute([$cluster]);
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    $periods = [];
    if (count($rows) > 0) {
        $peak = (float)$rows[0][1];
        $peakTs = $rows[0][0];
        $trough = $peak;
        $troughTs = $peakTs;
        $inDrawdown = false;

        for ($i = 1; $i < count($rows); $i++) {
            $ts = $rows[$i][0];
            $v = (float)$rows[$i][1];
            if ($v >= $peak) {
                if ($inDrawdown) {
                    $periods[] = [$peakTs, $troughTs, $peak - $trough];
                }
                $peak = $v; $peakTs = $ts;
                $trough = $v; $troughTs = $ts;
                $inDrawdown = false;
            } else {
                $inDrawdown = true;
                if ($v < $trough) { $trough = $v; $troughTs = $ts; }
            }
        }
        if ($inDrawdown) {
            $periods[] = [$peakTs, $troughTs, $peak - $trough];
        }
    }

    usort($periods, fn($a, $b) => $b[2] <=> $a[2]);
    $top = array_slice($periods, 0, $limit);

    $out = [];
    foreach ($top as $p) {
        $peakMs = strtotime($p[0] . ' UTC') * 1000;
        $troughMs = strtotime($p[1] . ' UTC') * 1000;
        $out[] = [
            'peak_ts' => $peakMs,
            'trough_ts' => $troughMs,
            'depth' => round($p[2], 1),
            'duration_hours' => round(($troughMs - $peakMs) / 3600000, 1),
        ];
    }
    echo json_encode($out);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
