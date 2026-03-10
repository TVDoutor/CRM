<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin']);

$db     = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '') ?: null;
    $role     = trim($_POST['role']     ?? 'user');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$name)     $errors[] = 'Nome é obrigatório.';
    if (!$email)    $errors[] = 'E-mail é obrigatório.';
    if (!$password) $errors[] = 'Senha é obrigatória.';
    if ($password !== $confirm) $errors[] = 'As senhas não conferem.';
    if (strlen($password) < 6) $errors[] = 'A senha deve ter ao menos 6 caracteres.';

    if ($email) {
        $dup = $db->prepare('SELECT id FROM users WHERE email = ?');
        $dup->execute([$email]);
        if ($dup->fetch()) $errors[] = 'E-mail já cadastrado.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare("INSERT INTO users (name, email, password_hash, role, phone) VALUES (?,?,?,?,?)")
           ->execute([$name, $email, $hash, $role, $phone]);

        $newId = (int)$db->lastInsertId();
        auditLog('CREATE', 'user', $newId, null, ['name' => $name, 'email' => $email, 'role' => $role], "Usuário criado: $name");

        flashSet('success', "Usuário \"$name\" criado com sucesso.");
        header('Location: ' . BASE_URL . '/pages/users/index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Novo Usuário — S8 Conect CRM</title>
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
      <span class="material-symbols-outlined text-brand">person_add</span>
      Novo Usuário
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

    <form method="POST" id="userForm" class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 space-y-5">
      <?= csrfField() ?>

      <!-- Dados pessoais -->
      <div>
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">badge</span> Dados Pessoais
        </p>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
            <input type="text" name="name" value="<?= sanitize($_POST['name'] ?? '') ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">E-mail *</label>
            <input type="email" name="email" value="<?= sanitize($_POST['email'] ?? '') ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
            <input type="text" name="phone" value="<?= sanitize($_POST['phone'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Perfil</label>
            <select name="role" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
              <option value="user"    <?= ($_POST['role'] ?? 'user') === 'user'    ? 'selected' : '' ?>>Usuário</option>
              <option value="manager" <?= ($_POST['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Gerente</option>
              <option value="admin"   <?= ($_POST['role'] ?? '') === 'admin'   ? 'selected' : '' ?>>Administrador</option>
            </select>
          </div>
        </div>
      </div>

      <hr class="border-gray-100">

      <!-- Senha -->
      <div>
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">lock</span> Segurança
        </p>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Senha *</label>
            <input type="password" name="password" required minlength="6"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <p class="text-[11px] text-gray-400 mt-1">Mínimo de 6 caracteres</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar Senha *</label>
            <input type="password" name="confirm" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
        </div>
      </div>

      <div class="flex gap-3 pt-2">
        <button type="submit" id="btnSubmit"
                class="bg-brand text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-brand-dark transition flex items-center gap-1.5">
          <span class="material-symbols-outlined text-base">save</span>
          Criar Usuário
        </button>
        <a href="/pages/users/index.php" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">Cancelar</a>
      </div>
    </form>
  </div>
</main>
<script>
document.getElementById('userForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Salvando...';
});
</script>
</body>
</html>
