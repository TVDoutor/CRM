<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$db    = getDB();
$token = trim($_GET['token'] ?? '');
$error   = '';
$success = '';
$valid   = false;
$user    = null;

if (!$token || strlen($token) !== 64) {
    $error = 'Link inválido ou expirado.';
} else {
    $stmt = $db->prepare('
        SELECT pr.id as reset_id, pr.user_id, pr.expires_at, pr.used_at, u.name, u.email
        FROM password_resets pr
        JOIN users u ON u.id = pr.user_id
        WHERE pr.token = ? LIMIT 1
    ');
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $error = 'Link inválido ou expirado.';
    } elseif ($reset['used_at']) {
        $error = 'Este link já foi utilizado. Solicite uma nova recuperação.';
    } elseif (strtotime($reset['expires_at']) < time()) {
        $error = 'Este link expirou. Solicite uma nova recuperação.';
    } else {
        $valid = true;
        $user  = $reset;
    }
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        $error = 'Sessão expirada. Recarregue a página.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 6) {
            $error = 'A senha deve ter no mínimo 6 caracteres.';
        } elseif ($password !== $confirm) {
            $error = 'As senhas não coincidem.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
               ->execute([$hash, $user['user_id']]);

            $db->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
               ->execute([$user['reset_id']]);

            $success = true;
            $valid   = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Redefinir Senha — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>
    tailwind.config = {
      theme: { extend: { colors: { brand: { DEFAULT: '#1B4F8C', dark: '#153d6f', light: '#D6E4F0' } } } }
    }
  </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-brand to-blue-900 flex items-center justify-center p-4">

  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <h1 class="text-2xl font-bold text-white">S8 CONECT CRM</h1>
      <p class="text-blue-200 text-sm mt-1">Redefinição de Senha</p>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl p-8">

      <?php if ($success === true): ?>
        <div class="text-center py-4">
          <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-green-600" style="font-size:36px">check_circle</span>
          </div>
          <h2 class="text-xl font-bold text-gray-800 mb-2">Senha alterada!</h2>
          <p class="text-sm text-gray-500 mb-6">Sua senha foi redefinida com sucesso. Agora você pode entrar com a nova senha.</p>
          <a href="<?= BASE_URL ?>/pages/login.php"
             class="inline-flex items-center gap-2 bg-brand hover:bg-brand-dark text-white font-semibold py-2.5 px-6 rounded-lg transition-colors text-sm">
            <span class="material-symbols-outlined text-base">login</span>
            Ir para o login
          </a>
        </div>

      <?php elseif ($valid && $user): ?>
        <h2 class="text-xl font-bold text-gray-800 mb-2 flex items-center gap-2">
          <span class="material-symbols-outlined text-brand">lock_reset</span>
          Nova senha
        </h2>
        <p class="text-sm text-gray-500 mb-6">
          Olá <strong><?= htmlspecialchars($user['name']) ?></strong>, defina sua nova senha abaixo.
        </p>

        <?php if ($error): ?>
          <div class="mb-4 p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm flex items-center gap-2">
            <span class="material-symbols-outlined text-base">error</span>
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <form method="POST" id="resetForm" novalidate>
          <?= csrfField() ?>
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1.5" for="password">Nova senha</label>
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-400" style="font-size:18px">lock</span>
              <input type="password" id="password" name="password"
                     required autofocus minlength="6"
                     class="w-full pl-10 pr-11 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent transition"
                     placeholder="Mínimo 6 caracteres">
              <button type="button" onclick="toggleVis('password','eye1','eye1off')" class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 focus:outline-none">
                <span class="material-symbols-outlined" style="font-size:20px" id="eye1">visibility</span>
                <span class="material-symbols-outlined hidden" style="font-size:20px" id="eye1off">visibility_off</span>
              </button>
            </div>
          </div>

          <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-1.5" for="password_confirm">Confirmar nova senha</label>
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-400" style="font-size:18px">lock</span>
              <input type="password" id="password_confirm" name="password_confirm"
                     required minlength="6"
                     class="w-full pl-10 pr-11 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent transition"
                     placeholder="Repita a nova senha">
              <button type="button" onclick="toggleVis('password_confirm','eye2','eye2off')" class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 focus:outline-none">
                <span class="material-symbols-outlined" style="font-size:20px" id="eye2">visibility</span>
                <span class="material-symbols-outlined hidden" style="font-size:20px" id="eye2off">visibility_off</span>
              </button>
            </div>
          </div>

          <div id="strengthBar" class="mb-4 hidden">
            <div class="flex items-center gap-2 text-xs">
              <div class="flex-1 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                <div id="strengthFill" class="h-full rounded-full transition-all duration-300" style="width:0"></div>
              </div>
              <span id="strengthLabel" class="font-medium text-gray-400">—</span>
            </div>
          </div>

          <button type="submit" id="btnReset"
                  class="w-full bg-brand hover:bg-brand-dark text-white font-semibold py-2.5 rounded-lg transition-colors text-sm flex items-center justify-center gap-2">
            <span class="material-symbols-outlined text-base" id="resetIcon">save</span>
            <span id="resetText">Salvar nova senha</span>
          </button>
        </form>

      <?php else: ?>
        <div class="text-center py-4">
          <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-red-500" style="font-size:36px">link_off</span>
          </div>
          <h2 class="text-xl font-bold text-gray-800 mb-2">Link inválido</h2>
          <p class="text-sm text-gray-500 mb-6"><?= htmlspecialchars($error) ?></p>
          <a href="<?= BASE_URL ?>/pages/forgot_password.php"
             class="inline-flex items-center gap-2 bg-brand hover:bg-brand-dark text-white font-semibold py-2.5 px-6 rounded-lg transition-colors text-sm">
            <span class="material-symbols-outlined text-base">lock_reset</span>
            Solicitar nova recuperação
          </a>
        </div>
      <?php endif; ?>

      <div class="mt-5 text-center">
        <a href="<?= BASE_URL ?>/pages/login.php" class="text-sm text-brand hover:text-brand-dark font-medium inline-flex items-center gap-1 transition">
          <span class="material-symbols-outlined text-base">arrow_back</span>
          Voltar ao login
        </a>
      </div>
    </div>

    <p class="text-center text-blue-200 text-xs mt-6">
      &copy; <?= date('Y') ?> S8 Conect — Todos os direitos reservados
    </p>
  </div>

  <script>
    function toggleVis(inputId, eyeId, eyeOffId) {
      const inp = document.getElementById(inputId);
      const eye = document.getElementById(eyeId);
      const off = document.getElementById(eyeOffId);
      if (inp.type === 'password') {
        inp.type = 'text';
        eye.classList.add('hidden');
        off.classList.remove('hidden');
      } else {
        inp.type = 'password';
        eye.classList.remove('hidden');
        off.classList.add('hidden');
      }
    }

    const pwdInput = document.getElementById('password');
    if (pwdInput) {
      pwdInput.addEventListener('input', function() {
        const bar   = document.getElementById('strengthBar');
        const fill  = document.getElementById('strengthFill');
        const label = document.getElementById('strengthLabel');
        const v = this.value;

        if (!v) { bar.classList.add('hidden'); return; }
        bar.classList.remove('hidden');

        let score = 0;
        if (v.length >= 6) score++;
        if (v.length >= 10) score++;
        if (/[A-Z]/.test(v)) score++;
        if (/[0-9]/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;

        const levels = [
          { w: '20%', c: 'bg-red-500',    t: 'Fraca',      tc: 'text-red-500' },
          { w: '40%', c: 'bg-orange-500',  t: 'Regular',    tc: 'text-orange-500' },
          { w: '60%', c: 'bg-yellow-500',  t: 'Média',      tc: 'text-yellow-600' },
          { w: '80%', c: 'bg-blue-500',    t: 'Boa',        tc: 'text-blue-500' },
          { w: '100%', c: 'bg-green-500',  t: 'Forte',      tc: 'text-green-600' },
        ];
        const lvl = levels[Math.min(score, 4)];
        fill.style.width = lvl.w;
        fill.className = 'h-full rounded-full transition-all duration-300 ' + lvl.c;
        label.textContent = lvl.t;
        label.className = 'font-medium ' + lvl.tc;
      });
    }

    document.getElementById('resetForm')?.addEventListener('submit', function() {
      const btn = document.getElementById('btnReset');
      btn.disabled = true;
      btn.classList.add('opacity-70');
      document.getElementById('resetIcon').classList.add('animate-spin');
      document.getElementById('resetIcon').textContent = 'progress_activity';
      document.getElementById('resetText').textContent = 'Salvando...';
    });
  </script>
</body>
</html>
