<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db     = getDB();
$errors = [];

$models  = $db->query("SELECT id, brand, model_name FROM equipment_models WHERE is_active = 1 ORDER BY brand, model_name")->fetchAll();
$batches = $db->query("SELECT id, name, received_at FROM batches ORDER BY created_at DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $batch_id      = (int)($_POST['batch_id']      ?? 0);
    $entry_date    = trim($_POST['entry_date']     ?? '');
    $purchase_date = trim($_POST['purchase_date'] ?? '');
    $model_id      = (int)($_POST['model_id']     ?? 0);
    $asset_tags    = $_POST['asset_tag']    ?? [];
    $serials       = $_POST['serial_number'] ?? [];
    $macs          = $_POST['mac_address']  ?? [];

    if (!$batch_id)      $errors[] = 'Selecione um lote.';
    if (!$entry_date)    $errors[] = 'Data de entrada é obrigatória.';
    if (!$purchase_date) $errors[] = 'Data de compra é obrigatória.';
    if (!$model_id)      $errors[] = 'Modelo é obrigatório.';
    if (empty($asset_tags)) $errors[] = 'Adicione ao menos um equipamento.';

    // Buscar nome do lote para uso nas mensagens
    $batchRow = null;
    if ($batch_id) {
        $bStmt = $db->prepare("SELECT id, name FROM batches WHERE id = ?");
        $bStmt->execute([$batch_id]);
        $batchRow = $bStmt->fetch();
        if (!$batchRow) $errors[] = 'Lote inválido.';
    }
    $batchName = $batchRow['name'] ?? '';

    // Checar duplicatas entre si (etiqueta)
    $uniqueTags = array_unique(array_map('trim', $asset_tags));
    if (count($uniqueTags) !== count($asset_tags)) {
        $errors[] = 'Existem etiquetas duplicadas no lote.';
    }

    // Checar duplicatas de MAC no lote (validação pelo MAC completo)
    $normalizedMacs = [];
    $dupMacsInBatch = [];
    foreach ($macs as $i => $mac) {
        $mac = trim($mac);
        if (!$mac) continue;
        $norm = normalizeMac($mac);
        if (strlen($norm) < 12) continue;
        if (isset($normalizedMacs[$norm])) {
            $dupMacsInBatch[] = $mac;
        } else {
            $normalizedMacs[$norm] = $mac;
        }
    }
    if (!empty($dupMacsInBatch)) {
        $errors[] = 'MAC addresses duplicados no lote: ' . implode(', ', array_unique($dupMacsInBatch));
    }

    // Checar duplicatas no banco: etiqueta E MAC completo
    if (empty($errors) && !empty($uniqueTags)) {
        $placeholders = implode(',', array_fill(0, count($uniqueTags), '?'));
        $dupStmt = $db->prepare("SELECT asset_tag FROM equipment WHERE asset_tag IN ($placeholders)");
        $dupStmt->execute(array_values($uniqueTags));
        $dups = $dupStmt->fetchAll(\PDO::FETCH_COLUMN);
        if ($dups) {
            $errors[] = 'Etiquetas já existem no banco: ' . implode(', ', $dups);
        }
    }
    // Checar MAC completo já cadastrado no banco
    if (empty($errors) && !empty($normalizedMacs)) {
        $allMacs = $db->query("SELECT mac_address FROM equipment WHERE mac_address IS NOT NULL AND mac_address != ''")->fetchAll(\PDO::FETCH_COLUMN);
        $existingNorm = [];
        foreach ($allMacs as $m) {
            $n = normalizeMac($m);
            if (strlen($n) >= 12) $existingNorm[$n] = $m;
        }
        $dupMacsInDb = [];
        foreach ($normalizedMacs as $norm => $inputMac) {
            if (isset($existingNorm[$norm])) {
                $dupMacsInDb[] = $inputMac;
            }
        }
        if (!empty($dupMacsInDb)) {
            $errors[] = 'MAC addresses já cadastrados no banco: ' . implode(', ', array_unique($dupMacsInDb));
        }
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Criar operação de ENTRADA
            $db->prepare("INSERT INTO equipment_operations (operation_type, operation_date, notes, performed_by)
                          VALUES ('ENTRADA', NOW(), ?, ?)")
               ->execute(["Entrada de lote $batchName", $_SESSION['user_id']]);
            $opId = (int)$db->lastInsertId();

            $count = 0;
            foreach ($asset_tags as $i => $tag) {
                $tag = trim($tag);
                if (!$tag) continue;

                $serial = trim($serials[$i] ?? '') ?: null;
                $mac    = trim($macs[$i]    ?? '') ?: null;

                $db->prepare("INSERT INTO equipment
                    (asset_tag, model_id, serial_number, mac_address, condition_status, kanban_status,
                     entry_date, purchase_date, batch, batch_id, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$tag, $model_id, $serial, $mac, 'novo', 'entrada',
                               $entry_date, $purchase_date, $batchName, $batch_id, $_SESSION['user_id']]);

                $eqId = (int)$db->lastInsertId();

                $db->prepare("INSERT INTO kanban_history (equipment_id, from_status, to_status, moved_by) VALUES (?,NULL,'entrada',?)")
                   ->execute([$eqId, $_SESSION['user_id']]);

                $db->prepare("INSERT INTO equipment_operation_items (operation_id, equipment_id) VALUES (?,?)")
                   ->execute([$opId, $eqId]);

                auditLog('CREATE', 'equipment', $eqId, null,
                    ['asset_tag' => $tag, 'batch' => $batchName, 'kanban_status' => 'entrada'],
                    "Entrada de lote $batchName: $tag");
                $count++;
            }

            $db->commit();
            flashSet('success', "Lote de $count equipamentos cadastrado com sucesso! Lote: $batchName");
            header('Location: ' . BASE_URL . '/pages/equipment/index.php?batch_saved=1');
            exit;
        } catch (\Exception $e) {
            $db->rollBack();
            $errors[] = 'Erro ao salvar lote: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Entrada de Lote — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-4xl mx-auto">
    <div class="flex items-center gap-3 mb-4">
      <a href="/pages/equipment/index.php" class="text-gray-400 hover:text-gray-600 text-sm">← Equipamentos</a>
    </div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">Entrada de Lote</h1>
    <p class="text-gray-500 text-sm mb-6">Cadastre múltiplos equipamentos de uma só vez</p>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-lg">
      <p class="text-sm font-semibold text-red-800 mb-2">Os dados do formulário foram preservados. Corrija os erros e tente novamente.</p>
      <?php foreach ($errors as $e): ?><p class="text-sm text-red-700"><span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    // Repopular linhas de equipamentos em caso de erro (evita perda dos 16+ itens digitados)
    $postRows = [];
    if (!empty($errors) && !empty($_POST['asset_tag'])) {
        $tags  = (array)($_POST['asset_tag'] ?? []);
        $macs  = (array)($_POST['mac_address'] ?? []);
        $serials = (array)($_POST['serial_number'] ?? []);
        foreach ($tags as $i => $tag) {
            $postRows[] = [
                'asset_tag'     => $tag,
                'mac_address'   => $macs[$i] ?? '',
                'serial_number' => $serials[$i] ?? '',
            ];
        }
    }
    ?>

    <form method="POST" id="batchForm" autocomplete="off">
      <?= csrfField() ?>

      <!-- Dados gerais do lote -->
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-5">
        <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider mb-4">Dados do Lote</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Lote *</label>
            <?php if (empty($batches)): ?>
            <div class="w-full px-3 py-2 border border-amber-300 bg-amber-50 rounded-lg text-sm text-amber-700">
              Nenhum lote cadastrado.
              <a href="/pages/equipment/batches.php?action=create" class="underline font-semibold" target="_blank">Criar lote</a>
            </div>
            <?php else: ?>
            <select name="batch_id" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
              <option value="">Selecione um lote...</option>
              <?php foreach ($batches as $b): ?>
              <option value="<?= $b['id'] ?>"
                      <?= (int)($_POST['batch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                <?= sanitize($b['name']) ?>
                <?= $b['received_at'] ? '(' . formatDate($b['received_at']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <a href="/pages/equipment/batches.php?action=create" target="_blank"
               class="text-xs text-brand hover:underline mt-1 inline-block">+ Criar novo lote</a>
            <?php endif; ?>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data de Entrada *</label>
            <input type="date" name="entry_date" value="<?= sanitize($_POST['entry_date'] ?? date('Y-m-d')) ?>" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data de Compra *</label>
            <input type="date" name="purchase_date" value="<?= sanitize($_POST['purchase_date'] ?? '') ?>"
                   required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Modelo *</label>
            <select name="model_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
              <option value="">Selecione...</option>
              <?php foreach ($models as $m): ?>
              <option value="<?= $m['id'] ?>" <?= (int)($_POST['model_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                <?= sanitize(displayModelName($m['brand'], $m['model_name'])) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-4 flex items-end gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quantidade</label>
            <input type="number" id="qtd" min="1" max="100" value="<?= max(1, count($postRows)) ?>"
                   class="w-28 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <button type="button" onclick="generateFields()"
                  class="bg-brand text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">
            Gerar Campos
          </button>
        </div>
      </div>

      <!-- Linhas dinâmicas dos equipamentos -->
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-5">
        <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider mb-4">Equipamentos do Lote</h2>

        <div class="grid grid-cols-12 gap-2 mb-2 text-xs font-semibold text-gray-400 uppercase">
          <div class="col-span-1">#</div>
          <div class="col-span-4">MAC Address</div>
          <div class="col-span-3">Número de Série</div>
          <div class="col-span-4">Etiqueta *</div>
        </div>

        <div id="equipmentRows" class="space-y-2">
          <?php if (!empty($postRows)): ?>
          <?php foreach ($postRows as $idx => $row): ?>
          <div class="grid grid-cols-12 gap-2 items-center equipment-row">
            <span class="col-span-1 text-sm text-gray-400 font-mono text-center"><?= $idx + 1 ?></span>
            <input type="text" name="mac_address[]" value="<?= htmlspecialchars($row['mac_address'] ?? '') ?>"
                   placeholder="AA:BB:CC:DD:EE:FF"
                   oninput="formatMacMask(this); autoFillRowTag(this)"
                   maxlength="17"
                   class="col-span-4 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand font-mono text-xs">
            <input type="text" name="serial_number[]" value="<?= htmlspecialchars($row['serial_number'] ?? '') ?>"
                   placeholder="S/N"
                   class="col-span-3 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <input type="text" name="asset_tag[]" value="<?= htmlspecialchars($row['asset_tag'] ?? '') ?>"
                   placeholder="← do MAC" required
                   class="col-span-4 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand font-mono bg-gray-50">
          </div>
          <?php endforeach; ?>
          <?php else: ?>
          <div class="grid grid-cols-12 gap-2 items-center equipment-row">
            <span class="col-span-1 text-sm text-gray-400 font-mono text-center">1</span>
            <input type="text" name="mac_address[]" placeholder="AA:BB:CC:DD:EE:FF"
                   oninput="formatMacMask(this); autoFillRowTag(this)"
                   maxlength="17"
                   class="col-span-4 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand font-mono text-xs">
            <input type="text" name="serial_number[]" placeholder="S/N"
                   class="col-span-3 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <input type="text" name="asset_tag[]" placeholder="← do MAC" required
                   class="col-span-4 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand font-mono bg-gray-50">
          </div>
          <?php endif; ?>
        </div>

        <p id="emptyMsg" class="hidden text-center text-gray-400 text-sm py-4">
          Informe a quantidade acima e clique em "Gerar Campos".
        </p>
      </div>

      <div class="flex gap-3">
        <button type="submit" id="btnSubmitBatch"
                class="bg-green-600 text-white px-8 py-2.5 rounded-lg text-sm font-semibold hover:bg-green-700 transition">
          <span class="material-symbols-outlined text-base">save</span> Salvar Lote
        </button>
        <a href="/pages/equipment/index.php"
           class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
          Cancelar
        </a>
      </div>
    </form>
  </div>
</main>

<!-- Modal: Equipamentos Preenchidos -->
<div id="saveConfirmModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 text-center">
    <p class="text-gray-800 font-medium mb-6">Equipamentos Preenchidos, deseja salvar?</p>
    <div class="flex gap-3 justify-center">
      <button type="button" onclick="confirmSaveBatch()"
              class="px-6 py-2.5 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 transition">
        Sim
      </button>
      <button type="button" onclick="cancelSaveBatch()"
              class="px-6 py-2.5 bg-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-300 transition">
        Não
      </button>
      <button type="button" onclick="cancelAndExit()"
              class="px-6 py-2.5 bg-red-100 text-red-700 rounded-lg font-semibold hover:bg-red-200 transition">
        Cancelar
      </button>
    </div>
  </div>
</div>

<script>
const DRAFT_KEY = 'tvd_batch_entry_draft';
const fromPostWithErrors = <?= !empty($errors) ? 'true' : 'false' ?>;

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

function autoFillRowTag(macInput) {
    const tag = macToAssetTag(macInput.value);
    if (!tag) return;
    const row = macInput.closest('.equipment-row');
    if (!row) return;
    const tagInput = row.querySelector('input[name="asset_tag[]"]');
    if (!tagInput) return;
    tagInput.value = tag;
    tagInput.classList.add('ring-2', 'ring-green-400', 'border-green-400');
    setTimeout(() => tagInput.classList.remove('ring-2', 'ring-green-400', 'border-green-400'), 1500);
}

function collectDraft() {
    const form = document.getElementById('batchForm');
    if (!form) return null;
    const macs = Array.from(form.querySelectorAll('input[name="mac_address[]"]')).map(i => i.value.trim());
    const serials = Array.from(form.querySelectorAll('input[name="serial_number[]"]')).map(i => i.value.trim());
    const tags = Array.from(form.querySelectorAll('input[name="asset_tag[]"]')).map(i => i.value.trim());
    const rows = macs.map((mac, i) => ({ mac_address: mac, serial_number: serials[i] || '', asset_tag: tags[i] || '' }));
    return {
        batch_id: (form.querySelector('[name="batch_id"]')?.value || '').trim(),
        entry_date: (form.querySelector('[name="entry_date"]')?.value || '').trim(),
        purchase_date: (form.querySelector('[name="purchase_date"]')?.value || '').trim(),
        model_id: (form.querySelector('[name="model_id"]')?.value || '').trim(),
        qtd: Math.max(1, rows.length),
        rows: rows,
        saved_at: new Date().toISOString()
    };
}

function saveDraft() {
    const draft = collectDraft();
    if (!draft) return;
    try {
        localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
        showDraftIndicator();
    } catch (e) {}
}

function showDraftIndicator() {
    let el = document.getElementById('draftIndicator');
    if (!el) {
        el = document.createElement('div');
        el.id = 'draftIndicator';
        el.className = 'fixed bottom-4 right-4 px-3 py-2 bg-emerald-100 border border-emerald-300 rounded-lg text-sm text-emerald-700 shadow z-50';
        document.body.appendChild(el);
    }
    const now = new Date();
    el.textContent = 'Rascunho salvo às ' + now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    el.classList.remove('opacity-0');
    el.classList.add('opacity-100');
    clearTimeout(window._draftIndicatorTimeout);
    window._draftIndicatorTimeout = setTimeout(() => el.classList.add('opacity-0', 'transition-opacity', 'duration-300'), 2000);
}

function generateFields(initialRows) {
    const qtdInput = document.getElementById('qtd');
    const qtd = initialRows ? initialRows.length : (parseInt(qtdInput?.value) || 1);
    if (qtdInput) qtdInput.value = qtd;
    const container = document.getElementById('equipmentRows');
    container.innerHTML = '';

    for (let i = 0; i < qtd; i++) {
        const rowData = initialRows?.[i] || { mac_address: '', serial_number: '', asset_tag: '' };
        const row = document.createElement('div');
        row.className = 'grid grid-cols-12 gap-2 items-center equipment-row';
        row.innerHTML = `
            <span class="col-span-1 text-sm text-gray-400 font-mono text-center">${i + 1}</span>
            <input type="text" name="mac_address[]" placeholder="AA:BB:CC:DD:EE:FF"
                   value="${escapeHtml(rowData.mac_address || '')}"
                   oninput="formatMacMask(this); autoFillRowTag(this)"
                   maxlength="17"
                   class="col-span-4 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand font-mono text-xs">
            <input type="text" name="serial_number[]" placeholder="S/N"
                   value="${escapeHtml(rowData.serial_number || '')}"
                   class="col-span-3 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <input type="text" name="asset_tag[]" placeholder="← do MAC" required
                   value="${escapeHtml(rowData.asset_tag || '')}"
                   class="col-span-4 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand font-mono bg-gray-50">
        `;
        container.appendChild(row);
    }

    container.querySelectorAll('input[name="mac_address[]"]').forEach(inp => { if (inp.value) formatMacMask(inp); });
    saveDraft();
}

function escapeHtml(str) {
    const s = String(str || '');
    return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function restoreDraft() {
    try {
        const json = localStorage.getItem(DRAFT_KEY);
        if (!json) return false;
        const draft = JSON.parse(json);
        const form = document.getElementById('batchForm');
        if (!form || !draft.rows?.length) return false;

        const batchSel = form.querySelector('[name="batch_id"]');
        if (batchSel) batchSel.value = draft.batch_id || '';
        const entryDate = form.querySelector('[name="entry_date"]');
        if (entryDate) entryDate.value = draft.entry_date || '';
        const purchaseDate = form.querySelector('[name="purchase_date"]');
        if (purchaseDate) purchaseDate.value = draft.purchase_date || '';
        const modelSel = form.querySelector('[name="model_id"]');
        if (modelSel) modelSel.value = draft.model_id || '';

        generateFields(draft.rows);
        clearDraft();
        return true;
    } catch (e) { return false; }
}

function clearDraft() {
    try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
}

function initAutoSave() {
    let timer;
    const form = document.getElementById('batchForm');
    if (!form) return;
    const debouncedSave = () => {
        clearTimeout(timer);
        timer = setTimeout(saveDraft, 600);
    };
    form.addEventListener('input', debouncedSave);
    form.addEventListener('change', debouncedSave);
}

let _saveModalPending = false;

function handleMacFilled(macInput) {
    const raw = macInput.value.replace(/[^A-Fa-f0-9]/g, '');
    if (raw.length >= 12) {
        const row = macInput.closest('.equipment-row');
        const serialInput = row?.querySelector('input[name="serial_number[]"]');
        if (serialInput) {
            serialInput.focus();
            serialInput.select();
        }
    }
}

function handleSerialNav(serialInput) {
    if (!serialInput.value.trim()) return;
    const row = serialInput.closest('.equipment-row');
    const nextRow = row?.nextElementSibling;
    if (nextRow) {
        const nextMac = nextRow.querySelector('input[name="mac_address[]"]');
        if (nextMac) {
            nextMac.focus();
            nextMac.select();
        }
    } else {
        showSaveConfirmModal();
    }
}

function showSaveConfirmModal() {
    if (_saveModalPending) return;
    _saveModalPending = true;
    document.getElementById('saveConfirmModal').classList.remove('hidden');
}

function confirmSaveBatch() {
    _saveModalPending = false;
    document.getElementById('saveConfirmModal').classList.add('hidden');
    document.getElementById('batchForm')?.requestSubmit();
}

function cancelSaveBatch() {
    _saveModalPending = false;
    document.getElementById('saveConfirmModal').classList.add('hidden');
    const serials = document.querySelectorAll('input[name="serial_number[]"]');
    if (serials.length) serials[serials.length - 1].focus();
}

function cancelAndExit() {
    _saveModalPending = false;
    document.getElementById('saveConfirmModal').classList.add('hidden');
    clearDraft();
    window.location.href = '/pages/equipment/index.php';
}

function initBatchEntryNav() {
    const container = document.getElementById('equipmentRows');
    if (!container) return;

    // Auto-jump MAC → Serial when 12 hex digits typed/scanned
    container.addEventListener('input', function(e) {
        if (e.target.matches('input[name="mac_address[]"]')) {
            handleMacFilled(e.target);
        }
    });

    // Enter key from barcode scanner: navigate between fields
    container.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        e.stopPropagation();

        if (e.target.matches('input[name="mac_address[]"]')) {
            const row = e.target.closest('.equipment-row');
            const serialInput = row?.querySelector('input[name="serial_number[]"]');
            if (serialInput) {
                serialInput.focus();
                serialInput.select();
            }
        } else if (e.target.matches('input[name="serial_number[]"]')) {
            handleSerialNav(e.target);
        } else if (e.target.matches('input[name="asset_tag[]"]')) {
            const row = e.target.closest('.equipment-row');
            const nextRow = row?.nextElementSibling;
            if (nextRow) {
                const nextMac = nextRow.querySelector('input[name="mac_address[]"]');
                if (nextMac) { nextMac.focus(); nextMac.select(); }
            }
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="mac_address[]"]').forEach(function(inp) {
        if (inp.value) formatMacMask(inp);
    });

    initAutoSave();
    initBatchEntryNav();

    // Bloquear submit por Enter (pistola de código de barras envia Enter)
    document.getElementById('batchForm')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
            e.preventDefault();
        }
    });

    if (!fromPostWithErrors) {
        try {
            const json = localStorage.getItem(DRAFT_KEY);
            if (json) {
                const draft = JSON.parse(json);
                const hasData = draft?.rows?.some(r => (r.mac_address||'').trim() || (r.serial_number||'').trim() || (r.asset_tag||'').trim()) || (draft?.batch_id || draft?.entry_date || draft?.model_id);
                if (hasData) {
                    if (confirm('Há um rascunho salvo do seu último preenchimento. Deseja restaurar?')) {
                        restoreDraft();
                    }
                }
            }
        } catch (e) {}
    }
});
</script>
</body>
</html>
