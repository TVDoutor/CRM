<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager','user']);

$db       = getDB();
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo   = trim($_GET['date_to']   ?? date('Y-m-d'));
$type     = trim($_GET['type']      ?? '');

$where  = ["eo.operation_date BETWEEN ? AND ?", "eo.operation_type IN ('ENTRADA','SAIDA','RETORNO')"];
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
if ($type) { $where[] = 'eo.operation_type = ?'; $params[] = $type; }

$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("SELECT eo.id, eo.operation_type, eo.operation_date, eo.notes,
    c.name as client_name, c.client_code,
    u.name as performed_by_name,
    COUNT(eoi.id) as equipment_count,
    GROUP_CONCAT(e.asset_tag ORDER BY e.asset_tag SEPARATOR ', ') as asset_tags
FROM equipment_operations eo
LEFT JOIN clients c ON c.id = eo.client_id
JOIN users u ON u.id = eo.performed_by
LEFT JOIN equipment_operation_items eoi ON eoi.operation_id = eo.id
LEFT JOIN equipment e ON e.id = eoi.equipment_id
WHERE $whereStr
GROUP BY eo.id
ORDER BY eo.operation_date DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Resumo do período
$sumStmt = $db->prepare("SELECT operation_type, COUNT(*) as ops, SUM(item_count) as eq_total
FROM (
    SELECT eo.operation_type, COUNT(eoi.id) as item_count
    FROM equipment_operations eo
    JOIN equipment_operation_items eoi ON eoi.operation_id = eo.id
    WHERE eo.operation_date BETWEEN ? AND ?
      AND eo.operation_type IN ('ENTRADA','SAIDA','RETORNO')
    GROUP BY eo.id
) sub
GROUP BY operation_type");
$sumStmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$summary = [];
foreach ($sumStmt->fetchAll() as $s) $summary[$s['operation_type']] = $s;

$opMeta = [
    'ENTRADA' => ['label' => 'Entradas', 'icon' => '<span class="material-symbols-outlined" style="font-size:12px">inventory_2</span>', 'cls' => 'bg-blue-100 text-blue-800'],
    'SAIDA'   => ['label' => 'Saídas',   'icon' => '<span class="material-symbols-outlined" style="font-size:12px">call_made</span>', 'cls' => 'bg-green-100 text-green-800'],
    'RETORNO' => ['label' => 'Devoluções','icon' => '<span class="material-symbols-outlined" style="font-size:12px">call_received</span>', 'cls' => 'bg-orange-100 text-orange-800'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Entradas e Saídas — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mt-2 mb-6">
      <div>
        <a href="/pages/reports/index.php" class="text-gray-400 hover:text-gray-600 text-sm">← Relatórios</a>
        <h1 class="text-2xl font-bold text-gray-800 mt-2 flex items-center gap-2"><span class="material-symbols-outlined text-brand">bar_chart</span> Entradas e Saídas</h1>
      </div>
      <?php $csvParams = http_build_query(['report'=>'entries_exits','date_from'=>$dateFrom,'date_to'=>$dateTo,'type'=>$type]); ?>
      <a href="/pages/api/export_csv.php?<?= $csvParams ?>"
         class="flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        Exportar CSV
      </a>
    </div>

    <!-- Resumo -->
    <div class="grid grid-cols-3 gap-4 mb-6">
      <?php foreach (['ENTRADA','SAIDA','RETORNO'] as $t):
        $s = $summary[$t] ?? ['ops' => 0, 'eq_total' => 0];
        $m = $opMeta[$t];
      ?>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1"><?= $m['label'] ?></p>
        <p class="text-2xl font-bold text-gray-800"><?= (int)$s['eq_total'] ?> <span class="text-sm font-normal text-gray-400">equipamentos</span></p>
        <p class="text-xs text-gray-400 mt-0.5"><?= (int)$s['ops'] ?> operações</p>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Filtros -->
    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-5">
      <div class="flex gap-3 flex-wrap">
        <input type="date" name="date_from" value="<?= sanitize($dateFrom) ?>"
               class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        <input type="date" name="date_to" value="<?= sanitize($dateTo) ?>"
               class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        <select name="type" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Todos os tipos</option>
          <?php foreach ($opMeta as $k => $m): ?>
          <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $m['label'] ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-blue-800 transition">Filtrar</button>
        <a href="?" class="bg-gray-100 text-gray-700 text-sm px-4 py-2 rounded-lg hover:bg-gray-200 transition">Limpar</a>
      </div>
    </form>

    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b text-sm text-gray-500"><?= count($rows) ?> operações</div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr class="text-xs text-gray-500 uppercase tracking-wider">
              <th class="text-left px-4 py-3">Data</th>
              <th class="text-left px-4 py-3">Tipo</th>
              <th class="text-left px-4 py-3">Cliente</th>
              <th class="text-left px-4 py-3">Equip.</th>
              <th class="text-left px-4 py-3">Etiquetas</th>
              <th class="text-left px-4 py-3">Responsável</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="text-center py-10 text-gray-400">Nenhuma operação no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
              $meta = $opMeta[$r['operation_type']] ?? ['label' => $r['operation_type'], 'icon' => '•', 'cls' => 'bg-gray-100 text-gray-700'];
            ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2.5 text-xs text-gray-600 whitespace-nowrap"><?= formatDate($r['operation_date'], true) ?></td>
              <td class="px-4 py-2.5">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold <?= $meta['cls'] ?>">
                  <?= $meta['icon'] ?> <?= $meta['label'] ?>
                </span>
              </td>
              <td class="px-4 py-2.5 text-xs text-gray-700"><?= $r['client_name'] ? sanitize($r['client_name']) : '<span class="text-gray-400">—</span>' ?></td>
              <td class="px-4 py-2.5 font-bold text-gray-700"><?= (int)$r['equipment_count'] ?></td>
              <td class="px-4 py-2.5 text-xs text-gray-400 max-w-[200px] truncate" title="<?= sanitize($r['asset_tags'] ?? '') ?>"><?= sanitize($r['asset_tags'] ?? '—') ?></td>
              <td class="px-4 py-2.5 text-xs text-gray-600"><?= sanitize($r['performed_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
</body>
</html>
