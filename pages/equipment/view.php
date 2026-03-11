<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/pages/equipment/index.php'); exit; }

$stmt = $db->prepare("SELECT e.*, em.brand, em.model_name, em.category,
    c.name as client_name, c.client_code, c.id as client_id, c.city as client_city, c.state as client_state
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
LEFT JOIN clients c ON c.id = e.current_client_id
WHERE e.id = ?");
$stmt->execute([$id]);
$eq = $stmt->fetch();
if (!$eq) { flashSet('error', 'Equipamento não encontrado.'); header('Location: ' . BASE_URL . '/pages/equipment/index.php'); exit; }

// Histórico de status
$histStmt = $db->prepare("SELECT kh.moved_at, kh.from_status, kh.to_status, kh.notes,
    u.name as moved_by_name, c.name as client_name, c.client_code, c.city, c.state
FROM kanban_history kh
JOIN users u ON u.id = kh.moved_by
LEFT JOIN clients c ON c.id = kh.client_id
WHERE kh.equipment_id = ? ORDER BY kh.moved_at ASC");
$histStmt->execute([$id]);
$history = $histStmt->fetchAll();

// Histórico de devoluções: via tela Devolução/Retorno (equipment_operation_items) + via Kanban
$retStmt = $db->prepare("SELECT eoi.created_at, eoi.accessories_power, eoi.accessories_hdmi,
    eoi.accessories_remote, eoi.condition_after_return, eoi.return_notes,
    eo.operation_date, u.name as performed_by, c.name as client_name, 0 as via_kanban
FROM equipment_operation_items eoi
JOIN equipment_operations eo ON eo.id = eoi.operation_id
JOIN users u ON u.id = eo.performed_by
LEFT JOIN clients c ON c.id = eo.client_id
WHERE eoi.equipment_id = ? AND eo.operation_type = 'RETORNO'
ORDER BY eo.operation_date DESC");
$retStmt->execute([$id]);
$returns = $retStmt->fetchAll();

// Devoluções via Kanban (quando movido direto para Eq. Usado/Manutenção/Baixado sem passar pela tela Retorno)
$kanbanRetStmt = $db->prepare("SELECT kh.moved_at as operation_date, kh.notes as return_notes,
    u.name as performed_by, c.name as client_name, 1 as via_kanban,
    NULL as accessories_power, NULL as accessories_hdmi, NULL as accessories_remote,
    CASE kh.to_status WHEN 'equipamento_usado' THEN 'ok' WHEN 'manutencao' THEN 'manutencao' WHEN 'baixado' THEN 'descartar' ELSE 'ok' END as condition_after_return
FROM kanban_history kh
JOIN users u ON u.id = kh.moved_by
LEFT JOIN clients c ON c.id = kh.client_id
WHERE kh.equipment_id = ?
  AND kh.from_status IN ('alocado','licenca_removida','processo_devolucao')
  AND kh.to_status IN ('equipamento_usado','manutencao','baixado')
  AND NOT EXISTS (
    SELECT 1 FROM equipment_operation_items eoi
    JOIN equipment_operations eo ON eo.id = eoi.operation_id
    WHERE eoi.equipment_id = kh.equipment_id AND eo.operation_type = 'RETORNO'
      AND DATE(eo.operation_date) = DATE(kh.moved_at)
  )
ORDER BY kh.moved_at DESC");
$kanbanRetStmt->execute([$id]);
$returnsKanban = $kanbanRetStmt->fetchAll();

// Mesclar e ordenar por data (mais recente primeiro)
$returnsAll = array_merge($returns, $returnsKanban);
usort($returnsAll, function($a, $b) {
    $da = $a['operation_date'] ?? $a['created_at'] ?? '';
    $db = $b['operation_date'] ?? $b['created_at'] ?? '';
    return strcmp($db, $da);
});
$returns = $returnsAll;

// Notas
$notesStmt = $db->prepare("SELECT n.id, n.note, n.created_at, u.name as user_name
FROM equipment_notes n JOIN users u ON u.id = n.user_id
WHERE n.equipment_id = ? ORDER BY n.created_at DESC");
$notesStmt->execute([$id]);
$notes = $notesStmt->fetchAll();

// Fotos
$photos = [];
try {
    $photoStmt = $db->prepare("SELECT p.id, p.filename, p.original_name, p.created_at, u.name as uploaded_by
    FROM equipment_photos p JOIN users u ON u.id = p.uploaded_by
    WHERE p.equipment_id = ? ORDER BY p.created_at DESC");
    $photoStmt->execute([$id]);
    $photos = $photoStmt->fetchAll();
} catch (\Exception $e) {}

// Lotes para edição do campo lote
$batches = $db->query("SELECT id, name FROM batches ORDER BY created_at DESC")->fetchAll();

// Histórico de Clientes e Locais (vida do equipamento)
$clientHistStmt = $db->prepare("SELECT kh.moved_at, kh.from_status, kh.to_status, kh.client_id, kh.notes,
    c.name as client_name, c.client_code, c.city, c.state,
    u.name as moved_by_name,
    CASE
        WHEN kh.to_status IN ('alocado','aguardando_instalacao') AND kh.client_id IS NOT NULL THEN 'alocacao'
        WHEN kh.from_status IN ('alocado','licenca_removida','processo_devolucao')
             AND kh.to_status NOT IN ('alocado','licenca_removida','processo_devolucao') AND kh.client_id IS NOT NULL THEN 'devolucao'
        ELSE NULL
    END as tipo
FROM kanban_history kh
LEFT JOIN clients c ON c.id = kh.client_id
LEFT JOIN users u ON u.id = kh.moved_by
WHERE kh.equipment_id = ? AND kh.client_id IS NOT NULL
ORDER BY kh.moved_at ASC");
$clientHistStmt->execute([$id]);
$clientHistoryRaw = $clientHistStmt->fetchAll();

// Também capturar SAÍDAs via equipment_operations (caso não tenham kanban_history)
$saidaStmt = $db->prepare("SELECT eo.operation_date as moved_at, eo.client_id,
    c.name as client_name, c.client_code, c.city, c.state,
    u.name as moved_by_name
FROM equipment_operation_items eoi
JOIN equipment_operations eo ON eo.id = eoi.operation_id
LEFT JOIN clients c ON c.id = eo.client_id
LEFT JOIN users u ON u.id = eo.performed_by
WHERE eoi.equipment_id = ? AND eo.operation_type = 'SAIDA'
  AND NOT EXISTS (
    SELECT 1 FROM kanban_history kh
    WHERE kh.equipment_id = eoi.equipment_id AND kh.client_id = eo.client_id
      AND DATE(kh.moved_at) = DATE(eo.operation_date) AND kh.to_status IN ('alocado','aguardando_instalacao')
  )");
$saidaStmt->execute([$id]);
$saidasExtras = $saidaStmt->fetchAll();

$clientHistory = [];
foreach ($clientHistoryRaw as $r) {
    if ($r['tipo']) {
        $clientHistory[] = [
            'moved_at' => $r['moved_at'],
            'tipo' => $r['tipo'],
            'client_name' => $r['client_name'],
            'client_code' => $r['client_code'],
            'city' => $r['city'],
            'state' => $r['state'],
            'moved_by_name' => $r['moved_by_name'],
            'notes' => $r['notes'],
        ];
    }
}
foreach ($saidasExtras as $r) {
    $clientHistory[] = [
        'moved_at' => $r['moved_at'],
        'tipo' => 'alocacao',
        'client_name' => $r['client_name'],
        'client_code' => $r['client_code'],
        'city' => $r['city'],
        'state' => $r['state'],
        'moved_by_name' => $r['moved_by_name'],
        'notes' => null,
    ];
}
usort($clientHistory, fn($a, $b) => strcmp($a['moved_at'], $b['moved_at']));

// Auditoria (histórico de ações neste equipamento)
$auditStmt = $db->prepare("SELECT al.created_at, al.action, al.description, al.old_value, al.new_value, u.name as user_name
FROM audit_log al
JOIN users u ON u.id = al.user_id
WHERE al.entity_type = 'equipment' AND al.entity_id = ?
ORDER BY al.created_at DESC LIMIT 50");
$auditStmt->execute([$id]);
$auditHistory = $auditStmt->fetchAll();

$conditionMap = ['ok' => '<span class="material-symbols-outlined text-sm">check_circle</span> Bom estado', 'manutencao' => '<span class="material-symbols-outlined" style="font-size:12px">build</span> Manutenção', 'descartar' => '<span class="material-symbols-outlined" style="font-size:12px">delete</span> Descartar'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?> — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">
    <div class="flex items-center gap-3 mb-4">
      <a href="/pages/equipment/index.php" class="text-gray-400 hover:text-gray-600 text-sm">← Equipamentos</a>
    </div>

    <?php flashRender(); ?>

    <!-- Cabeçalho -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="font-mono text-3xl font-bold text-brand mb-2"><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?></p>
          <p class="text-lg text-gray-700 mb-3"><?= sanitize(displayModelName($eq['brand'], $eq['model_name'])) ?></p>
          <div class="flex flex-wrap gap-2">
            <?= conditionBadge($eq['condition_status']) ?>
            <?= kanbanBadge($eq['kanban_status']) ?>
            <?php if ($eq['contract_type']): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-700">
              <?= contractLabel($eq['contract_type']) ?>
            </span>
            <?php endif; ?>
            <?php
              $viewLabels = parseEquipmentLabels($eq['custom_labels'] ?? null);
              foreach ($viewLabels as $lbl):
            ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-200">
              <?= htmlspecialchars($lbl) ?>
            </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button onclick="document.getElementById('modalQR').classList.remove('hidden')"
                  class="flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>
            </svg>
            QR Code
          </button>
          <a href="/pages/equipment/edit.php?id=<?= $id ?>"
             class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-blue-800 transition">
            <span class="material-symbols-outlined text-base">edit</span> Editar
          </a>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6 pt-6 border-t border-gray-100">
        <div class="editable-field" data-field="serial_number">
          <p class="text-xs text-gray-400 uppercase tracking-wider flex items-center gap-1">
            Número de Série
            <button type="button" onclick="startEdit('serial_number')" title="Editar"
                    class="opacity-50 hover:opacity-100 text-brand p-0.5 rounded">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
              </svg>
            </button>
          </p>
          <div class="field-view">
            <p class="text-sm font-mono font-medium text-gray-700 mt-0.5"><?= sanitize($eq['serial_number'] ?? '—') ?></p>
          </div>
          <div class="field-edit hidden">
            <input type="text" id="edit_serial_number" value="<?= sanitize($eq['serial_number'] ?? '') ?>"
                   class="mt-0.5 w-full px-2 py-1 border border-gray-300 rounded text-sm font-mono focus:ring-2 focus:ring-brand">
            <div class="flex gap-1 mt-1">
              <button type="button" onclick="saveField('serial_number')" class="text-xs px-2 py-1 bg-brand text-white rounded hover:bg-blue-800">Salvar</button>
              <button type="button" onclick="cancelEdit('serial_number')" class="text-xs px-2 py-1 bg-gray-200 text-gray-600 rounded hover:bg-gray-300">Cancelar</button>
            </div>
          </div>
        </div>
        <div class="editable-field" data-field="mac_address">
          <p class="text-xs text-gray-400 uppercase tracking-wider flex items-center gap-1">
            MAC Address
            <button type="button" onclick="startEdit('mac_address')" title="Editar"
                    class="opacity-50 hover:opacity-100 text-brand p-0.5 rounded">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
              </svg>
            </button>
          </p>
          <div class="field-view">
            <p class="text-sm font-mono font-medium text-gray-700 mt-0.5"><?= sanitize($eq['mac_address'] ?? '—') ?></p>
          </div>
          <div class="field-edit hidden">
            <input type="text" id="edit_mac_address" value="<?= sanitize($eq['mac_address'] ?? '') ?>"
                   maxlength="17" placeholder="AA:BB:CC:DD:EE:FF" oninput="formatMacMask(this)"
                   class="mt-0.5 w-full px-2 py-1 border border-gray-300 rounded text-sm font-mono focus:ring-2 focus:ring-brand">
            <div class="flex gap-1 mt-1">
              <button type="button" onclick="saveField('mac_address')" class="text-xs px-2 py-1 bg-brand text-white rounded hover:bg-blue-800">Salvar</button>
              <button type="button" onclick="cancelEdit('mac_address')" class="text-xs px-2 py-1 bg-gray-200 text-gray-600 rounded hover:bg-gray-300">Cancelar</button>
            </div>
          </div>
        </div>
        <div>
          <p class="text-xs text-gray-400 uppercase tracking-wider">Entrada no Estoque</p>
          <p class="text-sm font-medium text-gray-700 mt-0.5"><?= formatDate($eq['entry_date']) ?></p>
        </div>
        <div class="editable-field" data-field="batch_id">
          <p class="text-xs text-gray-400 uppercase tracking-wider flex items-center gap-1">
            Lote
            <button type="button" onclick="startEdit('batch_id')" title="Editar"
                    class="opacity-50 hover:opacity-100 text-brand p-0.5 rounded">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
              </svg>
            </button>
          </p>
          <div class="field-view">
            <?php if (!empty($eq['batch_id'])): ?>
            <a href="/pages/equipment/batches.php?action=view&id=<?= (int)$eq['batch_id'] ?>"
               class="text-sm font-semibold text-brand hover:underline mt-0.5 inline-block font-mono field-value">
              <?= sanitize($eq['batch'] ?? $eq['batch_id']) ?>
            </a>
            <?php else: ?>
            <p class="text-sm font-medium text-gray-700 mt-0.5 field-value"><?= sanitize($eq['batch'] ?? '—') ?></p>
            <?php endif; ?>
          </div>
          <div class="field-edit hidden">
            <div class="relative mt-0.5" id="batchAutocompleteWrap">
              <input type="text" id="edit_batch_search" value="<?= sanitize($eq['batch'] ?? '') ?>"
                     placeholder="Digite para buscar..."
                     autocomplete="off"
                     class="w-full px-2 py-1 border border-gray-300 rounded text-sm font-mono focus:ring-2 focus:ring-brand">
              <input type="hidden" id="edit_batch_id" value="<?= (int)($eq['batch_id'] ?? 0) ?>">
              <div id="batchSuggestions" class="absolute left-0 right-0 top-full mt-0.5 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto z-50 hidden"></div>
            </div>
            <div class="flex gap-1 mt-1">
              <button type="button" onclick="saveField('batch_id')" class="text-xs px-2 py-1 bg-brand text-white rounded hover:bg-blue-800">Salvar</button>
              <button type="button" onclick="cancelEdit('batch_id')" class="text-xs px-2 py-1 bg-gray-200 text-gray-600 rounded hover:bg-gray-300">Cancelar</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Garantia -->
      <?php
        $w = warrantyStatus($eq['purchase_date'] ?? null, $eq['warranty_extended_until'] ?? null);
        $wBg = match($w['status']) {
            'ok'       => 'bg-green-50 border-green-200',
            'vencendo' => 'bg-orange-50 border-orange-200',
            'vencida'  => 'bg-red-50 border-red-200',
            default    => 'bg-gray-50 border-gray-200',
        };
        $wTxt = match($w['status']) {
            'ok'       => 'text-green-800',
            'vencendo' => 'text-orange-800',
            'vencida'  => 'text-red-800',
            default    => 'text-gray-500',
        };
      ?>
      <div class="mt-4 pt-4 border-t border-gray-100">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-2">Garantia</p>
        <div class="flex flex-wrap items-center gap-4">
          <?= warrantyBadge($eq['purchase_date'] ?? null, 'md', $eq['warranty_extended_until'] ?? null) ?>
          <?php if ($eq['purchase_date']): ?>
          <span class="text-sm text-gray-500">
            Data de compra: <strong><?= formatDate($eq['purchase_date']) ?></strong>
          </span>
          <?php if ($w['status'] !== 'sem_data'): ?>
          <span class="text-sm text-gray-500">
            Vencimento: <strong><?= $w['expires'] ?></strong>
            <?php if ($w['extended']): ?>
              <span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-semibold ml-1">★ Renovada</span>
            <?php endif; ?>
            <?php if ($w['status'] === 'ok'): ?>
              <span class="text-green-600">(<?= $w['days'] ?> dias restantes)</span>
            <?php elseif ($w['status'] === 'vencendo'): ?>
              <span class="text-orange-600">(<?= $w['days'] ?> dias restantes)</span>
            <?php else: ?>
              <span class="text-red-600">(vencida há <?= $w['days'] ?> dias)</span>
            <?php endif; ?>
          </span>
          <?php endif; ?>
          <?php else: ?>
          <span class="text-sm text-gray-400">Data de compra não informada</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Cliente atual -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3">Cliente Atual</h2>
      <?php if ($eq['client_name']): ?>
        <div class="flex items-center justify-between">
          <div>
            <a href="/pages/clients/view.php?code=<?= urlencode($eq['client_code']) ?>"
               class="text-lg font-semibold text-brand hover:underline"><?= sanitize($eq['client_name']) ?></a>
            <p class="text-sm text-gray-400"><?= sanitize($eq['client_code']) ?><?php if (!empty($eq['client_city']) || !empty($eq['client_state'])): ?> · <?= sanitize($eq['client_city'] ?? '') ?><?= ($eq['client_city'] ?? '') && ($eq['client_state'] ?? '') ? '/' : '' ?><?= sanitize($eq['client_state'] ?? '') ?><?php endif; ?></p>
          </div>
        </div>
      <?php else: ?>
        <p class="text-gray-400">— Disponível em estoque —</p>
      <?php endif; ?>
    </div>

    <!-- Histórico de Clientes e Locais (vida do equipamento) -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-1">Histórico de Clientes e Locais</h2>
      <p class="text-xs text-gray-400 mb-4">Todos os clientes e locais onde este equipamento esteve.</p>
      <?php if (empty($clientHistory)): ?>
        <p class="text-gray-400 text-sm">Nenhum registro de alocação ou devolução com cliente.</p>
      <?php else: ?>
        <div class="relative pl-6 space-y-3">
          <div class="absolute left-2 top-0 bottom-0 w-0.5 bg-gray-100"></div>
          <?php foreach ($clientHistory as $ch): ?>
          <div class="relative">
            <div class="absolute -left-4 top-1.5 w-3 h-3 rounded-full border-2 <?= $ch['tipo'] === 'alocacao' ? 'border-green-500 bg-green-50' : 'border-amber-500 bg-amber-50' ?> bg-white"></div>
            <p class="text-xs text-gray-500"><?= formatDate($ch['moved_at'], true) ?></p>
            <p class="text-sm font-medium text-gray-800">
              <?php if ($ch['tipo'] === 'alocacao'): ?>
                <span class="text-green-700">Enviado para</span>
              <?php else: ?>
                <span class="text-amber-700">Devolvido por</span>
              <?php endif; ?>
              <a href="/pages/clients/view.php?code=<?= urlencode($ch['client_code']) ?>"
                 class="text-brand hover:underline"><?= sanitize($ch['client_name']) ?></a>
              <?= $ch['client_code'] ? ' <span class="text-gray-400 font-mono">' . sanitize($ch['client_code']) . '</span>' : '' ?>
            </p>
            <?php if ($ch['city'] || $ch['state']): ?>
            <p class="text-xs text-gray-500 flex items-center gap-1">
              <span class="material-symbols-outlined" style="font-size:12px">location_on</span>
              <?= sanitize($ch['city'] ?? '') ?><?= ($ch['city'] && $ch['state']) ? '/' : '' ?><?= sanitize($ch['state'] ?? '') ?>
            </p>
            <?php endif; ?>
            <?php if ($ch['moved_by_name']): ?>
            <p class="text-xs text-gray-300">por <?= sanitize($ch['moved_by_name']) ?></p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

      <!-- Timeline de status -->
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
        <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">Linha do Tempo</h2>
        <?php if (empty($history)): ?>
          <p class="text-gray-400 text-sm">Sem histórico.</p>
        <?php else: ?>
          <div class="relative pl-6 space-y-4">
            <div class="absolute left-2 top-0 bottom-0 w-0.5 bg-gray-100"></div>
            <?php foreach ($history as $h): ?>
            <div class="relative">
              <div class="absolute -left-4 top-1 w-3 h-3 rounded-full border-2 border-brand bg-white"></div>
              <p class="text-xs text-gray-400"><?= formatDate($h['moved_at'], true) ?></p>
              <p class="text-sm font-medium text-gray-700">
                <?= $h['from_status'] ? kanbanLabel($h['from_status']) . ' →' : 'Cadastrado:' ?>
                <?= kanbanLabel($h['to_status']) ?>
              </p>
              <?php if ($h['client_name']): ?>
                <p class="text-xs text-gray-400"><span class="material-symbols-outlined text-brand">assignment_ind</span> <?= sanitize($h['client_name']) ?><?php if (!empty($h['city']) || !empty($h['state'])): ?> — <?= sanitize($h['city'] ?? '') ?><?= ($h['city'] ?? '') && ($h['state'] ?? '') ? '/' : '' ?><?= sanitize($h['state'] ?? '') ?><?php endif; ?></p>
              <?php endif; ?>
              <?php if ($h['notes']): ?>
                <p class="text-xs text-gray-400 italic"><?= sanitize($h['notes']) ?></p>
              <?php endif; ?>
              <p class="text-xs text-gray-300">por <?= sanitize($h['moved_by_name']) ?></p>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Devoluções / Periféricos -->
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
        <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">Devoluções</h2>
        <?php if (empty($returns)): ?>
          <p class="text-gray-400 text-sm">Nenhuma devolução registrada.</p>
        <?php else: ?>
          <div class="space-y-4">
            <?php foreach ($returns as $r): ?>
            <div class="bg-gray-50 rounded-lg p-3 text-sm">
              <p class="font-medium text-gray-700 mb-1"><?= formatDate($r['operation_date'], true) ?><?= !empty($r['via_kanban']) ? ' <span class="text-[10px] font-normal text-gray-400">(via Kanban)</span>' : '' ?></p>
              <p class="text-xs text-gray-500 mb-2">Cliente: <?= sanitize($r['client_name'] ?? '—') ?> · por <?= sanitize($r['performed_by']) ?></p>
              <?php if (!empty($r['via_kanban'])): ?>
              <p class="text-xs text-gray-400 mb-2">Registrado no Kanban — sem checklist de periféricos</p>
              <?php else: ?>
              <div class="flex gap-3 text-xs mb-2">
                <span class="<?= $r['accessories_power']  ? 'text-green-600' : 'text-red-400 line-through' ?>">🔌 Fonte</span>
                <span class="<?= $r['accessories_hdmi']   ? 'text-green-600' : 'text-red-400 line-through' ?>">📺 HDMI</span>
                <span class="<?= $r['accessories_remote'] ? 'text-green-600' : 'text-red-400 line-through' ?>">🎮 Controle</span>
              </div>
              <?php endif; ?>
              <p class="text-xs">Condição: <strong><?= $conditionMap[$r['condition_after_return'] ?? ''] ?? ($r['condition_after_return'] ?? '—') ?></strong></p>
              <?php if ($r['return_notes']): ?>
                <p class="text-xs text-gray-400 italic mt-1"><?= sanitize($r['return_notes']) ?></p>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Auditoria -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mt-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">Auditoria</h2>
      <p class="text-xs text-gray-400 mb-3">Histórico de alterações neste equipamento (inclui edições de Série, MAC e Lote).</p>
      <?php if (empty($auditHistory)): ?>
      <p class="text-gray-400 text-sm">Nenhum registro de auditoria.</p>
      <?php else: ?>
      <div class="overflow-x-auto max-h-64 overflow-y-auto">
        <table class="w-full text-sm">
          <thead class="text-left text-xs text-gray-500 uppercase border-b border-gray-100">
            <tr>
              <th class="py-2 pr-4">Data/Hora</th>
              <th class="py-2 pr-4">Usuário</th>
              <th class="py-2 pr-4">Ação</th>
              <th class="py-2">Descrição</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($auditHistory as $a): ?>
            <tr>
              <td class="py-1.5 pr-4 text-gray-500 text-xs"><?= formatDate($a['created_at'], true) ?></td>
              <td class="py-1.5 pr-4 text-gray-700"><?= sanitize($a['user_name']) ?></td>
              <td class="py-1.5 pr-4"><span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 text-gray-700"><?= sanitize($a['action']) ?></span></td>
              <td class="py-1.5 text-gray-600"><?= sanitize($a['description'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fotos -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mt-6">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider">Fotos (<?= count($photos) ?>/10)</h2>
        <label for="photoUpload" class="cursor-pointer flex items-center gap-2 px-3 py-1.5 bg-brand text-white text-xs rounded-lg hover:bg-blue-800 transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          Adicionar Foto
        </label>
        <input type="file" id="photoUpload" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="uploadPhoto(this)">
      </div>

      <?php if (!empty($photos)): ?>
      <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 mb-4" id="photoGrid">
        <?php foreach ($photos as $p): ?>
        <div class="relative group rounded-lg overflow-hidden border border-gray-200 aspect-square" id="photo-<?= $p['id'] ?>">
          <a href="/uploads/equipment/<?= sanitize($p['filename']) ?>" target="_blank">
            <img src="/uploads/equipment/<?= sanitize($p['filename']) ?>"
                 alt="<?= sanitize($p['original_name'] ?? $p['filename']) ?>"
                 class="w-full h-full object-cover">
          </a>
          <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition flex items-end justify-between p-2 opacity-0 group-hover:opacity-100">
            <span class="text-white text-[10px] truncate max-w-[70%]"><?= sanitize($p['uploaded_by']) ?></span>
            <?php if (in_array($_SESSION['user_role'], ['admin','manager'])): ?>
            <button onclick="deletePhoto(<?= $p['id'] ?>)"
                    class="text-red-300 hover:text-red-100 transition p-1 rounded bg-black/50">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
            <?php endif; ?>
          </div>
          <div class="absolute top-1 right-1">
            <span class="text-[9px] bg-black/50 text-white px-1 py-0.5 rounded">
              <?= date('d/m/y', strtotime($p['created_at'])) ?>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <p class="text-gray-400 text-sm mb-4" id="noPhotosMsg">Nenhuma foto cadastrada.</p>
      <?php endif; ?>

      <div id="uploadProgress" class="hidden">
        <div class="flex items-center gap-3 text-sm text-gray-600 bg-blue-50 border border-blue-200 rounded-lg px-4 py-3">
          <svg class="w-4 h-4 animate-spin text-brand" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
          Enviando foto...
        </div>
      </div>
    </div>

    <!-- Notas -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mt-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">Notas Internas</h2>

      <?php if (!empty($notes)): ?>
      <div class="space-y-3 mb-5" id="notesList">
        <?php foreach ($notes as $n): ?>
        <div class="bg-gray-50 rounded-lg p-3 flex items-start gap-3 group" id="note-<?= $n['id'] ?>">
          <div class="flex-1 min-w-0">
            <!-- Modo leitura -->
            <div class="note-view">
              <p class="text-sm text-gray-700 note-text"><?= nl2br(sanitize($n['note'])) ?></p>
              <p class="text-xs text-gray-400 mt-1"><?= sanitize($n['user_name']) ?> · <?= formatDate($n['created_at'], true) ?></p>
            </div>
            <!-- Modo edição (oculto por padrão) -->
            <div class="note-edit hidden">
              <textarea class="w-full px-2 py-1.5 border border-brand rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none note-textarea"
                        rows="2"><?= sanitize($n['note']) ?></textarea>
              <div class="flex gap-2 mt-2">
                <button onclick="saveNote(<?= $n['id'] ?>)"
                        class="px-3 py-1 bg-brand text-white text-xs font-semibold rounded-lg hover:bg-blue-800 transition">
                  Salvar
                </button>
                <button onclick="cancelEditNote(<?= $n['id'] ?>)"
                        class="px-3 py-1 bg-gray-100 text-gray-600 text-xs font-semibold rounded-lg hover:bg-gray-200 transition">
                  Cancelar
                </button>
              </div>
            </div>
          </div>
          <!-- Botões de ação (visíveis no hover) -->
          <div class="shrink-0 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity note-actions">
            <button onclick="startEditNote(<?= $n['id'] ?>)"
                    title="Editar nota"
                    class="p-1 rounded text-gray-300 hover:text-amber-500 hover:bg-amber-50 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
              </svg>
            </button>
            <button onclick="deleteNote(<?= $n['id'] ?>)"
                    title="Excluir nota"
                    class="p-1 rounded text-gray-300 hover:text-red-500 hover:bg-red-50 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div id="notesList" class="space-y-3 mb-5"></div>
      <?php endif; ?>

      <form id="noteForm" class="flex gap-3">
        <textarea id="noteText" rows="2" placeholder="Adicionar nota..."
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"></textarea>
        <button type="button" onclick="addNote()"
                class="bg-brand text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-800 transition self-end">
          Adicionar
        </button>
      </form>
    </div>
  </div>
</main>

<script>
const EQUIPMENT_ID = <?= $id ?>;
const CSRF_TOKEN = '<?= csrfToken() ?>';

function formatMacMask(input) {
    const raw = input.value.replace(/[^A-Fa-f0-9]/g, '').toUpperCase().slice(0, 12);
    const parts = raw.match(/.{1,2}/g) || [];
    input.value = parts.join(':');
}

function startEdit(field) {
    document.querySelectorAll('.editable-field').forEach(el => {
        el.querySelector('.field-view')?.classList.remove('hidden');
        el.querySelector('.field-edit')?.classList.add('hidden');
    });
    const el = document.querySelector('.editable-field[data-field="' + field + '"]');
    if (el) {
        el.querySelector('.field-view').classList.add('hidden');
        el.querySelector('.field-edit').classList.remove('hidden');
        const inp = field === 'batch_id' ? document.getElementById('edit_batch_search') : document.getElementById('edit_' + field);
        if (inp) { inp.focus(); if (inp.value) inp.select(); }
        if (field === 'batch_id') loadBatchSuggestions('');
    }
}

function cancelEdit(field) {
    const el = document.querySelector('.editable-field[data-field="' + field + '"]');
    if (el) {
        el.querySelector('.field-view').classList.remove('hidden');
        el.querySelector('.field-edit').classList.add('hidden');
    }
}

async function saveField(field) {
    const payload = { equipment_id: EQUIPMENT_ID, csrf_token: CSRF_TOKEN };
    if (field === 'serial_number') payload.serial_number = document.getElementById('edit_serial_number').value.trim();
    if (field === 'mac_address')   payload.mac_address   = document.getElementById('edit_mac_address').value.trim();
    if (field === 'batch_id')      payload.batch_id      = parseInt(document.getElementById('edit_batch_id').value) || 0;

    const resp = await fetch('/pages/api/update_equipment_fields.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const data = await resp.json();

    if (data.success) {
        cancelEdit(field);
        if (field === 'serial_number') {
            const p = document.querySelector('.editable-field[data-field="serial_number"] .field-view p');
            if (p) p.textContent = data.serial_number || '—';
        }
        if (field === 'mac_address') {
            const p = document.querySelector('.editable-field[data-field="mac_address"] .field-view p');
            if (p) p.textContent = data.mac_address || '—';
        }
        if (field === 'batch_id') {
            const v = document.querySelector('.editable-field[data-field="batch_id"] .field-value');
            if (v) {
                const batchName = data.batch || '—';
                if (data.batch_id) {
                    v.outerHTML = '<a href="/pages/equipment/batches.php?action=view&id=' + data.batch_id + '" class="text-sm font-semibold text-brand hover:underline mt-0.5 inline-block font-mono field-value">' + escapeHtml(batchName) + '</a>';
                } else {
                    v.outerHTML = '<p class="text-sm font-medium text-gray-700 mt-0.5 field-value">' + escapeHtml(batchName) + '</p>';
                }
            }
        }
        window.location.reload();
    } else {
        alert('Erro ao salvar: ' + (data.message || ''));
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

let batchSearchTimer;
async function loadBatchSuggestions(q) {
    const url = '/pages/api/search_batches.php?q=' + encodeURIComponent(q) + '&limit=15';
    const resp = await fetch(url);
    const data = await resp.json();
    const list = document.getElementById('batchSuggestions');
    if (!data.batches || data.batches.length === 0) {
        list.innerHTML = '<div class="px-3 py-2 text-gray-400 text-sm">Nenhum lote encontrado</div>';
    } else {
        list.innerHTML = data.batches.map(b => {
            const safe = (String(b.name || '')).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
            return '<div class="px-3 py-2 hover:bg-brand/10 cursor-pointer text-sm font-mono" data-id="' + b.id + '" data-name="' + safe + '">' + escapeHtml(b.name) + '</div>';
        }).join('');
        list.querySelectorAll('div[data-id]').forEach(div => {
            div.addEventListener('click', function() {
                document.getElementById('edit_batch_id').value = this.dataset.id;
                document.getElementById('edit_batch_search').value = this.dataset.name;
                list.classList.add('hidden');
            });
        });
    }
    list.classList.remove('hidden');
}

document.addEventListener('DOMContentLoaded', function() {
    const batchInput = document.getElementById('edit_batch_search');
    const batchList = document.getElementById('batchSuggestions');
    if (batchInput && batchList) {
        batchInput.addEventListener('input', function() {
            clearTimeout(batchSearchTimer);
            const q = this.value.trim();
            if (!q) document.getElementById('edit_batch_id').value = 0;
            batchSearchTimer = setTimeout(() => loadBatchSuggestions(q), 200);
        });
        batchInput.addEventListener('focus', function() {
            if (batchList.innerHTML) batchList.classList.remove('hidden');
            else loadBatchSuggestions(this.value.trim());
        });
        batchInput.addEventListener('blur', function() {
            setTimeout(() => batchList.classList.add('hidden'), 150);
        });
    }
});

async function uploadPhoto(input) {
    if (!input.files || !input.files[0]) return;
    document.getElementById('uploadProgress').classList.remove('hidden');

    const fd = new FormData();
    fd.append('equipment_id', <?= $id ?>);
    fd.append('csrf_token', '<?= csrfToken() ?>');
    fd.append('photo', input.files[0]);

    const resp = await fetch('/pages/api/upload_photo.php', { method: 'POST', body: fd });
    document.getElementById('uploadProgress').classList.add('hidden');
    input.value = '';

    const data = await resp.json();
    if (data.success) {
        window.location.reload();
    } else {
        alert('Erro ao enviar foto: ' + (data.message || ''));
    }
}

async function deletePhoto(photoId) {
    if (!confirm('Remover esta foto?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('photo_id', photoId);
    fd.append('csrf_token', '<?= csrfToken() ?>');

    const resp = await fetch('/pages/api/upload_photo.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success) {
        const el = document.getElementById('photo-' + photoId);
        if (el) el.remove();
    } else {
        alert('Erro ao remover foto: ' + (data.message || ''));
    }
}

async function addNote() {
    const text = document.getElementById('noteText').value.trim();
    if (!text) return;

    const resp = await fetch('/pages/api/add_note.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ equipment_id: <?= $id ?>, note: text, csrf_token: '<?= csrfToken() ?>' })
    });
    const data = await resp.json();
    if (data.success) {
        window.location.reload();
    } else {
        alert('Erro ao adicionar nota: ' + (data.message || ''));
    }
}

async function deleteNote(noteId) {
    if (!confirm('Excluir esta nota?')) return;

    const resp = await fetch('/pages/api/delete_note.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note_id: noteId, csrf_token: '<?= csrfToken() ?>' })
    });
    const data = await resp.json();
    if (data.success) {
        const el = document.getElementById('note-' + noteId);
        if (el) el.remove();
    } else {
        alert('Erro ao excluir nota: ' + (data.message || ''));
    }
}

function startEditNote(noteId) {
    const el = document.getElementById('note-' + noteId);
    el.querySelector('.note-view').classList.add('hidden');
    el.querySelector('.note-edit').classList.remove('hidden');
    el.querySelector('.note-actions').classList.add('hidden');
    el.querySelector('.note-textarea').focus();
}

function cancelEditNote(noteId) {
    const el = document.getElementById('note-' + noteId);
    el.querySelector('.note-edit').classList.add('hidden');
    el.querySelector('.note-view').classList.remove('hidden');
    el.querySelector('.note-actions').classList.remove('hidden');
}

async function saveNote(noteId) {
    const el       = document.getElementById('note-' + noteId);
    const textarea = el.querySelector('.note-textarea');
    const text     = textarea.value.trim();
    if (!text) return;

    const resp = await fetch('/pages/api/edit_note.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note_id: noteId, note: text, csrf_token: '<?= csrfToken() ?>' })
    });
    const data = await resp.json();
    if (data.success) {
        // Atualiza o texto exibido sem reload
        const textEl = el.querySelector('.note-text');
        textEl.innerHTML = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
        cancelEditNote(noteId);
    } else {
        alert('Erro ao salvar nota: ' + (data.message || ''));
    }
}
</script>
<!-- Modal QR Code -->
<div id="modalQR" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4"
     onclick="if(event.target===this)this.classList.add('hidden')">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 text-center">
    <h3 class="text-base font-bold text-gray-900 mb-1">QR Code do Equipamento</h3>
    <p class="font-mono text-sm text-brand mb-4"><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?></p>
    <div id="qrcode" class="flex justify-center mb-4"></div>
    <p class="text-xs text-gray-400 mb-5">Aponte a câmera para acessar a ficha deste equipamento.</p>
    <div class="flex gap-3 justify-center">
      <button onclick="printQR()"
              class="flex items-center gap-2 px-4 py-2 bg-brand text-white text-sm rounded-lg hover:bg-blue-800 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
        </svg>
        Imprimir Etiqueta
      </button>
      <button onclick="document.getElementById('modalQR').classList.add('hidden')"
              class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200 transition">
        Fechar
      </button>
    </div>
  </div>
</div>

<!-- Área oculta de impressão da etiqueta -->
<div id="printArea" class="hidden">
  <style>
    @media print {
      body > *:not(#printArea) { display: none !important; }
      #printArea {
        display: flex !important;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px;
        font-family: Arial, sans-serif;
      }
      .label-box {
        border: 2px solid #000;
        border-radius: 8px;
        padding: 16px 20px;
        text-align: center;
        width: 200px;
      }
      .label-title { font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
      .label-tag { font-size: 18px; font-weight: bold; font-family: monospace; color: #1B4F8C; }
      .label-model { font-size: 11px; color: #333; margin: 4px 0; }
    }
  </style>
  <div class="label-box">
    <div class="label-title">S8 Conect CRM</div>
    <div id="printQR"></div>
    <div class="label-tag"><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?></div>
    <div class="label-model"><?= sanitize(displayModelName($eq['brand'], $eq['model_name'])) ?></div>
  </div>
</div>

<script>
// Gera o QR Code ao abrir o modal
const qrUrl = '<?= BASE_URL ?>/pages/equipment/view.php?id=<?= $id ?>';
let qrGenerated = false;
document.getElementById('modalQR').addEventListener('click', function() {
    if (!qrGenerated) {
        new QRCode(document.getElementById('qrcode'), {
            text: qrUrl,
            width: 200,
            height: 200,
            colorDark: '#1B4F8C',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M,
        });
        qrGenerated = true;
    }
});

function printQR() {
    if (!document.querySelector('#qrcode img')) return;
    const qrImg = document.querySelector('#qrcode img').src;
    document.getElementById('printQR').innerHTML = '<img src="' + qrImg + '" style="width:120px;height:120px;margin:8px auto;display:block;">';
    document.getElementById('printArea').classList.remove('hidden');
    window.print();
    setTimeout(() => document.getElementById('printArea').classList.add('hidden'), 500);
}
</script>
</body>
</html>
