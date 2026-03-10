<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db     = getDB();
$search = trim($_GET['search'] ?? '');
$active = $_GET['active'] ?? '1';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(c.name LIKE ? OR c.client_code LIKE ? OR c.cnpj LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($active !== '') {
    $where[] = 'c.is_active = ?';
    $params[] = (int)$active;
}

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM clients c WHERE $whereStr");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare("SELECT c.*,
    (SELECT COUNT(*) FROM equipment WHERE current_client_id = c.id AND kanban_status = 'alocado') as active_equipment
FROM clients c WHERE $whereStr ORDER BY c.name LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// KPIs
$kpiTotal    = (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$kpiActive   = (int)$db->query("SELECT COUNT(*) FROM clients WHERE is_active = 1")->fetchColumn();
$kpiWithEquip = (int)$db->query("SELECT COUNT(DISTINCT current_client_id) FROM equipment WHERE current_client_id IS NOT NULL AND kanban_status = 'alocado'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Clientes — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-6xl mx-auto space-y-5">

    <!-- Header -->
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl lg:text-2xl font-bold text-gray-800 flex items-center gap-2">
          <span class="material-symbols-outlined text-brand">business</span>
          Clientes
        </h1>
        <p class="text-gray-400 text-sm"><?= $total ?> cliente(s) encontrado(s)</p>
      </div>
      <?php if (in_array($_SESSION['user_role'], ['admin','manager'])): ?>
      <a href="/pages/clients/create.php"
         class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-brand-dark transition flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base">person_add</span>
        Novo Cliente
      </a>
      <?php endif; ?>
    </div>

    <?php flashRender(); ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-2xl font-bold text-gray-700"><?= $kpiTotal ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Total Cadastrados</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-2xl font-bold text-green-600"><?= $kpiActive ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Ativos</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-2xl font-bold text-brand"><?= $kpiWithEquip ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Com Equip. Alocado</p>
      </div>
    </div>

    <!-- Filtro -->
    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
      <div class="flex flex-wrap gap-3">
        <div class="flex items-center gap-2 flex-1 min-w-[180px]">
          <span class="material-symbols-outlined text-gray-400 text-lg">search</span>
          <input type="text" name="search" value="<?= sanitize($search) ?>"
                 placeholder="Nome, código ou CNPJ..."
                 class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <select name="active" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="1" <?= $active === '1' ? 'selected' : '' ?>>Ativos</option>
          <option value="0" <?= $active === '0' ? 'selected' : '' ?>>Inativos</option>
          <option value=""  <?= $active === ''  ? 'selected' : '' ?>>Todos</option>
        </select>
        <button type="submit" class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-brand-dark transition flex items-center gap-1.5">
          <span class="material-symbols-outlined text-base">filter_list</span> Filtrar
        </button>
        <a href="/pages/clients/index.php" class="bg-gray-100 text-gray-600 text-sm px-4 py-2 rounded-lg hover:bg-gray-200 transition">Limpar</a>
      </div>
    </form>

    <!-- Tabela -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr class="text-xs text-gray-500 uppercase tracking-wider">
            <th class="text-left px-4 py-3">Código</th>
            <th class="text-left px-4 py-3">Nome</th>
            <th class="text-left px-4 py-3 hidden md:table-cell">CNPJ</th>
            <th class="text-left px-4 py-3 hidden sm:table-cell">Telefone</th>
            <th class="text-left px-4 py-3 hidden lg:table-cell">Cidade/UF</th>
            <th class="text-left px-4 py-3">Equip.</th>
            <th class="text-left px-4 py-3">Status</th>
            <th class="text-left px-4 py-3">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="text-center py-16">
            <span class="material-symbols-outlined text-5xl text-gray-300">search_off</span>
            <p class="text-gray-400 font-medium mt-2">Nenhum cliente encontrado</p>
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $c): ?>
          <tr class="hover:bg-gray-50 transition">
            <td class="px-4 py-3 font-mono text-xs font-semibold text-brand"><?= sanitize($c['client_code']) ?></td>
            <td class="px-4 py-3 font-medium text-gray-800">
              <a href="/pages/clients/view.php?code=<?= urlencode($c['client_code']) ?>"
                 class="hover:underline"><?= sanitize($c['name']) ?></a>
            </td>
            <td class="px-4 py-3 text-gray-500 font-mono text-xs hidden md:table-cell"><?= sanitize($c['cnpj'] ?? '—') ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs hidden sm:table-cell"><?= sanitize($c['phone'] ?? '—') ?></td>
            <td class="px-4 py-3 text-gray-500 text-xs hidden lg:table-cell"><?= sanitize($c['city'] ?? '—') ?><?= $c['state'] ? '/' . sanitize($c['state']) : '' ?></td>
            <td class="px-4 py-3">
              <?php if ($c['active_equipment'] > 0): ?>
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800">
                <span class="material-symbols-outlined" style="font-size:12px">devices</span>
                <?= (int)$c['active_equipment'] ?>
              </span>
              <?php else: ?>
              <span class="text-gray-300 text-xs">—</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold <?= $c['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' ?>">
                <span class="material-symbols-outlined" style="font-size:12px"><?= $c['is_active'] ? 'check_circle' : 'cancel' ?></span>
                <?= $c['is_active'] ? 'Ativo' : 'Inativo' ?>
              </span>
            </td>
            <td class="px-4 py-3">
              <div class="flex gap-2">
                <a href="/pages/clients/view.php?code=<?= urlencode($c['client_code']) ?>"
                   class="inline-flex items-center gap-1 text-xs text-blue-600 hover:underline font-medium">
                  <span class="material-symbols-outlined" style="font-size:14px">visibility</span> Ver
                </a>
                <?php if (in_array($_SESSION['user_role'], ['admin','manager'])): ?>
                <a href="/pages/clients/edit.php?id=<?= $c['id'] ?>"
                   class="inline-flex items-center gap-1 text-xs text-gray-600 hover:underline">
                  <span class="material-symbols-outlined" style="font-size:14px">edit</span> Editar
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
      <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-t border-gray-100 text-sm text-gray-500">
        <span>Página <?= $page ?> de <?= $totalPages ?></span>
        <div class="flex gap-1">
          <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>"
             class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200 text-xs">Primeira</a>
          <?php endif; ?>
          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
             class="px-3 py-1 rounded <?= $p === $page ? 'bg-brand text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
            <?= $p ?>
          </a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"
             class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200 text-xs">Última</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      </div>
    </div>
  </div>
</main>
</body>
</html>
