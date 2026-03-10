<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager','user']);

$db        = getDB();
$condition = trim($_GET['condition_status'] ?? '');
$modelId   = (int)($_GET['model_id'] ?? 0);
$batch     = trim($_GET['batch'] ?? '');

$where  = ["e.kanban_status = 'entrada'"];
$params = [];
if ($condition) { $where[] = 'e.condition_status = ?'; $params[] = $condition; }
if ($modelId)   { $where[] = 'e.model_id = ?';         $params[] = $modelId; }
if ($batch)     { $where[] = 'e.batch LIKE ?';         $params[] = "%$batch%"; }

$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("SELECT e.asset_tag, e.serial_number, e.mac_address,
    e.condition_status, e.kanban_status, e.batch, e.entry_date,
    em.brand, em.model_name, em.category
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
WHERE $whereStr
ORDER BY e.condition_status, em.brand, em.model_name");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Totalizadores
$total  = count($rows);
$novos  = count(array_filter($rows, fn($r) => $r['condition_status'] === 'novo'));
$usados = $total - $novos;

// Por modelo
$byModel = [];
foreach ($rows as $r) {
    $k = $r['brand'] . ' ' . $r['model_name'];
    if (!isset($byModel[$k])) $byModel[$k] = ['novos' => 0, 'usados' => 0];
    $byModel[$k][$r['condition_status'] === 'novo' ? 'novos' : 'usados']++;
}

$models = $db->query("SELECT id, brand, model_name FROM equipment_models WHERE is_active = 1 ORDER BY brand")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Estoque Atual — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
      <div>
        <a href="/pages/reports/index.php" class="text-gray-400 hover:text-gray-600 text-sm">← Relatórios</a>
        <h1 class="text-2xl font-bold text-gray-800 mt-2 flex items-center gap-2"><span class="material-symbols-outlined" style="font-size:inherit">inventory_2</span> Estoque Atual</h1>
      </div>
      <?php
        $csvParams = http_build_query(['report'=>'stock','condition_status'=>$condition,'model_id'=>$modelId,'batch'=>$batch]);
      ?>
      <a href="/pages/api/export_csv.php?<?= $csvParams ?>"
         class="flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        Exportar CSV
      </a>
    </div>

    <!-- Totalizadores -->
    <div class="grid grid-cols-3 gap-4 mb-6">
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Total em Estoque</p>
        <p class="text-3xl font-bold text-brand"><?= $total ?></p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Novos</p>
        <p class="text-3xl font-bold text-green-600"><?= $novos ?></p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Usados</p>
        <p class="text-3xl font-bold text-orange-500"><?= $usados ?></p>
      </div>
    </div>

    <!-- Subtotais por modelo -->
    <?php if (!empty($byModel)): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3">Por Modelo</h2>
      <div class="flex flex-wrap gap-3">
        <?php foreach ($byModel as $name => $counts): ?>
        <div class="bg-gray-50 rounded-lg px-4 py-2 text-sm">
          <span class="font-medium text-gray-700"><?= sanitize($name) ?></span>
          <span class="text-green-600 ml-2"><?= $counts['novos'] ?> N</span>
          <span class="text-orange-500 ml-1"><?= $counts['usados'] ?> U</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Filtros -->
    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-5">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <select name="condition_status" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Todas as condições</option>
          <option value="novo"  <?= $condition === 'novo'  ? 'selected' : '' ?>>Novo</option>
          <option value="usado" <?= $condition === 'usado' ? 'selected' : '' ?>>Usado</option>
        </select>
        <select name="model_id" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Todos os modelos</option>
          <?php foreach ($models as $m): ?>
          <option value="<?= $m['id'] ?>" <?= $modelId === (int)$m['id'] ? 'selected' : '' ?>>
            <?= sanitize($m['brand']) ?> <?= sanitize($m['model_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="batch" value="<?= sanitize($batch) ?>" placeholder="Lote..."
               class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        <div class="flex gap-2">
          <button type="submit" class="flex-1 bg-brand text-white text-sm py-2 rounded-lg hover:bg-blue-800 transition">Filtrar</button>
          <a href="?" class="flex-1 text-center bg-gray-100 text-gray-700 text-sm py-2 rounded-lg hover:bg-gray-200 transition">Limpar</a>
        </div>
      </div>
    </form>

    <!-- Tabela -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr class="text-xs text-gray-500 uppercase tracking-wider">
              <th class="text-left px-4 py-3">Etiqueta</th>
              <th class="text-left px-4 py-3">Modelo</th>
              <th class="text-left px-4 py-3">S/N</th>
              <th class="text-left px-4 py-3">MAC</th>
              <th class="text-left px-4 py-3">Condição</th>
              <th class="text-left px-4 py-3">Status</th>
              <th class="text-left px-4 py-3">Lote</th>
              <th class="text-left px-4 py-3">Entrada</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="text-center py-10 text-gray-400">Nenhum equipamento em estoque.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2.5 font-mono font-semibold text-brand text-xs"><?= sanitize(displayTag($r['asset_tag'], $r['mac_address'] ?? null)) ?></td>
              <td class="px-4 py-2.5 text-gray-700 text-xs"><?= sanitize($r['brand']) ?> <?= sanitize($r['model_name']) ?></td>
              <td class="px-4 py-2.5 font-mono text-xs text-gray-400"><?= sanitize($r['serial_number'] ?? '—') ?></td>
              <td class="px-4 py-2.5 font-mono text-xs text-gray-400"><?= sanitize($r['mac_address'] ?? '—') ?></td>
              <td class="px-4 py-2.5"><?= conditionBadge($r['condition_status']) ?></td>
              <td class="px-4 py-2.5"><?= kanbanBadge($r['kanban_status']) ?></td>
              <td class="px-4 py-2.5 text-xs text-gray-500"><?= sanitize($r['batch'] ?? '—') ?></td>
              <td class="px-4 py-2.5 text-xs text-gray-500"><?= formatDate($r['entry_date']) ?></td>
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
