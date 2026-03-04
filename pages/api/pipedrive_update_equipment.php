<?php
/**
 * Atualização em massa dos equipamentos importados do Pipedrive
 * Corrige: modelo, MAC, lote, data de compra, serial (MAC sem espaços)
 * Lê os projetos do board 8 e atualiza os equipamentos pelo asset_tag
 */

error_reporting(0);
ob_start();

require_once __DIR__ . '/../../config/pipedrive.php';

$isCronUrl = isset($_GET['cron_key']) && $_GET['cron_key'] === PIPEDRIVE_CRON_KEY;
$isManual  = !$isCronUrl;

if ($isManual) {
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/helpers.php';
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    requireLogin();
    requireRole(['admin', 'manager']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Use POST.']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($input['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
        exit;
    }
} else {
    require_once __DIR__ . '/../../includes/helpers.php';
}

@set_time_limit(600);

$db         = getDB();
$updated    = 0;
$skipped    = 0;
$errors     = [];
$totalFound = 0;
$startTime  = microtime(true);
$performedBy = $_SESSION['user_id'] ?? $db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn();

$modeloMap    = PIPE_MODELO_MAP;
$fornecedorMap = PIPE_FORNECEDOR_MAP;

// ── Garantir modelos no banco ──────────────────────────────────────────────
// Mapeamento: nome do modelo → marca
$brandMap = [
    'Amlogic K95W'          => 'Amlogic',
    'Amlogic K3 PRO'        => 'Amlogic',
    'AQUARIO STV-2000'      => 'Aquario',
    'AQUARIO STV-3000'      => 'Aquario',
    'Computador'            => 'Generico',
    'Smart TV'              => 'Generico',
    'Monitor LG'            => 'LG',
    'Proeletronic PROSB3000'=> 'Proeletronic',
    'Proeletronic PROSB5000'=> 'Proeletronic',
    'Outros'                => 'Generico',
];

// Cache de model_id por nome
$modelIdCache = [];
foreach ($modeloMap as $enumId => $modelName) {
    $stmt = $db->prepare("SELECT id FROM equipment_models WHERE model_name = ? LIMIT 1");
    $stmt->execute([$modelName]);
    $mid = $stmt->fetchColumn();
    if (!$mid) {
        $brand = $brandMap[$modelName] ?? 'Generico';
        $db->prepare("INSERT INTO equipment_models (brand, model_name, created_at, updated_at) VALUES (?,?,NOW(),NOW())")
           ->execute([$brand, $modelName]);
        $mid = $db->lastInsertId();
    }
    $modelIdCache[$enumId] = (int)$mid;
}

// ── Buscar todos os projetos abertos do board 8 ────────────────────────────
$allProjects = [];
$cursor = null;
do {
    $params = ['limit' => 100, 'status' => 'open'];
    if ($cursor) $params['cursor'] = $cursor;
    $resp = pipedriveGet('projects', $params);
    if (!($resp['success'] ?? false)) {
        $errors[] = "Erro API: " . ($resp['error'] ?? 'Falha');
        break;
    }
    foreach ($resp['data'] ?? [] as $proj) {
        if (in_array((int)($proj['board_id'] ?? 0), PIPEDRIVE_ACTIVE_BOARD_IDS)) {
            $allProjects[] = $proj;
        }
    }
    $cursor = $resp['additional_data']['next_cursor'] ?? null;
} while ($cursor);

$totalFound = count($allProjects);

// ── Processar cada projeto ─────────────────────────────────────────────────
foreach ($allProjects as $proj) {
    try {
        $title = trim($proj['title'] ?? '');

        // Extrair asset_tag (4-6 chars hex após |, últimos 6 do MAC)
        $assetTag = null;
        if (preg_match('/\|\s*([A-F0-9]{4,6})\s*(?:\||$)/i', $title, $am)) {
            $assetTag = macLast6(trim($am[1]));
        } elseif (preg_match('/\|\s*([A-F0-9]{4,6})\s*$/i', $title, $am)) {
            $assetTag = macLast6(trim($am[1]));
        }
        if (!$assetTag && ($macRawTmp = trim($proj[PIPE_PROJ_MAC] ?? ''))) {
            $assetTag = macLast6(preg_replace('/[^A-F0-9]/i', '', $macRawTmp));
        }

        if (!$assetTag) { $skipped++; continue; }

        // Dados dos campos customizados
        $modeloEnumId = (int)($proj[PIPE_PROJ_MODELO] ?? 0);
        $macRaw       = trim($proj[PIPE_PROJ_MAC]         ?? '');
        $lote         = trim($proj[PIPE_PROJ_LOTE]        ?? '');
        $dataCompra   = $proj[PIPE_PROJ_DATA_COMPRA]      ?? null;

        // Normalizar MAC: "90F4 21A3 597C" → "90:F4:21:A3:59:7C"
        $macClean = null;
        if ($macRaw) {
            $hex = strtoupper(preg_replace('/[^A-F0-9]/i', '', $macRaw));
            if (strlen($hex) >= 12) {
                $macClean = implode(':', str_split(substr($hex, 0, 12), 2));
            } else {
                $macClean = $macRaw; // mantém como veio se não for 12 hex
            }
        }

        // Model ID
        $modelId = $modeloEnumId ? ($modelIdCache[$modeloEnumId] ?? null) : null;

        // Busca equipamento pelo asset_tag
        $eqStmt = $db->prepare("SELECT id, model_id, mac_address, batch, purchase_date FROM equipment WHERE asset_tag = ? LIMIT 1");
        $eqStmt->execute([$assetTag]);
        $eq = $eqStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$eq) {
            // Tenta busca parcial (últimos 6 dígitos)
            $eqStmt2 = $db->prepare("SELECT id, model_id, mac_address, batch, purchase_date FROM equipment WHERE asset_tag LIKE ? LIMIT 1");
            $eqStmt2->execute(['%' . $assetTag]);
            $eq = $eqStmt2->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$eq) { $skipped++; continue; }

        // Monta UPDATE apenas com campos que têm valor no Pipedrive
        $sets   = ['updated_by = ?', 'updated_at = NOW()'];
        $values = [$performedBy];

        if ($modelId) {
            $sets[]   = 'model_id = ?';
            $values[] = $modelId;
        }
        if ($macClean) {
            $sets[]   = 'mac_address = ?';
            $values[] = $macClean;
            // Serial number = MAC sem separadores
            $sets[]   = 'serial_number = ?';
            $values[] = str_replace(':', '', $macClean);
        }
        if ($lote) {
            $sets[]   = 'batch = ?';
            $values[] = $lote;
        }
        if ($dataCompra) {
            $sets[]   = 'purchase_date = ?';
            $values[] = $dataCompra;
        }

        $values[] = $eq['id'];
        $db->prepare("UPDATE equipment SET " . implode(', ', $sets) . " WHERE id = ?")
           ->execute($values);

        $updated++;

    } catch (\Exception $e) {
        $errors[] = "Projeto [{$proj['title']}]: " . $e->getMessage();
    }
}

$duration = (int)((microtime(true) - $startTime) * 1000);

$result = [
    'success'     => empty($errors) || $updated > 0,
    'total_found' => $totalFound,
    'updated'     => $updated,
    'skipped'     => $skipped,
    'errors'      => array_slice($errors, 0, 10),
    'duration'    => $duration . 'ms',
    'message'     => "{$updated} equipamentos atualizados com modelo, MAC e dados corretos.",
];

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
