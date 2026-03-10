<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    flashSet('error', 'Usuário não encontrado.');
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $action   = $_POST['action'] ?? 'profile';

    // ── Atualizar dados do perfil ─────────────────────────────────────────────
    if ($action === 'profile') {
        $name  = trim($_POST['name']  ?? '');
        $phone = trim($_POST['phone'] ?? '') ?: null;

        if (!$name) $errors[] = 'Nome é obrigatório.';

        if (empty($errors)) {
            $db->prepare("UPDATE users SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$name, $phone, $userId]);

            // Atualiza sessão com novo nome
            $_SESSION['user_name'] = $name;

            auditLog('UPDATE', 'user', $userId,
                ['name' => $user['name'], 'phone' => $user['phone']],
                ['name' => $name, 'phone' => $phone],
                'Perfil atualizado pelo próprio usuário'
            );

            flashSet('success', 'Perfil atualizado com sucesso!');
            header('Location: ' . BASE_URL . '/pages/profile/edit.php');
            exit;
        }
    }

    // ── Alterar senha ─────────────────────────────────────────────────────────
    if ($action === 'password') {
        $current  = trim($_POST['current_password'] ?? '');
        $new      = trim($_POST['new_password']     ?? '');
        $confirm  = trim($_POST['confirm_password'] ?? '');

        if (!$current) $errors[] = 'Informe a senha atual.';
        if (!$new)     $errors[] = 'Informe a nova senha.';
        if ($new && strlen($new) < 6) $errors[] = 'A nova senha deve ter ao menos 6 caracteres.';
        if ($new && $new !== $confirm) $errors[] = 'As senhas não conferem.';

        if (empty($errors) && !password_verify($current, $user['password_hash'])) {
            $errors[] = 'Senha atual incorreta.';
        }

        if (empty($errors)) {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$hash, $userId]);

            auditLog('UPDATE', 'user', $userId, null, null, 'Senha alterada pelo próprio usuário');

            flashSet('success', 'Senha alterada com sucesso!');
            header('Location: ' . BASE_URL . '/pages/profile/edit.php');
            exit;
        }
    }

    // Recarregar dados após erro
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Meu Perfil — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#dbeafe'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>body{font-family:'Inter',sans-serif;}</style>
</head>
<body class="bg-gray-50" style="display:flex;height:100vh;overflow:hidden;">

<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>

<main class="flex-1 overflow-y-auto p-8">
  <div class="max-w-2xl mx-auto">

    <!-- Header -->
    <div class="flex items-center gap-4 mb-8">
      <div class="w-16 h-16 rounded-full bg-[#1B4F8C] flex items-center justify-center text-white text-xl font-bold shadow-lg">
        <?php
          $initials = '';
          foreach (explode(' ', trim($user['name'])) as $p) {
              $initials .= mb_strtoupper(mb_substr($p, 0, 1));
              if (strlen($initials) >= 2) break;
          }
          echo htmlspecialchars($initials ?: 'U');
        ?>
      </div>
      <div>
        <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($user['name']) ?></h1>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(roleLabel($user['role'])) ?> · <?= htmlspecialchars($user['email']) ?></p>
      </div>
    </div>

    <?php flashRender(); ?>

    <?php if (!empty($errors)): ?>
    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
      <?php foreach ($errors as $e): ?>
      <p class="text-sm text-red-700 flex items-center gap-2">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <?= htmlspecialchars($e) ?>
      </p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Card: Dados Pessoais -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-6">
      <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
          <svg class="w-4 h-4 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
        </div>
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Dados Pessoais</h2>
      </div>
      <form method="POST" class="p-6 space-y-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="profile">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Nome completo *</label>
          <input type="text" name="name" value="<?= sanitize($_POST['name'] ?? $user['name']) ?>" required
                 class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 focus:border-brand">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">E-mail</label>
          <div class="relative">
            <input type="email" value="<?= sanitize($user['email']) ?>" disabled
                   class="w-full px-3 py-2.5 border border-gray-100 rounded-xl text-sm bg-gray-50 text-gray-400 cursor-not-allowed">
            <span class="absolute right-3 top-1/2 -translate-y-1/2">
              <span class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-semibold">Somente Admin</span>
            </span>
          </div>
          <p class="text-xs text-gray-400 mt-1">Alteração de e-mail deve ser solicitada a um administrador.</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Telefone</label>
          <input type="text" name="phone" value="<?= sanitize($_POST['phone'] ?? ($user['phone'] ?? '')) ?>"
                 placeholder="(00) 00000-0000"
                 class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 focus:border-brand">
        </div>

        <div class="pt-2">
          <button type="submit"
                  class="flex items-center gap-2 bg-[#1B4F8C] hover:bg-blue-900 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Salvar Alterações
          </button>
        </div>
      </form>
    </div>

    <!-- Card: Alterar Senha -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
        <div class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center">
          <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
          </svg>
        </div>
        <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Alterar Senha</h2>
      </div>
      <form method="POST" class="p-6 space-y-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="password">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Senha atual *</label>
          <input type="password" name="current_password" required
                 class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 focus:border-brand">
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Nova senha *</label>
            <input type="password" name="new_password" minlength="6" required
                   class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 focus:border-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">Confirmar senha *</label>
            <input type="password" name="confirm_password" minlength="6" required
                   class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 focus:border-brand">
          </div>
        </div>

        <p class="text-xs text-gray-400">Mínimo de 6 caracteres.</p>

        <div class="pt-2">
          <button type="submit"
                  class="flex items-center gap-2 bg-orange-500 hover:bg-orange-600 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            Alterar Senha
          </button>
        </div>
      </form>
    </div>

  </div>
</main>
</body>
</html>
