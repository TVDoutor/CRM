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

$noteId = (int)($data['note_id'] ?? 0);
$note   = trim($data['note']    ?? '');

if (!$noteId || !$note) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("SELECT id, equipment_id, note FROM equipment_notes WHERE id = ?");
$stmt->execute([$noteId]);
$existing = $stmt->fetch();

if (!$existing) {
    echo json_encode(['success' => false, 'message' => 'Nota não encontrada.']);
    exit;
}

$db->prepare("UPDATE equipment_notes SET note = ? WHERE id = ?")->execute([$note, $noteId]);

auditLog('UPDATE', 'equipment', $existing['equipment_id'],
    ['note' => mb_substr($existing['note'], 0, 100)],
    ['note' => mb_substr($note, 0, 100)],
    "Nota #{$noteId} editada no equipamento #{$existing['equipment_id']}");

echo json_encode(['success' => true]);
