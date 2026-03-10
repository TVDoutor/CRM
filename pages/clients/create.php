<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager']);

$db     = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $code    = trim($_POST['client_code']  ?? '');
    $name    = trim($_POST['name']         ?? '');
    $cnpj    = trim($_POST['cnpj']         ?? '') ?: null;
    $contact = trim($_POST['contact_name'] ?? '') ?: null;
    $phone   = trim($_POST['phone']        ?? '') ?: null;
    $email   = trim($_POST['email']        ?? '') ?: null;
    $address = trim($_POST['address']      ?? '') ?: null;
    $city    = trim($_POST['city']         ?? '') ?: null;
    $state   = strtoupper(trim($_POST['state'] ?? '')) ?: null;
    $notes   = trim($_POST['notes']        ?? '') ?: null;

    if (!$code) $errors[] = 'Código é obrigatório.';
    if (!$name) $errors[] = 'Nome é obrigatório.';

    if ($code) {
        $dup = $db->prepare('SELECT id FROM clients WHERE client_code = ?');
        $dup->execute([$code]);
        if ($dup->fetch()) $errors[] = 'Código já cadastrado.';
    }

    if (empty($errors)) {
        $db->prepare("INSERT INTO clients
            (client_code, name, cnpj, contact_name, phone, email, address, city, state, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$code, $name, $cnpj, $contact, $phone, $email, $address, $city, $state, $notes]);

        $newId = (int)$db->lastInsertId();
        auditLog('CREATE', 'client', $newId, null, ['client_code' => $code, 'name' => $name], "Cliente criado: $name");

        flashSet('success', "Cliente \"$name\" cadastrado com sucesso.");
        header("Location: /pages/clients/view.php?code=" . urlencode($code));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Novo Cliente — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-2xl mx-auto">
    <a href="/pages/clients/index.php" class="inline-flex items-center gap-1 text-gray-400 hover:text-gray-600 text-sm">
      <span class="material-symbols-outlined text-base">arrow_back</span> Clientes
    </a>
    <h1 class="text-2xl font-bold text-gray-800 mt-4 mb-6 flex items-center gap-2">
      <span class="material-symbols-outlined text-brand">person_add</span>
      Novo Cliente
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

    <form method="POST" id="clientForm" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 space-y-5">
      <?= csrfField() ?>

      <!-- Identificação -->
      <div>
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">badge</span> Identificação
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
            <input type="text" name="client_code" value="<?= sanitize($_POST['client_code'] ?? '') ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">CNPJ</label>
            <input type="text" name="cnpj" value="<?= sanitize($_POST['cnpj'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
            <input type="text" name="name" value="<?= sanitize($_POST['name'] ?? '') ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
        </div>
      </div>

      <hr class="border-gray-100">

      <!-- Contato -->
      <div>
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">call</span> Contato
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome do Contato</label>
            <input type="text" name="contact_name" value="<?= sanitize($_POST['contact_name'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
            <input type="text" name="phone" value="<?= sanitize($_POST['phone'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
            <input type="email" name="email" value="<?= sanitize($_POST['email'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
        </div>
      </div>

      <hr class="border-gray-100">

      <!-- Endereço -->
      <div>
        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm">location_on</span> Endereço
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Endereço</label>
            <input type="text" name="address" value="<?= sanitize($_POST['address'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cidade</label>
            <input type="text" name="city" value="<?= sanitize($_POST['city'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Estado (UF)</label>
            <input type="text" name="state" value="<?= sanitize($_POST['state'] ?? '') ?>" maxlength="2" placeholder="SP"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
        </div>
      </div>

      <hr class="border-gray-100">

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
        <textarea name="notes" rows="3"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"><?= sanitize($_POST['notes'] ?? '') ?></textarea>
      </div>

      <div class="flex gap-3 pt-2">
        <button type="submit" id="btnSubmit"
                class="bg-brand text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-brand-dark transition flex items-center gap-1.5">
          <span class="material-symbols-outlined text-base">save</span> Salvar
        </button>
        <a href="/pages/clients/index.php" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">Cancelar</a>
      </div>
    </form>
  </div>
</main>
<script>
document.getElementById('clientForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Salvando...';
});
</script>
</body>
</html>
