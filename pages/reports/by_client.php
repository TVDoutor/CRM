<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager','user']);

$db      = getDB();
$search  = trim($_GET['search'] ?? '');
$client  = null;
$activeEq = [];
$history  = [];

if ($search) {
    $stmt = $db->prepare("SELECT * FROM clients WHERE client_code = ? OR name LIKE ? LIMIT 1");
    $stmt->execute([$search, "%$search%"]);
    $client = $stmt->fetch();

    if ($client) {
        $cid = (int)$client['id'];

        $activeStmt = $db->prepare("SELECT e.id, e.asset_tag, e.mac_address, e.condition_status, e.contract_type,
            em.brand, em.model_name,
            DATEDIFF(NOW(), kh.moved_at) as days_allocated
        FROM equipment e
        JOIN equipment_models em ON em.id = e.model_id
        LEFT JOIN kanban_history kh ON kh.equipment_id = e.id
          AND kh.to_status = 'alocado'
          AND kh.id = (SELECT MAX(id) FROM kanban_history WHERE equipment_id = e.id AND to_status = 'alocado')
        WHERE e.current_client_id = ? AND e.kanban_status = 'alocado'
        ORDER BY days_allocated DESC");
        $activeStmt->execute([$cid]);
        $activeEq = $activeStmt->fetchAll();

        $histStmt = $db->prepare("
        (SELECT e.id, e.asset_tag, e.mac_address, em.brand, em.model_name,
            MIN(kh.moved_at) as first_allocation,
            MAX(CASE WHEN kh.to_status != 'alocado' THEN kh.moved_at END) as returned_at
        FROM kanban_history kh
        JOIN equipment e ON e.id = kh.equipment_id
        JOIN equipment_models em ON em.id = e.model_id
        WHERE kh.client_id = ?
        GROUP BY e.id)
        UNION
        (SELECT e.id, e.asset_tag, e.mac_address, em.brand, em.model_name,
            NULL as first_allocation, NULL as returned_at
        FROM equipment e
        JOIN equipment_models em ON em.id = e.model_id
        WHERE e.current_client_id = ? AND e.kanban_status = 'alocado'
          AND e.id NOT IN (SELECT equipment_id FROM kanban_history WHERE client_id = ?))
        ORDER BY first_allocation DESC");
        $histStmt->execute([$cid, $cid, $cid]);
        $history = $histStmt->fetchAll();
    }
}

$clients = $db->query("SELECT id, client_code, name FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Relatório por Cliente — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mt-2 mb-6">
      <div>
        <a href="/pages/reports/index.php" class="inline-flex items-center gap-1 text-gray-400 hover:text-gray-600 text-sm">
        <span class="material-symbols-outlined text-base">arrow_back</span> Relatórios</a>
        <h1 class="text-2xl font-bold text-gray-800 mt-2 flex items-center gap-2">
          <span class="material-symbols-outlined text-brand">assignment_ind</span>
          Relatório por Cliente
        </h1>
      </div>
      <?php if ($client): ?>
      <a href="/pages/api/export_csv.php?report=by_client&search=<?= urlencode($search) ?>"
         class="flex items-center gap-1.5 px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition">
        <span class="material-symbols-outlined text-base">download</span>
        Exportar CSV
      </a>
      <?php endif; ?>
    </div>

    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6 flex gap-3">
      <select name="search" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        <option value="">Selecione ou busque um cliente...</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= sanitize($c['client_code']) ?>" <?= $search === $c['client_code'] ? 'selected' : '' ?>>
          <?= sanitize($c['name']) ?> (<?= sanitize($c['client_code']) ?>)
        </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="bg-brand text-white text-sm px-5 py-2 rounded-lg hover:bg-brand-dark transition flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base">search</span> Ver Relatório
      </button>
    </form>

    <?php if ($search && !$client): ?>
    <div class="bg-yellow-50 border border-yellow-300 rounded-xl p-6 text-center text-yellow-700">
      Cliente não encontrado.
    </div>
    <?php endif; ?>

    <?php if ($client): ?>
    <!-- Dados do cliente -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
      <div class="flex items-start justify-between">
        <div>
          <p class="font-mono text-xs text-gray-400 mb-1"><?= sanitize($client['client_code']) ?></p>
          <h2 class="text-xl font-bold text-gray-800"><?= sanitize($client['name']) ?></h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4 text-sm">
            <div><p class="text-xs text-gray-400">CNPJ</p><p><?= sanitize($client['cnpj'] ?? '—') ?></p></div>
            <div><p class="text-xs text-gray-400">Telefone</p><p><?= sanitize($client['phone'] ?? '—') ?></p></div>
            <div><p class="text-xs text-gray-400">Cidade/UF</p><p><?= sanitize($client['city'] ?? '—') ?><?= $client['state'] ? '/' . sanitize($client['state']) : '' ?></p></div>
          </div>
        </div>
        <a href="/pages/clients/view.php?code=<?= urlencode($client['client_code']) ?>"
           class="text-sm text-brand hover:underline">Ver ficha →</a>
      </div>
    </div>

    <!-- Equipamentos ativos -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">
        Equipamentos Ativos (<?= count($activeEq) ?>)
      </h2>
      <?php if (empty($activeEq)): ?>
        <p class="text-gray-400 text-sm">Nenhum equipamento alocado.</p>
      <?php else: ?>
        <table class="w-full text-sm">
          <thead class="text-xs text-gray-400 uppercase border-b border-gray-100">
            <tr>
              <th class="text-left pb-2">Etiqueta</th>
              <th class="text-left pb-2">Modelo</th>
              <th class="text-left pb-2">Condição</th>
              <th class="text-left pb-2">Tipo</th>
              <th class="text-right pb-2">Dias</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($activeEq as $e): ?>
            <tr>
              <td class="py-2">
                <a href="/pages/equipment/view.php?id=<?= $e['id'] ?>"
                   class="font-mono font-semibold text-brand hover:underline text-xs"><?= sanitize(displayTag($e['asset_tag'], $e['mac_address'] ?? null)) ?></a>
              </td>
              <td class="py-2 text-xs text-gray-600"><?= sanitize(displayModelName($e['brand'], $e['model_name'])) ?></td>
              <td class="py-2"><?= conditionBadge($e['condition_status']) ?></td>
              <td class="py-2 text-xs text-gray-500"><?= contractLabel($e['contract_type']) ?></td>
              <td class="py-2 text-right font-bold text-brand text-xs"><?= (int)$e['days_allocated'] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Histórico completo -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">
        Histórico Completo (<?= count($history) ?> equipamentos)
      </h2>
      <?php if (empty($history)): ?>
        <p class="text-gray-400 text-sm">Sem histórico.</p>
      <?php else: ?>
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
                   class="font-mono font-semibold text-brand hover:underline text-xs"><?= sanitize(displayTag($h['asset_tag'], $h['mac_address'] ?? null)) ?></a>
              </td>
              <td class="py-2 text-xs text-gray-600"><?= sanitize(displayModelName($h['brand'], $h['model_name'])) ?></td>
              <td class="py-2 text-xs text-gray-500"><?= formatDate($h['first_allocation'], true) ?></td>
              <td class="py-2 text-xs"><?= $h['returned_at'] ? formatDate($h['returned_at'], true) : '<span class="text-green-600 font-medium">Alocado</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
