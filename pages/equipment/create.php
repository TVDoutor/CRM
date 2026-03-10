<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager']);

$db     = getDB();
$errors = [];

$models  = $db->query("SELECT id, brand, model_name, category FROM equipment_models WHERE is_active = 1 ORDER BY brand, model_name")->fetchAll();
$batches = $db->query("SELECT id, name FROM batches ORDER BY created_at DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $asset_tag     = trim($_POST['asset_tag']     ?? '');
    $model_id      = (int)($_POST['model_id']     ?? 0);
    $serial_number = trim($_POST['serial_number'] ?? '');
    $mac_address   = trim($_POST['mac_address']   ?? '');
    $entry_date    = trim($_POST['entry_date']     ?? '');
    $purchase_date = trim($_POST['purchase_date'] ?? '');
    $batch_id      = (int)($_POST['batch_id']      ?? 0) ?: null;
    $batch         = trim($_POST['batch']         ?? '');
    $notes         = trim($_POST['notes']         ?? '');
    $kanban_status = trim($_POST['kanban_status'] ?? 'entrada');
    $contract_type = trim($_POST['contract_type'] ?? '') ?: null;

    if (!$asset_tag)     $errors[] = 'Etiqueta é obrigatória.';
    if (!$model_id)      $errors[] = 'Modelo é obrigatório.';
    if (!$entry_date)    $errors[] = 'Data de entrada é obrigatória.';
    if (!$purchase_date) $errors[] = 'Data de compra é obrigatória.';
    if (!$contract_type) $errors[] = 'Tipo de contrato é obrigatório.';

    if ($asset_tag) {
        $dup = $db->prepare('SELECT id FROM equipment WHERE asset_tag = ?');
        $dup->execute([$asset_tag]);
        if ($dup->fetch()) $errors[] = 'Etiqueta já cadastrada no sistema.';
    }

    if ($mac_address && strlen(normalizeMac($mac_address)) >= 12) {
        $allMacs = $db->query("SELECT mac_address FROM equipment WHERE mac_address IS NOT NULL AND mac_address != ''")->fetchAll(\PDO::FETCH_COLUMN);
        $inputNorm = normalizeMac($mac_address);
        foreach ($allMacs as $m) {
            if (normalizeMac($m) === $inputNorm) {
                $errors[] = 'MAC address já cadastrado no sistema: ' . $mac_address;
                break;
            }
        }
    }

    if (empty($errors)) {
        // Busca o nome do lote selecionado para preencher o campo legado batch
        $batchName = null;
        if ($batch_id) {
            $bStmt = $db->prepare("SELECT name FROM batches WHERE id = ?");
            $bStmt->execute([$batch_id]);
            $batchName = $bStmt->fetchColumn() ?: null;
        }

        $db->prepare("INSERT INTO equipment
            (asset_tag, model_id, serial_number, mac_address, condition_status, kanban_status,
             contract_type, entry_date, purchase_date, batch, batch_id, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $asset_tag, $model_id, $serial_number ?: null, $mac_address ?: null,
               'novo', $kanban_status, $contract_type, $entry_date, $purchase_date ?: null,
               $batchName, $batch_id, $notes ?: null, $_SESSION['user_id']
           ]);

        $newId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO kanban_history (equipment_id, from_status, to_status, moved_by) VALUES (?,NULL,?,?)")
           ->execute([$newId, $kanban_status, $_SESSION['user_id']]);

        auditLog('CREATE', 'equipment', $newId, null,
            ['asset_tag' => $asset_tag, 'kanban_status' => $kanban_status],
            "Equipamento cadastrado: $asset_tag");

        flashSet('success', "Equipamento $asset_tag cadastrado com sucesso.");
        header('Location: ' . BASE_URL . '/pages/equipment/view.php?id=' . $newId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Novo Equipamento — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
      <a href="/pages/equipment/index.php" class="text-gray-400 hover:text-gray-600">← Equipamentos</a>
    </div>
    <h1 class="text-xl lg:text-2xl font-bold text-gray-800 mb-6">Novo Equipamento</h1>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-lg">
      <?php foreach ($errors as $e): ?>
        <p class="text-sm text-red-700"><span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($e) ?></p>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 space-y-5">
      <?= csrfField() ?>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Etiqueta (asset_tag) *</label>
          <input type="text" name="asset_tag" id="asset_tag" value="<?= sanitize($_POST['asset_tag'] ?? '') ?>"
                 required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand"
                 placeholder="Preenchido automaticamente pelo MAC">
          <p class="text-xs text-gray-400 mt-1">Preenchido automaticamente com os 4 últimos dígitos do MAC ao informá-lo.</p>
        </div>

        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Modelo *</label>
          <select name="model_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <option value="">Selecione...</option>
            <?php foreach ($models as $m): ?>
            <option value="<?= $m['id'] ?>" <?= (int)($_POST['model_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
              <?= sanitize($m['brand']) ?> <?= sanitize($m['model_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Número de Série</label>
          <input type="text" name="serial_number" value="<?= sanitize($_POST['serial_number'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">MAC Address</label>
          <input type="text" name="mac_address" id="mac_address" value="<?= sanitize($_POST['mac_address'] ?? '') ?>"
                 oninput="formatMacMask(this); autoFillAssetTag(this.value)"
                 maxlength="17"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand font-mono"
                 placeholder="AA:BB:CC:DD:EE:FF">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Data de Entrada *</label>
          <input type="date" name="entry_date" value="<?= sanitize($_POST['entry_date'] ?? date('Y-m-d')) ?>"
                 required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Data de Compra *</label>
          <input type="date" name="purchase_date" value="<?= sanitize($_POST['purchase_date'] ?? '') ?>"
                 required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Lote</label>
          <select name="batch_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <option value="">Sem lote</option>
            <?php foreach ($batches as $b): ?>
            <option value="<?= $b['id'] ?>"
                    <?= (int)($_POST['batch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
              <?= sanitize($b['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <a href="/pages/equipment/batches.php?action=create" target="_blank"
             class="text-xs text-brand hover:underline mt-1 inline-block">+ Criar novo lote</a>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Contrato *</label>
          <select name="contract_type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <option value="">Selecione...</option>
            <option value="comodato"            <?= ($_POST['contract_type'] ?? '') === 'comodato'            ? 'selected' : '' ?>>Comodato</option>
            <option value="equipamento_cliente" <?= ($_POST['contract_type'] ?? '') === 'equipamento_cliente' ? 'selected' : '' ?>>Equipamento do Cliente</option>
            <option value="parceria"            <?= ($_POST['contract_type'] ?? '') === 'parceria'            ? 'selected' : '' ?>>Parceria</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Status inicial</label>
          <select name="kanban_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <option value="entrada" <?= ($_POST['kanban_status'] ?? 'entrada') === 'entrada' ? 'selected' : '' ?>>Entrada</option>
            <option value="aguardando_instalacao" <?= ($_POST['kanban_status'] ?? '') === 'aguardando_instalacao' ? 'selected' : '' ?>>Aguardando Instalação</option>
          </select>
        </div>

        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
          <textarea name="notes" rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"><?= sanitize($_POST['notes'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="flex gap-3 pt-2">
        <button type="submit" class="bg-brand text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">
          Salvar Equipamento
        </button>
        <a href="/pages/equipment/index.php" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
          Cancelar
        </a>
      </div>
    </form>
  </div>
</main>
<script>
function formatMacMask(input) {
    const raw = input.value.replace(/[^A-Fa-f0-9]/g, '').toUpperCase().slice(0, 12);
    const parts = raw.match(/.{1,2}/g) || [];
    input.value = parts.join(':');
}

function macToAssetTag(mac) {
    // Usa últimos 6 dígitos do MAC para evitar colisão entre MACs diferentes (ex: 90:..:A8:86:2E vs 98:..:AB:86:2E)
    const digits = mac.replace(/[^A-Fa-f0-9]/g, '');
    return digits.length >= 6 ? digits.slice(-6).toUpperCase() : (digits.length >= 4 ? digits.slice(-4).toUpperCase() : '');
}

function autoFillAssetTag(macValue) {
    const tagInput = document.getElementById('asset_tag');
    if (!tagInput) return;
    const tag = macToAssetTag(macValue);
    // Só preenche automaticamente — não sobrescreve se o usuário já digitou algo diferente de um tag gerado por MAC anterior
    if (tag) {
        tagInput.value = tag;
        tagInput.classList.add('ring-2', 'ring-green-400', 'border-green-400');
        setTimeout(() => tagInput.classList.remove('ring-2', 'ring-green-400', 'border-green-400'), 1500);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var mac = document.getElementById('mac_address');
    if (mac && mac.value) formatMacMask(mac);
});
</script>
</body>
</html>
