<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        $error = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$email || !$password) {
            $error = 'Preencha e-mail e senha.';
        } elseif (!doLogin($email, $password)) {
            $error = 'E-mail ou senha inválidos, ou usuário inativo.';
        } else {
            header('Location: ' . BASE_URL . '/pages/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — S8 Conect CRM</title>
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
      <p class="text-blue-200 text-sm mt-1">Controle de Equipamentos</p>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl p-8">
      <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
        <span class="material-symbols-outlined text-brand">login</span>
        Entrar no sistema
      </h2>

      <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm flex items-center gap-2">
          <span class="material-symbols-outlined text-base">error</span> <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" id="loginForm" novalidate>
        <?= csrfField() ?>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1.5" for="email">E-mail</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-400" style="font-size:18px">mail</span>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   required autofocus
                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent transition"
                   placeholder="seu@email.com">
          </div>
        </div>

        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-1.5" for="password">Senha</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-400" style="font-size:18px">lock</span>
            <input type="password" id="password" name="password"
                   required
                   class="w-full pl-10 pr-11 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent transition"
                   placeholder="••••••••">
            <button type="button" id="togglePassword" aria-label="Mostrar ou ocultar senha"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600 focus:outline-none">
              <span class="material-symbols-outlined" style="font-size:20px" id="iconEye">visibility</span>
              <span class="material-symbols-outlined hidden" style="font-size:20px" id="iconEyeSlash">visibility_off</span>
            </button>
          </div>
        </div>

        <button type="submit" id="btnLogin"
                class="w-full bg-brand hover:bg-brand-dark text-white font-semibold py-2.5 rounded-lg transition-colors text-sm flex items-center justify-center gap-2">
          <span class="material-symbols-outlined text-base" id="loginIcon">login</span>
          <span id="loginText">Entrar</span>
        </button>
      </form>

      <div class="mt-4 text-center">
        <a href="<?= BASE_URL ?>/pages/forgot_password.php" class="text-sm text-brand hover:text-brand-dark font-medium inline-flex items-center gap-1 transition">
          <span class="material-symbols-outlined text-sm">lock_reset</span>
          Esqueceu a senha?
        </a>
      </div>
    </div>

    <p class="text-center text-blue-200 text-xs mt-6">
      &copy; <?= date('Y') ?> S8 Conect — Todos os direitos reservados
    </p>
  </div>

  <script>
    document.getElementById('togglePassword').addEventListener('click', function() {
      const pwd = document.getElementById('password');
      const eye = document.getElementById('iconEye');
      const slash = document.getElementById('iconEyeSlash');
      if (pwd.type === 'password') {
        pwd.type = 'text';
        eye.classList.add('hidden');
        slash.classList.remove('hidden');
      } else {
        pwd.type = 'password';
        eye.classList.remove('hidden');
        slash.classList.add('hidden');
      }
    });

    document.getElementById('loginForm')?.addEventListener('submit', function() {
      const btn = document.getElementById('btnLogin');
      btn.disabled = true;
      document.getElementById('loginIcon').classList.add('animate-spin');
      document.getElementById('loginIcon').textContent = 'progress_activity';
      document.getElementById('loginText').textContent = 'Entrando...';
    });
  </script>
</body>
</html>
