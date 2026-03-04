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
// Todos os usuários autenticados podem editar estes campos (somente editar, não excluir)

$data = json_decode(file_get_contents('php://input'), true);

if (($data['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
    exit;
}

$equipmentId = (int)($data['equipment_id'] ?? 0);
$serialNumber = isset($data['serial_number']) ? trim($data['serial_number']) : null;
$macAddress   = isset($data['mac_address'])   ? trim($data['mac_address'])   : null;
$batchId      = isset($data['batch_id'])      ? (int)$data['batch_id']      : null;

if (!$equipmentId) {
    echo json_encode(['success' => false, 'message' => 'Equipamento inválido.']);
    exit;
}

// Normalizar MAC (formato XX:XX:XX:XX:XX:XX)
if ($macAddress !== null) {
    $raw = preg_replace('/[^A-Fa-f0-9]/', '', $macAddress);
    if (strlen($raw) >= 4) {
        $parts = str_split(strtoupper($raw), 2);
        $macAddress = implode(':', array_slice($parts, 0, 6));
    } else {
        $macAddress = $macAddress ?: null;
    }
}

$db = getDB();

$stmt = $db->prepare("SELECT id, serial_number, mac_address, batch_id, batch FROM equipment WHERE id = ?");
$stmt->execute([$equipmentId]);
$eq = $stmt->fetch();
if (!$eq) {
    echo json_encode(['success' => false, 'message' => 'Equipamento não encontrado.']);
    exit;
}

$old = ['serial_number' => $eq['serial_number'], 'mac_address' => $eq['mac_address'], 'batch_id' => $eq['batch_id']];

$batchName = null;
if ($batchId) {
    $bStmt = $db->prepare("SELECT name FROM batches WHERE id = ?");
    $bStmt->execute([$batchId]);
    $batchName = $bStmt->fetchColumn() ?: null;
}

$updates = [];
$params  = [];

if ($serialNumber !== null) {
    $updates[] = 'serial_number = ?';
    $params[]  = $serialNumber ?: null;
}
if ($macAddress !== null) {
    $updates[] = 'mac_address = ?';
    $params[]  = $macAddress;
}
if ($batchId !== null) {
    $updates[] = 'batch_id = ?';
    $params[]  = $batchId ?: null;
    $updates[] = 'batch = ?';
    $params[]  = $batchName;
}

if (empty($updates)) {
    echo json_encode(['success' => true, 'message' => 'Nenhuma alteração.']);
    exit;
}

$params[] = $_SESSION['user_id'];
$params[] = $equipmentId;

$db->prepare("UPDATE equipment SET " . implode(', ', $updates) . ", updated_by = ? WHERE id = ?")
   ->execute($params);

$newSerial = $serialNumber !== null ? ($serialNumber ?: null) : $eq['serial_number'];
$newMac    = $macAddress   !== null ? $macAddress              : $eq['mac_address'];
$newBatch  = $batchId      !== null ? ($batchId ?: null)       : $eq['batch_id'];

$changes = [];
if ($serialNumber !== null && $serialNumber !== (string)($eq['serial_number'] ?? '')) {
    $changes['serial_number'] = $newSerial;
}
if ($macAddress !== null && $macAddress !== (string)($eq['mac_address'] ?? '')) {
    $changes['mac_address'] = $newMac;
}
if ($batchId !== null && $batchId != ($eq['batch_id'] ?? 0)) {
    $changes['batch_id'] = $newBatch;
    $changes['batch'] = $batchName;
}

if (!empty($changes)) {
    $desc = [];
    if (isset($changes['serial_number'])) $desc[] = 'Série: ' . ($changes['serial_number'] ?? '—');
    if (isset($changes['mac_address']))   $desc[] = 'MAC: ' . ($changes['mac_address'] ?? '—');
    if (isset($changes['batch']))         $desc[] = 'Lote: ' . ($changes['batch'] ?? '—');

    auditLog('UPDATE', 'equipment', $equipmentId, $old,
        ['serial_number' => $newSerial, 'mac_address' => $newMac, 'batch_id' => $newBatch, 'batch' => $batchName],
        'Edição de campos: ' . implode(', ', $desc));
}

echo json_encode([
    'success' => true,
    'serial_number' => $newSerial,
    'mac_address'   => $newMac,
    'batch'         => $batchName,
    'batch_id'      => $newBatch,
]);
