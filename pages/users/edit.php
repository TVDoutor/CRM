<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin']);

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/pages/users/index.php'); exit; }

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { flashSet('error', 'Usuário não encontrado.'); header('Location: ' . BASE_URL . '/pages/users/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfValidate();
    if ($id === (int)$_SESSION['user_id']) {
        flashSet('error', 'Você não pode excluir sua própria conta.');
        header('Location: /pages/users/edit.php?id=' . $id);
        exit;
    }
    $userName = $user['name'];
    try { $db->prepare("DELETE FROM audit_log WHERE user_id = ?")->execute([$id]); } catch (\Exception $e) {}
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    auditLog('DELETE', 'user', $id, ['name' => $userName, 'email' => $user['email']], null, "Usuário excluído: $userName");
    flashSet('success', "Usuário \"$userName\" excluído com sucesso.");
    header('Location: /pages/users/index.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '') ?: null;
    $role     = trim($_POST['role']     ?? 'user');
    $active   = isset($_POST['is_active']) ? 1 : 0;
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$name)  $errors[] = 'Nome é obrigatório.';
    if (!$email) $errors[] = 'E-mail é obrigatório.';
    if ($password && $password !== $confirm) $errors[] = 'As senhas não conferem.';
    if ($password && strlen($password) < 6)  $errors[] = 'A senha deve ter ao menos 6 caracteres.';

    if ($role !== 'admin' && $user['role'] === 'admin') {
        $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount <= 1) $errors[] = 'Não é possível rebaixar o único administrador do sistema.';
    }

    if ($email && $email !== $user['email']) {
        $dup = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $dup->execute([$email, $id]);
        if ($dup->fetch()) $errors[] = 'E-mail já cadastrado para outro usuário.';
    }

    if (empty($errors)) {
        $old = ['name' => $user['name'], 'role' => $user['role'], 'is_active' => $user['is_active']];
        if ($password) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, is_active=?, password_hash=? WHERE id=?")
               ->execute([$name, $email, $phone, $role, $active, $hash, $id]);
        } else {
            $db->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, is_active=? WHERE id=?")
               ->execute([$name, $email, $phone, $role, $active, $id]);
        }
        auditLog('UPDATE', 'user', $id, $old, ['name' => $name, 'role' => $role], "Usuário editado: $name");
        flashSet('success', 'Usuário atualizado com sucesso.');
        header('Location: ' . BASE_URL . '/pages/users/index.php');
        exit;
    }
}
$f = $_POST ?: $user;
$isSelf = ($id === (int)$_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar Usuário — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-lg mx-auto">
    <a href="/pages/users/index.php" class="inline-flex items-center gap-1 text-gray-400 hover:text-gray-600 text-sm">
      <span class="material-symbols-outlined text-base">arrow_back</span> Usuários
    </a>
    <h1 class="text-2xl font-bold text-gray-800 mt-4 mb-6 flex items-center gap-2">
      <span class="material-symbols-outlined text-brand">edit</span>
      Editar Usuário
    </h1>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-xl">
      <?php foreach ($errors as $e): ?>
      <p class="text-sm text-red-700 flex items-center gap-1.5">
        <span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($e) ?>
      </p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php flashRender(); ?>

    <form method="POST" id="editForm" class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 space-y-5">
      <?= csrfField() ?>

      <div>
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">badge</span> Dados Pessoais
        </p>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
            <input type="text" name="name" value="<?= sanitize($f['name']) ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
              E-mail *
              <span class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-semibold">Somente Admin</span>
            </label>
            <input type="email" name="email" value="<?= sanitize($f['email']) ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
            <input type="text" name="phone" value="<?= sanitize($f['phone'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Perfil</label>
            <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
              <option value="user"    <?= ($f['role'] ?? 'user') === 'user'    ? 'selected' : '' ?>>Usuário</option>
              <option value="manager" <?= ($f['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Gerente</option>
              <option value="admin"   <?= ($f['role'] ?? '') === 'admin'   ? 'selected' : '' ?>>Administrador</option>
            </select>
          </div>
          <div>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-brand rounded"
                     <?= ($f['is_active'] ?? 1) ? 'checked' : '' ?>>
              <span class="text-sm font-medium text-gray-700">Usuário ativo</span>
            </label>
          </div>
        </div>
      </div>

      <hr class="border-gray-100">

      <div>
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">lock</span> Alterar Senha
        </p>
        <p class="text-xs text-gray-400 mb-3">Deixe em branco para manter a senha atual.</p>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nova Senha</label>
            <input type="password" name="password" minlength="6"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar Nova Senha</label>
            <input type="password" name="confirm"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
        </div>
      </div>

      <div class="flex items-center justify-between gap-3 pt-2">
        <div class="flex gap-3">
          <button type="submit" id="btnSubmit"
                  class="bg-brand text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-brand-dark transition flex items-center gap-1.5">
            <span class="material-symbols-outlined text-base">save</span> Salvar
          </button>
          <a href="/pages/users/index.php" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">Cancelar</a>
        </div>
        <?php if (!$isSelf): ?>
        <button type="button" onclick="document.getElementById('modalDeleteUser').classList.remove('hidden')"
                class="flex items-center gap-1.5 px-4 py-2.5 bg-red-50 text-red-600 border border-red-200 rounded-lg text-sm font-semibold hover:bg-red-100 transition">
          <span class="material-symbols-outlined text-base">delete</span>
          Excluir
        </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</main>

<?php if (!$isSelf): ?>
<div id="modalDeleteUser" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-red-600">person_remove</span>
      </div>
      <div>
        <h3 class="text-base font-bold text-gray-900">Excluir Usuário</h3>
        <p class="text-sm text-gray-500">Esta ação não pode ser desfeita.</p>
      </div>
    </div>
    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-5">
      <p class="text-sm text-red-700">
        Você está prestes a excluir <strong><?= sanitize($user['name']) ?></strong>
        <span class="text-gray-500">(<?= sanitize($user['email']) ?>)</span>.
      </p>
    </div>
    <p class="text-sm text-gray-600 mb-4">
      Digite <strong class="font-mono text-red-600"><?= sanitize($user['email']) ?></strong> para confirmar:
    </p>
    <input type="text" id="deleteUserInput" placeholder="<?= sanitize($user['email']) ?>"
           oninput="checkDeleteUser()"
           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono mb-4 focus:outline-none focus:ring-2 focus:ring-red-400">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <div class="flex gap-3">
        <button type="button" onclick="document.getElementById('modalDeleteUser').classList.add('hidden')"
                class="flex-1 px-4 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-200 transition">
          Cancelar
        </button>
        <button type="submit" id="btnConfirmDeleteUser" disabled
                class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold transition
                       disabled:opacity-40 disabled:cursor-not-allowed hover:bg-red-700 disabled:hover:bg-red-600">
          Excluir definitivamente
        </button>
      </div>
    </form>
  </div>
</div>
<script>
function checkDeleteUser() {
    const input = document.getElementById('deleteUserInput').value.trim();
    const expected = <?= json_encode($user['email']) ?>;
    document.getElementById('btnConfirmDeleteUser').disabled = (input !== expected);
}
document.getElementById('modalDeleteUser').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>
<?php endif; ?>

<script>
document.getElementById('editForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Salvando...';
});
</script>
</body>
</html>
