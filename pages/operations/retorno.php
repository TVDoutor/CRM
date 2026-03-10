<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db     = getDB();
$errors = [];

// Equipamentos alocados / lic. removida / processo devolução
$stmt = $db->query("SELECT e.id, e.asset_tag, e.mac_address, e.serial_number, e.condition_status,
    e.current_client_id, e.kanban_status, c.name as client_name, c.client_code,
    em.brand, em.model_name,
    DATEDIFF(NOW(), (
        SELECT moved_at FROM kanban_history
        WHERE equipment_id = e.id AND to_status IN ('alocado','licenca_removida','processo_devolucao')
        ORDER BY moved_at DESC LIMIT 1
    )) as days_with_client
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
JOIN clients c ON c.id = e.current_client_id
WHERE e.kanban_status IN ('alocado', 'processo_devolucao', 'licenca_removida')
ORDER BY c.name, e.asset_tag");
$alocados = $stmt->fetchAll();

// KPIs
$kpiAlocado = 0; $kpiLicRemovida = 0; $kpiProcDev = 0;
foreach ($alocados as $eq) {
    if ($eq['kanban_status'] === 'alocado') $kpiAlocado++;
    elseif ($eq['kanban_status'] === 'licenca_removida') $kpiLicRemovida++;
    elseif ($eq['kanban_status'] === 'processo_devolucao') $kpiProcDev++;
}

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
            $results  = ['usado' => 0, 'manutencao' => 0, 'baixado' => 0];

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

$kanbanBadgeMap = [
    'alocado'            => ['label' => 'Alocado',           'cls' => 'bg-green-100 text-green-700', 'icon' => 'check_circle'],
    'licenca_removida'   => ['label' => 'Lic. Removida',     'cls' => 'bg-purple-100 text-purple-700', 'icon' => 'lock'],
    'processo_devolucao' => ['label' => 'Proc. Devolução',    'cls' => 'bg-red-100 text-red-700', 'icon' => 'swap_horiz'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Devolução — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <style>
    .eq-card.selected { border-color: #1B4F8C; background: #f0f5ff; }
    .eq-card.selected .card-details { display: block; }
    .cond-btn.active-ok   { background: #dcfce7; border-color: #22c55e; color: #15803d; }
    .cond-btn.active-man  { background: #ffedd5; border-color: #f97316; color: #c2410c; }
    .cond-btn.active-desc { background: #fee2e2; border-color: #ef4444; color: #b91c1c; }
  </style>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 overflow-auto pt-16 lg:pt-0" style="padding-bottom:90px;">
  <div class="max-w-5xl mx-auto p-4 lg:p-8 space-y-5">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2">
      <div>
        <h1 class="text-xl lg:text-2xl font-bold text-gray-800 flex items-center gap-2">
          <span class="material-symbols-outlined text-orange-500">call_received</span>
          Devolução / Retorno
        </h1>
        <p class="text-gray-400 text-sm">Registre o retorno de equipamentos por clientes</p>
      </div>
      <a href="/pages/kanban.php" class="inline-flex items-center gap-1.5 text-xs text-brand hover:underline font-medium">
        <span class="material-symbols-outlined text-sm">view_kanban</span> Ir ao Kanban
      </a>
    </div>

    <?php if ($errors): ?>
    <div class="p-4 bg-red-50 border border-red-300 rounded-xl">
      <?php foreach ($errors as $e): ?><p class="text-sm text-red-700 flex items-center gap-1.5"><span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php flashRender(); ?>

    <?php if (empty($alocados)): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-16 text-center">
      <span class="material-symbols-outlined text-5xl text-green-400 mb-3">celebration</span>
      <p class="text-gray-500 text-lg font-medium">Nenhum equipamento pendente de devolução</p>
      <p class="text-gray-400 text-sm mt-1">Todos os equipamentos estão com status atualizado.</p>
    </div>
    <?php else: ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-3 text-center">
        <p class="text-2xl font-bold text-gray-700"><?= count($alocados) ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Total para devolver</p>
      </div>
      <button type="button" onclick="filterByStatus('alocado')" class="status-filter-btn bg-white rounded-xl border border-gray-100 shadow-sm p-3 text-center hover:border-green-300 transition" data-status="alocado">
        <p class="text-2xl font-bold text-green-600"><?= $kpiAlocado ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Alocados</p>
      </button>
      <button type="button" onclick="filterByStatus('licenca_removida')" class="status-filter-btn bg-white rounded-xl border border-gray-100 shadow-sm p-3 text-center hover:border-purple-300 transition" data-status="licenca_removida">
        <p class="text-2xl font-bold text-purple-600"><?= $kpiLicRemovida ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Lic. Removida</p>
      </button>
      <button type="button" onclick="filterByStatus('processo_devolucao')" class="status-filter-btn bg-white rounded-xl border border-gray-100 shadow-sm p-3 text-center hover:border-red-300 transition" data-status="processo_devolucao">
        <p class="text-2xl font-bold text-red-600"><?= $kpiProcDev ?></p>
        <p class="text-[11px] text-gray-400 font-medium">Proc. Devolução</p>
      </button>
    </div>

    <!-- Filtro -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
      <div class="flex items-center gap-2 flex-1">
        <span class="material-symbols-outlined text-gray-400 text-lg">search</span>
        <input type="text" id="clientFilter"
               placeholder="Buscar por cliente, código (P2254) ou MAC/player (B8DF)..."
               oninput="applyFilters()"
               class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
      </div>
      <button type="button" onclick="clearFilters()" class="text-xs text-gray-500 hover:text-brand font-medium px-3 py-2 rounded-lg hover:bg-gray-100 transition whitespace-nowrap">
        Limpar filtros
      </button>
    </div>

    <!-- No results message -->
    <div id="noResults" class="hidden bg-white rounded-xl border border-gray-100 shadow-sm p-10 text-center">
      <span class="material-symbols-outlined text-3xl text-gray-300 mb-2">search_off</span>
      <p class="text-gray-500 font-medium">Nenhum resultado encontrado</p>
      <p class="text-gray-400 text-sm mt-1">Tente outro termo de busca ou limpe os filtros.</p>
    </div>

    <form method="POST" id="retornoForm">
      <?= csrfField() ?>

      <div id="clientGroups" class="space-y-4">
        <?php foreach ($byClient as $cid => $clientData):
          $clientEqCount = count($clientData['items']);
          $clientStatuses = implode(' ', array_unique(array_column($clientData['items'], 'kanban_status')));
        ?>
        <div class="client-group bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden"
             data-client="<?= strtolower(sanitize($clientData['name'])) ?>"
             data-client-code="<?= strtolower(sanitize($clientData['code'] ?? '')) ?>"
             data-statuses="<?= $clientStatuses ?>"
             data-equipment-tags="<?= strtolower(implode(' ', array_map(function($i) {
                $tag = displayTag($i['asset_tag'], $i['mac_address'] ?? null);
                $mac = preg_replace('/[^a-f0-9]/', '', strtolower($i['mac_address'] ?? ''));
                return $tag . ' ' . $mac . ' ' . strtolower($i['asset_tag'] ?? '');
             }, $clientData['items']))) ?>">

          <!-- Client header -->
          <div class="bg-gradient-to-r from-gray-50 to-white px-5 py-3 border-b border-gray-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-lg bg-brand/10 flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-brand text-lg">business</span>
              </div>
              <div>
                <p class="font-semibold text-gray-800 text-sm"><?= sanitize($clientData['name']) ?></p>
                <p class="text-[11px] text-gray-400 font-mono"><?= sanitize($clientData['code']) ?> · <?= $clientEqCount ?> equipamento<?= $clientEqCount > 1 ? 's' : '' ?></p>
              </div>
            </div>
            <button type="button" onclick="selectClientAll(<?= $cid ?>)"
                    class="inline-flex items-center gap-1 text-xs text-brand hover:bg-brand/5 px-3 py-1.5 rounded-lg transition font-medium">
              <span class="material-symbols-outlined text-sm">select_all</span>
              Selecionar todos
            </button>
          </div>

          <div class="p-4 space-y-3">
            <?php foreach ($clientData['items'] as $eq):
              $kb = $kanbanBadgeMap[$eq['kanban_status']] ?? ['label' => $eq['kanban_status'], 'cls' => 'bg-gray-100 text-gray-600', 'icon' => 'circle'];
              $days = $eq['days_with_client'] !== null ? (int)$eq['days_with_client'] : null;
              $daysColor = $days !== null && $days > 180 ? 'text-red-500' : ($days !== null && $days > 90 ? 'text-orange-500' : 'text-gray-400');
            ?>
            <div class="eq-card border border-gray-200 rounded-xl p-4 transition-all hover:border-gray-300"
                 data-client-id="<?= $cid ?>" data-status="<?= $eq['kanban_status'] ?>">

              <div class="flex items-start gap-3">
                <input type="checkbox" name="return_ids[]" value="<?= $eq['id'] ?>"
                       id="cb<?= $eq['id'] ?>"
                       onchange="toggleCard(this)"
                       class="w-5 h-5 text-brand mt-0.5 rounded border-gray-300 cursor-pointer">
                <label for="cb<?= $eq['id'] ?>" class="flex-1 cursor-pointer min-w-0">
                  <div class="flex flex-wrap items-center gap-2 mb-1">
                    <span class="font-mono font-bold text-brand text-sm"><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?></span>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold <?= $kb['cls'] ?>">
                      <span class="material-symbols-outlined" style="font-size:11px"><?= $kb['icon'] ?></span>
                      <?= $kb['label'] ?>
                    </span>
                    <?= conditionBadge($eq['condition_status']) ?>
                  </div>
                  <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                    <span class="flex items-center gap-1">
                      <span class="material-symbols-outlined" style="font-size:13px">devices</span>
                      <?= sanitize($eq['brand']) ?> <?= sanitize($eq['model_name']) ?>
                    </span>
                    <?php if ($days !== null): ?>
                    <span class="flex items-center gap-1 <?= $daysColor ?> font-medium">
                      <span class="material-symbols-outlined" style="font-size:13px">schedule</span>
                      <?= $days ?> dias com cliente
                    </span>
                    <?php endif; ?>
                  </div>
                </label>
              </div>

              <!-- Detalhes (visível quando selecionado) -->
              <div class="card-details hidden mt-4 ml-8 space-y-3">

                <!-- Periféricos -->
                <div class="bg-gray-50 rounded-lg p-3">
                  <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Periféricos devolvidos</p>
                  <div class="flex flex-wrap gap-3">
                    <label class="flex items-center gap-2 bg-white border border-gray-200 rounded-lg px-3 py-2 cursor-pointer hover:border-brand/40 transition">
                      <input type="checkbox" name="accessories_power[<?= $eq['id'] ?>]" value="1" class="w-4 h-4 text-brand rounded">
                      <span class="material-symbols-outlined text-gray-500" style="font-size:16px">power</span>
                      <span class="text-xs font-medium text-gray-700">Fonte</span>
                    </label>
                    <label class="flex items-center gap-2 bg-white border border-gray-200 rounded-lg px-3 py-2 cursor-pointer hover:border-brand/40 transition">
                      <input type="checkbox" name="accessories_hdmi[<?= $eq['id'] ?>]" value="1" class="w-4 h-4 text-brand rounded">
                      <span class="material-symbols-outlined text-gray-500" style="font-size:16px">cable</span>
                      <span class="text-xs font-medium text-gray-700">HDMI</span>
                    </label>
                    <label class="flex items-center gap-2 bg-white border border-gray-200 rounded-lg px-3 py-2 cursor-pointer hover:border-brand/40 transition">
                      <input type="checkbox" name="accessories_remote[<?= $eq['id'] ?>]" value="1" class="w-4 h-4 text-brand rounded">
                      <span class="material-symbols-outlined text-gray-500" style="font-size:16px">settings_remote</span>
                      <span class="text-xs font-medium text-gray-700">Controle</span>
                    </label>
                  </div>
                </div>

                <!-- Condição: card buttons -->
                <div>
                  <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-2">Condição do equipamento</p>
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <input type="hidden" name="condition_after_return[<?= $eq['id'] ?>]" id="cond<?= $eq['id'] ?>" value="ok">
                    <button type="button" onclick="setCond(<?= $eq['id'] ?>,'ok')"
                            class="cond-btn active-ok border-2 rounded-lg p-3 text-left transition" data-eq="<?= $eq['id'] ?>" data-val="ok">
                      <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined" style="font-size:18px">check_circle</span>
                        <span class="text-sm font-bold">Bom estado</span>
                      </div>
                      <p class="text-[11px] opacity-70">Pode ser reenviado (Eq. Usado)</p>
                    </button>
                    <button type="button" onclick="setCond(<?= $eq['id'] ?>,'manutencao')"
                            class="cond-btn border-2 border-gray-200 rounded-lg p-3 text-left text-gray-500 transition" data-eq="<?= $eq['id'] ?>" data-val="manutencao">
                      <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined" style="font-size:18px">build</span>
                        <span class="text-sm font-bold">Manutenção</span>
                      </div>
                      <p class="text-[11px] opacity-70">Precisa de reparo</p>
                    </button>
                    <button type="button" onclick="setCond(<?= $eq['id'] ?>,'descartar')"
                            class="cond-btn border-2 border-gray-200 rounded-lg p-3 text-left text-gray-500 transition" data-eq="<?= $eq['id'] ?>" data-val="descartar">
                      <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined" style="font-size:18px">delete_forever</span>
                        <span class="text-sm font-bold">Descartar</span>
                      </div>
                      <p class="text-[11px] opacity-70">Baixar do sistema</p>
                    </button>
                  </div>
                </div>

                <!-- Observações -->
                <div>
                  <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1.5">Observações da inspeção</label>
                  <textarea name="return_notes[<?= $eq['id'] ?>]" rows="2"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"
                            placeholder="Estado do equipamento, acessórios faltando, observações..."></textarea>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Hidden fields for footer form -->
      <input type="hidden" name="operation_date" id="opDateHidden" value="<?= sanitize($_POST['operation_date'] ?? date('Y-m-d\TH:i')) ?>">
      <input type="hidden" name="op_notes" id="opNotesHidden" value="<?= sanitize($_POST['op_notes'] ?? '') ?>">
    </form>
    <?php endif; ?>
  </div>
</main>

<?php if (!empty($alocados)): ?>
<!-- Footer fixo -->
<div class="fixed bottom-0 left-0 lg:left-64 right-0 bg-white border-t border-gray-200 shadow-lg z-30">
  <div class="max-w-5xl mx-auto px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-3">
    <div class="flex items-center gap-4 text-sm text-gray-500">
      <span class="flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base text-brand">check_box</span>
        <strong id="returnCount" class="text-brand text-lg">0</strong> selecionado(s)
      </span>
      <span class="hidden sm:inline text-gray-300">|</span>
      <span class="hidden sm:flex items-center gap-1.5 text-xs">
        <span class="material-symbols-outlined text-sm">schedule</span>
        <input type="datetime-local" id="opDateFooter"
               value="<?= sanitize($_POST['operation_date'] ?? date('Y-m-d\TH:i')) ?>"
               onchange="document.getElementById('opDateHidden').value=this.value"
               class="px-2 py-1 border border-gray-200 rounded text-xs focus:outline-none focus:ring-1 focus:ring-brand">
      </span>
    </div>
    <div class="flex items-center gap-2">
      <input type="text" id="opNotesFooter" placeholder="Observação geral..."
             value="<?= sanitize($_POST['op_notes'] ?? '') ?>"
             oninput="document.getElementById('opNotesHidden').value=this.value"
             class="px-3 py-2 border border-gray-200 rounded-lg text-xs w-48 focus:outline-none focus:ring-1 focus:ring-brand hidden sm:block">
      <button type="button" onclick="openConfirmModal()" id="btnConfirm" disabled
              class="bg-orange-500 hover:bg-orange-600 disabled:bg-gray-300 disabled:cursor-not-allowed text-white px-6 py-2.5 rounded-lg font-semibold text-sm transition flex items-center gap-2">
        <span class="material-symbols-outlined text-base">check</span>
        Confirmar Devolução
      </button>
    </div>
  </div>
</div>

<!-- Modal de confirmação -->
<div id="confirmModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-orange-600">warning</span>
      </div>
      <div>
        <h3 class="font-bold text-gray-800">Confirmar Devolução</h3>
        <p class="text-sm text-gray-500" id="confirmDesc"></p>
      </div>
    </div>
    <p class="text-sm text-gray-600 mb-5">Esta ação irá registrar a devolução e mover os equipamentos no Kanban. Deseja continuar?</p>
    <div class="flex gap-3 justify-end">
      <button type="button" onclick="closeConfirmModal()"
              class="px-5 py-2 text-sm text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition font-medium">
        Cancelar
      </button>
      <button type="button" onclick="submitReturn()"
              class="px-5 py-2 text-sm text-white bg-orange-500 hover:bg-orange-600 rounded-lg transition font-semibold flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base">check</span>
        Confirmar
      </button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
let activeStatusFilter = null;

function toggleCard(cb) {
    const card = cb.closest('.eq-card');
    card.classList.toggle('selected', cb.checked);
    updateReturnCount();
}

function selectClientAll(cid) {
    document.querySelectorAll(`.eq-card[data-client-id="${cid}"] input[type="checkbox"]`).forEach(cb => {
        cb.checked = true;
        toggleCard(cb);
    });
}

function setCond(eqId, val) {
    document.getElementById('cond' + eqId).value = val;
    document.querySelectorAll(`.cond-btn[data-eq="${eqId}"]`).forEach(btn => {
        btn.classList.remove('active-ok','active-man','active-desc');
        btn.classList.add('border-gray-200','text-gray-500');
        btn.classList.remove('border-green-500','border-orange-500','border-red-500');
        if (btn.dataset.val === val) {
            btn.classList.remove('border-gray-200','text-gray-500');
            if (val === 'ok')         btn.classList.add('active-ok');
            if (val === 'manutencao') btn.classList.add('active-man');
            if (val === 'descartar')  btn.classList.add('active-desc');
        }
    });
}

function updateReturnCount() {
    const count = document.querySelectorAll('input[name="return_ids[]"]:checked').length;
    document.getElementById('returnCount').textContent = count;
    document.getElementById('btnConfirm').disabled = count === 0;
}

function filterByStatus(status) {
    if (activeStatusFilter === status) {
        activeStatusFilter = null;
    } else {
        activeStatusFilter = status;
    }
    document.querySelectorAll('.status-filter-btn').forEach(btn => {
        btn.classList.toggle('ring-2', btn.dataset.status === activeStatusFilter);
        btn.classList.toggle('ring-brand', btn.dataset.status === activeStatusFilter);
    });
    applyFilters();
}

function applyFilters() {
    const q = (document.getElementById('clientFilter')?.value || '').toLowerCase().trim();
    let visibleCount = 0;

    document.querySelectorAll('.client-group').forEach(g => {
        const matchText = !q
            || (g.dataset.client || '').includes(q)
            || (g.dataset.clientCode || '').includes(q)
            || (g.dataset.equipmentTags || '').includes(q);
        const matchStatus = !activeStatusFilter || (g.dataset.statuses || '').includes(activeStatusFilter);
        const visible = matchText && matchStatus;
        g.classList.toggle('hidden', !visible);
        if (visible) visibleCount++;
    });

    const noRes = document.getElementById('noResults');
    if (noRes) noRes.classList.toggle('hidden', visibleCount > 0);
}

function clearFilters() {
    const input = document.getElementById('clientFilter');
    if (input) input.value = '';
    activeStatusFilter = null;
    document.querySelectorAll('.status-filter-btn').forEach(btn => {
        btn.classList.remove('ring-2','ring-brand');
    });
    applyFilters();
}

function openConfirmModal() {
    const count = document.querySelectorAll('input[name="return_ids[]"]:checked').length;
    if (!count) return;
    document.getElementById('confirmDesc').textContent = count + ' equipamento(s) selecionado(s)';
    document.getElementById('confirmModal').classList.remove('hidden');
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.add('hidden');
}

function submitReturn() {
    closeConfirmModal();
    document.getElementById('retornoForm').submit();
}
</script>
</body>
</html>
