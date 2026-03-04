<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager']);

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/pages/clients/index.php'); exit; }

$stmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) { flashSet('error', 'Cliente não encontrado.'); header('Location: ' . BASE_URL . '/pages/clients/index.php'); exit; }

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
    $active  = isset($_POST['is_active']) ? 1 : 0;

    if (!$code) $errors[] = 'Código é obrigatório.';
    if (!$name) $errors[] = 'Nome é obrigatório.';

    if ($code && $code !== $client['client_code']) {
        $dup = $db->prepare('SELECT id FROM clients WHERE client_code = ? AND id != ?');
        $dup->execute([$code, $id]);
        if ($dup->fetch()) $errors[] = 'Código já cadastrado para outro cliente.';
    }

    if (empty($errors)) {
        $old = ['name' => $client['name'], 'client_code' => $client['client_code']];
        $db->prepare("UPDATE clients SET client_code=?, name=?, cnpj=?, contact_name=?,
            phone=?, email=?, address=?, city=?, state=?, notes=?, is_active=? WHERE id=?")
           ->execute([$code, $name, $cnpj, $contact, $phone, $email, $address, $city, $state, $notes, $active, $id]);

        auditLog('UPDATE', 'client', $id, $old, ['name' => $name, 'client_code' => $code], "Cliente editado: $name");
        flashSet('success', 'Cliente atualizado com sucesso.');
        header("Location: /pages/clients/view.php?code=" . urlencode($code));
        exit;
    }
}
$f = $_POST ?: $client;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar Cliente — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-2xl mx-auto">
    <a href="/pages/clients/view.php?code=<?= urlencode($client['client_code']) ?>" class="text-gray-400 hover:text-gray-600 text-sm">← Ver cliente</a>
    <h1 class="text-2xl font-bold text-gray-800 mt-4 mb-6">Editar Cliente</h1>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-lg">
      <?php foreach ($errors as $e): ?><p class="text-sm text-red-700">❌ <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 space-y-4">
      <?= csrfField() ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
          <input type="text" name="client_code" value="<?= sanitize($f['client_code']) ?>" required
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">CNPJ</label>
          <input type="text" name="cnpj" value="<?= sanitize($f['cnpj'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
          <input type="text" name="name" value="<?= sanitize($f['name']) ?>" required
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Nome do Contato</label>
          <input type="text" name="contact_name" value="<?= sanitize($f['contact_name'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
          <input type="text" name="phone" value="<?= sanitize($f['phone'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
          <input type="email" name="email" value="<?= sanitize($f['email'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Endereço</label>
          <input type="text" name="address" value="<?= sanitize($f['address'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Cidade</label>
          <input type="text" name="city" value="<?= sanitize($f['city'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Estado (UF)</label>
          <input type="text" name="state" value="<?= sanitize($f['state'] ?? '') ?>" maxlength="2"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
          <textarea name="notes" rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"><?= sanitize($f['notes'] ?? '') ?></textarea>
        </div>
        <div class="col-span-2">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-brand rounded"
                   <?= ($f['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span class="text-sm font-medium text-gray-700">Cliente ativo</span>
          </label>
        </div>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="bg-brand text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">Salvar</button>
        <a href="/pages/clients/view.php?code=<?= urlencode($client['client_code']) ?>" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">Cancelar</a>
      </div>
    </form>
  </div>
</main>
</body>
</html>
