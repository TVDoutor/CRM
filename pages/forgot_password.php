<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/pages/dashboard.php');
    exit;
}

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValid()) {
        $error = 'Sessão expirada. Recarregue a página.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um e-mail válido.';
        } else {
            $db   = getDB();
            $stmt = $db->prepare('SELECT id, name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $db->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
                   ->execute([$user['id']]);

                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $db->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
                   ->execute([$user['id'], $token, $expiresAt]);

                $resetUrl = BASE_URL . '/pages/reset_password.php?token=' . $token;

                $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8">
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin:0; padding:0; background:#f3f4f6; }
                    .container { max-width:520px; margin:24px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.1); }
                    .header { background:#1B4F8C; padding:24px 28px; text-align:center; }
                    .header h1 { color:#fff; font-size:20px; margin:0; }
                    .body { padding:28px; color:#374151; font-size:14px; line-height:1.7; }
                    .btn { display:inline-block; padding:12px 32px; background:#1B4F8C; color:#fff !important; text-decoration:none; border-radius:8px; font-weight:600; font-size:14px; margin:16px 0; }
                    .footer { background:#f9fafb; padding:16px 28px; font-size:11px; color:#9ca3af; border-top:1px solid #e5e7eb; text-align:center; }
                    .note { background:#fef9c3; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; font-size:12px; color:#92400e; margin-top:16px; }
                </style></head><body>
                <div class="container">
                    <div class="header"><h1>Recuperação de Senha</h1></div>
                    <div class="body">
                        <p>Olá <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>
                        <p>Recebemos uma solicitação para redefinir a senha da sua conta no <strong>S8 Conect CRM</strong>.</p>
                        <p style="text-align:center;"><a href="' . $resetUrl . '" class="btn">Redefinir minha senha</a></p>
                        <div class="note">
                            <strong>Atenção:</strong> Este link é válido por <strong>1 hora</strong>. Se você não solicitou essa alteração, ignore este e-mail.
                        </div>
                    </div>
                    <div class="footer">S8 Conect CRM · crm.tvdoutor.com.br</div>
                </div></body></html>';

                $headers = implode("\r\n", [
                    'MIME-Version: 1.0',
                    'Content-Type: text/html; charset=UTF-8',
                    'From: S8 Conect CRM <noreply@crm.tvdoutor.com.br>',
                    'Reply-To: noreply@crm.tvdoutor.com.br',
                    'X-Mailer: S8ConectCRM/1.0',
                ]);

                @mail($user['email'], 'Recuperação de Senha — S8 Conect CRM', $htmlBody, $headers);
            }

            $success = 'Se o e-mail estiver cadastrado, você receberá um link para redefinir sua senha.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recuperar Senha — S8 Conect CRM</title>
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
      <p class="text-blue-200 text-sm mt-1">Recuperação de Senha</p>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl p-8">
      <h2 class="text-xl font-bold text-gray-800 mb-2 flex items-center gap-2">
        <span class="material-symbols-outlined text-brand">lock_reset</span>
        Esqueceu a senha?
      </h2>
      <p class="text-sm text-gray-500 mb-6">Informe seu e-mail e enviaremos um link para redefinir sua senha.</p>

      <?php if ($success): ?>
        <div class="mb-4 p-3 bg-green-50 border border-green-300 rounded-lg text-green-700 text-sm flex items-center gap-2">
          <span class="material-symbols-outlined text-base">check_circle</span>
          <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-50 border border-red-300 rounded-lg text-red-700 text-sm flex items-center gap-2">
          <span class="material-symbols-outlined text-base">error</span>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST" id="forgotForm" novalidate>
        <?= csrfField() ?>
        <div class="mb-5">
          <label class="block text-sm font-medium text-gray-700 mb-1.5" for="email">E-mail cadastrado</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-gray-400" style="font-size:18px">mail</span>
            <input type="email" id="email" name="email"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   required autofocus
                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-transparent transition"
                   placeholder="seu@email.com">
          </div>
        </div>

        <button type="submit" id="btnSend"
                class="w-full bg-brand hover:bg-brand-dark text-white font-semibold py-2.5 rounded-lg transition-colors text-sm flex items-center justify-center gap-2">
          <span class="material-symbols-outlined text-base" id="sendIcon">send</span>
          <span id="sendText">Enviar link de recuperação</span>
        </button>
      </form>
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
    document.getElementById('forgotForm')?.addEventListener('submit', function() {
      const btn = document.getElementById('btnSend');
      btn.disabled = true;
      btn.classList.add('opacity-70');
      document.getElementById('sendIcon').classList.add('animate-spin');
      document.getElementById('sendIcon').textContent = 'progress_activity';
      document.getElementById('sendText').textContent = 'Enviando...';
    });
  </script>
</body>
</html>
