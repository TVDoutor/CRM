<?php
/**
 * Sincronização de Projetos Pipedrive → CRM (paginada por cursor)
 * Cada chamada processa 30 projetos e retorna cursor para a próxima.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ignore_user_abort(true);
@set_time_limit(60);

// Garante JSON mesmo em erro fatal
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false,
            'message' => 'PHP fatal: ' . $err['message'] . ' [' . basename($err['file']) . ':' . $err['line'] . ']']);
    }
});

require_once __DIR__ . '/../../config/pipedrive.php';

$cronKey = $_GET['cron_key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$isCronUrl = ($cronKey === PIPEDRIVE_CRON_KEY);
$isCron    = $isCronUrl;
$isManual  = !$isCron;

if ($isManual) {
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/helpers.php';
    header('Content-Type: application/json; charset=utf-8');
    requireLogin();
    requireRole(['admin', 'manager']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Use POST.']); exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($input['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']); exit;
    }
} else {
    require_once __DIR__ . '/../../includes/helpers.php';
    $input = [];
}

// ── Migração: garante colunas no servidor ─────────────────────────────────
$db = getDB();
try {
    $existCols = [];
    foreach ($db->query("SHOW COLUMNS FROM pipedrive_projects")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $existCols[] = $c['Field'];
    }
    if (!in_array('asset_tag',   $existCols)) $db->exec("ALTER TABLE pipedrive_projects ADD COLUMN `asset_tag` VARCHAR(20) DEFAULT NULL");
    if (!in_array('mac_address', $existCols)) $db->exec("ALTER TABLE pipedrive_projects ADD COLUMN `mac_address` VARCHAR(30) DEFAULT NULL");
    // Garante índice único em pipedrive_id para o UPSERT funcionar
    $idxRows = $db->query("SHOW INDEX FROM pipedrive_projects WHERE Key_name = 'pipedrive_id'")->fetchAll();
    if (empty($idxRows)) {
        try { $db->exec("ALTER TABLE pipedrive_projects ADD UNIQUE KEY `uq_pipedrive_id` (`pipedrive_id`)"); } catch (\Exception $ie) {}
    }
} catch (\Exception $me) {
    // Tabela pode não existir ainda
}

$startTime   = microtime(true);
$performedBy = $isManual ? ($_SESSION['user_id'] ?? null) : null;

// Parâmetros de paginação
$cursor     = $input['cursor']      ?? null;
$pipeStatus = $input['pipe_status'] ?? 'open';

// Contadores acumulados vindos do frontend
$accCreated = (int)($input['acc_created']        ?? 0);
$accUpdated = (int)($input['acc_updated']        ?? 0);
$accKanban  = (int)($input['acc_kanban_updated'] ?? 0);
$accTotal   = (int)($input['acc_total']          ?? 0);
$errors     = (array)($input['acc_errors']       ?? []);

$statusSequence = ['open', 'completed', 'canceled'];

// Fases hardcodadas
$phaseNames = [
    31=>'Novas Solicitações', 32=>'Contato e Analise', 33=>'Oferta Recuperação',
    34=>'Período 60 Dias', 35=>'Cliente Recuperado', 38=>'Processo Devolução',
    50=>'Entrada', 54=>'Pontos Ativos Onsign', 55=>'Processo de Cancelamento',
    57=>'Offline +31 Dias', 60=>'Cadastro', 61=>'Planejamento', 62=>'Onboarding',
    63=>'30 dias - Ativação & Conteúdo', 64=>'Avaliação Onboarding',
    65=>'Plano Personalizado', 66=>'Plano S/Personalizado', 70=>'Suspensão Temporária',
    76=>'60 dias', 77=>'90 dias', 78=>'Em Veiculação', 79=>'Finalizado',
    90=>'Sazonais', 93=>'Entrada Jornada', 94=>'Prototipação', 95=>'Beta Teste I',
    97=>'Beta Teste II', 98=>'Em Desenvolvimento', 100=>'Entregue',
    101=>'Análise e Pré Veiculação', 103=>'Entrada | Solicitação',
    106=>'Encaminhado ao CS | Sem Licença',
];
$phaseMap = PIPEDRIVE_PHASE_MAP;

// Pré-carregar clientes em memória (1 query total)
$clientByCode  = [];
$clientByOrgId = [];
try {
    foreach ($db->query("SELECT id, client_code, pipedrive_org_id FROM clients WHERE is_active = 1")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        if ($c['client_code'])       $clientByCode[strtoupper($c['client_code'])] = (int)$c['id'];
        if ($c['pipedrive_org_id'])  $clientByOrgId[(int)$c['pipedrive_org_id']] = (int)$c['id'];
    }
} catch (\Exception $e) {}

// ── Buscar página de projetos ─────────────────────────────────────────────
$params = ['limit' => 30, 'status' => $pipeStatus];
if ($cursor) $params['cursor'] = $cursor;

$resp = pipedriveGet('projects', $params);
if (!($resp['success'] ?? false)) {
    echo json_encode(['success' => false,
        'message' => "Erro ao buscar projetos ($pipeStatus): " . ($resp['error'] ?? 'Falha na API')]);
    exit;
}

$pageProjects = $resp['data'] ?? [];
$nextCursor   = $resp['additional_data']['next_cursor'] ?? null;

$pageCreated = 0; $pageUpdated = 0; $pageKanban = 0;

// Statements reutilizáveis para equipamento
// Matching melhorado: 1) exato (asset_tag = XXXX) 2) sufixo (LIKE %XXXX) preferindo tags mais curtos
$stmtEqExact = $db->prepare("SELECT id, kanban_status, current_client_id FROM equipment WHERE asset_tag = ? LIMIT 1");
$stmtEqLike  = $db->prepare("SELECT id, kanban_status, current_client_id FROM equipment WHERE asset_tag LIKE ? ORDER BY LENGTH(asset_tag) ASC LIMIT 1");
$stmtUpdEq   = $db->prepare("UPDATE equipment SET kanban_status = ? WHERE id = ?");

foreach ($pageProjects as $proj) {
    if (!in_array((int)($proj['board_id'] ?? 0), PIPEDRIVE_ACTIVE_BOARD_IDS)) continue;

    try {
        $pipeId      = (int)$proj['id'];
        $title       = trim($proj['title'] ?? '');
        $status      = $proj['status']      ?? 'open';
        $orgId       = $proj['org_id']      ? (int)$proj['org_id']   : null;
        $phaseId     = $proj['phase_id']    ? (int)$proj['phase_id'] : null;
        $boardId     = $proj['board_id']    ? (int)$proj['board_id'] : null;
        $startDt     = $proj['start_date']  ?? null;
        $endDt       = $proj['end_date']    ?? null;
        $ownerId     = $proj['owner_id']    ? (int)$proj['owner_id'] : null;
        $desc        = $proj['description'] ?? null;
        $labels      = !empty($proj['labels']) ? implode(',', (array)$proj['labels']) : null;
        $macRaw      = trim($proj[PIPE_PROJ_MAC] ?? '');

        // client_code do título
        $clientCode = null;
        if (preg_match('/^(P[\d.]+)\s*[-–]/i', $title, $m)) {
            $clientCode = strtoupper(rtrim(trim($m[1]), '.'));
        }

        // asset_tag: 1) título; 2) campo MAC — usa últimos 6 dígitos hex
        $assetTag = null;
        if (preg_match('/\|([^|]+)$/', $title, $am)) {
            $hex = preg_replace('/[^A-F0-9]/i', '', trim($am[1]));
            if (strlen($hex) >= 4) $assetTag = macLast6($hex);
        }
        if (!$assetTag && $macRaw !== '') {
            $hex = preg_replace('/[^A-F0-9]/i', '', $macRaw);
            if (strlen($hex) >= 4) $assetTag = macLast6($hex);
        }

        $phaseName    = $phaseNames[$phaseId] ?? null;
        $kanbanStatus = $phaseId ? ($phaseMap[$phaseId] ?? null) : null;

        // Resolver cliente (memória)
        // Tenta: 1) exato (P2857.1); 2) base sem subíndice (P2857); 3) org_id
        $clientId = null;
        if ($clientCode) {
            if (isset($clientByCode[$clientCode])) {
                $clientId = $clientByCode[$clientCode];
            } else {
                // Fallback: P2857.1 → tenta P2857
                $base = preg_replace('/\.\d+$/', '', $clientCode);
                if ($base !== $clientCode && isset($clientByCode[$base])) {
                    $clientId = $clientByCode[$base];
                }
            }
        }
        if (!$clientId && $orgId && isset($clientByOrgId[$orgId])) {
            $clientId = $clientByOrgId[$orgId];
        }

        // Atualizar Kanban + cliente (só open)
        // O cliente vem do projeto Pipedrive (client_code no título) — garante que o CRM reflita o de-para
        // Matching: prioriza exato (evita duplicidades), depois LIKE com preferência por tag mais curto
        if ($assetTag && $kanbanStatus && $status === 'open') {
            $stmtEqExact->execute([$assetTag]);
            $eq = $stmtEqExact->fetch(\PDO::FETCH_ASSOC);
            if (!$eq) {
                $stmtEqLike->execute(['%' . $assetTag]);
                $eq = $stmtEqLike->fetch(\PDO::FETCH_ASSOC);
            }
            if ($eq) {
                $needUpdate = ($eq['kanban_status'] !== $kanbanStatus);
                $needClientUpdate = ($clientId !== null && (int)($eq['current_client_id'] ?? 0) !== $clientId);

                if ($needUpdate || $needClientUpdate) {
                    $uid = $performedBy ?? (int)$db->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
                    if ($needUpdate) $stmtUpdEq->execute([$kanbanStatus, $eq['id']]);
                    if ($needClientUpdate) {
                        $db->prepare("UPDATE equipment SET current_client_id=?, updated_by=? WHERE id=?")
                           ->execute([$clientId, $uid, $eq['id']]);
                    }
                    // Registrar em kanban_history para o Histórico de Clientes e Locais
                    if ($needClientUpdate || $needUpdate) {
                        $pageKanban++;
                        try {
                            $toStatus = $needUpdate ? $kanbanStatus : $eq['kanban_status'];
                            $db->prepare("INSERT INTO kanban_history (equipment_id, from_status, to_status, client_id, moved_by, notes)
                                VALUES (?,?,?,?,?,?)")
                               ->execute([$eq['id'], $eq['kanban_status'], $toStatus, $clientId, $uid, "Sync Pipedrive (fase: {$phaseName})"]);
                            auditLog('kanban_move', 'equipment', (int)$eq['id'],
                                ['kanban_status' => $eq['kanban_status'], 'client_id' => $eq['current_client_id']],
                                ['kanban_status' => $toStatus, 'client_id' => $clientId],
                                "Sync Pipedrive (fase: {$phaseName})");
                        } catch (\Exception $ae) {}
                    }
                }
            }
        }

        // ── UPSERT simples: INSERT ... ON DUPLICATE KEY UPDATE ─────────────
        // Usa apenas colunas que existem em 100% das instalações.
        // mac_address é tentado; se falhar, usa versão sem ela.
        $upsertOk = false;
        try {
            $db->prepare("INSERT INTO pipedrive_projects
                (pipedrive_id, title, client_code, asset_tag, mac_address,
                 client_id, pipedrive_org_id, board_id, phase_id, phase_name,
                 status, start_date, end_date, description, labels, owner_id,
                 pipedrive_synced_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                    title=VALUES(title), client_code=VALUES(client_code),
                    asset_tag=VALUES(asset_tag), mac_address=VALUES(mac_address),
                    client_id=VALUES(client_id), pipedrive_org_id=VALUES(pipedrive_org_id),
                    board_id=VALUES(board_id), phase_id=VALUES(phase_id),
                    phase_name=VALUES(phase_name), status=VALUES(status),
                    start_date=VALUES(start_date), end_date=VALUES(end_date),
                    description=VALUES(description), labels=VALUES(labels),
                    owner_id=VALUES(owner_id), pipedrive_synced_at=NOW()")
               ->execute([$pipeId,$title,$clientCode,$assetTag,$macRaw?:null,
                          $clientId,$orgId,$boardId,$phaseId,$phaseName,
                          $status,$startDt,$endDt,$desc,$labels,$ownerId]);
            $upsertOk = true;
        } catch (\Exception $e1) {}

        // Fallback sem mac_address
        if (!$upsertOk) {
            $db->prepare("INSERT INTO pipedrive_projects
                (pipedrive_id, title, client_code, asset_tag,
                 client_id, pipedrive_org_id, board_id, phase_id, phase_name,
                 status, start_date, end_date, description, labels, owner_id,
                 pipedrive_synced_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                    title=VALUES(title), client_code=VALUES(client_code),
                    asset_tag=VALUES(asset_tag),
                    client_id=VALUES(client_id), pipedrive_org_id=VALUES(pipedrive_org_id),
                    board_id=VALUES(board_id), phase_id=VALUES(phase_id),
                    phase_name=VALUES(phase_name), status=VALUES(status),
                    start_date=VALUES(start_date), end_date=VALUES(end_date),
                    description=VALUES(description), labels=VALUES(labels),
                    owner_id=VALUES(owner_id), pipedrive_synced_at=NOW()")
               ->execute([$pipeId,$title,$clientCode,$assetTag,
                          $clientId,$orgId,$boardId,$phaseId,$phaseName,
                          $status,$startDt,$endDt,$desc,$labels,$ownerId]);
        }

        // rowCount=1 INSERT novo, rowCount=2 UPDATE, rowCount=0 sem mudança
        $pageUpdated++;

    } catch (\Exception $e) {
        $errors[] = "#{$proj['id']}: " . substr($e->getMessage(), 0, 100);
    }
}

$accUpdated += $pageUpdated;
$accKanban  += $pageKanban;
$accTotal   += count($pageProjects);

// Próxima chamada
$done = false; $nextPipeStatus = $pipeStatus;
if ($nextCursor) {
    // continua no mesmo status
} else {
    $idx = array_search($pipeStatus, $statusSequence);
    if ($idx !== false && isset($statusSequence[$idx + 1])) {
        $nextPipeStatus = $statusSequence[$idx + 1];
        $nextCursor     = null;
    } else {
        $done = true;
    }
}

$duration = (int)((microtime(true) - $startTime) * 1000);

// Log ao concluir
if ($done) {
    $logStatus = empty($errors) ? 'success' : (count($errors) < 5 ? 'partial' : 'error');
    try {
        $db->prepare("INSERT INTO pipedrive_projects_sync_log
            (status,total_found,created,updated,errors,duration_ms,performed_by)
            VALUES (?,?,?,?,?,?,?)")
           ->execute([$logStatus, $accTotal, $accCreated, $accUpdated,
               empty($errors) ? null : implode("\n", array_slice($errors, 0, 20)),
               $duration, $performedBy]);
    } catch (\Exception $e) {}
}

echo json_encode([
    'success'            => true,
    'done'               => $done,
    'pipe_status'        => $nextPipeStatus,
    'cursor'             => $nextCursor,
    'acc_created'        => $accCreated,
    'acc_updated'        => $accUpdated,
    'acc_kanban_updated' => $accKanban,
    'acc_total'          => $accTotal,
    'acc_errors'         => array_slice($errors, 0, 20),
    'page_processed'     => count($pageProjects),
    'duration'           => $duration . 'ms',
], JSON_UNESCAPED_UNICODE);
