<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db = getDB();

// ── Contagem por status Kanban ─────────────────────────────────────────────
$statusCounts = [];
$stmtStatus = $db->query("SELECT kanban_status, COUNT(*) as cnt FROM equipment GROUP BY kanban_status");
foreach ($stmtStatus->fetchAll() as $r) $statusCounts[$r['kanban_status']] = (int)$r['cnt'];

$totalEstoque       = $statusCounts['entrada'] ?? 0;
$totalAlocado       = $statusCounts['alocado'] ?? 0;
$totalManutencao    = $statusCounts['manutencao'] ?? 0;
$totalLicRemovida   = $statusCounts['licenca_removida'] ?? 0;
$totalProcDevolucao = $statusCounts['processo_devolucao'] ?? 0;
$totalEqUsado       = $statusCounts['equipamento_usado'] ?? 0;
$totalComercial     = $statusCounts['comercial'] ?? 0;
$totalAguardando    = $statusCounts['aguardando_instalacao'] ?? 0;
$totalBaixado       = $statusCounts['baixado'] ?? 0;
$totalAtivos        = array_sum($statusCounts) - $totalBaixado;
$totalGeral         = array_sum($statusCounts);

// Novos vs Usados em estoque
$stmtCond = $db->query("SELECT condition_status, COUNT(*) as total FROM equipment WHERE kanban_status = 'entrada' GROUP BY condition_status");
$novos = 0; $usados = 0;
foreach ($stmtCond->fetchAll() as $r) {
    if ($r['condition_status'] === 'novo') $novos = (int)$r['total'];
    else $usados += (int)$r['total'];
}

// ── Movimentações últimos 30 dias (para gráfico) ───────────────────────────
$stmtChart = $db->query("SELECT DATE(operation_date) as dt, operation_type, COUNT(*) as cnt
    FROM equipment_operations
    WHERE operation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND operation_type IN ('ENTRADA','SAIDA','RETORNO')
    GROUP BY dt, operation_type
    ORDER BY dt");
$chartData = ['labels' => [], 'entrada' => [], 'saida' => [], 'retorno' => []];
$tmpChart = [];
foreach ($stmtChart->fetchAll() as $r) {
    $tmpChart[$r['dt']][$r['operation_type']] = (int)$r['cnt'];
}
foreach ($tmpChart as $dt => $ops) {
    $chartData['labels'][]  = date('d/m', strtotime($dt));
    $chartData['entrada'][] = $ops['ENTRADA'] ?? 0;
    $chartData['saida'][]   = $ops['SAIDA'] ?? 0;
    $chartData['retorno'][] = $ops['RETORNO'] ?? 0;
}

// ── Estoque por modelo ─────────────────────────────────────────────────────
$modelRows = $db->query("SELECT em.brand, em.model_name,
    COUNT(e.id) as total,
    SUM(CASE WHEN e.condition_status = 'novo' THEN 1 ELSE 0 END) as novos,
    SUM(CASE WHEN e.condition_status != 'novo' THEN 1 ELSE 0 END) as usados
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
WHERE e.kanban_status = 'entrada'
GROUP BY em.id
ORDER BY total DESC")->fetchAll();

// ── Top 5 clientes com mais equipamentos alocados ──────────────────────────
$topClients = $db->query("SELECT c.id, c.client_code, c.name,
    COUNT(e.id) as eq_count
FROM clients c
JOIN equipment e ON e.current_client_id = c.id AND e.kanban_status IN ('alocado','licenca_removida','processo_devolucao','aguardando_instalacao')
GROUP BY c.id
ORDER BY eq_count DESC
LIMIT 5")->fetchAll();

// ── Últimas 12 operações ───────────────────────────────────────────────────
$recentOps = $db->query("SELECT eo.operation_date, eo.operation_type, c.name as client_name,
    u.name as performed_by_name,
    COUNT(eoi.id) as equipment_count
FROM equipment_operations eo
JOIN users u ON u.id = eo.performed_by
LEFT JOIN clients c ON c.id = eo.client_id
LEFT JOIN equipment_operation_items eoi ON eoi.operation_id = eo.id
WHERE eo.operation_type IN ('SAIDA','RETORNO','ENTRADA')
GROUP BY eo.id
ORDER BY eo.operation_date DESC LIMIT 12")->fetchAll();

// ── Alertas: Manutenção > 30 dias ─────────────────────────────────────────
$maintRows = $db->query("SELECT e.id, e.asset_tag, e.mac_address, em.brand, em.model_name,
    DATEDIFF(NOW(), kh.moved_at) as days_in_maintenance
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
JOIN kanban_history kh ON kh.equipment_id = e.id
WHERE e.kanban_status = 'manutencao'
  AND kh.to_status = 'manutencao'
  AND kh.id = (SELECT MAX(id) FROM kanban_history WHERE equipment_id = e.id)
HAVING days_in_maintenance > 30
ORDER BY days_in_maintenance DESC")->fetchAll();

// ── Alertas: Garantias vencendo (próximos 30 dias) e vencidas ──────────────
$warrantyExpiring = $db->query("SELECT e.id, e.asset_tag, e.mac_address, e.purchase_date,
    em.brand, em.model_name,
    DATEDIFF(COALESCE(e.warranty_extended_until, DATE_ADD(e.purchase_date, INTERVAL 12 MONTH)), CURDATE()) as dias_restantes
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
WHERE e.purchase_date IS NOT NULL
  AND e.kanban_status NOT IN ('baixado','entrada')
  AND DATEDIFF(COALESCE(e.warranty_extended_until, DATE_ADD(e.purchase_date, INTERVAL 12 MONTH)), CURDATE()) BETWEEN -30 AND 30
ORDER BY dias_restantes ASC
LIMIT 10")->fetchAll();

// ── Pipedrive: última sincronização ────────────────────────────────────────
$pipeLastSync = null;
$pipeStats = ['total' => 0, 'open' => 0];
try {
    $pipeLastSync = $db->query("SELECT * FROM pipedrive_projects_sync_log ORDER BY created_at DESC LIMIT 1")->fetch();
    $ps = $db->query("SELECT COUNT(*) as total, SUM(status='open') as open FROM pipedrive_projects WHERE board_id = 8")->fetch();
    if ($ps) $pipeStats = ['total' => (int)$ps['total'], 'open' => (int)$ps['open']];
} catch (\Exception $e) {}

// ── Totais de operações (semana atual vs anterior) ─────────────────────────
$weekOps = $db->query("SELECT
    SUM(CASE WHEN operation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as this_week,
    SUM(CASE WHEN operation_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND operation_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as last_week
FROM equipment_operations
WHERE operation_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)")->fetch();
$opsThisWeek = (int)($weekOps['this_week'] ?? 0);
$opsLastWeek = (int)($weekOps['last_week'] ?? 0);

$opLabels = [
    'ENTRADA' => ['label' => 'Entrada',    'icon' => 'inventory_2',  'cls' => 'bg-blue-100 text-blue-700'],
    'SAIDA'   => ['label' => 'Saída',      'icon' => 'call_made',    'cls' => 'bg-green-100 text-green-700'],
    'RETORNO' => ['label' => 'Devolução',   'icon' => 'call_received','cls' => 'bg-orange-100 text-orange-700'],
];

$totalAlertas = count($maintRows) + count($warrantyExpiring) + $totalLicRemovida;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>
    tailwind.config = {
      theme: { extend: { colors: { brand: { DEFAULT:'#1B4F8C', dark:'#153d6f', light:'#D6E4F0' } } } }
    }
  </script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-7xl mx-auto space-y-6">

    <!-- Header + Quick Actions -->
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
      <div>
        <h1 class="text-xl lg:text-2xl font-bold text-gray-800">Dashboard</h1>
        <p class="text-gray-400 text-sm">Visão geral — <?= date('d/m/Y') ?></p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="/pages/kanban.php"
           class="inline-flex items-center gap-1.5 px-3 py-2 bg-brand text-white text-xs font-semibold rounded-lg hover:bg-blue-900 transition">
          <span class="material-symbols-outlined text-sm">view_kanban</span> Kanban
        </a>
        <a href="/pages/operations/saida.php"
           class="inline-flex items-center gap-1.5 px-3 py-2 bg-green-600 text-white text-xs font-semibold rounded-lg hover:bg-green-700 transition">
          <span class="material-symbols-outlined text-sm">call_made</span> Nova Saída
        </a>
        <a href="/pages/operations/retorno.php"
           class="inline-flex items-center gap-1.5 px-3 py-2 bg-orange-500 text-white text-xs font-semibold rounded-lg hover:bg-orange-600 transition">
          <span class="material-symbols-outlined text-sm">call_received</span> Devolução
        </a>
        <a href="/pages/equipment/batch_entry.php"
           class="inline-flex items-center gap-1.5 px-3 py-2 bg-gray-700 text-white text-xs font-semibold rounded-lg hover:bg-gray-800 transition">
          <span class="material-symbols-outlined text-sm">add_circle</span> Entrada Lote
        </a>
      </div>
    </div>

    <?php flashRender(); ?>

    <!-- KPIs Row 1: Principais -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 lg:gap-4">
      <a href="/pages/equipment/index.php?kanban_status=entrada" class="bg-white rounded-xl shadow-sm p-4 lg:p-5 border border-gray-100 hover:shadow-md hover:border-brand/30 transition group">
        <div class="flex items-center justify-between mb-2">
          <span class="material-symbols-outlined text-brand/60 text-xl">inventory_2</span>
          <span class="text-[10px] font-bold text-gray-400 uppercase">Estoque</span>
        </div>
        <p class="text-2xl lg:text-3xl font-bold text-brand"><?= $totalEstoque ?></p>
        <p class="text-[11px] text-gray-400 mt-1"><?= $novos ?> novos · <?= $usados ?> usados</p>
      </a>
      <a href="/pages/equipment/index.php?kanban_status=alocado" class="bg-white rounded-xl shadow-sm p-4 lg:p-5 border border-gray-100 hover:shadow-md hover:border-green-300 transition group">
        <div class="flex items-center justify-between mb-2">
          <span class="material-symbols-outlined text-green-500/60 text-xl">check_circle</span>
          <span class="text-[10px] font-bold text-gray-400 uppercase">Alocados</span>
        </div>
        <p class="text-2xl lg:text-3xl font-bold text-green-600"><?= $totalAlocado ?></p>
        <p class="text-[11px] text-gray-400 mt-1">em campo com clientes</p>
      </a>
      <a href="/pages/equipment/index.php?kanban_status=manutencao" class="bg-white rounded-xl shadow-sm p-4 lg:p-5 border border-gray-100 hover:shadow-md hover:border-orange-300 transition group">
        <div class="flex items-center justify-between mb-2">
          <span class="material-symbols-outlined text-orange-500/60 text-xl">build</span>
          <span class="text-[10px] font-bold text-gray-400 uppercase">Manutenção</span>
        </div>
        <p class="text-2xl lg:text-3xl font-bold text-orange-500"><?= $totalManutencao ?></p>
        <p class="text-[11px] text-gray-400 mt-1">aguardando conserto</p>
      </a>
      <div class="bg-white rounded-xl shadow-sm p-4 lg:p-5 border border-gray-100">
        <div class="flex items-center justify-between mb-2">
          <span class="material-symbols-outlined text-gray-400/60 text-xl">devices</span>
          <span class="text-[10px] font-bold text-gray-400 uppercase">Ativos</span>
        </div>
        <p class="text-2xl lg:text-3xl font-bold text-gray-700"><?= $totalAtivos ?></p>
        <p class="text-[11px] text-gray-400 mt-1"><?= $totalGeral ?> total · <?= $totalBaixado ?> baixados</p>
      </div>
    </div>

    <!-- KPIs Row 2: Status secundários -->
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
      <?php
      $secondaryKpis = [
          ['status' => 'licenca_removida',      'label' => 'Lic. Removida',    'count' => $totalLicRemovida,   'color' => 'purple', 'icon' => 'lock'],
          ['status' => 'processo_devolucao',     'label' => 'Proc. Devolução',  'count' => $totalProcDevolucao, 'color' => 'red',    'icon' => 'swap_horiz'],
          ['status' => 'equipamento_usado',      'label' => 'Eq. Usado',        'count' => $totalEqUsado,       'color' => 'blue',   'icon' => 'recycling'],
          ['status' => 'aguardando_instalacao',  'label' => 'Aguard. Instal.',  'count' => $totalAguardando,    'color' => 'amber',  'icon' => 'pending'],
          ['status' => 'comercial',              'label' => 'Comercial',        'count' => $totalComercial,     'color' => 'teal',   'icon' => 'business_center'],
      ];
      foreach ($secondaryKpis as $kpi):
      ?>
      <a href="/pages/equipment/index.php?kanban_status=<?= $kpi['status'] ?>"
         class="bg-white rounded-lg shadow-sm px-3 py-3 border border-gray-100 hover:shadow-md transition flex items-center gap-3">
        <span class="material-symbols-outlined text-<?= $kpi['color'] ?>-500 text-lg"><?= $kpi['icon'] ?></span>
        <div class="min-w-0">
          <p class="text-lg font-bold text-gray-700 leading-tight"><?= $kpi['count'] ?></p>
          <p class="text-[10px] text-gray-400 font-medium truncate"><?= $kpi['label'] ?></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Alertas -->
    <?php if ($totalAlertas > 0): ?>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 lg:p-5">
      <div class="flex items-center gap-2 mb-3">
        <span class="material-symbols-outlined text-red-600">warning</span>
        <h2 class="text-sm font-bold text-red-700 uppercase tracking-wider">Atenção (<?= $totalAlertas ?> alertas)</h2>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">

        <?php if ($totalLicRemovida > 0): ?>
        <a href="/pages/kanban.php" class="bg-white rounded-lg p-3 border border-red-100 hover:border-red-300 transition">
          <div class="flex items-center gap-2 mb-1">
            <span class="material-symbols-outlined text-purple-600 text-base">lock</span>
            <span class="text-xs font-bold text-purple-700"><?= $totalLicRemovida ?> Licença Removida</span>
          </div>
          <p class="text-[11px] text-gray-500">Equipamentos offline que precisam de ação</p>
        </a>
        <?php endif; ?>

        <?php if (!empty($maintRows)): ?>
        <div class="bg-white rounded-lg p-3 border border-red-100">
          <div class="flex items-center gap-2 mb-1">
            <span class="material-symbols-outlined text-orange-600 text-base">build</span>
            <span class="text-xs font-bold text-orange-700"><?= count($maintRows) ?> Manutenção &gt; 30 dias</span>
          </div>
          <div class="space-y-1 mt-2 max-h-24 overflow-y-auto">
            <?php foreach (array_slice($maintRows, 0, 5) as $r): ?>
            <a href="/pages/equipment/view.php?id=<?= $r['id'] ?>" class="flex items-center justify-between text-[11px] hover:bg-gray-50 rounded px-1 py-0.5">
              <span class="font-mono text-brand"><?= sanitize(displayTag($r['asset_tag'], $r['mac_address'] ?? null)) ?></span>
              <span class="font-bold text-orange-600"><?= (int)$r['days_in_maintenance'] ?>d</span>
            </a>
            <?php endforeach; ?>
            <?php if (count($maintRows) > 5): ?>
            <p class="text-[10px] text-gray-400 text-center">+<?= count($maintRows) - 5 ?> mais</p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($warrantyExpiring)): ?>
        <div class="bg-white rounded-lg p-3 border border-red-100">
          <div class="flex items-center gap-2 mb-1">
            <span class="material-symbols-outlined text-red-600 text-base">shield</span>
            <span class="text-xs font-bold text-red-700"><?= count($warrantyExpiring) ?> Garantia vencendo/vencida</span>
          </div>
          <div class="space-y-1 mt-2 max-h-24 overflow-y-auto">
            <?php foreach (array_slice($warrantyExpiring, 0, 5) as $r):
              $dias = (int)$r['dias_restantes'];
              $cls = $dias < 0 ? 'text-red-600' : 'text-amber-600';
              $label = $dias < 0 ? abs($dias) . 'd vencida' : $dias . 'd restantes';
            ?>
            <a href="/pages/equipment/view.php?id=<?= $r['id'] ?>" class="flex items-center justify-between text-[11px] hover:bg-gray-50 rounded px-1 py-0.5">
              <span class="font-mono text-brand"><?= sanitize(displayTag($r['asset_tag'], $r['mac_address'] ?? null)) ?></span>
              <span class="font-bold <?= $cls ?>"><?= $label ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

    <!-- Charts + Top Clientes -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

      <!-- Gráfico: Movimentações 30 dias -->
      <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Movimentações — Últimos 30 dias</h2>
          <div class="flex items-center gap-1.5 text-xs text-gray-400">
            <span class="material-symbols-outlined text-sm">trending_up</span>
            <?= $opsThisWeek ?> esta semana
            <?php if ($opsLastWeek > 0):
              $pctChange = round((($opsThisWeek - $opsLastWeek) / $opsLastWeek) * 100);
              $arrow = $pctChange >= 0 ? 'arrow_upward' : 'arrow_downward';
              $arrowCls = $pctChange >= 0 ? 'text-green-500' : 'text-red-500';
            ?>
            <span class="material-symbols-outlined text-xs <?= $arrowCls ?>"><?= $arrow ?></span>
            <span class="<?= $arrowCls ?> font-bold"><?= abs($pctChange) ?>%</span>
            <?php endif; ?>
          </div>
        </div>
        <div style="position:relative;height:220px;">
          <canvas id="opsChart"></canvas>
        </div>
      </div>

      <!-- Top Clientes -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4">Top Clientes</h2>
        <?php if (empty($topClients)): ?>
        <p class="text-gray-400 text-sm text-center py-6">Nenhum cliente com equipamentos.</p>
        <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($topClients as $i => $cl):
            $bar = $totalAlocado > 0 ? round(($cl['eq_count'] / $totalAlocado) * 100, 1) : 0;
          ?>
          <a href="/pages/clients/view.php?code=<?= urlencode($cl['client_code']) ?>" class="block group">
            <div class="flex items-center justify-between mb-1">
              <div class="flex items-center gap-2 min-w-0">
                <span class="w-5 h-5 rounded-full bg-brand/10 text-brand text-[10px] font-bold flex items-center justify-center shrink-0"><?= $i + 1 ?></span>
                <span class="text-sm text-gray-700 font-medium truncate group-hover:text-brand transition"><?= sanitize($cl['name']) ?></span>
              </div>
              <span class="text-sm font-bold text-brand ml-2 shrink-0"><?= (int)$cl['eq_count'] ?></span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-1.5">
              <div class="bg-brand/60 h-1.5 rounded-full transition-all" style="width:<?= max(2, $bar) ?>%"></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <a href="/pages/clients/index.php" class="mt-4 inline-block text-xs text-brand hover:underline">Ver todos →</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Distribuição Kanban + Estoque por Modelo -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

      <!-- Gráfico: Distribuição Kanban -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4">Distribuição por Status</h2>
        <div style="position:relative;height:220px;">
          <canvas id="kanbanChart"></canvas>
        </div>
      </div>

      <!-- Estoque por modelo -->
      <div class="lg:col-span-1 bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4">Estoque por Modelo</h2>
        <?php if (empty($modelRows)): ?>
          <p class="text-gray-400 text-sm text-center py-6">Nenhum em estoque.</p>
        <?php else: ?>
          <div class="space-y-2 max-h-[230px] overflow-y-auto">
            <?php foreach ($modelRows as $m): ?>
            <div class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-700 truncate"><?= sanitize($m['brand']) ?> <?= sanitize($m['model_name']) ?></p>
                <p class="text-[11px] text-gray-400"><?= (int)$m['novos'] ?> novos · <?= (int)$m['usados'] ?> usados</p>
              </div>
              <span class="text-lg font-bold text-brand ml-2 shrink-0"><?= (int)$m['total'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Pipedrive Sync -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 flex flex-col">
        <div class="flex items-center gap-2 mb-4">
          <div class="w-7 h-7 bg-[#0F4C81] rounded-lg flex items-center justify-center text-white text-xs font-bold">P</div>
          <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Pipedrive</h2>
        </div>
        <div class="flex-1 space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs text-gray-500">Projetos abertos</span>
            <span class="text-sm font-bold text-[#0F4C81]"><?= $pipeStats['open'] ?></span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-xs text-gray-500">Total projetos</span>
            <span class="text-sm font-bold text-gray-600"><?= $pipeStats['total'] ?></span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-xs text-gray-500">Última sincronização</span>
            <span class="text-xs font-medium text-gray-600">
              <?= $pipeLastSync ? formatDate($pipeLastSync['created_at'], true) : 'Nunca' ?>
            </span>
          </div>
          <?php if ($pipeLastSync && isset($pipeLastSync['projects_synced'])): ?>
          <div class="flex items-center justify-between">
            <span class="text-xs text-gray-500">Projetos sincronizados</span>
            <span class="text-sm font-bold text-green-600"><?= (int)$pipeLastSync['projects_synced'] ?></span>
          </div>
          <?php endif; ?>
        </div>
        <a href="/pages/pipedrive/projects.php"
           class="mt-4 inline-flex items-center gap-1 text-xs text-[#0F4C81] hover:underline font-medium">
          <span class="material-symbols-outlined text-sm">open_in_new</span> Ver projetos
        </a>
      </div>
    </div>

    <!-- Últimas Movimentações -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Últimas Movimentações</h2>
        <a href="/pages/operations/history.php" class="text-xs text-brand hover:underline font-medium">Ver todas →</a>
      </div>
      <?php if (empty($recentOps)): ?>
        <p class="text-gray-400 text-sm text-center py-6">Nenhuma movimentação registrada.</p>
      <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1">
          <?php foreach ($recentOps as $op):
            $meta = $opLabels[$op['operation_type']] ?? ['label' => $op['operation_type'], 'icon' => 'circle', 'cls' => 'bg-gray-100 text-gray-700'];
            $initials = '';
            foreach (explode(' ', trim($op['performed_by_name'])) as $part) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                if (strlen($initials) >= 2) break;
            }
          ?>
          <div class="flex items-center gap-3 py-2.5 border-b border-gray-50 last:border-0">
            <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center shrink-0" title="<?= sanitize($op['performed_by_name']) ?>">
              <span class="text-[10px] font-bold text-gray-500"><?= $initials ?></span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-700 truncate">
                <?= htmlspecialchars($op['client_name'] ?? 'Entrada de Lote') ?>
                <span class="text-gray-400 font-normal text-xs">(<?= (int)$op['equipment_count'] ?>)</span>
              </p>
              <p class="text-[11px] text-gray-400"><?= formatDate($op['operation_date'], true) ?></p>
            </div>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold <?= $meta['cls'] ?> shrink-0">
              <span class="material-symbols-outlined text-xs"><?= $meta['icon'] ?></span>
              <?= $meta['label'] ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</main>

<script>
// Gráfico: Movimentações 30 dias
const opsCtx = document.getElementById('opsChart').getContext('2d');
new Chart(opsCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartData['labels']) ?>,
        datasets: [
            { label: 'Entradas', data: <?= json_encode($chartData['entrada']) ?>, backgroundColor: 'rgba(59,130,246,.7)', borderRadius: 4 },
            { label: 'Saídas',   data: <?= json_encode($chartData['saida']) ?>,   backgroundColor: 'rgba(34,197,94,.7)',  borderRadius: 4 },
            { label: 'Devoluções',data: <?= json_encode($chartData['retorno']) ?>, backgroundColor: 'rgba(249,115,22,.7)', borderRadius: 4 },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15, font: { size: 11 } } } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } }, grid: { color: 'rgba(0,0,0,.04)' } }
        }
    }
});

// Gráfico: Distribuição Kanban
const kanbanCtx = document.getElementById('kanbanChart').getContext('2d');
new Chart(kanbanCtx, {
    type: 'doughnut',
    data: {
        labels: ['Estoque', 'Alocado', 'Manutenção', 'Lic. Removida', 'Proc. Devolução', 'Eq. Usado', 'Aguard. Instal.', 'Comercial'],
        datasets: [{
            data: [<?= $totalEstoque ?>, <?= $totalAlocado ?>, <?= $totalManutencao ?>, <?= $totalLicRemovida ?>, <?= $totalProcDevolucao ?>, <?= $totalEqUsado ?>, <?= $totalAguardando ?>, <?= $totalComercial ?>],
            backgroundColor: ['#64748b','#22c55e','#f97316','#a855f7','#ef4444','#3b82f6','#f59e0b','#14b8a6'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '60%',
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, padding: 8, font: { size: 10 } } }
        }
    }
});
</script>
</body>
</html>
