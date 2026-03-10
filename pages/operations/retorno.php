<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db     = getDB();
$errors = [];

// Equipamentos alocados
$stmt = $db->query("SELECT e.id, e.asset_tag, e.mac_address, e.serial_number, e.condition_status,
    e.current_client_id, c.name as client_name, c.client_code,
    em.brand, em.model_name,
    DATEDIFF(NOW(), (
        SELECT moved_at FROM kanban_history
        WHERE equipment_id = e.id AND to_status = 'alocado'
        ORDER BY moved_at DESC LIMIT 1
    )) as days_with_client
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
JOIN clients c ON c.id = e.current_client_id
WHERE e.kanban_status IN ('alocado', 'processo_devolucao', 'licenca_removida')
ORDER BY c.name, e.asset_tag");
$alocados = $stmt->fetchAll();

// Agrupar por cliente
$byClient = [];
foreach ($alocados as $eq) {
    $byClient[$eq['current_client_id']] = $byClient[$eq['current_client_id']] ?? ['name' => $eq['client_name'], 'code' => $eq['client_code'], 'items' => []];
    $byClient[$eq['current_client_id']]['items'][] = $eq;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();

    $selectedIds  = array_filter(array_map('intval', $_POST['return_ids'] ?? []));
    $opDate       = trim($_POST['operation_date'] ?? date('Y-m-d\TH:i'));
    $opNotes      = trim($_POST['op_notes'] ?? '') ?: null;

    if (empty($selectedIds)) $errors[] = 'Selecione ao menos um equipamento para devolução.';

    // Validar que são equipamentos alocados
    if (!empty($selectedIds)) {
        $ph = implode(',', array_fill(0, count($selectedIds), '?'));
        $valStmt = $db->prepare("SELECT id, current_client_id FROM equipment WHERE id IN ($ph) AND kanban_status IN ('alocado', 'processo_devolucao', 'licenca_removida')");
        $valStmt->execute(array_values($selectedIds));
        $valid = $valStmt->fetchAll();
        if (count($valid) !== count($selectedIds)) $errors[] = 'Alguns equipamentos não estão alocados.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $clientId = null;
            $results  = ['usado' => 0, 'manutencao' => 0, 'baixado' => 0];

            // Agrupar por cliente (operação por cliente)
            $clientGroups = [];
            foreach ($selectedIds as $eId) {
                $cStmt = $db->prepare("SELECT current_client_id FROM equipment WHERE id = ?");
                $cStmt->execute([$eId]);
                $cid = (int)$cStmt->fetchColumn();
                $clientGroups[$cid][] = $eId;
            }

            foreach ($clientGroups as $cid => $eIds) {
                $db->prepare("INSERT INTO equipment_operations
                    (operation_type, operation_date, client_id, notes, performed_by)
                    VALUES ('RETORNO', ?, ?, ?, ?)")
                   ->execute([str_replace('T', ' ', $opDate), $opNotes, $_SESSION['user_id']]);
                $opId = (int)$db->lastInsertId();

                $cnStmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
                $cnStmt->execute([$cid]);
                $clientName = $cnStmt->fetchColumn();

                $allowedCond = ['ok', 'manutencao', 'descartar'];
                foreach ($eIds as $eId) {
                    $power   = isset($_POST['accessories_power'][$eId])  ? 1 : 0;
                    $hdmi    = isset($_POST['accessories_hdmi'][$eId])   ? 1 : 0;
                    $remote  = isset($_POST['accessories_remote'][$eId]) ? 1 : 0;
                    $cond    = $_POST['condition_after_return'][$eId]    ?? 'ok';
                    if (!in_array($cond, $allowedCond, true)) $cond = 'ok';
                    $retNote = trim($_POST['return_notes'][$eId] ?? '') ?: null;

                    $fromStatusStmt = $db->prepare("SELECT kanban_status FROM equipment WHERE id = ?");
                    $fromStatusStmt->execute([$eId]);
                    $fromStatus = $fromStatusStmt->fetchColumn() ?: 'alocado';

                    $newStatus = match($cond) {
                        'manutencao' => 'manutencao',
                        'descartar'  => 'baixado',
                        default      => 'equipamento_usado',
                    };
                    $results[$cond === 'ok' ? 'usado' : ($cond === 'manutencao' ? 'manutencao' : 'baixado')]++;

                    $db->prepare("INSERT INTO equipment_operation_items
                        (operation_id, equipment_id, accessories_power, accessories_hdmi,
                         accessories_remote, condition_after_return, return_notes)
                        VALUES (?,?,?,?,?,?,?)")
                       ->execute([$opId, $eId, $power, $hdmi, $remote, $cond, $retNote]);

                    $db->prepare("UPDATE equipment SET kanban_status=?, condition_status='usado',
                        current_client_id=NULL, updated_by=? WHERE id=?")
                       ->execute([$newStatus, $_SESSION['user_id'], $eId]);

                    $db->prepare("INSERT INTO kanban_history
                        (equipment_id, from_status, to_status, client_id, moved_by, notes)
                        VALUES (?,?,?,?,?,?)")
                       ->execute([$eId, $fromStatus, $newStatus, $cid, $_SESSION['user_id'],
                                  "Devolução de $clientName | Fonte:$power HDMI:$hdmi Controle:$remote"]);

                    auditLog('RETORNO', 'equipment', $eId,
                        ['kanban_status' => $fromStatus, 'client_id' => $cid],
                        ['kanban_status' => $newStatus, 'client_id' => null],
                        "Devolução de $clientName | Periféricos: fonte=$power, hdmi=$hdmi, controle=$remote");
                }
            }

            $db->commit();
            $msg = count($selectedIds) . ' equipamento(s) devolvido(s): ';
            $parts = [];
            if ($results['usado'])     $parts[] = $results['usado']     . ' para Eq. Usado';
            if ($results['manutencao']) $parts[] = $results['manutencao'] . ' para Manutenção';
            if ($results['baixado'])   $parts[] = $results['baixado']   . ' Baixados';
            flashSet('success', $msg . implode(', ', $parts) . '.');
            header('Location: ' . BASE_URL . '/pages/dashboard.php');
            exit;
        } catch (\Exception $e) {
            $db->rollBack();
            $errors[] = 'Erro ao registrar devolução: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Devolução — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-4xl mx-auto">
    <h1 class="text-xl lg:text-2xl font-bold text-gray-800 mb-1">📥 Devolução / Retorno</h1>
    <p class="text-gray-500 text-sm mb-6">Registre o retorno de equipamentos por clientes</p>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-lg">
      <?php foreach ($errors as $e): ?><p class="text-sm text-red-700">❌ <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($alocados)): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-12 text-center">
      <p class="text-4xl mb-3">🎉</p>
      <p class="text-gray-500">Nenhum equipamento alocado no momento.</p>
    </div>
    <?php else: ?>

    <!-- Filtro por cliente -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-5 flex items-center gap-3">
      <label class="text-sm font-medium text-gray-700">Filtrar por cliente:</label>
      <input type="text" id="clientFilter" placeholder="Nome, código do cliente (ex: P2254) ou MAC/player (ex: B8DF)..."
             oninput="filterByClient()"
             class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
    </div>

    <form method="POST" id="retornoForm">
      <?= csrfField() ?>
      <div id="clientGroups" class="space-y-5">
        <?php foreach ($byClient as $cid => $clientData): ?>
        <div class="client-group bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden"
             data-client="<?= strtolower(sanitize($clientData['name'])) ?>"
             data-client-code="<?= strtolower(sanitize($clientData['code'] ?? '')) ?>"
             data-equipment-tags="<?= strtolower(implode(' ', array_map(function($i) {
    $tag = displayTag($i['asset_tag'], $i['mac_address'] ?? null);
    $mac = preg_replace('/[^a-f0-9]/', '', strtolower($i['mac_address'] ?? ''));
    $ast = strtolower($i['asset_tag'] ?? '');
    return $tag . ' ' . $mac . ' ' . $ast;
}, $clientData['items']))) ?>">
          <div class="bg-gray-50 px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <div>
              <p class="font-semibold text-gray-800"><?= sanitize($clientData['name']) ?></p>
              <p class="text-xs text-gray-400"><?= sanitize($clientData['code']) ?></p>
            </div>
            <button type="button" onclick="selectClientAll(<?= $cid ?>)"
                    class="text-xs text-brand hover:underline">Selecionar todos</button>
          </div>

          <div class="p-4 space-y-4">
            <?php foreach ($clientData['items'] as $eq): ?>
            <div class="border border-gray-100 rounded-xl p-4 eq-card" data-client-id="<?= $cid ?>">
              <div class="flex items-start gap-3 mb-3">
                <input type="checkbox" name="return_ids[]" value="<?= $eq['id'] ?>"
                       id="cb<?= $eq['id'] ?>"
                       onchange="toggleCard(this)"
                       class="w-4 h-4 text-brand mt-0.5 rounded">
                <label for="cb<?= $eq['id'] ?>" class="flex-1 cursor-pointer">
                  <div class="flex flex-wrap items-center gap-2 mb-0.5">
                    <span class="font-mono font-bold text-brand"><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?></span>
                    <?= conditionBadge($eq['condition_status']) ?>
                  </div>
                  <p class="text-xs text-gray-500"><?= sanitize($eq['brand']) ?> <?= sanitize($eq['model_name']) ?></p>
                  <?php if ($eq['days_with_client'] !== null): ?>
                  <p class="text-xs text-gray-400"><?= (int)$eq['days_with_client'] ?> dias com o cliente</p>
                  <?php endif; ?>
                </label>
              </div>

              <!-- Checklist e condição (fica visível apenas quando selecionado) -->
              <div class="card-details hidden pl-7">
                <div class="bg-gray-50 rounded-lg p-3 mb-3">
                  <p class="text-xs font-semibold text-gray-600 mb-2">Periféricos devolvidos:</p>
                  <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input type="checkbox" name="accessories_power[<?= $eq['id'] ?>]" value="1"
                             class="w-4 h-4 text-brand rounded">
                      <span class="text-sm">🔌 Fonte</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input type="checkbox" name="accessories_hdmi[<?= $eq['id'] ?>]" value="1"
                             class="w-4 h-4 text-brand rounded">
                      <span class="text-sm">📺 HDMI</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input type="checkbox" name="accessories_remote[<?= $eq['id'] ?>]" value="1"
                             class="w-4 h-4 text-brand rounded">
                      <span class="text-sm">🎮 Controle</span>
                    </label>
                  </div>
                </div>

                <div class="mb-3">
                  <p class="text-xs font-semibold text-gray-600 mb-2">Condição do equipamento:</p>
                  <div class="space-y-1.5">
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input type="radio" name="condition_after_return[<?= $eq['id'] ?>]" value="ok" checked class="text-brand">
                      <span class="text-sm text-green-700">✅ Bom estado — pode ser reenviado (Equipamento Usado)</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input type="radio" name="condition_after_return[<?= $eq['id'] ?>]" value="manutencao" class="text-brand">
                      <span class="text-sm text-orange-700">🔧 Precisa de manutenção</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                      <input type="radio" name="condition_after_return[<?= $eq['id'] ?>]" value="descartar" class="text-brand">
                      <span class="text-sm text-red-700">🗑️ Descartar / Baixar</span>
                    </label>
                  </div>
                </div>

                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Observações da inspeção</label>
                  <textarea name="return_notes[<?= $eq['id'] ?>]" rows="2"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"
                            placeholder="Estado do equipamento, acessórios, observações..."></textarea>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Footer de confirmação -->
      <div class="mt-6 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data/Hora do Retorno</label>
            <input type="datetime-local" name="operation_date"
                   value="<?= sanitize($_POST['operation_date'] ?? date('Y-m-d\TH:i')) ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Observações gerais</label>
            <input type="text" name="op_notes" value="<?= sanitize($_POST['op_notes'] ?? '') ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          </div>
          <div class="flex items-end">
            <button type="submit"
                    class="w-full bg-orange-500 hover:bg-orange-600 text-white py-2.5 rounded-lg font-semibold text-sm transition">
              ✅ Confirmar Devolução (<span id="returnCount">0</span>)
            </button>
          </div>
        </div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</main>

<script>
function toggleCard(cb) {
    const card = cb.closest('.eq-card');
    const details = card.querySelector('.card-details');
    details.classList.toggle('hidden', !cb.checked);
    updateReturnCount();
}

function selectClientAll(cid) {
    document.querySelectorAll(`.eq-card[data-client-id="${cid}"] input[type="checkbox"]`).forEach(cb => {
        cb.checked = true;
        toggleCard(cb);
    });
}

function updateReturnCount() {
    const count = document.querySelectorAll('input[name="return_ids[]"]:checked').length;
    document.getElementById('returnCount').textContent = count;
}

function filterByClient() {
    const q = document.getElementById('clientFilter').value.toLowerCase().trim();
    if (!q) {
        document.querySelectorAll('.client-group').forEach(g => g.classList.remove('hidden'));
        return;
    }
    document.querySelectorAll('.client-group').forEach(g => {
        const matchName = (g.dataset.client || '').includes(q);
        const matchCode = (g.dataset.clientCode || '').includes(q);
        const matchEq = (g.dataset.equipmentTags || '').includes(q);
        g.classList.toggle('hidden', !matchName && !matchCode && !matchEq);
    });
}
</script>
</body>
</html>
