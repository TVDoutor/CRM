<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db   = getDB();
$code = trim($_GET['code'] ?? '');
if (!$code) { header('Location: ' . BASE_URL . '/pages/clients/index.php'); exit; }

$stmt = $db->prepare('SELECT * FROM clients WHERE client_code = ?');
$stmt->execute([$code]);
$client = $stmt->fetch();
if (!$client) { flashSet('error', 'Cliente não encontrado.'); header('Location: ' . BASE_URL . '/pages/clients/index.php'); exit; }

$cid = (int)$client['id'];

// KPIs de uptime / SLA
$kpiStmt = $db->prepare("
    SELECT
        COUNT(DISTINCT e.id) as total_equipamentos,
        SUM(CASE WHEN e.kanban_status = 'alocado' THEN 1 ELSE 0 END) as alocados,
        SUM(CASE WHEN e.kanban_status = 'manutencao' THEN 1 ELSE 0 END) as em_manutencao,
        SUM(CASE WHEN e.kanban_status = 'licenca_removida' THEN 1 ELSE 0 END) as offline,
        COALESCE(MAX(DATEDIFF(NOW(), kh_alloc.moved_at)), 0) as max_dias_alocado,
        COALESCE(ROUND(AVG(DATEDIFF(NOW(), kh_alloc.moved_at))), 0) as media_dias_alocado
    FROM equipment e
    LEFT JOIN kanban_history kh_alloc ON kh_alloc.equipment_id = e.id
        AND kh_alloc.to_status = 'alocado'
        AND kh_alloc.id = (SELECT MAX(id) FROM kanban_history WHERE equipment_id = e.id AND to_status = 'alocado')
    WHERE e.current_client_id = ? OR EXISTS (
        SELECT 1 FROM kanban_history kh2 WHERE kh2.equipment_id = e.id AND kh2.client_id = ?
    )
");
$kpiStmt->execute([$cid, $cid]);
$kpi = $kpiStmt->fetch();

// Total de dias com equipamentos alocados (histórico completo)
$totalAllocDaysStmt = $db->prepare("
    SELECT COALESCE(SUM(
        DATEDIFF(
            COALESCE(
                (SELECT MIN(kh2.moved_at) FROM kanban_history kh2 WHERE kh2.equipment_id = kh.equipment_id AND kh2.moved_at > kh.moved_at AND kh2.to_status != 'alocado'),
                NOW()
            ),
            kh.moved_at
        )
    ), 0) as total_dias
    FROM kanban_history kh
    WHERE kh.client_id = ? AND kh.to_status = 'alocado'
");
$totalAllocDaysStmt->execute([$cid]);
$totalAllocDays = (int)$totalAllocDaysStmt->fetchColumn();

// Retornos registrados
$returnsCount = (int)$db->prepare("
    SELECT COUNT(*) FROM equipment_operations eo
    JOIN equipment_operation_items eoi ON eoi.operation_id = eo.id
    JOIN equipment e ON e.id = eoi.equipment_id
    WHERE eo.client_id = ? AND eo.operation_type = 'RETORNO'
")->execute([$cid]) ? $db->prepare("
    SELECT COUNT(*) FROM equipment_operations eo
    WHERE eo.client_id = ? AND eo.operation_type = 'RETORNO'
")->execute([$cid]) && ($s = $db->prepare("SELECT COUNT(*) FROM equipment_operations WHERE client_id = ? AND operation_type = 'RETORNO'"))->execute([$cid]) ? (int)$s->fetchColumn() : 0 : 0;
$retStmt = $db->prepare("SELECT COUNT(*) FROM equipment_operations WHERE client_id = ? AND operation_type = 'RETORNO'");
$retStmt->execute([$cid]);
$returnsCount = (int)$retStmt->fetchColumn();

// Projetos Pipedrive vinculados
$projectsStmt = $db->prepare("SELECT * FROM pipedrive_projects WHERE client_id = ? OR client_code = ? ORDER BY status ASC, start_date DESC");
$clientProjects = [];
try {
    $projectsStmt->execute([$cid, $client['client_code']]);
    $clientProjects = $projectsStmt->fetchAll();
} catch (\Exception $e) {
    // Tabela ainda não existe
}

// Equipamentos ativos — mesma lógica da listagem (current_client_id + kanban_status = 'alocado')
// LEFT JOIN em kanban_history para incluir equipamentos alocados via Pipedrive (sem registro em kanban_history)
$activeStmt = $db->prepare("SELECT e.id, e.asset_tag, e.mac_address, e.condition_status, e.contract_type,
    em.brand, em.model_name,
    kh.moved_at,
    DATEDIFF(NOW(), kh.moved_at) as days_allocated
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
LEFT JOIN kanban_history kh ON kh.equipment_id = e.id
  AND kh.to_status = 'alocado'
  AND kh.id = (SELECT MAX(id) FROM kanban_history kh2 WHERE kh2.equipment_id = e.id AND kh2.to_status = 'alocado')
WHERE e.current_client_id = ? AND e.kanban_status = 'alocado'
ORDER BY kh.moved_at DESC, e.asset_tag ASC");
$activeStmt->execute([$cid]);
$activeEq = $activeStmt->fetchAll();

// Histórico completo
$histStmt = $db->prepare("SELECT DISTINCT e.id, e.asset_tag, e.mac_address, em.brand, em.model_name,
    MIN(kh.moved_at) as first_allocation,
    MAX(CASE WHEN kh.to_status != 'alocado' THEN kh.moved_at END) as returned_at
FROM kanban_history kh
JOIN equipment e ON e.id = kh.equipment_id
JOIN equipment_models em ON em.id = e.model_id
WHERE kh.client_id = ?
GROUP BY e.id
ORDER BY first_allocation DESC");
$histStmt->execute([$cid]);
$history = $histStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= sanitize($client['name']) ?> — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">
    <a href="/pages/clients/index.php" class="text-gray-400 hover:text-gray-600 text-sm">← Clientes</a>

    <?php flashRender(); ?>

    <!-- Cabeçalho -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mt-4 mb-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="font-mono text-sm text-gray-400 mb-1"><?= sanitize($client['client_code']) ?></p>
          <h1 class="text-2xl font-bold text-gray-800 mb-1"><?= sanitize($client['name']) ?></h1>
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $client['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' ?>">
            <?= $client['is_active'] ? 'Ativo' : 'Inativo' ?>
          </span>
        </div>
        <?php if (in_array($_SESSION['user_role'], ['admin','manager'])): ?>
        <a href="/pages/clients/edit.php?id=<?= $cid ?>"
           class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-blue-800 transition">
          <span class="material-symbols-outlined text-base">edit</span> Editar
        </a>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-5 pt-5 border-t border-gray-100">
        <div>
          <p class="text-xs text-gray-400 uppercase tracking-wider">CNPJ</p>
          <p class="text-sm text-gray-700 mt-0.5"><?= sanitize($client['cnpj'] ?? '—') ?></p>
        </div>
        <div>
          <p class="text-xs text-gray-400 uppercase tracking-wider">Contato</p>
          <p class="text-sm text-gray-700 mt-0.5"><?= sanitize($client['contact_name'] ?? '—') ?></p>
        </div>
        <div>
          <p class="text-xs text-gray-400 uppercase tracking-wider">Telefone</p>
          <p class="text-sm text-gray-700 mt-0.5"><?= sanitize($client['phone'] ?? '—') ?></p>
        </div>
        <div>
          <p class="text-xs text-gray-400 uppercase tracking-wider">E-mail</p>
          <p class="text-sm text-gray-700 mt-0.5"><?= sanitize($client['email'] ?? '—') ?></p>
        </div>
        <?php if ($client['address'] || $client['city']): ?>
        <div class="col-span-2">
          <p class="text-xs text-gray-400 uppercase tracking-wider">Endereço</p>
          <p class="text-sm text-gray-700 mt-0.5">
            <?= sanitize($client['address'] ?? '') ?>
            <?= $client['city'] ? ' — ' . sanitize($client['city']) . ($client['state'] ? '/' . sanitize($client['state']) : '') : '' ?>
          </p>
        </div>
        <?php endif; ?>
        <?php if ($client['notes']): ?>
        <div class="col-span-4">
          <p class="text-xs text-gray-400 uppercase tracking-wider">Observações</p>
          <p class="text-sm text-gray-600 mt-0.5"><?= nl2br(sanitize($client['notes'])) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- KPIs de uptime / SLA -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Equipamentos Ativos</p>
        <p class="text-3xl font-bold text-brand"><?= count($activeEq) ?></p>
        <p class="text-xs text-gray-400 mt-1">de <?= (int)($kpi['total_equipamentos'] ?? 0) ?> histórico</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Média Dias Alocado</p>
        <p class="text-3xl font-bold text-green-600"><?= (int)($kpi['media_dias_alocado'] ?? 0) ?></p>
        <p class="text-xs text-gray-400 mt-1">dias por equipamento</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Total Dias Alocados</p>
        <p class="text-3xl font-bold text-blue-600"><?= $totalAllocDays ?></p>
        <p class="text-xs text-gray-400 mt-1">dias acumulados</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <?php
          $alocados  = (int)($kpi['alocados']  ?? 0);
          $total     = (int)($kpi['total_equipamentos'] ?? 0);
          $manut     = (int)($kpi['em_manutencao'] ?? 0);
          $offline   = (int)($kpi['offline'] ?? 0);
          $uptime    = $total > 0 ? round(($alocados / $total) * 100) : 0;
          $uptimeColor = $uptime >= 90 ? 'text-green-600' : ($uptime >= 70 ? 'text-orange-500' : 'text-red-600');
        ?>
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Uptime (% ativos)</p>
        <p class="text-3xl font-bold <?= $uptimeColor ?>"><?= $uptime ?>%</p>
        <p class="text-xs text-gray-400 mt-1"><?= $manut ?> em manutenção · <?= $offline ?> offline</p>
      </div>
    </div>

    <!-- Equipamentos ativos -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">
        Equipamentos Ativos (<?= count($activeEq) ?>)
      </h2>
      <?php if (empty($activeEq)): ?>
        <p class="text-gray-400 text-sm">Nenhum equipamento alocado no momento.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-gray-400 uppercase border-b border-gray-100">
              <tr>
                <th class="text-left pb-2">Etiqueta</th>
                <th class="text-left pb-2">Modelo</th>
                <th class="text-left pb-2">Condição</th>
                <th class="text-left pb-2">Tipo</th>
                <th class="text-left pb-2">Dias Alocado</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <?php foreach ($activeEq as $e): ?>
              <tr>
                <td class="py-2">
                  <a href="/pages/equipment/view.php?id=<?= $e['id'] ?>"
                     class="font-mono font-semibold text-brand hover:underline"><?= sanitize(displayTag($e['asset_tag'], $e['mac_address'] ?? null)) ?></a>
                </td>
                <td class="py-2 text-gray-600"><?= sanitize($e['brand']) ?> <?= sanitize($e['model_name']) ?></td>
                <td class="py-2"><?= conditionBadge($e['condition_status']) ?></td>
                <td class="py-2 text-gray-500 text-xs"><?= contractLabel($e['contract_type']) ?></td>
                <td class="py-2 font-semibold text-brand"><?= isset($e['days_allocated']) && $e['days_allocated'] !== null ? (int)$e['days_allocated'] . ' dias' : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Projetos Pipedrive -->
    <?php if (!empty($clientProjects)): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider flex items-center gap-2">
          <svg class="h-4 w-4 text-[#0F4C81]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
          </svg>
          Projetos Pipedrive (<?= count($clientProjects) ?>)
        </h2>
        <a href="https://tvdoutor.pipedrive.com/projects/board" target="_blank"
           class="text-xs text-[#0F4C81] hover:underline">Ver no Pipedrive →</a>
      </div>
      <div class="space-y-2">
        <?php foreach ($clientProjects as $proj):
          $statusColor = match($proj['status']) {
            'open'      => 'bg-green-100 text-green-800',
            'completed' => 'bg-blue-100 text-blue-800',
            'canceled'  => 'bg-red-100 text-red-700',
            default     => 'bg-gray-100 text-gray-600',
          };
          $statusLabel = match($proj['status']) {
            'open'      => 'Aberto',
            'completed' => 'Concluído',
            'canceled'  => 'Cancelado',
            default     => $proj['status'],
          };
        ?>
        <div class="flex flex-wrap items-center justify-between gap-3 p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition">
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800 truncate"><?= sanitize($proj['title']) ?></p>
            <p class="text-xs text-gray-400 mt-0.5 flex flex-wrap gap-3">
              <?php if ($proj['phase_name']): ?>
              <span><span class="material-symbols-outlined text-sm">location_on</span> <?= sanitize($proj['phase_name']) ?></span>
              <?php endif; ?>
              <?php if ($proj['start_date']): ?>
              <span><span class="material-symbols-outlined text-sm">calendar_today</span> Início: <?= date('d/m/Y', strtotime($proj['start_date'])) ?></span>
              <?php endif; ?>
              <?php if ($proj['end_date']): ?>
              <span><span class="material-symbols-outlined text-sm">flag</span> Fim: <?= date('d/m/Y', strtotime($proj['end_date'])) ?></span>
              <?php endif; ?>
            </p>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $statusColor ?>">
              <?= $statusLabel ?>
            </span>
            <a href="https://tvdoutor.pipedrive.com/projects/<?= $proj['pipedrive_id'] ?>" target="_blank"
               class="text-xs text-[#0F4C81] hover:underline">Ver →</a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Histórico completo -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">
        Histórico Completo (<?= count($history) ?>)
      </h2>
      <?php if (empty($history)): ?>
        <p class="text-gray-400 text-sm">Sem histórico de equipamentos.</p>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-gray-400 uppercase border-b border-gray-100">
              <tr>
                <th class="text-left pb-2">Etiqueta</th>
                <th class="text-left pb-2">Modelo</th>
                <th class="text-left pb-2">1ª Alocação</th>
                <th class="text-left pb-2">Devolução</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
              <?php foreach ($history as $h): ?>
              <tr>
                <td class="py-2">
                  <a href="/pages/equipment/view.php?id=<?= $h['id'] ?>"
                     class="font-mono font-semibold text-brand hover:underline"><?= sanitize(displayTag($h['asset_tag'], $h['mac_address'] ?? null)) ?></a>
                </td>
                <td class="py-2 text-gray-600"><?= sanitize($h['brand']) ?> <?= sanitize($h['model_name']) ?></td>
                <td class="py-2 text-gray-500"><?= formatDate($h['first_allocation'], true) ?></td>
                <td class="py-2 text-gray-500"><?= $h['returned_at'] ? formatDate($h['returned_at'], true) : '<span class="text-green-600 font-medium">Alocado</span>' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
