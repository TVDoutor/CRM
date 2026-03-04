<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);

if (($data['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
    exit;
}

$equipmentId = (int)($data['equipment_id'] ?? 0);
$note        = trim($data['note'] ?? '');

if (!$equipmentId || !$note) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("SELECT id FROM equipment WHERE id = ?");
$stmt->execute([$equipmentId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Equipamento não encontrado.']);
    exit;
}

$db->prepare("INSERT INTO equipment_notes (equipment_id, user_id, note) VALUES (?,?,?)")
   ->execute([$equipmentId, $_SESSION['user_id'], $note]);

$noteId = (int)$db->lastInsertId();

auditLog('NOTE', 'equipment', $equipmentId, null, ['note' => mb_substr($note, 0, 100)], "Nota adicionada ao equipamento #$equipmentId");

echo json_encode(['success' => true, 'id' => $noteId]);
