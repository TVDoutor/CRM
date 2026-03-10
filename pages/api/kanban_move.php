<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/pipedrive_push.php';

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
$toStatus    = trim($data['to_status']    ?? '');
$notes       = trim($data['notes']        ?? '') ?: null;

$validStatuses = ['entrada','aguardando_instalacao','alocado','manutencao','licenca_removida','equipamento_usado','comercial','processo_devolucao','baixado'];

if (!$equipmentId || !in_array($toStatus, $validStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos.']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, kanban_status, current_client_id FROM equipment WHERE id = ?");
$stmt->execute([$equipmentId]);
$eq = $stmt->fetch();

if (!$eq) {
    echo json_encode(['success' => false, 'message' => 'Equipamento não encontrado.']);
    exit;
}

$fromStatus = $eq['kanban_status'];
// Preservar client_id quando equipamento está vinculado ao cliente; zerar quando em estoque/baixado
$statusComCliente = ['aguardando_instalacao', 'alocado', 'licenca_removida', 'processo_devolucao', 'comercial', 'manutencao'];
$keepClient       = in_array($toStatus, $statusComCliente, true);
$clientId         = $keepClient ? ($eq['current_client_id'] ?? null) : null;

kanbanMove($equipmentId, $fromStatus, $toStatus, $clientId, $notes);

// ── Push para Pipedrive (assíncrono — falha não bloqueia o CRM) ───────────
$pipeResult  = ['skipped' => true];
$pipeWarning = null;
try {
    $pipeResult = pipePushSyncStatus($equipmentId, $toStatus);
    if (!($pipeResult['success'] ?? false) && empty($pipeResult['skipped'])) {
        $pipeWarning = $pipeResult['error'] ?? 'Falha ao sincronizar com Pipedrive.';
    }
} catch (\Exception $pe) {
    $pipeWarning = $pe->getMessage();
}

$response = ['success' => true, 'from' => $fromStatus, 'to' => $toStatus];
if ($pipeWarning) {
    $response['pipe_warning'] = $pipeWarning;
}
if (!empty($pipeResult['skipped'])) {
    $response['pipe_skipped'] = true;
}

echo json_encode($response);
