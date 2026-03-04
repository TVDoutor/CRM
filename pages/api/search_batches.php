<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['batches' => []]);
    exit;
}

requireLogin();

$q = trim($_GET['q'] ?? '');
$limit = min(20, max(5, (int)($_GET['limit'] ?? 15)));

$db = getDB();

if (strlen($q) < 1) {
    // Sem busca: retorna os lotes mais recentes
    $stmt = $db->prepare("SELECT id, name FROM batches ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
} else {
    $term = '%' . $q . '%';
    $stmt = $db->prepare("SELECT id, name FROM batches WHERE name LIKE ? ORDER BY name LIMIT ?");
    $stmt->execute([$term, $limit]);
}

$batches = $stmt->fetchAll(\PDO::FETCH_ASSOC);

echo json_encode(['batches' => $batches]);
