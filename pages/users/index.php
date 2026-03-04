<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin']);

$db   = getDB();
$rows = $db->query("SELECT id, name, email, role, phone, is_active, last_login, created_at FROM users ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Usuários — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
      <h1 class="text-xl lg:text-2xl font-bold text-gray-800">Usuários</h1>
      <a href="/pages/users/create.php" class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-blue-800 transition">+ Novo Usuário</a>
    </div>
    <?php flashRender(); ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr class="text-xs text-gray-500 uppercase tracking-wider">
            <th class="text-left px-4 py-3">Nome</th>
            <th class="text-left px-4 py-3">E-mail</th>
            <th class="text-left px-4 py-3">Perfil</th>
            <th class="text-left px-4 py-3">Telefone</th>
            <th class="text-left px-4 py-3">Último Login</th>
            <th class="text-left px-4 py-3">Status</th>
            <th class="text-left px-4 py-3">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($rows as $u): ?>
          <tr class="hover:bg-gray-50 transition">
            <td class="px-4 py-3 font-medium text-gray-800"><?= sanitize($u['name']) ?></td>
            <td class="px-4 py-3 text-gray-500"><?= sanitize($u['email']) ?></td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold
                <?= $u['role'] === 'admin' ? 'bg-red-100 text-red-800' : ($u['role'] === 'manager' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700') ?>">
                <?= roleLabel($u['role']) ?>
              </span>
            </td>
            <td class="px-4 py-3 text-gray-500 text-xs"><?= sanitize($u['phone'] ?? '—') ?></td>
            <td class="px-4 py-3 text-gray-400 text-xs"><?= $u['last_login'] ? formatDate($u['last_login'], true) : 'Nunca' ?></td>
            <td class="px-4 py-3">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $u['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700' ?>">
                <?= $u['is_active'] ? 'Ativo' : 'Inativo' ?>
              </span>
            </td>
            <td class="px-4 py-3">
              <?php if ($u['id'] !== (int)$_SESSION['user_id'] || $_SESSION['user_role'] === 'admin'): ?>
              <a href="/pages/users/edit.php?id=<?= $u['id'] ?>" class="text-xs text-brand hover:underline">Editar</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div><!-- /overflow-x-auto -->
    </div>
  </div>
</main>
</body>
</html>
