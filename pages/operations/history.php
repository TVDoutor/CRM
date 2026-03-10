<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db = getDB();

$type      = trim($_GET['type']       ?? '');
$dateFrom  = trim($_GET['date_from']  ?? date('Y-m-01'));
$dateTo    = trim($_GET['date_to']    ?? date('Y-m-d'));
$userId    = (int)($_GET['user_id']   ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;
$offset    = ($page - 1) * $perPage;

$where  = ["eo.operation_date BETWEEN ? AND ?"];
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

if ($type)   { $where[] = 'eo.operation_type = ?'; $params[] = $type; }
if ($userId) { $where[] = 'eo.performed_by = ?';   $params[] = $userId; }

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM equipment_operations eo WHERE $whereStr");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

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
ORDER BY eo.operation_date DESC
LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$users = $db->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$opMeta = [
    'ENTRADA'    => ['label' => 'Entrada de Lote', 'icon' => 'inventory_2',   'cls' => 'bg-blue-100 text-blue-800'],
    'SAIDA'      => ['label' => 'Saída',           'icon' => 'call_made',     'cls' => 'bg-green-100 text-green-800'],
    'RETORNO'    => ['label' => 'Devolução',        'icon' => 'call_received', 'cls' => 'bg-orange-100 text-orange-800'],
    'KANBAN_MOVE'=> ['label' => 'Mov. Kanban',      'icon' => 'swap_horiz',   'cls' => 'bg-gray-100 text-gray-700'],
    'MANUTENCAO' => ['label' => 'Manutenção',       'icon' => 'build',        'cls' => 'bg-yellow-100 text-yellow-800'],
    'BAIXA'      => ['label' => 'Baixa',            'icon' => 'delete',       'cls' => 'bg-red-100 text-red-800'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Histórico de Operações — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-6xl mx-auto">
    <h1 class="text-xl lg:text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
      <span class="material-symbols-outlined text-brand">history</span>
      Histórico de Operações
    </h1>

    <!-- Filtros -->
    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
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
        <select name="user_id" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Todos os usuários</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= sanitize($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="flex gap-2">
          <button type="submit" class="flex-1 bg-brand text-white text-sm py-2 px-3 rounded-lg hover:bg-blue-800 transition">Filtrar</button>
          <a href="?" class="flex-1 text-center bg-gray-100 text-gray-700 text-sm py-2 px-3 rounded-lg hover:bg-gray-200 transition">Limpar</a>
        </div>
      </div>
    </form>

    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100 text-sm text-gray-500">
        <?= $total ?> operação(ões) encontrada(s)
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr class="text-xs text-gray-500 uppercase tracking-wider">
              <th class="text-left px-4 py-3">Data/Hora</th>
              <th class="text-left px-4 py-3">Tipo</th>
              <th class="text-left px-4 py-3">Cliente</th>
              <th class="text-left px-4 py-3">Equipamentos</th>
              <th class="text-left px-4 py-3">Responsável</th>
              <th class="text-left px-4 py-3">Observações</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="text-center py-16">
              <span class="material-symbols-outlined text-5xl text-gray-300">search_off</span>
              <p class="text-gray-400 font-medium mt-2">Nenhuma operação encontrada</p>
            </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
              $meta = $opMeta[$r['operation_type']] ?? ['label' => $r['operation_type'], 'icon' => '•', 'cls' => 'bg-gray-100 text-gray-700'];
            ?>
            <tr class="hover:bg-gray-50 transition">
              <td class="px-4 py-3 text-gray-600 whitespace-nowrap"><?= formatDate($r['operation_date'], true) ?></td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold <?= $meta['cls'] ?>">
                  <span class="material-symbols-outlined" style="font-size:12px"><?= $meta['icon'] ?></span> <?= $meta['label'] ?>
                </span>
              </td>
              <td class="px-4 py-3 text-gray-700">
                <?php if ($r['client_name']): ?>
                  <a href="/pages/clients/view.php?code=<?= urlencode($r['client_code']) ?>"
                     class="hover:underline text-brand"><?= sanitize($r['client_name']) ?></a>
                <?php else: ?>
                  <span class="text-gray-400">—</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <span class="font-semibold text-gray-700"><?= (int)$r['equipment_count'] ?></span>
                <?php if ($r['asset_tags']): ?>
                  <p class="text-xs text-gray-400 truncate max-w-[200px]" title="<?= sanitize($r['asset_tags']) ?>">
                    <?= sanitize($r['asset_tags']) ?>
                  </p>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-600"><?= sanitize($r['performed_by_name']) ?></td>
              <td class="px-4 py-3 text-gray-400 text-xs italic"><?= sanitize($r['notes'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 text-sm text-gray-500">
        <span>Página <?= $page ?> de <?= $totalPages ?></span>
        <div class="flex gap-1">
          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
             class="px-3 py-1 rounded <?= $p === $page ? 'bg-brand text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
            <?= $p ?>
          </a>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
