<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin']); // Somente administradores

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/pages/users/index.php'); exit; }

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { flashSet('error', 'Usuário não encontrado.'); header('Location: ' . BASE_URL . '/pages/users/index.php'); exit; }

// ── Exclusão ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfValidate();

    // Não pode excluir a si mesmo
    if ($id === (int)$_SESSION['user_id']) {
        flashSet('error', 'Você não pode excluir sua própria conta.');
        header('Location: /pages/users/edit.php?id=' . $id);
        exit;
    }

    $userName = $user['name'];
    try {
        $db->prepare("DELETE FROM audit_log WHERE user_id = ?")->execute([$id]);
    } catch (\Exception $e) {}
    $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

    auditLog('DELETE', 'user', $id, ['name' => $userName, 'email' => $user['email']], null, "Usuário excluído: $userName");
    flashSet('success', "Usuário \"$userName\" excluído com sucesso.");
    header('Location: /pages/users/index.php');
    exit;
}

// ── Edição ─────────────────────────────────────────────────────────────────
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

    // Não pode rebaixar o único admin
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
  <title>Editar Usuário — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-lg mx-auto">
    <a href="/pages/users/index.php" class="text-gray-400 hover:text-gray-600 text-sm">← Usuários</a>
    <h1 class="text-2xl font-bold text-gray-800 mt-4 mb-6">Editar Usuário</h1>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-lg">
      <?php foreach ($errors as $e): ?><p class="text-sm text-red-700">❌ <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php flashRender(); ?>

    <form method="POST" class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 space-y-4">
      <?= csrfField() ?>

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

      <hr class="border-gray-100">
      <p class="text-xs text-gray-400">Deixe em branco para manter a senha atual.</p>

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

      <div class="flex items-center justify-between gap-3 pt-2">
        <div class="flex gap-3">
          <button type="submit" class="bg-brand text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">Salvar</button>
          <a href="/pages/users/index.php" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">Cancelar</a>
        </div>
        <?php if (!$isSelf): ?>
        <button type="button" onclick="document.getElementById('modalDeleteUser').classList.remove('hidden')"
                class="flex items-center gap-2 px-4 py-2.5 bg-red-50 text-red-600 border border-red-200 rounded-lg text-sm font-semibold hover:bg-red-100 transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          Excluir Usuário
        </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</main>

<?php if (!$isSelf): ?>
<!-- Modal de confirmação de exclusão -->
<div id="modalDeleteUser" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
        </svg>
      </div>
      <div>
        <h3 class="text-base font-bold text-gray-900">Excluir Usuário</h3>
        <p class="text-sm text-gray-500">Esta ação não pode ser desfeita.</p>
      </div>
    </div>

    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-5">
      <p class="text-sm text-red-700">
        Você está prestes a excluir o usuário
        <strong><?= sanitize($user['name']) ?></strong>
        <span class="text-gray-500">(<?= sanitize($user['email']) ?>)</span>.
      </p>
    </div>

    <p class="text-sm text-gray-600 mb-4">
      Digite <strong class="font-mono text-red-600"><?= sanitize($user['email']) ?></strong> para confirmar:
    </p>
    <input type="text" id="deleteUserInput"
           placeholder="<?= sanitize($user['email']) ?>"
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
    const input    = document.getElementById('deleteUserInput').value.trim();
    const expected = '<?= addslashes($user['email']) ?>';
    document.getElementById('btnConfirmDeleteUser').disabled = (input !== expected);
}
document.getElementById('modalDeleteUser').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>
<?php endif; ?>

</body>
</html>
