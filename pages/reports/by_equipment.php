<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager','user']);

$db    = getDB();
$search = trim($_GET['search'] ?? '');
$eq    = null;
$history = [];
$returns = [];

if ($search) {
    $stmt = $db->prepare("SELECT e.*, em.brand, em.model_name,
        c.name as client_name, c.client_code
    FROM equipment e
    JOIN equipment_models em ON em.id = e.model_id
    LEFT JOIN clients c ON c.id = e.current_client_id
    WHERE e.asset_tag = ? OR e.serial_number = ?
    LIMIT 1");
    $stmt->execute([$search, $search]);
    $eq = $stmt->fetch();

    if ($eq) {
        $histStmt = $db->prepare("SELECT kh.moved_at, kh.from_status, kh.to_status, kh.notes,
            u.name as moved_by_name, c.name as client_name
        FROM kanban_history kh
        JOIN users u ON u.id = kh.moved_by
        LEFT JOIN clients c ON c.id = kh.client_id
        WHERE kh.equipment_id = ? ORDER BY kh.moved_at ASC");
        $histStmt->execute([$eq['id']]);
        $history = $histStmt->fetchAll();

        $retStmt = $db->prepare("SELECT eoi.created_at, eoi.accessories_power, eoi.accessories_hdmi,
            eoi.accessories_remote, eoi.condition_after_return, eoi.return_notes,
            eo.operation_date, u.name as performed_by, c.name as client_name
        FROM equipment_operation_items eoi
        JOIN equipment_operations eo ON eo.id = eoi.operation_id
        JOIN users u ON u.id = eo.performed_by
        LEFT JOIN clients c ON c.id = eo.client_id
        WHERE eoi.equipment_id = ? AND eo.operation_type = 'RETORNO'
        ORDER BY eoi.created_at DESC");
        $retStmt->execute([$eq['id']]);
        $returns = $retStmt->fetchAll();
    }
}
$condMap = ['ok' => '<span class="material-symbols-outlined text-sm">check_circle</span> Bom estado', 'manutencao' => '<span class="material-symbols-outlined" style="font-size:12px">build</span> Manutenção', 'descartar' => '<span class="material-symbols-outlined" style="font-size:12px">delete</span> Descartar'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Histórico de Equipamento — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mt-2 mb-6">
      <div>
        <a href="/pages/reports/index.php" class="text-gray-400 hover:text-gray-600 text-sm">← Relatórios</a>
        <h1 class="text-2xl font-bold text-gray-800 mt-2">🔍 Histórico de Equipamento</h1>
      </div>
      <?php if ($search && $eq): ?>
      <a href="/pages/api/export_csv.php?report=by_equipment&search=<?= urlencode($search) ?>"
         class="flex items-center gap-2 px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        Exportar CSV
      </a>
      <?php endif; ?>
    </div>

    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6 flex gap-3">
      <input type="text" name="search" value="<?= sanitize($search) ?>"
             placeholder="Etiqueta (asset_tag) ou Número de Série..."
             class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
      <button type="submit" class="bg-brand text-white text-sm px-5 py-2 rounded-lg hover:bg-blue-800 transition">Buscar</button>
    </form>

    <?php if ($search && !$eq): ?>
    <div class="bg-yellow-50 border border-yellow-300 rounded-xl p-6 text-center text-yellow-700">
      Nenhum equipamento encontrado com a etiqueta ou S/N "<?= sanitize($search) ?>".
    </div>
    <?php endif; ?>

    <?php if ($eq): ?>
    <!-- Dados do equipamento -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
      <div class="flex items-start justify-between">
        <div>
          <p class="font-mono text-2xl font-bold text-brand mb-1"><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?></p>
          <p class="text-gray-600"><?= sanitize($eq['brand']) ?> <?= sanitize($eq['model_name']) ?></p>
          <div class="flex gap-2 mt-2">
            <?= conditionBadge($eq['condition_status']) ?>
            <?= kanbanBadge($eq['kanban_status']) ?>
          </div>
        </div>
        <a href="/pages/equipment/view.php?id=<?= $eq['id'] ?>"
           class="text-sm text-brand hover:underline">Ver ficha completa →</a>
      </div>
      <div class="grid grid-cols-3 gap-4 mt-5 pt-5 border-t border-gray-100 text-sm">
        <div><p class="text-xs text-gray-400">S/N</p><p class="font-mono text-gray-700"><?= sanitize($eq['serial_number'] ?? '—') ?></p></div>
        <div><p class="text-xs text-gray-400">Lote</p><p class="text-gray-700"><?= sanitize($eq['batch'] ?? '—') ?></p></div>
        <div><p class="text-xs text-gray-400">Cliente Atual</p><p class="text-gray-700"><?= $eq['client_name'] ? sanitize($eq['client_name']) : '— Em Estoque —' ?></p></div>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- Timeline -->
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
        <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">Linha do Tempo</h2>
        <div class="relative pl-6 space-y-4">
          <div class="absolute left-2 top-0 bottom-0 w-0.5 bg-gray-100"></div>
          <?php foreach ($history as $h): ?>
          <div class="relative">
            <div class="absolute -left-4 top-1 w-3 h-3 rounded-full border-2 border-brand bg-white"></div>
            <p class="text-xs text-gray-400"><?= formatDate($h['moved_at'], true) ?></p>
            <p class="text-sm font-medium text-gray-700">
              <?= $h['from_status'] ? kanbanLabel($h['from_status']) . ' →' : 'Cadastrado:' ?>
              <?= kanbanLabel($h['to_status']) ?>
            </p>
            <?php if ($h['client_name']): ?>
              <p class="text-xs text-gray-400"><span class="material-symbols-outlined text-brand">assignment_ind</span> <?= sanitize($h['client_name']) ?></p>
            <?php endif; ?>
            <p class="text-xs text-gray-300">por <?= sanitize($h['moved_by_name']) ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Devoluções -->
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
        <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">Devoluções (<?= count($returns) ?>)</h2>
        <?php if (empty($returns)): ?>
          <p class="text-gray-400 text-sm">Nenhuma devolução.</p>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach ($returns as $r): ?>
            <div class="bg-gray-50 rounded-lg p-3 text-sm">
              <p class="font-medium text-gray-700 mb-1"><?= formatDate($r['operation_date'], true) ?></p>
              <p class="text-xs text-gray-400 mb-2">Cliente: <?= sanitize($r['client_name'] ?? '—') ?></p>
              <div class="flex gap-3 text-xs mb-1">
                <span class="<?= $r['accessories_power']  ? 'text-green-600' : 'text-red-400 line-through' ?>">🔌 Fonte</span>
                <span class="<?= $r['accessories_hdmi']   ? 'text-green-600' : 'text-red-400 line-through' ?>">📺 HDMI</span>
                <span class="<?= $r['accessories_remote'] ? 'text-green-600' : 'text-red-400 line-through' ?>">🎮 Controle</span>
              </div>
              <p class="text-xs">Condição: <strong><?= $condMap[$r['condition_after_return']] ?? '—' ?></strong></p>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
