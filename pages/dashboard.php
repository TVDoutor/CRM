<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db = getDB();

// KPI 1: Total em estoque (mesmo critério do relatório Estoque Atual: apenas kanban_status = 'entrada')
$stmtStock = $db->query("SELECT COUNT(*) FROM equipment WHERE kanban_status = 'entrada'");
$totalStock = (int)$stmtStock->fetchColumn();

// KPI 2: Alocados
$stmtAloc = $db->query("SELECT COUNT(*) FROM equipment WHERE kanban_status = 'alocado'");
$totalAlocado = (int)$stmtAloc->fetchColumn();

// KPI 3: Em manutenção
$stmtMan = $db->query("SELECT COUNT(*) FROM equipment WHERE kanban_status = 'manutencao'");
$totalManutencao = (int)$stmtMan->fetchColumn();

// KPI 4: Novos vs Usados em estoque
$stmtCond = $db->query("SELECT condition_status, COUNT(*) as total FROM equipment WHERE kanban_status = 'entrada' GROUP BY condition_status");
$condRows = $stmtCond->fetchAll();
$novos = 0; $usados = 0;
foreach ($condRows as $r) {
    if ($r['condition_status'] === 'novo') $novos = (int)$r['total'];
    else $usados = (int)$r['total'];
}

// Estoque por modelo (mesmo critério: kanban_status = 'entrada')
$stmtModels = $db->query("SELECT em.brand, em.model_name, em.category,
    COUNT(e.id) as total,
    SUM(CASE WHEN e.condition_status = 'novo' THEN 1 ELSE 0 END) as novos,
    SUM(CASE WHEN e.condition_status = 'usado' THEN 1 ELSE 0 END) as usados
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
WHERE e.kanban_status = 'entrada'
GROUP BY em.id
ORDER BY em.category, em.brand");
$modelRows = $stmtModels->fetchAll();

// Últimas 10 operações
$stmtOps = $db->query("SELECT eo.operation_date, eo.operation_type, c.name as client_name,
    u.name as performed_by_name,
    COUNT(eoi.id) as equipment_count
FROM equipment_operations eo
JOIN users u ON u.id = eo.performed_by
LEFT JOIN clients c ON c.id = eo.client_id
LEFT JOIN equipment_operation_items eoi ON eoi.operation_id = eo.id
WHERE eo.operation_type IN ('SAIDA','RETORNO','ENTRADA')
GROUP BY eo.id
ORDER BY eo.operation_date DESC LIMIT 10");
$recentOps = $stmtOps->fetchAll();

// Equipamentos há mais de 30 dias em manutenção
$stmtMaint = $db->query("SELECT e.id, e.asset_tag, e.mac_address, em.brand, em.model_name,
    DATEDIFF(NOW(), kh.moved_at) as days_in_maintenance
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
JOIN kanban_history kh ON kh.equipment_id = e.id
WHERE e.kanban_status = 'manutencao'
  AND kh.to_status = 'manutencao'
  AND kh.id = (SELECT MAX(id) FROM kanban_history WHERE equipment_id = e.id)
HAVING days_in_maintenance > 30
ORDER BY days_in_maintenance DESC");
$maintRows = $stmtMaint->fetchAll();

$opLabels = [
    'ENTRADA' => ['label' => 'Entrada de Lote', 'icon' => '📦', 'cls' => 'bg-blue-100 text-blue-800'],
    'SAIDA'   => ['label' => 'Saída',            'icon' => '📤', 'cls' => 'bg-green-100 text-green-800'],
    'RETORNO' => ['label' => 'Devolução',         'icon' => '📥', 'cls' => 'bg-orange-100 text-orange-800'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: { extend: { colors: { brand: { DEFAULT:'#1B4F8C', dark:'#153d6f', light:'#D6E4F0' } } } }
    }
  </script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-7xl mx-auto">

    <div class="mb-6">
      <h1 class="text-xl lg:text-2xl font-bold text-gray-800">Dashboard</h1>
      <p class="text-gray-500 text-sm mt-1">Visão geral dos equipamentos — <?= date('d/m/Y') ?></p>
    </div>

    <?php flashRender(); ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-5 mb-6">
      <div class="bg-white rounded-xl shadow-sm p-4 lg:p-5 border border-gray-100">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Em Estoque</p>
        <p class="text-2xl lg:text-3xl font-bold text-brand"><?= $totalStock ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= $novos ?> novos · <?= $usados ?> usados</p>
      </div>
      <div class="bg-white rounded-xl shadow-sm p-4 lg:p-5 border border-gray-100">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Alocados</p>
        <p class="text-2xl lg:text-3xl font-bold text-green-600"><?= $totalAlocado ?></p>
        <p class="text-xs text-gray-400 mt-1">em campo com clientes</p>
      </div>
      <div class="bg-white rounded-xl shadow-sm p-4 lg:p-5 border border-gray-100">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Manutenção</p>
        <p class="text-2xl lg:text-3xl font-bold text-orange-500"><?= $totalManutencao ?></p>
        <p class="text-xs text-gray-400 mt-1">aguardando conserto</p>
      </div>
      <div class="bg-white rounded-xl shadow-sm p-4 lg:p-5 border border-gray-100">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Total Geral</p>
        <p class="text-2xl lg:text-3xl font-bold text-gray-700"><?= $totalStock + $totalAlocado + $totalManutencao ?></p>
        <p class="text-xs text-gray-400 mt-1">ativos no sistema</p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

      <!-- Estoque por modelo -->
      <div class="lg:col-span-1 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4">Estoque por Modelo</h2>
        <?php if (empty($modelRows)): ?>
          <p class="text-gray-400 text-sm text-center py-4">Nenhum equipamento em estoque.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($modelRows as $m): ?>
            <div class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
              <div>
                <p class="text-sm font-medium text-gray-800"><?= sanitize($m['brand']) ?> <?= sanitize($m['model_name']) ?></p>
                <p class="text-xs text-gray-400"><?= (int)$m['novos'] ?> novos · <?= (int)$m['usados'] ?> usados</p>
              </div>
              <span class="text-lg font-bold text-brand"><?= (int)$m['total'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Últimas operações -->
      <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-4">Últimas Movimentações</h2>
        <?php if (empty($recentOps)): ?>
          <p class="text-gray-400 text-sm text-center py-4">Nenhuma movimentação registrada.</p>
        <?php else: ?>
          <div class="space-y-2">
            <?php foreach ($recentOps as $op):
              $meta = $opLabels[$op['operation_type']] ?? ['label' => $op['operation_type'], 'icon' => '•', 'cls' => 'bg-gray-100 text-gray-700'];
            ?>
            <div class="flex items-center gap-3 py-2 border-b border-gray-50 last:border-0">
              <span class="text-xl"><?= $meta['icon'] ?></span>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800 truncate">
                  <?= htmlspecialchars($op['client_name'] ?? 'Entrada de Lote') ?>
                  <span class="text-gray-400 font-normal">(<?= (int)$op['equipment_count'] ?> equip.)</span>
                </p>
                <p class="text-xs text-gray-400"><?= htmlspecialchars($op['performed_by_name']) ?> · <?= formatDate($op['operation_date'], true) ?></p>
              </div>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $meta['cls'] ?>">
                <?= $meta['label'] ?>
              </span>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <a href="/pages/operations/history.php" class="mt-4 inline-block text-xs text-brand hover:underline">Ver todas →</a>
      </div>
    </div>

    <!-- Alerta: Equipamentos em manutenção há mais de 30 dias -->
    <?php if (!empty($maintRows)): ?>
    <div class="mt-6 bg-orange-50 border border-orange-200 rounded-xl p-6">
      <h2 class="text-sm font-bold text-orange-700 uppercase tracking-wider mb-3">
        ⚠️ Equipamentos em Manutenção há mais de 30 dias
      </h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-orange-600 uppercase">
              <th class="pb-2">Etiqueta</th>
              <th class="pb-2">Modelo</th>
              <th class="pb-2 text-right">Dias</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($maintRows as $r): ?>
            <tr class="border-t border-orange-100">
              <td class="py-1.5">
                <a href="/pages/equipment/view.php?id=<?= $r['id'] ?? '' ?>"
                   class="font-mono text-brand hover:underline"><?= sanitize(displayTag($r['asset_tag'], $r['mac_address'] ?? null)) ?></a>
              </td>
              <td class="py-1.5 text-gray-600"><?= sanitize($r['brand']) ?> <?= sanitize($r['model_name']) ?></td>
              <td class="py-1.5 text-right font-bold text-orange-700"><?= (int)$r['days_in_maintenance'] ?> dias</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>
</main>

</body>
</html>
