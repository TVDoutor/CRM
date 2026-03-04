<?php
/**
 * Importação de Equipamentos a partir dos Projetos Pipedrive
 * Board "Pontos de Exibição" (board_id = 8)
 *
 * Para cada projeto aberto extrai:
 *   - asset_tag  → código hex após "|"  (ex: "597C")
 *   - client_code → "P3209" do início do título
 *   - kanban_status → mapeado da phase_id
 *
 * Só insere equipamentos que ainda NÃO existem no banco (by asset_tag).
 * Chamada via POST (browser) ou GET com cron_key.
 */

error_reporting(0);
ob_start();

require_once __DIR__ . '/../../config/pipedrive.php';
require_once __DIR__ . '/../../config/database.php';

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

@set_time_limit(300);

$db          = getDB();
$errors      = [];
$created     = 0;

// ID do usuário que executa a importação (criado_por)
$performedBy = $_SESSION['user_id'] ?? null;
// Fallback: pega o primeiro admin se não houver sessão
if (!$performedBy) {
    $performedBy = $db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
}
$skipped     = 0;
$totalFound  = 0;
$startTime   = microtime(true);

// Mapeamento phase_id → kanban_status
$phaseMap  = PIPEDRIVE_PHASE_MAP;
$modeloMap = PIPE_MODELO_MAP;
$brandMap  = [
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

// Cache model_id por nome
$modelIdCache = [];
foreach ($modeloMap as $enumId => $modelName) {
    $ms = $db->prepare("SELECT id FROM equipment_models WHERE model_name = ? LIMIT 1");
    $ms->execute([$modelName]);
    $mid = $ms->fetchColumn();
    if (!$mid) {
        $brand = $brandMap[$modelName] ?? 'Generico';
        $db->prepare("INSERT INTO equipment_models (brand, model_name, created_at, updated_at) VALUES (?,?,NOW(),NOW())")
           ->execute([$brand, $modelName]);
        $mid = $db->lastInsertId();
    }
    $modelIdCache[$enumId] = (int)$mid;
}

// ── Modelo padrão para importação ─────────────────────────────────────────
// Busca ou cria modelo "OnSign Player" para os equipamentos importados
$ms = $db->prepare("SELECT id FROM equipment_models WHERE model_name = 'OnSign Player' LIMIT 1");
$ms->execute();
$defaultModelId = $ms->fetchColumn() ?: null;

if (!$defaultModelId) {
    // Cria o modelo automaticamente
    try {
        $db->prepare("INSERT INTO equipment_models (brand, model_name, created_at, updated_at) VALUES ('OnSign', 'OnSign Player', NOW(), NOW())")
           ->execute();
        $defaultModelId = $db->lastInsertId();
    } catch (\Exception $me) {
        // Fallback: usa "Generico Outros" (ID 8) ou qualquer modelo existente
        foreach (['Generico Outros', 'Outros'] as $mn) {
            $ms2 = $db->prepare("SELECT id FROM equipment_models WHERE model_name = ? LIMIT 1");
            $ms2->execute([$mn]);
            $defaultModelId = $ms2->fetchColumn() ?: null;
            if ($defaultModelId) break;
        }
        if (!$defaultModelId) {
            $defaultModelId = $db->query("SELECT id FROM equipment_models ORDER BY id ASC LIMIT 1")->fetchColumn();
        }
    }
}

if (!$defaultModelId) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Nenhum modelo cadastrado. Cadastre um modelo antes de importar.']);
    exit;
}

// ── Buscar projetos abertos do board "Pontos de Exibição" (ID 8) ──────────
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

// Fases para nomes (todos os boards ativos)
$phaseNames = [];
foreach (PIPEDRIVE_ACTIVE_BOARD_IDS as $boardId) {
    $phasesResp = pipedriveGet('projects/phases', ['board_id' => $boardId]);
    foreach ($phasesResp['data'] ?? [] as $p) {
        $phaseNames[(int)$p['id']] = $p['name'];
    }
}

$totalFound = count($allProjects);

foreach ($allProjects as $proj) {
    try {
        $title   = trim($proj['title'] ?? '');
        $phaseId = $proj['phase_id'] ? (int)$proj['phase_id'] : null;
        $orgId   = $proj['org_id']   ? (int)$proj['org_id']   : null;

        // ── Extrair asset_tag (código hex 4-6 chars após |, usa últimos 6 do MAC) ─────────────────
        $assetTag = null;
        if (preg_match('/\|\s*([A-F0-9]{4,6})\s*(?:\||$)/i', $title, $am)) {
            $assetTag = macLast6(trim($am[1]));
        } elseif (preg_match('/\|\s*([A-F0-9]{4,6})\s*$/i', $title, $am)) {
            $assetTag = macLast6(trim($am[1]));
        }
        if (!$assetTag && ($macRaw = trim($proj[PIPE_PROJ_MAC] ?? ''))) {
            $assetTag = macLast6(preg_replace('/[^A-F0-9]/i', '', $macRaw));
        }

        if (!$assetTag) {
            $skipped++;
            continue; // sem asset_tag não conseguimos identificar o equipamento
        }

        // ── Extrair client_code ────────────────────────────────────────────
        $clientCode = null;
        if (preg_match('/^(P[\d.]+)\s*[-–]/i', $title, $m)) {
            $clientCode = strtoupper(rtrim(trim($m[1]), '.'));
        }

        // ── kanban_status baseado na fase ──────────────────────────────────
        $kanbanStatus = ($phaseId && isset($phaseMap[$phaseId])) ? $phaseMap[$phaseId] : 'entrada';

        // ── Dados do equipamento via campos customizados ───────────────────
        $modeloEnumId = (int)($proj[PIPE_PROJ_MODELO]      ?? 0);
        $macRaw       = trim($proj[PIPE_PROJ_MAC]           ?? '');
        $lote         = trim($proj[PIPE_PROJ_LOTE]          ?? '');
        $dataCompra   = $proj[PIPE_PROJ_DATA_COMPRA]        ?? null;

        // Normalizar MAC: "90F4 21A3 597C" → "90:F4:21:A3:59:7C"
        $macClean = null;
        if ($macRaw) {
            $hex = strtoupper(preg_replace('/[^A-F0-9]/i', '', $macRaw));
            if (strlen($hex) >= 12) {
                $macClean = implode(':', str_split(substr($hex, 0, 12), 2));
            } else {
                $macClean = $macRaw;
            }
        }

        // Model ID pelo enum do Pipedrive, fallback para "OnSign Player"
        $modelId = $modeloEnumId ? ($modelIdCache[$modeloEnumId] ?? $defaultModelId) : $defaultModelId;

        // ── Vincular cliente ───────────────────────────────────────────────
        $clientId = null;
        if ($clientCode) {
            $cs = $db->prepare('SELECT id FROM clients WHERE client_code = ?');
            $cs->execute([$clientCode]);
            $clientId = $cs->fetchColumn() ?: null;
        }
        if (!$clientId && $orgId) {
            $cs = $db->prepare('SELECT id FROM clients WHERE pipedrive_org_id = ?');
            $cs->execute([$orgId]);
            $clientId = $cs->fetchColumn() ?: null;
        }

        // ── Verificar se equipamento já existe pelo asset_tag ──────────────
        // Prioriza match exato (evita duplicidades), depois sufixo LIKE
        $existExact = $db->prepare("SELECT id FROM equipment WHERE asset_tag = ? LIMIT 1");
        $existExact->execute([$assetTag]);
        if ($existExact->fetchColumn()) { $skipped++; continue; }
        $existLike = $db->prepare("SELECT id FROM equipment WHERE asset_tag LIKE ? LIMIT 1");
        $existLike->execute(['%' . $assetTag]);
        if ($existLike->fetchColumn()) { $skipped++; continue; }

        // ── Nome descritivo extraído do título ─────────────────────────────
        // "P3209 - Âmmi Odontologia | 597C" → "Âmmi Odontologia"
        $description = $title;
        if (preg_match('/^P[\d.]+\s*[-–]\s*(.+?)(?:\s*\|.*)?$/i', $title, $dm)) {
            $description = trim($dm[1]);
        }

        // ── Inserir equipamento ────────────────────────────────────────────
        $entryDate = $proj['start_date'] ?? date('Y-m-d');
        $serialNum = $macClean ? str_replace(':', '', $macClean) : null;

        $db->prepare("INSERT INTO equipment
            (asset_tag, model_id, condition_status, kanban_status,
             contract_type, current_client_id, entry_date, purchase_date,
             mac_address, serial_number, batch,
             notes, created_by, updated_by, created_at, updated_at)
            VALUES (?, ?, 'usado', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
           ->execute([
               $assetTag,
               $modelId,
               $kanbanStatus,
               $clientId ? 'comodato' : null,
               $clientId,
               $entryDate,
               $dataCompra,
               $macClean,
               $serialNum,
               $lote ?: null,
               "Importado do Pipedrive: {$description}",
               $performedBy,
               $performedBy,
           ]);

        $eqId = $db->lastInsertId();

        // Se tinha cliente, registra saída
        if ($clientId) {
            try {
                $db->prepare("INSERT INTO operations
                    (equipment_id, client_id, operation_type, notes, created_at)
                    VALUES (?, ?, 'saida', 'Alocado via importação Pipedrive', NOW())")
                   ->execute([$eqId, $clientId]);

                $db->prepare("UPDATE equipment SET current_client_id = ? WHERE id = ?")
                   ->execute([$clientId, $eqId]);
            } catch (\Exception $oe) { /* ignora se tabela ops não existir */ }
        }

        $created++;

    } catch (\Exception $e) {
        $errors[] = "Projeto [{$proj['title']}]: " . $e->getMessage();
    }
}

$duration = (int)((microtime(true) - $startTime) * 1000);

$result = [
    'success'     => empty($errors) || $created > 0,
    'total_found' => $totalFound,
    'created'     => $created,
    'skipped'     => $skipped,
    'errors'      => array_slice($errors, 0, 10),
    'duration'    => $duration . 'ms',
    'message'     => "{$created} equipamentos importados, {$skipped} ignorados (já existentes ou sem asset_tag).",
];

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
