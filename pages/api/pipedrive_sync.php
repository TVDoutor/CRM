<?php
/**
 * Script de sincronização Pipedrive → CRM
 * Pode ser chamado:
 *   - Via cron CLI:  php pipedrive_sync.php cron
 *   - Via cron URL:  GET ?cron_key=tvd_cron_pip_2026
 *   - Via browser (admin): POST com csrf_token
 */

// Suprimir warnings/notices que corrompem o JSON
error_reporting(0);
// Capturar qualquer output indesejado antes de responder
ob_start();

// Detectar modo cron ANTES de carregar auth (que usa sessão)
$isCronCli = (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'cron');

// Carregar config do Pipedrive primeiro (necessário para PIPEDRIVE_CRON_KEY)
require_once __DIR__ . '/../../config/pipedrive.php';

$isCronUrl = (!$isCronCli && isset($_GET['cron_key']) && $_GET['cron_key'] === PIPEDRIVE_CRON_KEY);
$isCron    = $isCronCli || $isCronUrl;
$isManual  = !$isCron;

if ($isCronCli) {
    require_once __DIR__ . '/../../includes/helpers.php';
} else {
    require_once __DIR__ . '/../../includes/auth.php';
    require_once __DIR__ . '/../../includes/helpers.php';
}

// Autenticação para chamadas manuais (browser)
if ($isManual) {
    // Limpar qualquer output gerado até aqui e definir header JSON
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    requireLogin();
    requireRole(['admin', 'manager']);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Use POST.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($data['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
        exit;
    }
}

// Garantir tempo suficiente para sincronizar muitos registros
@set_time_limit(300);

$startTime   = microtime(true);
$db          = getDB();
$syncType    = $isCron ? 'cron' : 'manual';
$performedBy = $isCron ? null : ($_SESSION['user_id'] ?? null);
$errors      = [];
$stats       = [
    'orgs_found'       => 0, 'orgs_created'    => 0, 'orgs_updated'    => 0,
    'persons_found'    => 0, 'persons_created' => 0, 'persons_updated' => 0,
];

// ─── 1. Sincronizar ORGANIZAÇÕES ─────────────────────────────────────────────
$orgParams = ['status' => 'all'];
if (defined('PIPEDRIVE_FILTER_ID') && PIPEDRIVE_FILTER_ID) {
    $orgParams['filter_id'] = PIPEDRIVE_FILTER_ID;
}

try {
    $orgs = pipedriveGetAll('organizations', $orgParams);
} catch (\RuntimeException $e) {
    $errMsg = $e->getMessage();
    // Registrar falha no log
    $duration = (int)((microtime(true) - $startTime) * 1000);
    try {
        $db->prepare("INSERT INTO pipedrive_sync_log
            (sync_type, status, orgs_found, orgs_created, orgs_updated,
             persons_found, persons_created, persons_updated, errors, duration_ms, performed_by)
            VALUES (?,?,0,0,0,0,0,0,?,?,?)")
           ->execute([$syncType, 'error', $errMsg, $duration, $performedBy]);
    } catch (\Exception $ignored) {}

    ob_end_clean();
    if (!$isCron) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => $errMsg, 'stats' => $stats, 'errors' => [$errMsg], 'duration' => $duration . 'ms'], JSON_UNESCAPED_UNICODE);
    exit;
}
$stats['orgs_found'] = count($orgs);

// Chaves dos campos customizados — mapeados via pipedrive_diag.php em 27/02/2026
const FIELD_CLIENT_CODE  = 'e359d2c59bb6b35fb659adccefb123b0f1d48cce'; // Código do Cliente (ex: P3386)
const FIELD_CNPJ         = 'c3d359952a7bfcc3a5d56a4d4070af21764f9600'; // CNPJ
const FIELD_RAZAO_SOCIAL = 'f27b7b6c1db05b81890fce2ccd79d638117e63a6'; // Razão Social completa
const FIELD_EMAIL_MAIN   = '6a7c681d817716b82c30195992fd8af7677565c9'; // E-mail principal
const FIELD_PHONE_CUSTOM = 'aed2f743e35585a63bd8e9c2a3b38617ba75f981'; // Telefone (campo customizado)
const FIELD_STATE_SIGLA  = '340c3d345022023033b14183b75580dec4672830'; // Estado já como sigla (SP, RJ...)
const FIELD_CEP          = '328bd3626a035cc3fab7d5ddca849a8c913153b5'; // CEP

foreach ($orgs as $org) {
    try {
        $pipeId = (int)$org['id'];
        $name   = trim($org['name'] ?? '');
        if (!$name) continue;

        // ── Código do cliente (campo customizado — ex: P3386) ────────────────
        $clientCode = trim($org[FIELD_CLIENT_CODE] ?? '');
        if (!$clientCode) $clientCode = 'PD-' . $pipeId;

        // ── CNPJ ─────────────────────────────────────────────────────────────
        $cnpj = trim($org[FIELD_CNPJ] ?? '') ?: null;

        // ── Nome para exibição: usa Razão Social se disponível ────────────────
        $razaoSocial = trim($org[FIELD_RAZAO_SOCIAL] ?? '');
        // name = nome curto (ex: "Unimed Lençóis Paulista")
        // razaoSocial = nome completo (ex: "Unimed Lençóis Paulista Cooperativa de Trabalho Médico")
        // Guardamos o nome curto em name e razão social em contact_name
        $contactName = $razaoSocial ?: null;

        // ── Telefone: campo customizado tem prioridade ────────────────────────
        $phone = trim($org[FIELD_PHONE_CUSTOM] ?? '');
        if (!$phone && !empty($org['phone'])) {
            $ph = is_array($org['phone']) ? ($org['phone'][0]['value'] ?? '') : $org['phone'];
            $phone = trim($ph);
        }

        // ── E-mail ────────────────────────────────────────────────────────────
        $email = trim($org[FIELD_EMAIL_MAIN] ?? '');
        if (!$email && !empty($org['email'])) {
            $em = is_array($org['email']) ? ($org['email'][0]['value'] ?? '') : $org['email'];
            $email = trim($em);
        }
        if (strtolower(trim($email)) === 'não informado') $email = '';

        // ── Endereço ──────────────────────────────────────────────────────────
        $address = trim($org['address_formatted_address'] ?? $org['address'] ?? '');
        $city    = trim($org['address_admin_area_level_2'] ?? ''); // Cidade/região
        // Estado: usa campo customizado com sigla direta (ex: "SP")
        $state = trim($org[FIELD_STATE_SIGLA] ?? '');
        if (!$state) {
            $state = trim($org['address_admin_area_level_1'] ?? '');
            $stateMap = [
                'São Paulo'=>'SP','Rio de Janeiro'=>'RJ','Minas Gerais'=>'MG',
                'Bahia'=>'BA','Paraná'=>'PR','Rio Grande do Sul'=>'RS',
                'Santa Catarina'=>'SC','Goiás'=>'GO','Pernambuco'=>'PE',
                'Ceará'=>'CE','Pará'=>'PA','Maranhão'=>'MA','Amazonas'=>'AM',
                'Mato Grosso'=>'MT','Mato Grosso do Sul'=>'MS','Espírito Santo'=>'ES',
                'Rio Grande do Norte'=>'RN','Piauí'=>'PI','Alagoas'=>'AL',
                'Sergipe'=>'SE','Rondônia'=>'RO','Tocantins'=>'TO','Acre'=>'AC',
                'Amapá'=>'AP','Roraima'=>'RR','Paraíba'=>'PB','Distrito Federal'=>'DF',
            ];
            if (isset($stateMap[$state])) $state = $stateMap[$state];
        }
        if (strlen($state) > 2) $state = mb_strtoupper(mb_substr($state, 0, 2));

        $isActive = isset($org['active_flag']) ? (int)(bool)$org['active_flag'] : 1;

        // ── Verificar se já existe pelo pipedrive_org_id ─────────────────────
        $existStmt = $db->prepare('SELECT id, client_code FROM clients WHERE pipedrive_org_id = ?');
        $existStmt->execute([$pipeId]);
        $existing = $existStmt->fetch();

        if ($existing) {
            $db->prepare("UPDATE clients SET
                client_code = ?, name = ?, contact_name = ?, cnpj = ?,
                phone = ?, email = ?, address = ?, city = ?, state = ?,
                is_active = ?, pipedrive_synced_at = NOW()
                WHERE pipedrive_org_id = ?")
               ->execute([
                   $clientCode, $name, $contactName, $cnpj,
                   $phone ?: null, $email ?: null,
                   $address ?: null, $city ?: null, $state ?: null,
                   $isActive, $pipeId
               ]);
            $stats['orgs_updated']++;
        } else {
            // Garantir client_code único
            $codeStmt = $db->prepare('SELECT id FROM clients WHERE client_code = ?');
            $codeStmt->execute([$clientCode]);
            if ($codeStmt->fetch()) {
                $clientCode = $clientCode . '-PD' . $pipeId;
            }

            $db->prepare("INSERT INTO clients
                (client_code, name, contact_name, cnpj, phone, email,
                 address, city, state, is_active, pipedrive_org_id, pipedrive_synced_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
               ->execute([
                   $clientCode, $name, $contactName, $cnpj,
                   $phone ?: null, $email ?: null,
                   $address ?: null, $city ?: null, $state ?: null,
                   $isActive, $pipeId
               ]);
            $stats['orgs_created']++;
        }
    } catch (\Exception $e) {
        $errors[] = "Org #{$org['id']} ({$org['name']}): " . $e->getMessage();
    }
}

// ─── 2. Sincronizar PESSOAS (vinculadas a organizações) ───────────────────────
$persons = pipedriveGetAll('persons', ['status' => 'all']);
$stats['persons_found'] = count($persons);

foreach ($persons as $person) {
    try {
        $pipePersonId = (int)$person['id'];
        $name         = trim($person['name'] ?? '');
        if (!$name) continue;

        // Só sincroniza pessoa se não tiver organização associada
        // (evitar duplicar clientes que já vieram como org)
        $orgId = (int)($person['org_id']['value'] ?? $person['org_id'] ?? 0);
        if ($orgId > 0) {
            // Apenas vincula o pipedrive_person_id ao cliente já existente
            $db->prepare("UPDATE clients SET pipedrive_person_id = ?, pipedrive_synced_at = NOW()
                          WHERE pipedrive_org_id = ?")
               ->execute([$pipePersonId, $orgId]);
            continue;
        }

        $phone = '';
        if (!empty($person['phone'])) {
            $ph = is_array($person['phone']) ? ($person['phone'][0]['value'] ?? '') : $person['phone'];
            $phone = trim($ph);
        }
        $email = '';
        if (!empty($person['email'])) {
            $em = is_array($person['email']) ? ($person['email'][0]['value'] ?? '') : $person['email'];
            $email = trim($em);
        }

        $isActive   = isset($person['active_flag']) ? (int)(bool)$person['active_flag'] : 1;
        $clientCode = 'PDP-' . $pipePersonId;

        $existStmt = $db->prepare('SELECT id FROM clients WHERE pipedrive_person_id = ?');
        $existStmt->execute([$pipePersonId]);
        $existing = $existStmt->fetch();

        if ($existing) {
            $db->prepare("UPDATE clients SET name=?, phone=?, email=?, is_active=?, pipedrive_synced_at=NOW()
                          WHERE pipedrive_person_id=?")
               ->execute([$name, $phone ?: null, $email ?: null, $isActive, $pipePersonId]);
            $stats['persons_updated']++;
        } else {
            $codeStmt = $db->prepare('SELECT id FROM clients WHERE client_code = ?');
            $codeStmt->execute([$clientCode]);
            if ($codeStmt->fetch()) $clientCode = 'PDP-' . $pipePersonId . '-' . time();

            $db->prepare("INSERT INTO clients
                (client_code, name, phone, email, is_active, pipedrive_person_id, pipedrive_synced_at)
                VALUES (?,?,?,?,?,?,NOW())")
               ->execute([$clientCode, $name, $phone ?: null, $email ?: null, $isActive, $pipePersonId]);
            $stats['persons_created']++;
        }
    } catch (\Exception $e) {
        $errors[] = "Person #{$person['id']} ({$person['name']}): " . $e->getMessage();
    }
}

// ─── 3. Registrar log ─────────────────────────────────────────────────────────
$duration = (int)((microtime(true) - $startTime) * 1000);
$status   = empty($errors) ? 'success' : (count($errors) < 5 ? 'partial' : 'error');

$db->prepare("INSERT INTO pipedrive_sync_log
    (sync_type, status, orgs_found, orgs_created, orgs_updated,
     persons_found, persons_created, persons_updated, errors, duration_ms, performed_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
   ->execute([
       $syncType, $status,
       $stats['orgs_found'], $stats['orgs_created'], $stats['orgs_updated'],
       $stats['persons_found'], $stats['persons_created'], $stats['persons_updated'],
       $errors ? implode("\n", $errors) : null,
       $duration, $performedBy,
   ]);

// Audit log
if ($performedBy) {
    auditLog('PIPEDRIVE_SYNC', 'integration', null, null, $stats,
        "Sincronização Pipedrive: {$stats['orgs_created']} orgs criadas, {$stats['orgs_updated']} atualizadas");
}

$result = [
    'success'  => $status !== 'error',
    'status'   => $status,
    'stats'    => $stats,
    'errors'   => $errors,
    'duration' => $duration . 'ms',
];

if ($isCron) {
    // Para cron, descartar output acumulado e escrever diretamente
    ob_end_clean();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    // Para browser, descartar qualquer output acumulado e enviar JSON limpo
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
