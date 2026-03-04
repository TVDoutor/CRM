<?php
/**
 * pipedrive_push.php
 * Funções de push CRM → Pipedrive (sincronização bidirecional).
 *
 * Requer que config/pipedrive.php já tenha sido incluído (pipedrivePost, pipedrivePut, etc.).
 */

if (!function_exists('pipedrivePost')) {
    require_once __DIR__ . '/../config/pipedrive.php';
}
if (!function_exists('getDB')) {
    require_once __DIR__ . '/auth.php';
}
if (!function_exists('macLast6')) {
    require_once __DIR__ . '/helpers.php';
}

// ── Constantes de push ────────────────────────────────────────────────────────

// Desativa todas as atualizações CRM → Pipedrive (push)
define('PIPE_PUSH_ENABLED', false);

// Board ID e ID do owner padrão (usuário proprietário dos projetos criados pelo CRM)
// owner_id = 0 significa que o Pipedrive usará o dono da API key
define('PIPE_PUSH_BOARD_ID', 8);
define('PIPE_PUSH_OWNER_ID', 0); // substitua pelo ID do usuário Pipedrive se necessário

// ── Funções utilitárias ───────────────────────────────────────────────────────

/**
 * Monta o título do projeto no formato padrão:
 *   "P3209 - Nome do Cliente | XXXXXX"
 * onde XXXXXX = últimos 6 dígitos do MAC/asset_tag (maiúsculos).
 */
function pipeBuildTitle(string $clientCode, string $clientName, string $assetTag): string
{
    $suffix = macLast6($assetTag);
    return "{$clientCode} - {$clientName} | {$suffix}";
}

/**
 * Resolve o enum ID do modelo no Pipedrive a partir do model_name do CRM.
 * Retorna null se não encontrar mapeamento.
 */
function pipeResolveModelId(string $modelName): ?int
{
    $map = defined('PIPE_MODELO_MAP_REVERSE') ? PIPE_MODELO_MAP_REVERSE : [];
    return $map[$modelName] ?? null;
}

/**
 * Resolve o phase_id do Pipedrive a partir do kanban_status do CRM.
 * Retorna null para status que não têm fase correspondente.
 */
function pipeResolvePhaseId(string $kanbanStatus): ?int
{
    $map = defined('PIPEDRIVE_KANBAN_TO_PHASE') ? PIPEDRIVE_KANBAN_TO_PHASE : [];
    return $map[$kanbanStatus] ?? null;
}

// ── Push principal ────────────────────────────────────────────────────────────

/**
 * Cria um novo projeto no Pipedrive a partir de um equipamento do CRM.
 *
 * @param int    $equipmentId  ID do equipamento no banco
 * @param int    $clientId     ID do cliente no banco
 * @param string $kanbanStatus Status Kanban atual do equipamento
 * @return array ['success' => bool, 'pipedrive_id' => int|null, 'error' => string|null]
 */
function pipePushCreateProject(int $equipmentId, int $clientId, string $kanbanStatus): array
{
    if (!(defined('PIPE_PUSH_ENABLED') && PIPE_PUSH_ENABLED)) {
        return ['success' => true, 'skipped' => true, 'reason' => 'Push Pipedrive desativado.'];
    }
    $db = getDB();

    // Buscar dados do equipamento
    $eqStmt = $db->prepare("
        SELECT e.asset_tag, e.serial_number, e.mac_address, e.purchase_date, e.batch,
               em.model_name, em.brand
        FROM equipment e
        JOIN equipment_models em ON em.id = e.model_id
        WHERE e.id = ?
    ");
    $eqStmt->execute([$equipmentId]);
    $eq = $eqStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$eq) return ['success' => false, 'error' => "Equipamento #$equipmentId não encontrado."];

    // Buscar dados do cliente (precisa de pipedrive_org_id para vincular)
    $clStmt = $db->prepare("SELECT client_code, name, pipedrive_org_id FROM clients WHERE id = ?");
    $clStmt->execute([$clientId]);
    $client = $clStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$client) return ['success' => false, 'error' => "Cliente #$clientId não encontrado."];

    // Verificar se já existe projeto para este equipamento no Pipedrive
    $existStmt = $db->prepare("SELECT pipedrive_id FROM pipedrive_projects WHERE asset_tag LIKE ? AND board_id = 8 AND status = 'open' LIMIT 1");
    $existStmt->execute(['%' . macLast6($eq['asset_tag'])]);
    $existing = $existStmt->fetch(\PDO::FETCH_ASSOC);
    if ($existing) {
        // Já existe — apenas atualiza a fase
        return pipePushUpdatePhase((int)$existing['pipedrive_id'], $kanbanStatus);
    }

    // Resolver phase_id
    $phaseId = pipeResolvePhaseId($kanbanStatus) ?? 50; // fallback: fase "Entrada"

    // Construir título
    $title = pipeBuildTitle($client['client_code'], $client['name'], $eq['asset_tag']);

    // Montar payload
    $payload = [
        'title'    => $title,
        'board_id' => PIPE_PUSH_BOARD_ID,
        'phase_id' => $phaseId,
        'status'   => 'open',
    ];

    // Vincular org se disponível
    if (!empty($client['pipedrive_org_id'])) {
        $payload['org_id'] = (int)$client['pipedrive_org_id'];
    }

    // Campos customizados
    if ($eq['purchase_date']) {
        $payload[PIPE_PROJ_DATA_COMPRA] = $eq['purchase_date'];
    }
    if ($eq['mac_address']) {
        $payload[PIPE_PROJ_MAC] = $eq['mac_address'];
    }
    if ($eq['batch']) {
        $payload[PIPE_PROJ_LOTE] = $eq['batch'];
    }
    $modelId = pipeResolveModelId($eq['model_name'] ?? '');
    if ($modelId) {
        $payload[PIPE_PROJ_MODELO] = $modelId;
    }

    // Enviar para Pipedrive
    $resp = pipedrivePost('projects', $payload);

    if (!($resp['success'] ?? false)) {
        $errMsg = $resp['error'] ?? ($resp['error'] ?? 'Erro desconhecido');
        error_log("[PipedrivePush] Falha ao criar projeto para equipamento #$equipmentId: $errMsg");
        return ['success' => false, 'error' => $errMsg];
    }

    $pipeId = (int)($resp['data']['id'] ?? 0);
    if (!$pipeId) return ['success' => false, 'error' => 'Pipedrive retornou ID inválido.'];

    // Salvar o pipedrive_id de volta no banco (tabela pipedrive_projects)
    try {
        $suffix6 = macLast6($eq['asset_tag']);
        $db->prepare("
            INSERT INTO pipedrive_projects
                (pipedrive_id, board_id, phase_id, phase_name, title, status, asset_tag, client_code, last_synced_at)
            VALUES (?, ?, ?, 'Entrada', ?, 'open', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                phase_id = VALUES(phase_id),
                phase_name = VALUES(phase_name),
                title = VALUES(title),
                status = VALUES(status),
                last_synced_at = NOW()
        ")->execute([
            $pipeId,
            PIPE_PUSH_BOARD_ID,
            $phaseId,
            $title,
            $suffix6,
            $client['client_code'],
        ]);
    } catch (\Exception $e) {
        // Tabela pode ter estrutura diferente — não bloqueia o fluxo
        error_log("[PipedrivePush] Aviso ao salvar pipedrive_projects: " . $e->getMessage());
    }

    return ['success' => true, 'pipedrive_id' => $pipeId];
}

/**
 * Atualiza a fase (phase_id) de um projeto existente no Pipedrive.
 *
 * @param int    $pipedriveId  ID do projeto no Pipedrive
 * @param string $kanbanStatus Novo status do Kanban no CRM
 * @return array ['success' => bool, 'error' => string|null]
 */
function pipePushUpdatePhase(int $pipedriveId, string $kanbanStatus): array
{
    if (!(defined('PIPE_PUSH_ENABLED') && PIPE_PUSH_ENABLED)) {
        return ['success' => true, 'skipped' => true, 'reason' => 'Push Pipedrive desativado.'];
    }
    $phaseId = pipeResolvePhaseId($kanbanStatus);

    if (!$phaseId) {
        // Status sem mapeamento (manutencao, equipamento_usado, comercial, baixado)
        // Não atualiza o Pipedrive — comportamento esperado
        return ['success' => true, 'skipped' => true, 'reason' => "Status '$kanbanStatus' não mapeado no Pipedrive."];
    }

    $resp = pipedrivePut("projects/{$pipedriveId}", ['phase_id' => $phaseId]);

    if (!($resp['success'] ?? false)) {
        $errMsg = $resp['error'] ?? 'Erro desconhecido';
        error_log("[PipedrivePush] Falha ao atualizar fase do projeto #$pipedriveId: $errMsg");
        return ['success' => false, 'error' => $errMsg];
    }

    // Atualiza cache local
    try {
        $db = getDB();
        $db->prepare("UPDATE pipedrive_projects SET phase_id = ?, last_synced_at = NOW() WHERE pipedrive_id = ?")
           ->execute([$phaseId, $pipedriveId]);
    } catch (\Exception $e) {
        error_log("[PipedrivePush] Aviso ao atualizar cache pipedrive_projects: " . $e->getMessage());
    }

    return ['success' => true, 'pipedrive_id' => $pipedriveId, 'new_phase_id' => $phaseId];
}

/**
 * Dado um equipment_id, encontra o pipedrive_id correspondente e atualiza a fase.
 * Função de alto nível chamada pelo kanban_move.php.
 *
 * @param int    $equipmentId  ID do equipamento no CRM
 * @param string $kanbanStatus Novo status Kanban
 * @return array
 */
function pipePushSyncStatus(int $equipmentId, string $kanbanStatus): array
{
    if (!(defined('PIPE_PUSH_ENABLED') && PIPE_PUSH_ENABLED)) {
        return ['success' => true, 'skipped' => true, 'reason' => 'Push Pipedrive desativado.'];
    }
    $phaseId = pipeResolvePhaseId($kanbanStatus);
    if (!$phaseId) {
        return ['success' => true, 'skipped' => true, 'reason' => "Status '$kanbanStatus' sem fase no Pipedrive."];
    }

    $db = getDB();

    // Buscar asset_tag do equipamento
    $eqStmt = $db->prepare("SELECT asset_tag FROM equipment WHERE id = ?");
    $eqStmt->execute([$equipmentId]);
    $assetTag = $eqStmt->fetchColumn();
    if (!$assetTag) return ['success' => false, 'error' => "Equipamento #$equipmentId não encontrado."];

    $suffix6 = macLast6($assetTag);

    // Buscar pipedrive_id na tabela de cache
    $ppStmt = $db->prepare("SELECT pipedrive_id FROM pipedrive_projects WHERE asset_tag LIKE ? AND board_id = 8 AND status = 'open' LIMIT 1");
    $ppStmt->execute(['%' . $suffix6]);
    $row = $ppStmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row || !$row['pipedrive_id']) {
        return ['success' => false, 'error' => "Nenhum projeto Pipedrive encontrado para o equipamento $assetTag."];
    }

    return pipePushUpdatePhase((int)$row['pipedrive_id'], $kanbanStatus);
}
