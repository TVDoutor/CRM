<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin']);

$db   = getDB();
$rows = $db->query("SELECT id, name, email, role, phone, is_active, last_login, created_at FROM users ORDER BY name")->fetchAll();

$totalUsers  = count($rows);
$totalActive = count(array_filter($rows, fn($u) => $u['is_active']));
$totalAdmin  = count(array_filter($rows, fn($u) => $u['role'] === 'admin'));
$search = strtolower(trim($_GET['q'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Usuários — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto space-y-5">

    <!-- Header -->
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-xl lg:text-2xl font-bold text-gray-800 flex items-center gap-2">
          <span class="material-symbols-outlined text-brand">group</span>
          Usuários
        </h1>
        <p class="text-gray-400 text-sm">Gerencie os usuários do sistema</p>
      </div>
      <a href="/pages/users/create.php"
         class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-brand-dark transition flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base">person_add</span>
        Novo Usuário
      </a>
    </div>

    <?php flashRender(); ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-2xl font-bold text-gray-700"><?= $totalUsers ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Total de Usuários</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-2xl font-bold text-green-600"><?= $totalActive ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Ativos</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-2xl font-bold text-red-600"><?= $totalAdmin ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Administradores</p>
      </div>
    </div>

    <!-- Filtro -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
      <div class="flex items-center gap-2">
        <span class="material-symbols-outlined text-gray-400 text-lg">search</span>
        <input type="text" id="userSearch" placeholder="Buscar por nome, e-mail ou perfil..."
               oninput="filterUsers()" value=""
               class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
      </div>
    </div>

    <!-- Tabela -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr class="text-xs text-gray-500 uppercase tracking-wider">
            <th class="text-left px-4 py-3">Usuário</th>
            <th class="text-left px-4 py-3 hidden sm:table-cell">Perfil</th>
            <th class="text-left px-4 py-3 hidden md:table-cell">Telefone</th>
            <th class="text-left px-4 py-3 hidden lg:table-cell">Último Login</th>
            <th class="text-left px-4 py-3">Status</th>
            <th class="text-left px-4 py-3">Ações</th>
          </tr>
        </thead>
        <tbody id="usersBody" class="divide-y divide-gray-50">
          <?php if (empty($rows)): ?>
          <tr id="emptyRow"><td colspan="6" class="text-center py-16">
            <span class="material-symbols-outlined text-5xl text-gray-300 mb-2">group_off</span>
            <p class="text-gray-400 font-medium mt-2">Nenhum usuário cadastrado</p>
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $u):
            $initials = strtoupper(mb_substr($u['name'], 0, 1));
            $avatarColors = ['admin' => 'bg-red-100 text-red-700', 'manager' => 'bg-blue-100 text-blue-700', 'user' => 'bg-gray-100 text-gray-600'];
            $avatarCls = $avatarColors[$u['role']] ?? $avatarColors['user'];
          ?>
          <tr class="user-row hover:bg-gray-50 transition"
              data-search="<?= strtolower(sanitize($u['name'] . ' ' . $u['email'] . ' ' . roleLabel($u['role']))) ?>">
            <td class="px-4 py-3">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full <?= $avatarCls ?> flex items-center justify-center shrink-0 font-bold text-sm">
                  <?= $initials ?>
                </div>
                <div class="min-w-0">
                  <p class="font-medium text-gray-800 truncate"><?= sanitize($u['name']) ?></p>
                  <p class="text-xs text-gray-400 truncate"><?= sanitize($u['email']) ?></p>
                </div>
              </div>
            </td>
            <td class="px-4 py-3 hidden sm:table-cell">
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold
                <?= $u['role'] === 'admin' ? 'bg-red-100 text-red-800' : ($u['role'] === 'manager' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700') ?>">
                <span class="material-symbols-outlined" style="font-size:12px"><?= $u['role'] === 'admin' ? 'shield' : ($u['role'] === 'manager' ? 'manage_accounts' : 'person') ?></span>
                <?= roleLabel($u['role']) ?>
              </span>
            </td>
            <td class="px-4 py-3 text-gray-500 text-xs hidden md:table-cell"><?= sanitize($u['phone'] ?? '—') ?></td>
            <td class="px-4 py-3 text-gray-400 text-xs hidden lg:table-cell">
              <?php if ($u['last_login']): ?>
              <span class="flex items-center gap-1">
                <span class="material-symbols-outlined" style="font-size:13px">schedule</span>
                <?= formatDate($u['last_login'], true) ?>
              </span>
              <?php else: ?>
              <span class="text-gray-300">Nunca</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold <?= $u['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' ?>">
                <span class="material-symbols-outlined" style="font-size:12px"><?= $u['is_active'] ? 'check_circle' : 'cancel' ?></span>
                <?= $u['is_active'] ? 'Ativo' : 'Inativo' ?>
              </span>
            </td>
            <td class="px-4 py-3">
              <?php if ($u['id'] !== (int)$_SESSION['user_id'] || $_SESSION['user_role'] === 'admin'): ?>
              <a href="/pages/users/edit.php?id=<?= $u['id'] ?>"
                 class="inline-flex items-center gap-1 text-xs text-brand hover:underline font-medium">
                <span class="material-symbols-outlined" style="font-size:14px">edit</span> Editar
              </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- No results -->
    <div id="noResults" class="hidden bg-white rounded-xl border border-gray-100 shadow-sm p-10 text-center">
      <span class="material-symbols-outlined text-3xl text-gray-300">search_off</span>
      <p class="text-gray-500 font-medium mt-2">Nenhum usuário encontrado</p>
    </div>
  </div>
</main>
<script>
function filterUsers() {
    const q = document.getElementById('userSearch').value.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.user-row').forEach(row => {
        const match = !q || (row.dataset.search || '').includes(q);
        row.classList.toggle('hidden', !match);
        if (match) visible++;
    });
    document.getElementById('noResults')?.classList.toggle('hidden', visible > 0);
}
</script>
</body>
</html>
