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

if (!$noteId) {
    echo json_encode(['success' => false, 'message' => 'ID da nota inválido.']);
    exit;
}

$db = getDB();

$stmt = $db->prepare("SELECT id, equipment_id FROM equipment_notes WHERE id = ?");
$stmt->execute([$noteId]);
$note = $stmt->fetch();

if (!$note) {
    echo json_encode(['success' => false, 'message' => 'Nota não encontrada.']);
    exit;
}

$db->prepare("DELETE FROM equipment_notes WHERE id = ?")->execute([$noteId]);

auditLog('DELETE', 'equipment', $note['equipment_id'], ['note_id' => $noteId], null, "Nota #{$noteId} excluída do equipamento #{$note['equipment_id']}");

echo json_encode(['success' => true]);
