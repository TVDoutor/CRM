<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
          <input type="password" id="password" name="password"
                 required
                 class="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent transition"
                 placeholder="••••••••">
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

</body>
</html>
