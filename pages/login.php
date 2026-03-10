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
  <title>Login — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: { DEFAULT: '#1B4F8C', dark: '#153d6f', light: '#D6E4F0' }
          }
        }
      }
    }
  </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-brand to-blue-900 flex items-center justify-center p-4">

  <div class="w-full max-w-sm">
    <div class="text-center mb-8">
      <h1 class="text-2xl font-bold text-white">TV Doutor CRM</h1>
      <p class="text-blue-200 text-sm mt-1">Controle de Equipamentos</p>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl p-8">
      <h2 class="text-xl font-bold text-gray-800 mb-6">Entrar no sistema</h2>

      <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm flex items-center gap-2">
          <span>❌</span> <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <?= csrfField() ?>
        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1.5" for="email">E-mail</label>
          <input type="email" id="email" name="email"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 required autofocus
                 class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent transition"
                 placeholder="seu@email.com">
        </div>

        <div class="mb-6">
          <label class="block text-sm font-medium text-gray-700 mb-1.5" for="password">Senha</label>
          <div class="relative">
            <input type="password" id="password" name="password"
                   required
                   class="w-full px-4 py-2.5 pr-11 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent transition"
                   placeholder="••••••••">
            <button type="button" id="togglePassword" aria-label="Mostrar ou ocultar senha"
                    class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-gray-500 hover:text-gray-700 focus:outline-none">
              <svg id="iconEye" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
              <svg id="iconEyeSlash" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878a4.5 4.5 0 106.262 6.262M4.031 11.117A8.959 8.959 0 003 12c0 4.478 2.943 8.268 7 9.542 3.968-1.274 7.261-4.965 8.617-9.19"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit"
                class="w-full bg-brand hover:bg-brand-dark text-white font-semibold py-2.5 rounded-lg transition-colors text-sm">
          Entrar
        </button>
      </form>
    </div>

    <p class="text-center text-blue-200 text-xs mt-6">
      © <?= date('Y') ?> TV Doutor — Todos os direitos reservados
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
  </script>
</body>
</html>
