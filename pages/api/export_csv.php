<?php
/**
 * Exporta qualquer relatório como CSV.
 * GET ?report=stock|entries_exits|by_equipment|by_client|by_user
 * + mesmos parâmetros de filtro de cada relatório.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$report = trim($_GET['report'] ?? '');

$db = getDB();

function csvRow(array $cols): string {
    return implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $cols)) . "\r\n";
}

function safeFilename(string $raw): string {
    $safe = preg_replace('/[^\p{L}\p{N}\-_\.]/u', '_', $raw);
    return substr($safe, 0, 100) ?: 'export';
}

function sendCsv(string $filename, array $headers, array $rows): void {
    $safe = safeFilename(pathinfo($filename, PATHINFO_FILENAME)) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Cache-Control: no-cache');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel
    echo csvRow($headers);
    foreach ($rows as $r) echo csvRow($r);
    exit;
}

// ── Estoque Atual ──────────────────────────────────────────────────────────
if ($report === 'stock') {
    requireRole(['admin','manager','user']);
    $condition = trim($_GET['condition_status'] ?? '');
    $modelId   = (int)($_GET['model_id'] ?? 0);
    $batch     = trim($_GET['batch'] ?? '');

    $where  = ["e.kanban_status = 'entrada'"];
    $params = [];
    if ($condition) { $where[] = 'e.condition_status = ?'; $params[] = $condition; }
    if ($modelId)   { $where[] = 'e.model_id = ?';         $params[] = $modelId; }
    if ($batch)     { $where[] = 'e.batch LIKE ?';         $params[] = "%$batch%"; }

    $stmt = $db->prepare("SELECT e.asset_tag, em.brand, em.model_name, e.serial_number, e.mac_address,
        e.condition_status, e.kanban_status, e.batch, e.entry_date, e.purchase_date
    FROM equipment e JOIN equipment_models em ON em.id = e.model_id
    WHERE " . implode(' AND ', $where) . " ORDER BY em.brand, em.model_name");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $data = array_map(fn($r) => [
        $r['asset_tag'], $r['brand'] . ' ' . $r['model_name'],
        $r['serial_number'] ?? '', $r['mac_address'] ?? '',
        $r['condition_status'] === 'novo' ? 'Novo' : 'Usado',
        kanbanLabel($r['kanban_status']),
        $r['batch'] ?? '', formatDate($r['entry_date']), formatDate($r['purchase_date']),
    ], $rows);

    sendCsv('estoque_' . date('Ymd') . '.csv',
        ['Etiqueta','Modelo','S/N','MAC','Condição','Status','Lote','Data Entrada','Data Compra'],
        $data);
}

// ── Entradas e Saídas ──────────────────────────────────────────────────────
if ($report === 'entries_exits') {
    requireRole(['admin','manager','user']);
    $dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
    $dateTo   = trim($_GET['date_to']   ?? date('Y-m-d'));
    $type     = trim($_GET['type']      ?? '');

    $where  = ["eo.operation_date BETWEEN ? AND ?", "eo.operation_type IN ('ENTRADA','SAIDA','RETORNO')"];
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    if ($type) { $where[] = 'eo.operation_type = ?'; $params[] = $type; }

    $stmt = $db->prepare("SELECT eo.operation_date, eo.operation_type, c.name as client_name,
        COUNT(eoi.id) as qty,
        GROUP_CONCAT(e.asset_tag ORDER BY e.asset_tag SEPARATOR '; ') as tags,
        u.name as performed_by, eo.notes
    FROM equipment_operations eo
    LEFT JOIN clients c ON c.id = eo.client_id
    JOIN users u ON u.id = eo.performed_by
    LEFT JOIN equipment_operation_items eoi ON eoi.operation_id = eo.id
    LEFT JOIN equipment e ON e.id = eoi.equipment_id
    WHERE " . implode(' AND ', $where) . " GROUP BY eo.id ORDER BY eo.operation_date DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $typeLabel = ['ENTRADA'=>'Entrada','SAIDA'=>'Saída','RETORNO'=>'Devolução'];
    $data = array_map(fn($r) => [
        formatDate($r['operation_date'], true),
        $typeLabel[$r['operation_type']] ?? $r['operation_type'],
        $r['client_name'] ?? '—', (int)$r['qty'],
        $r['tags'] ?? '', $r['performed_by'], $r['notes'] ?? '',
    ], $rows);

    sendCsv('entradas_saidas_' . date('Ymd') . '.csv',
        ['Data/Hora','Tipo','Cliente','Qtd Equipamentos','Etiquetas','Responsável','Observações'],
        $data);
}

// ── Histórico por Equipamento ──────────────────────────────────────────────
if ($report === 'by_equipment') {
    requireRole(['admin','manager','user']);
    $search = trim($_GET['search'] ?? '');
    if (!$search) { http_response_code(400); echo 'Informe o parâmetro search.'; exit; }

    $stmt = $db->prepare("SELECT e.asset_tag, em.brand, em.model_name, e.serial_number,
        kh.from_status, kh.to_status, kh.notes, kh.moved_at, u.name as moved_by, c.name as client_name
    FROM equipment e
    JOIN equipment_models em ON em.id = e.model_id
    JOIN kanban_history kh ON kh.equipment_id = e.id
    JOIN users u ON u.id = kh.moved_by
    LEFT JOIN clients c ON c.id = kh.client_id
    WHERE e.asset_tag = ? OR e.serial_number = ?
    ORDER BY kh.moved_at ASC");
    $stmt->execute([$search, $search]);
    $rows = $stmt->fetchAll();

    $data = array_map(fn($r) => [
        $r['asset_tag'], $r['brand'] . ' ' . $r['model_name'], $r['serial_number'] ?? '',
        $r['from_status'] ? kanbanLabel($r['from_status']) : 'Cadastrado',
        kanbanLabel($r['to_status']),
        $r['client_name'] ?? '—',
        formatDate($r['moved_at'], true), $r['moved_by'], $r['notes'] ?? '',
    ], $rows);

    sendCsv('historico_' . safeFilename($search) . '_' . date('Ymd') . '.csv',
        ['Etiqueta','Modelo','S/N','De','Para','Cliente','Data/Hora','Responsável','Observação'],
        $data);
}

// ── Relatório por Cliente ──────────────────────────────────────────────────
if ($report === 'by_client') {
    requireRole(['admin','manager','user']);
    $search = trim($_GET['search'] ?? '');
    if (!$search) { http_response_code(400); echo 'Informe o parâmetro search.'; exit; }

    $stmt = $db->prepare("SELECT * FROM clients WHERE client_code = ? OR name LIKE ? LIMIT 1");
    $stmt->execute([$search, "%$search%"]);
    $client = $stmt->fetch();
    if (!$client) { http_response_code(404); echo 'Cliente não encontrado.'; exit; }

    $histStmt = $db->prepare("SELECT DISTINCT e.asset_tag, em.brand, em.model_name,
        MIN(kh.moved_at) as first_allocation,
        MAX(CASE WHEN kh.to_status != 'alocado' THEN kh.moved_at END) as returned_at
    FROM kanban_history kh
    JOIN equipment e ON e.id = kh.equipment_id
    JOIN equipment_models em ON em.id = e.model_id
    WHERE kh.client_id = ?
    GROUP BY e.id ORDER BY first_allocation DESC");
    $histStmt->execute([$client['id']]);
    $rows = $histStmt->fetchAll();

    $data = array_map(fn($r) => [
        $r['asset_tag'], $r['brand'] . ' ' . $r['model_name'],
        formatDate($r['first_allocation'], true),
        $r['returned_at'] ? formatDate($r['returned_at'], true) : 'Alocado',
    ], $rows);

    sendCsv('cliente_' . safeFilename($client['client_code'] ?? '') . '_' . date('Ymd') . '.csv',
        ['Etiqueta','Modelo','1ª Alocação','Devolução'],
        $data);
}

// ── Auditoria por Usuário ──────────────────────────────────────────────────
if ($report === 'by_user') {
    requireRole(['admin']);
    $userId   = (int)($_GET['user_id']   ?? 0);
    $dateFrom = trim($_GET['date_from']  ?? date('Y-m-01'));
    $dateTo   = trim($_GET['date_to']    ?? date('Y-m-d'));
    $action   = trim($_GET['action']     ?? '');

    $where  = ['al.created_at BETWEEN ? AND ?'];
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    if ($userId) { $where[] = 'al.user_id = ?';  $params[] = $userId; }
    if ($action) { $where[] = 'al.action = ?';   $params[] = $action; }

    $stmt = $db->prepare("SELECT al.created_at, u.name as user_name, al.action, al.entity_type,
        al.entity_id, e.asset_tag, al.description, al.ip_address
    FROM audit_log al
    JOIN users u ON u.id = al.user_id
    LEFT JOIN equipment e ON e.id = al.entity_id AND al.entity_type = 'equipment'
    WHERE " . implode(' AND ', $where) . " ORDER BY al.created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $data = array_map(fn($r) => [
        formatDate($r['created_at'], true), $r['user_name'], $r['action'],
        $r['entity_type'], $r['asset_tag'] ?? ($r['entity_id'] ?? '—'),
        $r['description'] ?? '', $r['ip_address'] ?? '',
    ], $rows);

    sendCsv('auditoria_' . date('Ymd') . '.csv',
        ['Data/Hora','Usuário','Ação','Entidade','ID/Etiqueta','Descrição','IP'],
        $data);
}

http_response_code(400);
echo json_encode(['error' => 'Relatório inválido. Use: stock, entries_exits, by_equipment, by_client, by_user']);
