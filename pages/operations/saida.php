<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/pipedrive_push.php';
requireLogin();

$db     = getDB();
$errors = [];
$step   = (int)($_POST['step'] ?? 1);

// Equipamentos disponíveis para saída
$available = $db->query("SELECT e.id, e.asset_tag, e.mac_address, e.serial_number, e.condition_status,
    em.brand, em.model_name
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
WHERE e.kanban_status IN ('entrada','aguardando_instalacao','equipamento_usado')
ORDER BY e.condition_status ASC, e.asset_tag ASC")->fetchAll();

// Lista de clientes para autocomplete
$clients = $db->query("SELECT id, client_code, name, cnpj FROM clients WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    csrfValidate();

    $clientId     = (int)($_POST['client_id']     ?? 0);
    $contractType = trim($_POST['contract_type']  ?? '');
    $allowedContract = ['comodato', 'equipamento_cliente', 'parceria'];
    if (!in_array($contractType, $allowedContract, true)) $contractType = 'comodato';
    $opDate       = trim($_POST['operation_date'] ?? date('Y-m-d\TH:i'));
    $opNotes      = trim($_POST['op_notes']       ?? '') ?: null;
    $eqIds        = array_filter(array_map('intval', $_POST['equipment_ids'] ?? []));

    if (!$clientId)       $errors[] = 'Selecione um cliente.';
    if (!$contractType)   $errors[] = 'Selecione o tipo de contrato.';
    if (empty($eqIds))    $errors[] = 'Selecione ao menos um equipamento.';

    // Validar que todos os IDs são equipamentos disponíveis
    if (!empty($eqIds)) {
        $placeholders = implode(',', array_fill(0, count($eqIds), '?'));
        $checkStmt = $db->prepare("SELECT id FROM equipment WHERE id IN ($placeholders) AND kanban_status IN ('entrada','aguardando_instalacao','equipamento_usado')");
        $checkStmt->execute(array_values($eqIds));
        $validIds = array_column($checkStmt->fetchAll(), 'id');
        $invalid  = array_diff($eqIds, $validIds);
        if ($invalid) $errors[] = 'Equipamentos inválidos ou indisponíveis: ' . implode(', ', $invalid);
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Buscar nome do cliente
            $clientStmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
            $clientStmt->execute([$clientId]);
            $clientName = $clientStmt->fetchColumn();

            $db->prepare("INSERT INTO equipment_operations
                (operation_type, operation_date, client_id, notes, performed_by)
                VALUES ('SAIDA', ?, ?, ?, ?)")
               ->execute([str_replace('T', ' ', $opDate), $clientId, $opNotes, $_SESSION['user_id']]);
            $opId = (int)$db->lastInsertId();

            foreach ($eqIds as $eId) {
                // Buscar status atual
                $curStmt = $db->prepare("SELECT kanban_status FROM equipment WHERE id = ?");
                $curStmt->execute([$eId]);
                $curStatus = $curStmt->fetchColumn();

                $db->prepare("INSERT INTO equipment_operation_items (operation_id, equipment_id) VALUES (?,?)")
                   ->execute([$opId, $eId]);

                $db->prepare("UPDATE equipment SET kanban_status='aguardando_instalacao', contract_type=?, current_client_id=?, updated_by=? WHERE id=?")
                   ->execute([$contractType, $clientId, $_SESSION['user_id'], $eId]);

                $db->prepare("INSERT INTO kanban_history (equipment_id, from_status, to_status, client_id, moved_by, notes)
                              VALUES (?,?,'aguardando_instalacao',?,?,?)")
                   ->execute([$eId, $curStatus, $clientId, $_SESSION['user_id'], "Saída para $clientName"]);

                auditLog('SAIDA', 'equipment', $eId,
                    ['kanban_status' => $curStatus],
                    ['kanban_status' => 'aguardando_instalacao', 'client_id' => $clientId],
                    "Saída para cliente $clientName");
            }

            $db->commit();

            // ── Push para Pipedrive (fora da transaction para não afetar o CRM em caso de falha) ──
            $pipeErrors = [];
            foreach ($eqIds as $eId) {
                try {
                    $pushResult = pipePushCreateProject($eId, $clientId, 'aguardando_instalacao');
                    if (!($pushResult['success'] ?? false) && empty($pushResult['skipped'])) {
                        $pipeErrors[] = "Eq #{$eId}: " . ($pushResult['error'] ?? 'erro desconhecido');
                    }
                } catch (\Exception $pe) {
                    $pipeErrors[] = "Eq #{$eId}: " . $pe->getMessage();
                }
            }

            $msg = 'Saída registrada: ' . count($eqIds) . ' equipamento(s) enviado(s) para ' . $clientName . '.';
            if ($pipeErrors) {
                $msg .= ' Aviso Pipedrive: ' . implode('; ', $pipeErrors);
            }
            flashSet('success', $msg);
            header('Location: ' . BASE_URL . '/pages/dashboard.php');
            exit;
        } catch (\Exception $e) {
            $db->rollBack();
            $errors[] = 'Erro ao registrar saída: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Saída de Equipamento — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">
    <h1 class="text-xl lg:text-2xl font-bold text-gray-800 mb-1 flex items-center gap-2"><span class="material-symbols-outlined" style="font-size:inherit">call_made</span> Saída de Equipamento</h1>
    <p class="text-gray-500 text-sm mb-6">Envio de um ou mais equipamentos para um cliente</p>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-lg">
      <?php foreach ($errors as $e): ?><p class="text-sm text-red-700"><span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="saidaForm">
      <?= csrfField() ?>
      <input type="hidden" name="confirm" value="1">

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Coluna esquerda: configurações -->
        <div class="space-y-5">

          <!-- Cliente -->
          <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider mb-3">Cliente</h2>
            <input type="hidden" name="client_id" id="client_id_input">
            <input type="text" id="clientSearch" placeholder="Buscar por nome ou código..."
                   autocomplete="off"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            <div id="clientDropdown" class="hidden absolute z-10 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto w-64 mt-1"></div>
            <div id="clientSelected" class="hidden mt-3 p-3 bg-blue-50 rounded-lg text-sm">
              <p id="clientSelectedName" class="font-semibold text-brand"></p>
              <p id="clientSelectedCode" class="text-gray-500 text-xs"></p>
              <button type="button" onclick="clearClient()" class="text-xs text-red-500 hover:underline mt-1">Trocar cliente</button>
            </div>

            <button type="button" onclick="document.getElementById('newClientModal').classList.remove('hidden')"
                    class="mt-3 text-xs text-brand hover:underline">
              + Cadastrar novo cliente
            </button>
          </div>

          <!-- Tipo de contrato e data -->
          <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 space-y-4">
            <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider">Configurações</h2>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Contrato *</label>
              <div class="space-y-1.5">
                <?php foreach (['comodato' => 'Comodato', 'equipamento_cliente' => 'Equipamento do Cliente', 'parceria' => 'Parceria'] as $v => $l): ?>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input type="radio" name="contract_type" value="<?= $v ?>" class="text-brand"
                         <?= ($_POST['contract_type'] ?? '') === $v ? 'checked' : '' ?>>
                  <span class="text-sm text-gray-700"><?= $l ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Data/Hora da Saída</label>
              <input type="datetime-local" name="operation_date"
                     value="<?= sanitize($_POST['operation_date'] ?? date('Y-m-d\TH:i')) ?>"
                     class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
              <textarea name="op_notes" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"><?= sanitize($_POST['op_notes'] ?? '') ?></textarea>
            </div>
          </div>

          <!-- Resumo -->
          <div class="bg-brand/10 rounded-xl p-4 text-sm">
            <p class="font-semibold text-brand" id="summaryCount">0 equipamentos selecionados</p>
          </div>

          <button type="submit"
                  class="w-full bg-green-600 text-white py-3 rounded-xl font-semibold text-sm hover:bg-green-700 transition">
            <span class="material-symbols-outlined text-sm">check_circle</span> Confirmar Saída
          </button>
        </div>

        <!-- Coluna direita: lista de equipamentos -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
          <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider">Equipamentos Disponíveis</h2>
            <button type="button" onclick="selectAll()" class="text-xs text-brand hover:underline">Selecionar todos</button>
          </div>

          <input type="text" id="eqSearch" placeholder="Filtrar por etiqueta ou modelo..."
                 oninput="filterEquipment()"
                 class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand mb-3">

          <div class="space-y-2 max-h-[500px] overflow-y-auto pr-1" id="eqList">
            <?php foreach ($available as $eq): ?>
            <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-100 hover:bg-gray-50 cursor-pointer transition eq-item"
                   data-tag="<?= strtolower(sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null))) ?>"
                   data-model="<?= strtolower(sanitize($eq['brand'] . ' ' . $eq['model_name'])) ?>">
              <input type="checkbox" name="equipment_ids[]" value="<?= $eq['id'] ?>"
                     onchange="updateCount()"
                     class="w-4 h-4 text-brand rounded"
                     <?= in_array($eq['id'], array_map('intval', $_POST['equipment_ids'] ?? [])) ? 'checked' : '' ?>>
              <div class="flex-1 min-w-0">
                <p class="font-mono font-semibold text-sm text-gray-800"><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?></p>
                <p class="text-xs text-gray-500"><?= sanitize($eq['brand']) ?> <?= sanitize($eq['model_name']) ?></p>
                <?php if ($eq['serial_number']): ?>
                <p class="text-xs text-gray-400">S/N: <?= sanitize($eq['serial_number']) ?></p>
                <?php endif; ?>
              </div>
              <?= conditionBadge($eq['condition_status']) ?>
            </label>
            <?php endforeach; ?>

            <?php if (empty($available)): ?>
            <p class="text-center text-gray-400 py-8">Nenhum equipamento disponível para saída.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </form>
  </div>
</main>

<!-- Modal novo cliente -->
<div id="newClientModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 max-h-[90vh] overflow-y-auto">
    <h3 class="text-lg font-bold text-gray-800 mb-4">Cadastrar Novo Cliente</h3>
    <form id="newClientForm" class="space-y-3">
      <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 mb-1">Código *</label>
          <input type="text" id="nc_code" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand" placeholder="Código único">
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 mb-1">Nome *</label>
          <input type="text" id="nc_name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">CNPJ</label>
          <input type="text" id="nc_cnpj" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Telefone</label>
          <input type="text" id="nc_phone" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 mb-1">E-mail</label>
          <input type="email" id="nc_email" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-gray-700 mb-1">Endereço</label>
          <input type="text" id="nc_address" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Cidade</label>
          <input type="text" id="nc_city" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">Estado</label>
          <input type="text" id="nc_state" maxlength="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand" placeholder="UF">
        </div>
      </div>
      <div id="newClientError" class="hidden text-sm text-red-600"></div>
      <div class="flex gap-3 pt-2">
        <button type="button" onclick="saveNewClient()"
                class="flex-1 bg-brand text-white py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">
          Salvar Cliente
        </button>
        <button type="button" onclick="document.getElementById('newClientModal').classList.add('hidden')"
                class="flex-1 bg-gray-100 text-gray-700 py-2.5 rounded-lg text-sm hover:bg-gray-200 transition">
          Cancelar
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const clientsData = <?= json_encode($clients, JSON_UNESCAPED_UNICODE) ?>;

// Autocomplete de clientes
document.getElementById('clientSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    const dd = document.getElementById('clientDropdown');
    if (!q) { dd.classList.add('hidden'); return; }

    const matches = clientsData.filter(c =>
        c.name.toLowerCase().includes(q) || c.client_code.toLowerCase().includes(q)
    ).slice(0, 8);

    if (!matches.length) { dd.classList.add('hidden'); return; }

    dd.innerHTML = matches.map(c => `
        <div class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm" onclick="selectClient(${c.id}, '${c.name.replace(/'/g,"\'")}', '${c.client_code}')">
            <p class="font-medium text-gray-800">${c.name}</p>
            <p class="text-xs text-gray-400">${c.client_code}</p>
        </div>
    `).join('');
    dd.classList.remove('hidden');

    const rect = this.getBoundingClientRect();
    dd.style.left  = rect.left + 'px';
    dd.style.top   = (rect.bottom + window.scrollY + 4) + 'px';
    dd.style.width = rect.width + 'px';
    dd.style.position = 'fixed';
});

function selectClient(id, name, code) {
    document.getElementById('client_id_input').value = id;
    document.getElementById('clientSearch').classList.add('hidden');
    document.getElementById('clientDropdown').classList.add('hidden');
    document.getElementById('clientSelected').classList.remove('hidden');
    document.getElementById('clientSelectedName').textContent = name;
    document.getElementById('clientSelectedCode').textContent = code;
}

function clearClient() {
    document.getElementById('client_id_input').value = '';
    document.getElementById('clientSearch').value = '';
    document.getElementById('clientSearch').classList.remove('hidden');
    document.getElementById('clientSelected').classList.add('hidden');
}

function updateCount() {
    const count = document.querySelectorAll('input[name="equipment_ids[]"]:checked').length;
    document.getElementById('summaryCount').textContent = count + ' equipamento(s) selecionado(s)';
}

function selectAll() {
    document.querySelectorAll('.eq-item input[type="checkbox"]').forEach(cb => {
        if (!cb.closest('.eq-item').classList.contains('hidden')) cb.checked = true;
    });
    updateCount();
}

function filterEquipment() {
    const q = document.getElementById('eqSearch').value.toLowerCase();
    document.querySelectorAll('.eq-item').forEach(item => {
        const tag   = item.dataset.tag;
        const model = item.dataset.model;
        item.classList.toggle('hidden', !(tag.includes(q) || model.includes(q)));
    });
}

async function saveNewClient() {
    const code  = document.getElementById('nc_code').value.trim();
    const name  = document.getElementById('nc_name').value.trim();
    const errEl = document.getElementById('newClientError');

    if (!code || !name) { errEl.textContent = 'Código e nome são obrigatórios.'; errEl.classList.remove('hidden'); return; }
    errEl.classList.add('hidden');

    const resp = await fetch('/pages/api/save_client.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            client_code: code, name, cnpj: document.getElementById('nc_cnpj').value,
            phone: document.getElementById('nc_phone').value, email: document.getElementById('nc_email').value,
            address: document.getElementById('nc_address').value, city: document.getElementById('nc_city').value,
            state: document.getElementById('nc_state').value,
            csrf_token: '<?= csrfToken() ?>'
        })
    });
    const data = await resp.json();
    if (data.success) {
        clientsData.push({ id: data.id, client_code: code, name });
        selectClient(data.id, name, code);
        document.getElementById('newClientModal').classList.add('hidden');
    } else {
        errEl.textContent = data.message || 'Erro ao salvar cliente.';
        errEl.classList.remove('hidden');
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#clientSearch') && !e.target.closest('#clientDropdown')) {
        document.getElementById('clientDropdown').classList.add('hidden');
    }
});

updateCount();
</script>
</body>
</html>
