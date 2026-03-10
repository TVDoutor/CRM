<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin']);

$db       = getDB();
$userId   = (int)($_GET['user_id']   ?? 0);
$dateFrom = trim($_GET['date_from']  ?? date('Y-m-01'));
$dateTo   = trim($_GET['date_to']    ?? date('Y-m-d'));
$action   = trim($_GET['action']     ?? '');

$users = $db->query("SELECT id, name FROM users ORDER BY name")->fetchAll();
$rows  = [];
$totals = [];

if ($userId) {
    $where  = ['al.user_id = ?', 'al.created_at BETWEEN ? AND ?'];
    $params = [$userId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    if ($action) { $where[] = 'al.action = ?'; $params[] = $action; }

    $whereStr = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT al.created_at, al.action, al.entity_type, al.entity_id,
        al.description, al.ip_address, e.asset_tag
    FROM audit_log al
    LEFT JOIN equipment e ON al.entity_type = 'equipment' AND al.entity_id = e.id
    WHERE $whereStr
    ORDER BY al.created_at DESC LIMIT 1000");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $totStmt = $db->prepare("SELECT action, COUNT(*) as total
        FROM audit_log WHERE user_id = ? AND created_at BETWEEN ? AND ?
        GROUP BY action ORDER BY total DESC");
    $totStmt->execute([$userId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    $totals = $totStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Auditoria por Usuário — S8 Conect CRM</title>
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
        <h1 class="text-2xl font-bold text-gray-800 mt-2 flex items-center gap-2"><span class="material-symbols-outlined text-brand">person</span> Auditoria por Usuário</h1>
      </div>
      <?php if ($userId): ?>
      <?php $csvParams = http_build_query(['report'=>'by_user','user_id'=>$userId,'date_from'=>$dateFrom,'date_to'=>$dateTo,'action'=>$action]); ?>
      <a href="/pages/api/export_csv.php?<?= $csvParams ?>"
         class="flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        Exportar CSV
      </a>
      <?php endif; ?>
    </div>

    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <select name="user_id" required class="col-span-2 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Selecione o usuário</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= sanitize($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="date_from" value="<?= sanitize($dateFrom) ?>"
               class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        <input type="date" name="date_to" value="<?= sanitize($dateTo) ?>"
               class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        <button type="submit" class="bg-brand text-white text-sm py-2 px-4 rounded-lg hover:bg-blue-800 transition">Consultar</button>
      </div>
    </form>

    <?php if ($userId && !empty($totals)): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-5">
      <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Resumo de Ações</h2>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($totals as $t): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['action' => $t['action']])) ?>"
           class="px-3 py-1.5 rounded-full text-xs font-semibold <?= $action === $t['action'] ? 'bg-brand text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
          <?= sanitize($t['action']) ?> (<?= (int)$t['total'] ?>)
        </a>
        <?php endforeach; ?>
        <?php if ($action): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['action' => ''])) ?>"
           class="px-3 py-1.5 rounded-full text-xs font-semibold bg-red-100 text-red-700 hover:bg-red-200">
          Limpar filtro
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($userId): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b text-sm text-gray-500"><?= count($rows) ?> registros</div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr class="text-xs text-gray-500 uppercase tracking-wider">
              <th class="text-left px-4 py-3">Data/Hora</th>
              <th class="text-left px-4 py-3">Ação</th>
              <th class="text-left px-4 py-3">Entidade</th>
              <th class="text-left px-4 py-3">Etiqueta</th>
              <th class="text-left px-4 py-3">Descrição</th>
              <th class="text-left px-4 py-3">IP</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="text-center py-10 text-gray-400">Nenhum registro encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2.5 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($r['created_at'], true) ?></td>
              <td class="px-4 py-2.5">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-700">
                  <?= sanitize($r['action']) ?>
                </span>
              </td>
              <td class="px-4 py-2.5 text-xs text-gray-500"><?= sanitize($r['entity_type']) ?> #<?= $r['entity_id'] ?></td>
              <td class="px-4 py-2.5 font-mono text-xs text-brand"><?= $r['asset_tag'] ? sanitize($r['asset_tag']) : '—' ?></td>
              <td class="px-4 py-2.5 text-xs text-gray-600"><?= sanitize($r['description'] ?? '—') ?></td>
              <td class="px-4 py-2.5 text-xs text-gray-400"><?= sanitize($r['ip_address'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-12 text-center text-gray-400">
      Selecione um usuário para ver o histórico de ações.
    </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
