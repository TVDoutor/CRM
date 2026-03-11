<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db = getDB();

// Filtros
$search    = trim($_GET['search']    ?? '');
$kanban    = trim($_GET['kanban_status'] ?? '');
$condition = trim($_GET['condition_status'] ?? '');
$contract  = trim($_GET['contract_type'] ?? '');
$modelId   = (int)($_GET['model_id'] ?? 0);
$batch     = trim($_GET['batch'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 30;
$offset    = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(e.asset_tag LIKE ? OR e.serial_number LIKE ? OR e.mac_address LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($kanban)    { $where[] = 'e.kanban_status = ?';    $params[] = $kanban; }
if ($condition) { $where[] = 'e.condition_status = ?'; $params[] = $condition; }
if ($contract)  { $where[] = 'e.contract_type = ?';    $params[] = $contract; }
if ($modelId)   { $where[] = 'e.model_id = ?';         $params[] = $modelId; }
if ($batch)     { $where[] = 'e.batch LIKE ?';         $params[] = "%$batch%"; }

$whereStr = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM equipment e WHERE $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare("SELECT e.id, e.asset_tag, e.serial_number, e.mac_address,
    e.condition_status, e.kanban_status, e.contract_type, e.batch, e.entry_date, e.purchase_date,
    em.brand, em.model_name,
    c.name as client_name, c.client_code
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
LEFT JOIN clients c ON c.id = e.current_client_id
WHERE $whereStr
ORDER BY e.asset_tag ASC
LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Modelos para filtro
$models = $db->query("SELECT id, brand, model_name FROM equipment_models WHERE is_active = 1 ORDER BY brand")->fetchAll();

// Alertas de garantia (equipamentos com purchase_date preenchida)
$garantiaVencida = $db->query("
    SELECT e.id, e.asset_tag, e.mac_address, e.purchase_date, e.kanban_status,
           em.brand, em.model_name,
           DATEDIFF(DATE_ADD(e.purchase_date, INTERVAL 12 MONTH), CURDATE()) as dias_restantes
    FROM equipment e
    JOIN equipment_models em ON em.id = e.model_id
    WHERE e.purchase_date IS NOT NULL
      AND e.kanban_status != 'baixado'
      AND DATEDIFF(DATE_ADD(e.purchase_date, INTERVAL 12 MONTH), CURDATE()) < 0
    ORDER BY dias_restantes ASC
")->fetchAll();

$garantiaProxima = $db->query("
    SELECT e.id, e.asset_tag, e.mac_address, e.purchase_date, e.kanban_status,
           em.brand, em.model_name,
           DATEDIFF(DATE_ADD(e.purchase_date, INTERVAL 12 MONTH), CURDATE()) as dias_restantes
    FROM equipment e
    JOIN equipment_models em ON em.id = e.model_id
    WHERE e.purchase_date IS NOT NULL
      AND e.kanban_status != 'baixado'
      AND DATEDIFF(DATE_ADD(e.purchase_date, INTERVAL 12 MONTH), CURDATE()) BETWEEN 0 AND 30
    ORDER BY dias_restantes ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Equipamentos — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-7xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
      <div>
        <h1 class="text-xl lg:text-2xl font-bold text-gray-800">Equipamentos</h1>
        <p class="text-gray-500 text-sm"><?= $total ?> equipamento(s) encontrado(s)</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="/pages/equipment/create.php" class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-blue-800 transition">+ Novo</a>
        <a href="/pages/equipment/batch_entry.php" class="bg-green-600 text-white text-sm px-4 py-2 rounded-lg hover:bg-green-700 transition flex items-center gap-1"><span class="material-symbols-outlined" style="font-size:16px">inventory_2</span> Entrada de Lote</a>
      </div>
    </div>

    <?php flashRender(); ?>

    <!-- Filtros -->
    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-6">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
        <input type="text" name="search" value="<?= sanitize($search) ?>"
               placeholder="Etiqueta, S/N, MAC..."
               class="sm:col-span-2 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">

        <select name="kanban_status" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Todos os status</option>
          <?php foreach (['entrada','aguardando_instalacao','alocado','manutencao','licenca_removida','equipamento_usado','comercial','processo_devolucao','baixado'] as $s): ?>
          <option value="<?= $s ?>" <?= $kanban === $s ? 'selected' : '' ?>><?= kanbanLabel($s) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="condition_status" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Condição</option>
          <option value="novo"  <?= $condition === 'novo'  ? 'selected' : '' ?>>Novo</option>
          <option value="usado" <?= $condition === 'usado' ? 'selected' : '' ?>>Usado</option>
        </select>

        <select name="model_id" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Modelo</option>
          <?php foreach ($models as $m): ?>
          <option value="<?= $m['id'] ?>" <?= $modelId === (int)$m['id'] ? 'selected' : '' ?>>
            <?= sanitize(displayModelName($m['brand'], $m['model_name'])) ?>
          </option>
          <?php endforeach; ?>
        </select>

        <div class="flex gap-2">
          <button type="submit" class="flex-1 bg-brand text-white text-sm py-2 px-3 rounded-lg hover:bg-blue-800 transition">Filtrar</button>
          <a href="/pages/equipment/index.php" class="flex-1 text-center bg-gray-100 text-gray-700 text-sm py-2 px-3 rounded-lg hover:bg-gray-200 transition">Limpar</a>
        </div>
      </div>
    </form>

    <!-- Tabela -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr class="text-[11px] font-semibold text-gray-400 uppercase tracking-widest">
              <th class="text-left px-4 py-3 w-24">Etiqueta</th>
              <th class="text-left px-4 py-3">Modelo</th>
              <th class="text-left px-4 py-3 hidden lg:table-cell">S/N</th>
              <th class="text-left px-4 py-3">Condição</th>
              <th class="text-left px-4 py-3">Status Kanban</th>
              <th class="text-left px-4 py-3">Garantia</th>
              <th class="text-left px-4 py-3 hidden md:table-cell">Tipo</th>
              <th class="text-left px-4 py-3">Cliente</th>
              <th class="text-left px-4 py-3 hidden xl:table-cell">Lote</th>
              <th class="text-right px-4 py-3 w-28">Ações</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <?php if (empty($rows)): ?>
            <tr>
              <td colspan="10" class="text-center py-16 text-gray-400">
                <svg class="mx-auto mb-3 w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                Nenhum equipamento encontrado.
              </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
              // Modelo: evita repetir marca se já está no model_name
              $modelDisplay = displayModelName($r['brand'], $r['model_name']);
            ?>
            <tr class="hover:bg-blue-50/30 transition group">

              <!-- Etiqueta -->
              <td class="px-4 py-3">
                <a href="/pages/equipment/view.php?id=<?= $r['id'] ?>"
                   class="font-mono font-bold text-[13px] text-brand hover:underline tracking-wide">
                  <?= sanitize(displayTag($r['asset_tag'], $r['mac_address'] ?? null)) ?>
                </a>
              </td>

              <!-- Modelo (sem duplicar marca) -->
              <td class="px-4 py-3">
                <span class="text-gray-800 text-[13px] font-medium"><?= sanitize($modelDisplay) ?></span>
              </td>

              <!-- S/N (oculto em telas menores) -->
              <td class="px-4 py-3 hidden lg:table-cell">
                <span class="font-mono text-[11px] text-gray-400"><?= sanitize($r['serial_number'] ?? '—') ?></span>
              </td>

              <!-- Condição -->
              <td class="px-4 py-3">
                <?php if ($r['condition_status'] === 'novo'): ?>
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-700">● Novo</span>
                <?php else: ?>
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700">● Usado</span>
                <?php endif; ?>
              </td>

              <!-- Status Kanban -->
              <td class="px-4 py-3"><?= kanbanBadge($r['kanban_status']) ?></td>

              <!-- Garantia -->
              <td class="px-4 py-3">
                <?php
                  $w = warrantyStatus($r['purchase_date']);
                  switch ($w['status']) {
                    case 'ok':
                      echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-100 text-green-700 border border-green-200" title="Vence em '.$w['expires'].' ('.$w['days'].' dias)">Garantia</span>';
                      break;
                    case 'vencendo':
                      echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-orange-100 text-orange-700 border border-orange-200" title="Vence em '.$w['expires'].' ('.$w['days'].' dias restantes)">Vencendo</span>';
                      break;
                    case 'vencida':
                      echo '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-700 border border-red-200" title="Venceu em '.$w['expires'].' (há '.$w['days'].' dias)">Vencida</span>';
                      break;
                    default:
                      echo '<span class="text-[11px] text-gray-300">—</span>';
                  }
                ?>
              </td>

              <!-- Tipo (oculto em mobile) -->
              <td class="px-4 py-3 hidden md:table-cell">
                <span class="text-[11px] text-gray-500"><?= contractLabel($r['contract_type']) ?></span>
              </td>

              <!-- Cliente -->
              <td class="px-4 py-3 max-w-[180px]">
                <?php if ($r['client_name']): ?>
                  <a href="/pages/clients/view.php?code=<?= urlencode($r['client_code']) ?>"
                     class="text-[12px] text-brand hover:underline truncate block" title="<?= sanitize($r['client_name']) ?>">
                    <?= sanitize($r['client_name']) ?>
                  </a>
                <?php else: ?>
                  <span class="text-[11px] text-gray-300 italic">Em estoque</span>
                <?php endif; ?>
              </td>

              <!-- Lote (oculto em telas menores) -->
              <td class="px-4 py-3 hidden xl:table-cell">
                <span class="font-mono text-[11px] text-gray-400"><?= sanitize($r['batch'] ?? '—') ?></span>
              </td>

              <!-- Ações -->
              <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-1">
                  <a href="/pages/equipment/view.php?id=<?= $r['id'] ?>"
                     class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-semibold bg-brand/10 text-brand hover:bg-brand hover:text-white transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    Ver
                  </a>
                  <a href="/pages/equipment/edit.php?id=<?= $r['id'] ?>"
                     class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[11px] font-semibold bg-gray-100 text-gray-600 hover:bg-gray-200 transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Editar
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginação -->
      <?php if ($totalPages > 1): ?>
      <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-t border-gray-100 text-sm text-gray-500">
        <span class="text-xs"><?= $total ?> resultado(s) · Página <?= $page ?> de <?= $totalPages ?></span>
        <div class="flex gap-1">
          <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
             class="px-3 py-1.5 rounded-lg text-xs bg-gray-100 hover:bg-gray-200 transition">← Anterior</a>
          <?php endif; ?>
          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
             class="px-3 py-1.5 rounded-lg text-xs font-semibold <?= $p === $page ? 'bg-brand text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-600' ?> transition">
            <?= $p ?>
          </a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
             class="px-3 py-1.5 rounded-lg text-xs bg-gray-100 hover:bg-gray-200 transition">Próxima →</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php if (!empty($garantiaVencida) || !empty($garantiaProxima)): ?>
<!-- Modal de Alertas de Garantia -->
<div id="garantiaModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[85vh] flex flex-col">

    <!-- Header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
      <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-2xl">notifications</span>
        <div>
          <h3 class="text-lg font-bold text-gray-800">Alertas de Garantia</h3>
          <p class="text-xs text-gray-400">Equipamentos que requerem atenção</p>
        </div>
      </div>
      <button onclick="fecharModalGarantia()"
              class="text-gray-400 hover:text-gray-600 text-2xl leading-none font-light">×</button>
    </div>

    <!-- Corpo rolável -->
    <div class="overflow-y-auto flex-1 px-6 py-4 space-y-5">

      <?php if (!empty($garantiaVencida)): ?>
      <div>
        <div class="flex items-center gap-2 mb-3">
          <span class="inline-flex items-center gap-1.5 bg-red-100 text-red-700 text-xs font-bold px-3 py-1 rounded-full">
            <span class="material-symbols-outlined text-sm">error</span> GARANTIA VENCIDA — <?= count($garantiaVencida) ?> equipamento(s)
          </span>
        </div>
        <div class="space-y-2">
          <?php foreach ($garantiaVencida as $g):
            $venceuEm = date('d/m/Y', strtotime($g['purchase_date'] . ' +12 months'));
            $diasVencidos = abs((int)$g['dias_restantes']);
          ?>
          <div class="flex items-center justify-between bg-red-50 border border-red-200 rounded-xl px-4 py-3">
            <div>
              <a href="/pages/equipment/view.php?id=<?= $g['id'] ?>"
                 class="font-mono font-bold text-red-700 hover:underline">
                <?= sanitize(displayTag($g['asset_tag'], $g['mac_address'] ?? null)) ?>
              </a>
              <p class="text-xs text-gray-600 mt-0.5">
                <?= sanitize(displayModelName($g['brand'], $g['model_name'])) ?>
              </p>
              <p class="text-xs text-gray-400">
                Compra: <?= date('d/m/Y', strtotime($g['purchase_date'])) ?> ·
                Venceu em: <?= $venceuEm ?>
              </p>
            </div>
            <div class="text-right shrink-0 ml-4">
              <span class="inline-flex items-center px-2 py-1 rounded-lg bg-red-200 text-red-800 text-xs font-bold">
                <?= $diasVencidos ?> dia<?= $diasVencidos !== 1 ? 's' : '' ?> vencida
              </span>
              <p class="text-xs text-gray-400 mt-1"><?= kanbanLabel($g['kanban_status']) ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($garantiaProxima)): ?>
      <div>
        <div class="flex items-center gap-2 mb-3">
          <span class="inline-flex items-center gap-1.5 bg-orange-100 text-orange-700 text-xs font-bold px-3 py-1 rounded-full">
            <span class="material-symbols-outlined text-sm">warning</span> VENCE EM ATÉ 30 DIAS — <?= count($garantiaProxima) ?> equipamento(s)
          </span>
        </div>
        <div class="space-y-2">
          <?php foreach ($garantiaProxima as $g):
            $venceEm = date('d/m/Y', strtotime($g['purchase_date'] . ' +12 months'));
            $dias = (int)$g['dias_restantes'];
          ?>
          <div class="flex items-center justify-between bg-orange-50 border border-orange-200 rounded-xl px-4 py-3">
            <div>
              <a href="/pages/equipment/view.php?id=<?= $g['id'] ?>"
                 class="font-mono font-bold text-orange-700 hover:underline">
                <?= sanitize(displayTag($g['asset_tag'], $g['mac_address'] ?? null)) ?>
              </a>
              <p class="text-xs text-gray-600 mt-0.5">
                <?= sanitize(displayModelName($g['brand'], $g['model_name'])) ?>
              </p>
              <p class="text-xs text-gray-400">
                Compra: <?= date('d/m/Y', strtotime($g['purchase_date'])) ?> ·
                Vence em: <?= $venceEm ?>
              </p>
            </div>
            <div class="text-right shrink-0 ml-4">
              <?php if ($dias === 0): ?>
                <span class="inline-flex items-center px-2 py-1 rounded-lg bg-orange-300 text-orange-900 text-xs font-bold">
                  Vence HOJE
                </span>
              <?php else: ?>
                <span class="inline-flex items-center px-2 py-1 rounded-lg bg-orange-200 text-orange-800 text-xs font-bold">
                  <?= $dias ?> dia<?= $dias !== 1 ? 's' : '' ?> restante<?= $dias !== 1 ? 's' : '' ?>
                </span>
              <?php endif; ?>
              <p class="text-xs text-gray-400 mt-1"><?= kanbanLabel($g['kanban_status']) ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <!-- Footer -->
    <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
      <p class="text-xs text-gray-400">Clique no código do equipamento para ver detalhes.</p>
      <button onclick="fecharModalGarantia()"
              class="bg-brand text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">
        Entendido
      </button>
    </div>
  </div>
</div>

<script>
if (new URLSearchParams(window.location.search).get('batch_saved') === '1') {
    try { localStorage.removeItem('tvd_batch_entry_draft'); } catch (e) {}
}
function fecharModalGarantia() {
    document.getElementById('garantiaModal').classList.add('hidden');
}
// Fechar clicando fora do modal
document.getElementById('garantiaModal').addEventListener('click', function(e) {
    if (e.target === this) fecharModalGarantia();
});
</script>
<?php endif; ?>

</body>
</html>
