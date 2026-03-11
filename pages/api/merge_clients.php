<?php
/**
 * Mescla dois clientes: transfere equipamentos, histórico e operações
 * do cliente origem para o destino; desativa o cliente origem.
 * POST: source_id, target_id
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin', 'manager']);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido']);
    exit;
}

csrfValidate();

$input = $_POST;
$sourceId = (int)($input['source_id'] ?? 0);
$targetId = (int)($input['target_id'] ?? 0);

if (!$sourceId || !$targetId) {
    echo json_encode(['ok' => false, 'error' => 'IDs do cliente origem e destino são obrigatórios.']);
    exit;
}

if ($sourceId === $targetId) {
    echo json_encode(['ok' => false, 'error' => 'Cliente origem e destino devem ser diferentes.']);
    exit;
}

$db = getDB();

$src = $db->prepare('SELECT id, name, client_code, notes FROM clients WHERE id = ?');
$src->execute([$sourceId]);
$source = $src->fetch();

$tgt = $db->prepare('SELECT id, name, client_code FROM clients WHERE id = ?');
$tgt->execute([$targetId]);
$target = $tgt->fetch();

if (!$source || !$target) {
    echo json_encode(['ok' => false, 'error' => 'Cliente origem ou destino não encontrado.']);
    exit;
}

try {
    $db->beginTransaction();

    // 1. Equipamentos
    $stmtEq = $db->prepare('UPDATE equipment SET current_client_id = ?, updated_by = ? WHERE current_client_id = ?');
    $stmtEq->execute([$targetId, $_SESSION['user_id'], $sourceId]);
    $eqCount = $stmtEq->rowCount();

    // 2. Histórico Kanban
    $db->prepare('UPDATE kanban_history SET client_id = ? WHERE client_id = ?')->execute([$targetId, $sourceId]);

    // 3. Operações (entrada/saída/retorno)
    $db->prepare('UPDATE equipment_operations SET client_id = ? WHERE client_id = ?')->execute([$targetId, $sourceId]);

    // 4. Projetos Pipedrive
    try {
        $db->prepare('UPDATE pipedrive_projects SET client_id = ? WHERE client_id = ?')->execute([$targetId, $sourceId]);
    } catch (Exception $e) {
        // Tabela pode não existir
    }

    // 5. Desativar cliente origem e marcar como mesclado
    $mergeNote = "\n[Mesclado em {$target['client_code']} em " . date('Y-m-d H:i') . ']';
    $notesVal = ($source['notes'] ?? '') . $mergeNote;
    $db->prepare('UPDATE clients SET is_active = 0, notes = ? WHERE id = ?')->execute([$notesVal, $sourceId]);

    auditLog('MERGE', 'client', $sourceId,
        ['name' => $source['name'], 'client_code' => $source['client_code'], 'equipment_moved' => $eqCount],
        ['merged_into' => $targetId, 'target_name' => $target['name']],
        "Cliente {$source['name']} ( {$source['client_code']} ) mesclado em {$target['name']} ( {$target['client_code']} ). $eqCount equipamento(s) transferidos."
    );

    $db->commit();

    echo json_encode([
        'ok'    => true,
        'msg'   => "Cliente \"{$source['name']}\" mesclado em \"{$target['name']}\". $eqCount equipamento(s) transferidos. O cliente original foi desativado.",
        'redirect' => '/pages/clients/view.php?code=' . urlencode($target['client_code']),
    ]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Erro ao mesclar: ' . $e->getMessage()]);
}
