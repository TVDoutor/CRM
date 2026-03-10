<?php
define('PIPEDRIVE_TOKEN',     '7108d2fb2af1ed4964c19aed54998e84016f1e6d');
define('PIPEDRIVE_API_BASE',  'https://api.pipedrive.com/v1/');
define('PIPEDRIVE_PER_PAGE',  100);

// Filtro de organizações a importar (ID extraído da URL do Pipedrive)
// URL: https://tvdoutor.pipedrive.com/organizations/list/filter/1159
define('PIPEDRIVE_FILTER_ID', 1159);

// ── Boards Pipedrive monitorados ────────────────────────────────────────────
// Para adicionar um novo board: inclua no array PIPEDRIVE_BOARDS com id, nome e mapeamento de fases.
define('PIPEDRIVE_BOARD_PONTOS', 8); // Board principal (retrocompatibilidade)

define('PIPEDRIVE_BOARDS', [
    8 => [
        'name'      => 'Pontos de Exibição',
        'active'    => true,
        'phase_map' => [
            50  => 'aguardando_instalacao', // Entrada (79) → Aguardando Instalação
            54  => 'alocado',               // Pontos Ativos Onsign (995) → Alocado
            57  => 'alocado',                // Offline +31 Dias (1) → Alocado | Total Alocado = 996
            106 => 'licenca_removida',      // Encaminhado ao CS (135) → Licença Removida
            55  => 'processo_devolucao',    // Processo de Cancelamento (21) → Processo de Devolução
        ],
    ],
    // Exemplo de segundo board (desativado por padrão — ative e configure o phase_map):
    // 12 => [
    //     'name'      => 'Outro Board',
    //     'active'    => false,
    //     'phase_map' => [],
    // ],
]);

// Mapeamento global phase_id → kanban_status (union de todos os boards ativos)
// ATENÇÃO: usar + em vez de array_merge para preservar chaves numéricas (phase IDs)
$_phaseMap = [];
foreach (PIPEDRIVE_BOARDS as $board) {
    if ($board['active']) {
        $_phaseMap = $_phaseMap + $board['phase_map'];
    }
}
define('PIPEDRIVE_PHASE_MAP', $_phaseMap);
unset($_phaseMap);

// IDs dos boards ativos
$_activeBoards = array_keys(array_filter(PIPEDRIVE_BOARDS, fn($b) => $b['active']));
define('PIPEDRIVE_ACTIVE_BOARD_IDS', $_activeBoards);
unset($_activeBoards);

// ── Campos customizados de Organizações (clientes) ────────────────────────
// Mapeados via pipedrive_diag.php em 27/02/2026
// e359d2c59bb6b35fb659adccefb123b0f1d48cce = Código do Cliente (ex: P3386)
// c3d359952a7bfcc3a5d56a4d4070af21764f9600 = CNPJ
// 6a7c681d817716b82c30195992fd8af7677565c9 = E-mail principal
// 328bd3626a035cc3fab7d5ddca849a8c913153b5 = CEP

// ── Campos customizados de Projetos ───────────────────────────────────────
// Mapeados via pipedrive_projects_diag.php em 01/03/2026
define('PIPE_PROJ_MODELO',      '346ec2fbf8bbd445b6ce7e53d7faa9bae9cc51bd'); // enum
define('PIPE_PROJ_MAC',         'e7a0a2e5c8f9d4fff26ac99ab1410a77a7d8825c'); // varchar
define('PIPE_PROJ_LOTE',        '6673e297bfbe9da15f272defec20ae4211f327fd'); // varchar
define('PIPE_PROJ_DATA_COMPRA', 'e5b0d4486aa16b47cc3a142252b3f2b63e593fee'); // date
define('PIPE_PROJ_FORNECEDOR',  'abe04adde652e539fe579d69db52fb09011b74ed'); // enum

// Mapeamento enum ID → nome do modelo (campo "Modelo do Aparelho")
define('PIPE_MODELO_MAP', [
    831 => 'Amlogic K95W',
    832 => 'Amlogic K3 PRO',
    833 => 'AQUARIO STV-2000',
    834 => 'AQUARIO STV-3000',
    835 => 'Computador',
    836 => 'Smart TV',
    837 => 'Monitor LG',
    838 => 'Proeletronic PROSB3000',
    839 => 'Proeletronic PROSB5000',
    840 => 'Outros',
]);

// Mapeamento enum ID → nome do fornecedor (campo "Fornecedor")
define('PIPE_FORNECEDOR_MAP', [
    819 => 'LG',
    820 => 'Real Fort',
    821 => 'Pro Eletronic',
    822 => 'Outros',
]);

// Chave cron para proteção do endpoint
define('PIPEDRIVE_CRON_KEY',  'tvd_cron_pip_2026');

/**
 * Mapeamento inverso: kanban_status → phase_id do board 8 (Pontos de Exibição).
 * Somente os status que possuem fase correspondente no Pipedrive.
 */
define('PIPEDRIVE_KANBAN_TO_PHASE', [
    'entrada'               => 50,
    'alocado'               => 54,  // Alocado → Pipe Pontos Ativos Onsign
    'aguardando_instalacao' => 50,  // Aguardando Instalação → Pipe Entrada
    'licenca_removida'      => 106,
    'processo_devolucao'    => 55,
]);

/**
 * Mapeamento inverso: model_name → enum ID no Pipedrive.
 */
define('PIPE_MODELO_MAP_REVERSE', [
    'Amlogic K95W'          => 831,
    'Amlogic K3 PRO'        => 832,
    'AQUARIO STV-2000'      => 833,
    'AQUARIO STV-3000'      => 834,
    'Computador'            => 835,
    'Smart TV'              => 836,
    'Monitor LG'            => 837,
    'Proeletronic PROSB3000'=> 838,
    'Proeletronic PROSB5000'=> 839,
    'Outros'                => 840,
]);

/**
 * Faz uma requisição GET à API do Pipedrive.
 */
function pipedriveGet(string $endpoint, array $params = []): array {
    $params['api_token'] = PIPEDRIVE_TOKEN;
    $url = PIPEDRIVE_API_BASE . $endpoint . '?' . http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'S8ConectCRM/1.0',
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) return ['success' => false, 'error' => "cURL: $err"];
        if ($httpCode !== 200) return ['success' => false, 'error' => "HTTP $httpCode"];
    } else {
        $ctx  = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 60, 'header' => "User-Agent: S8ConectCRM/1.0\r\n"],
            'ssl'  => ['verify_peer' => false],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return ['success' => false, 'error' => 'file_get_contents falhou — verifique se curl/openssl está habilitado no servidor'];
    }

    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['success' => false, 'error' => 'JSON inválido'];
}

/**
 * Busca todos os registros paginando automaticamente.
 * Lança \RuntimeException se houver erro de conexão na primeira página.
 */
function pipedriveGetAll(string $endpoint, array $params = []): array {
    $all      = [];
    $start    = 0;
    $limit    = PIPEDRIVE_PER_PAGE;
    $firstReq = true;

    do {
        $params['start'] = $start;
        $params['limit'] = $limit;
        $resp = pipedriveGet($endpoint, $params);

        if (!($resp['success'] ?? false)) {
            $errMsg = $resp['error'] ?? 'Falha na API do Pipedrive';
            if ($firstReq) {
                throw new \RuntimeException("Erro ao conectar ao Pipedrive ($endpoint): $errMsg");
            }
            break;
        }
        if (empty($resp['data'])) break;

        $all      = array_merge($all, $resp['data']);
        $more     = $resp['additional_data']['pagination']['more_items_in_collection'] ?? false;
        $start   += $limit;
        $firstReq = false;
    } while ($more);

    return $all;
}

/**
 * Faz uma requisição POST à API do Pipedrive.
 * Retorna o array decodificado da resposta.
 */
function pipedrivePost(string $endpoint, array $body): array {
    $url = PIPEDRIVE_API_BASE . $endpoint . '?api_token=' . PIPEDRIVE_TOKEN;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'S8ConectCRM/1.0',
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        if ($err)            return ['success' => false, 'error' => "cURL: $err"];
        if ($httpCode >= 400) return ['success' => false, 'error' => "HTTP $httpCode", 'raw' => $resp];
    } else {
        $ctx  = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: S8ConectCRM/1.0\r\n",
            'content' => json_encode($body),
            'timeout' => 30,
        ], 'ssl' => ['verify_peer' => false]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return ['success' => false, 'error' => 'Falha na requisição POST'];
    }

    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['success' => false, 'error' => 'JSON inválido'];
}

/**
 * Faz uma requisição PUT à API do Pipedrive.
 * Retorna o array decodificado da resposta.
 */
function pipedrivePut(string $endpoint, array $body): array {
    $url = PIPEDRIVE_API_BASE . $endpoint . '?api_token=' . PIPEDRIVE_TOKEN;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'S8ConectCRM/1.0',
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        if ($err)            return ['success' => false, 'error' => "cURL: $err"];
        if ($httpCode >= 400) return ['success' => false, 'error' => "HTTP $httpCode", 'raw' => $resp];
    } else {
        $ctx  = stream_context_create(['http' => [
            'method'  => 'PUT',
            'header'  => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: S8ConectCRM/1.0\r\n",
            'content' => json_encode($body),
            'timeout' => 30,
        ], 'ssl' => ['verify_peer' => false]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return ['success' => false, 'error' => 'Falha na requisição PUT'];
    }

    $data = json_decode($resp, true);
    return is_array($data) ? $data : ['success' => false, 'error' => 'JSON inválido'];
}
