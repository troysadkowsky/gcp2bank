<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require __DIR__ . '/db.php';

try {
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT id, name, first_ts, last_ts, n_rows FROM clusters ORDER BY id');
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error']);
}
