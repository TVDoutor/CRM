<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager']);

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/pages/equipment/index.php'); exit; }

$eq = $db->prepare("SELECT e.*, em.category FROM equipment e JOIN equipment_models em ON em.id = e.model_id WHERE e.id = ?")->execute([$id]) ? null : null;
$stmt = $db->prepare("SELECT e.*, em.category FROM equipment e JOIN equipment_models em ON em.id = e.model_id WHERE e.id = ?");
$stmt->execute([$id]);
$eq = $stmt->fetch();
if (!$eq) { flashSet('error', 'Equipamento não encontrado.'); header('Location: ' . BASE_URL . '/pages/equipment/index.php'); exit; }

$models  = $db->query("SELECT id, brand, model_name FROM equipment_models WHERE is_active = 1 ORDER BY brand")->fetchAll();
$batches = $db->query("SELECT id, name FROM batches ORDER BY created_at DESC")->fetchAll();
$errors = [];

// ── Exclusão (somente admin) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    csrfValidate();
    if ($_SESSION['user_role'] !== 'admin') {
        flashSet('error', 'Somente administradores podem excluir equipamentos.');
        header("Location: /pages/equipment/edit.php?id=$id");
        exit;
    }

    $assetTagDel = $eq['asset_tag'];

    // Remove registros dependentes antes de excluir
    try { $db->prepare("DELETE FROM kanban_history WHERE equipment_id = ?")->execute([$id]); } catch (\Exception $e) {}
    try { $db->prepare("DELETE FROM equipment_notes WHERE equipment_id = ?")->execute([$id]); } catch (\Exception $e) {}
    try { $db->prepare("DELETE FROM audit_log WHERE entity_type = 'equipment' AND entity_id = ?")->execute([$id]); } catch (\Exception $e) {}
    try { $db->prepare("DELETE FROM pipedrive_projects WHERE client_id IN (SELECT current_client_id FROM equipment WHERE id = ?)")->execute([$id]); } catch (\Exception $e) {}

    $db->prepare("DELETE FROM equipment WHERE id = ?")->execute([$id]);

    auditLog('DELETE', 'equipment', $id, ['asset_tag' => $assetTagDel], null, "Equipamento excluído: $assetTagDel");

    flashSet('success', "Equipamento {$assetTagDel} excluído com sucesso.");
    header('Location: /pages/equipment/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $asset_tag              = trim($_POST['asset_tag']              ?? '');
    $model_id               = (int)($_POST['model_id']              ?? 0);
    $serial_number          = trim($_POST['serial_number']          ?? '') ?: null;
    $mac_address            = trim($_POST['mac_address']            ?? '') ?: null;
    $entry_date             = trim($_POST['entry_date']              ?? '');
    $purchase_date          = trim($_POST['purchase_date']          ?? '');
    $warranty_extended_until = trim($_POST['warranty_extended_until'] ?? '') ?: null;
    $batch_id               = (int)($_POST['batch_id']              ?? 0) ?: null;
    $batch                  = trim($_POST['batch']                  ?? '') ?: null;
    $notes                  = trim($_POST['notes']                  ?? '') ?: null;
    $contract_type          = trim($_POST['contract_type']          ?? '') ?: null;

    if (!$asset_tag)     $errors[] = 'Etiqueta é obrigatória.';
    if (!$model_id)      $errors[] = 'Modelo é obrigatório.';
    if (!$entry_date)    $errors[] = 'Data de entrada é obrigatória.';
    if (!$purchase_date) $errors[] = 'Data de compra é obrigatória.';
    if (!$contract_type) $errors[] = 'Tipo de contrato é obrigatório.';
    if ($warranty_extended_until && $purchase_date && strtotime($warranty_extended_until) <= strtotime('+12 months', strtotime($purchase_date))) {
        $errors[] = 'A data de renovação deve ser posterior ao vencimento original da garantia (12 meses após a compra).';
    }

    if ($asset_tag && $asset_tag !== $eq['asset_tag']) {
        $dup = $db->prepare('SELECT id FROM equipment WHERE asset_tag = ? AND id != ?');
        $dup->execute([$asset_tag, $id]);
        if ($dup->fetch()) $errors[] = 'Etiqueta já cadastrada para outro equipamento.';
    }

    if ($mac_address && strlen(normalizeMac($mac_address)) >= 12) {
        $allMacs = $db->prepare("SELECT mac_address FROM equipment WHERE mac_address IS NOT NULL AND mac_address != '' AND id != ?");
        $allMacs->execute([$id]);
        $inputNorm = normalizeMac($mac_address);
        foreach ($allMacs->fetchAll(\PDO::FETCH_COLUMN) as $m) {
            if (normalizeMac($m) === $inputNorm) {
                $errors[] = 'MAC address já cadastrado em outro equipamento: ' . $mac_address;
                break;
            }
        }
    }

    if (empty($errors)) {
        $old = ['asset_tag' => $eq['asset_tag'], 'model_id' => $eq['model_id'], 'notes' => $eq['notes']];

        // Busca nome do lote para manter campo legado batch sincronizado
        $batchName = null;
        if ($batch_id) {
            $bStmt = $db->prepare("SELECT name FROM batches WHERE id = ?");
            $bStmt->execute([$batch_id]);
            $batchName = $bStmt->fetchColumn() ?: null;
        }

        // Suporta coluna warranty_extended_until (adicionada via migração)
        try {
            $db->prepare("UPDATE equipment SET asset_tag=?, model_id=?, serial_number=?, mac_address=?,
                entry_date=?, purchase_date=?, warranty_extended_until=?, batch=?, batch_id=?, notes=?, contract_type=?, updated_by=? WHERE id=?")
               ->execute([$asset_tag, $model_id, $serial_number, $mac_address,
                          $entry_date, $purchase_date ?: null, $warranty_extended_until, $batchName, $batch_id, $notes, $contract_type, $_SESSION['user_id'], $id]);
        } catch (\Exception $e) {
            // Fallback: colunas mais novas ainda não existem no banco
            $db->prepare("UPDATE equipment SET asset_tag=?, model_id=?, serial_number=?, mac_address=?,
                entry_date=?, purchase_date=?, batch=?, notes=?, contract_type=?, updated_by=? WHERE id=?")
               ->execute([$asset_tag, $model_id, $serial_number, $mac_address,
                          $entry_date, $purchase_date ?: null, $batchName, $notes, $contract_type, $_SESSION['user_id'], $id]);
        }

        auditLog('UPDATE', 'equipment', $id, $old,
            ['asset_tag' => $asset_tag, 'model_id' => $model_id],
            "Equipamento editado: $asset_tag");

        flashSet('success', 'Equipamento atualizado com sucesso.');
        header("Location: /pages/equipment/view.php?id=$id");
        exit;
    }
}

$f = $_POST ?: $eq;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar Equipamento — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
      <a href="/pages/equipment/view.php?id=<?= $id ?>" class="text-gray-400 hover:text-gray-600">← Ver equipamento</a>
    </div>
    <h1 class="text-2xl font-bold text-gray-800 mb-2">Editar Equipamento</h1>
    <p class="text-gray-500 text-sm mb-6 font-mono"><?= sanitize($eq['asset_tag']) ?></p>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-lg">
      <?php foreach ($errors as $e): ?><p class="text-sm text-red-700"><span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 space-y-5">
      <?= csrfField() ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Etiqueta *</label>
          <input type="text" name="asset_tag" value="<?= sanitize($f['asset_tag']) ?>" required
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Modelo *</label>
          <select name="model_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <?php foreach ($models as $m): ?>
            <option value="<?= $m['id'] ?>" <?= (int)$f['model_id'] === (int)$m['id'] ? 'selected' : '' ?>>
              <?= sanitize($m['brand']) ?> <?= sanitize($m['model_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Número de Série</label>
          <input type="text" name="serial_number" value="<?= sanitize($f['serial_number'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">MAC Address</label>
          <input type="text" name="mac_address" value="<?= sanitize($f['mac_address'] ?? '') ?>"
                 oninput="formatMacMask(this)"
                 maxlength="17"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand font-mono"
                 placeholder="AA:BB:CC:DD:EE:FF">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Data de Entrada *</label>
          <input type="date" name="entry_date" value="<?= sanitize($f['entry_date'] ?? '') ?>" required
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Data de Compra *</label>
          <input type="date" name="purchase_date" value="<?= sanitize($f['purchase_date'] ?? '') ?>"
                 required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1 flex items-center gap-2">
            Renovação de Garantia
            <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-semibold">Opcional</span>
          </label>
          <input type="date" name="warranty_extended_until" value="<?= sanitize($f['warranty_extended_until'] ?? '') ?>"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <p class="text-[11px] text-gray-400 mt-1">Preencha somente se a garantia foi estendida além dos 12 meses originais.</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Contrato *</label>
          <select name="contract_type" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <option value="">Selecione...</option>
            <option value="comodato"            <?= ($f['contract_type'] ?? '') === 'comodato'            ? 'selected' : '' ?>>Comodato</option>
            <option value="equipamento_cliente" <?= ($f['contract_type'] ?? '') === 'equipamento_cliente' ? 'selected' : '' ?>>Equipamento do Cliente</option>
            <option value="parceria"            <?= ($f['contract_type'] ?? '') === 'parceria'            ? 'selected' : '' ?>>Parceria</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Lote</label>
          <select name="batch_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <option value="">Sem lote</option>
            <?php
              $selectedBatchId = isset($_POST['batch_id'])
                  ? (int)$_POST['batch_id']
                  : (int)($eq['batch_id'] ?? 0);
            ?>
            <?php foreach ($batches as $b): ?>
            <option value="<?= $b['id'] ?>"
                    <?= $selectedBatchId === (int)$b['id'] ? 'selected' : '' ?>>
              <?= sanitize($b['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <a href="/pages/equipment/batches.php?action=create" target="_blank"
             class="text-xs text-brand hover:underline mt-1 inline-block">+ Criar novo lote</a>
        </div>
        <div class="col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
          <textarea name="notes" rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"><?= sanitize($f['notes'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="flex items-center justify-between gap-3 pt-2">
        <div class="flex gap-3">
          <button type="submit" class="bg-brand text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">Salvar</button>
          <a href="/pages/equipment/view.php?id=<?= $id ?>" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">Cancelar</a>
        </div>
        <?php if ($_SESSION['user_role'] === 'admin'): ?>
        <button type="button" onclick="document.getElementById('modalDelete').classList.remove('hidden')"
                class="flex items-center gap-2 px-4 py-2.5 bg-red-50 text-red-600 border border-red-200 rounded-lg text-sm font-semibold hover:bg-red-100 transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
          </svg>
          Excluir Equipamento
        </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</main>

<?php if ($_SESSION['user_role'] === 'admin'): ?>
<!-- Modal de confirmação de exclusão -->
<div id="modalDelete" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
      </div>
      <div>
        <h3 class="text-base font-bold text-gray-900">Excluir Equipamento</h3>
        <p class="text-sm text-gray-500">Esta ação não pode ser desfeita.</p>
      </div>
    </div>

    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-5">
      <p class="text-sm text-red-700">
        Você está prestes a excluir permanentemente o equipamento
        <strong class="font-mono"><?= sanitize($eq['asset_tag']) ?></strong>.
        Todo o histórico de movimentações e auditoria deste equipamento também será removido.
      </p>
    </div>

    <p class="text-sm text-gray-600 mb-4">
      Digite <strong class="font-mono text-red-600"><?= sanitize($eq['asset_tag']) ?></strong> para confirmar:
    </p>
    <input type="text" id="deleteConfirmInput"
           placeholder="<?= sanitize($eq['asset_tag']) ?>"
           oninput="checkDeleteConfirm()"
           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono mb-4 focus:outline-none focus:ring-2 focus:ring-red-400">

    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete">
      <div class="flex gap-3">
        <button type="button" onclick="document.getElementById('modalDelete').classList.add('hidden')"
                class="flex-1 px-4 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-200 transition">
          Cancelar
        </button>
        <button type="submit" id="btnConfirmDelete" disabled
                class="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold transition
                       disabled:opacity-40 disabled:cursor-not-allowed hover:bg-red-700 disabled:hover:bg-red-600">
          Excluir definitivamente
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function formatMacMask(input) {
    const raw = input.value.replace(/[^A-Fa-f0-9]/g, '').toUpperCase().slice(0, 12);
    const parts = raw.match(/.{1,2}/g) || [];
    input.value = parts.join(':');
}

document.addEventListener('DOMContentLoaded', function() {
    var mac = document.querySelector('input[name="mac_address"]');
    if (mac && mac.value) formatMacMask(mac);
});

function checkDeleteConfirm() {
    const input    = document.getElementById('deleteConfirmInput').value.trim();
    const expected = '<?= addslashes($eq['asset_tag']) ?>';
    document.getElementById('btnConfirmDelete').disabled = (input !== expected);
}
// Fecha modal ao clicar fora
document.getElementById('modalDelete').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>
<?php endif; ?>

</body>
</html>
