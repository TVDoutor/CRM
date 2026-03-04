<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/pipedrive.php';
requireLogin();
requireRole(['admin', 'manager']);

$db = getDB();

// Filtros
$search    = trim($_GET['search']  ?? '');
$status    = trim($_GET['status']  ?? '');
$phase     = trim($_GET['phase']   ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 30;
$offset    = ($page - 1) * $perPage;

// Verificar se tabela existe
$tableExists = false;
try {
    $db->query("SELECT 1 FROM pipedrive_projects LIMIT 1");
    $tableExists = true;
} catch (\Exception $e) {}

$rows       = [];
$total      = 0;
$totalPages = 1;
$phases     = [];
$lastSync   = null;
$stats      = ['total' => 0, 'open' => 0, 'completed' => 0, 'canceled' => 0, 'linked' => 0];

if ($tableExists) {
    // Último sync
    try {
        $lastSync = $db->query("SELECT * FROM pipedrive_projects_sync_log ORDER BY created_at DESC LIMIT 1")->fetch();
    } catch (\Exception $e) {}

    // Fases disponíveis para filtro — apenas board Pontos de Exibição (8)
    $phases = $db->query("SELECT DISTINCT phase_name FROM pipedrive_projects WHERE phase_name IS NOT NULL AND board_id = 8 ORDER BY phase_name")->fetchAll(PDO::FETCH_COLUMN);

    // Stats — apenas board 8
    $statsRow = $db->query("SELECT
        COUNT(*) as total,
        SUM(status='open') as open,
        SUM(status='completed') as completed,
        SUM(status='canceled') as canceled,
        SUM(client_id IS NOT NULL) as linked
        FROM pipedrive_projects WHERE board_id = 8")->fetch();
    if ($statsRow) $stats = array_merge($stats, $statsRow);

    // Where — sempre filtra board 8
    $where  = ['pp.board_id = 8'];
    $params = [];
    if ($search) {
        $where[]  = '(pp.title LIKE ? OR pp.client_code LIKE ? OR c.name LIKE ?)';
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
    }
    if ($status) { $where[] = 'pp.status = ?'; $params[] = $status; }
    if ($phase)  { $where[] = 'pp.phase_name = ?'; $params[] = $phase; }
    $whereStr = implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM pipedrive_projects pp LEFT JOIN clients c ON c.id = pp.client_id WHERE $whereStr");
    $countStmt->execute($params);
    $total      = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $stmt = $db->prepare("SELECT pp.*, c.name as client_name
        FROM pipedrive_projects pp
        LEFT JOIN clients c ON c.id = pp.client_id
        WHERE $whereStr
        ORDER BY pp.status ASC, pp.start_date DESC
        LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Projetos Pipedrive — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-6xl mx-auto">

    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-[#0F4C81] rounded-xl flex items-center justify-center text-white font-bold text-lg shrink-0">P</div>
        <div>
          <h1 class="text-xl lg:text-2xl font-bold text-gray-800">Projetos Pipedrive</h1>
          <p class="text-gray-500 text-sm">Board Pontos de Exibição sincronizado com o CRM</p>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="/pages/pipedrive/index.php" class="text-sm text-gray-500 hover:text-brand px-3 py-2 rounded-lg hover:bg-gray-100 transition">← Integração</a>
        <button onclick="runUpdate()"
                id="btnUpdate"
                class="flex items-center gap-2 bg-violet-600 hover:bg-violet-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition"
                title="Atualiza modelo, MAC, lote e data de compra dos equipamentos já importados">
          <span id="updateIcon">🔧</span>
          <span id="updateLabel">Atualizar Dados</span>
        </button>
        <button onclick="runImport()"
                id="btnImport"
                class="flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
          <span id="importIcon">📥</span>
          <span id="importLabel">Importar Equipamentos</span>
        </button>
        <button onclick="runSync()"
                id="btnSync"
                class="flex items-center gap-2 bg-[#0F4C81] hover:bg-blue-900 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
          <span id="syncIcon">🔄</span>
          <span id="syncLabel">Sincronizar Projetos</span>
        </button>
      </div>
    </div>

    <?php flashRender(); ?>

    <!-- Resultado importação em tempo real -->
    <div id="importResult" class="hidden mb-4"></div>

    <?php if (!$tableExists): ?>
    <!-- Aviso de migração necessária -->
    <div class="bg-orange-50 border border-orange-200 rounded-xl p-6 mb-6">
      <h3 class="font-bold text-orange-800 mb-2">⚠️ Configuração necessária</h3>
      <p class="text-sm text-orange-700 mb-3">Execute o script SQL abaixo no phpMyAdmin para criar a tabela de projetos:</p>
      <div class="bg-white rounded-lg p-3 font-mono text-xs text-gray-700 border border-orange-200">
        Execute o arquivo: <strong>config/migrate_projects.sql</strong>
      </div>
      <p class="text-xs text-orange-600 mt-3">Após executar o SQL, clique em <strong>"Sincronizar Projetos"</strong> e depois em <strong>"Importar Equipamentos"</strong>.</p>
    </div>
    <?php else: ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Total</p>
        <p class="text-2xl font-bold text-gray-700"><?= (int)$stats['total'] ?></p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Abertos</p>
        <p class="text-2xl font-bold text-green-600"><?= (int)$stats['open'] ?></p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Concluídos</p>
        <p class="text-2xl font-bold text-blue-600"><?= (int)$stats['completed'] ?></p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Cancelados</p>
        <p class="text-2xl font-bold text-red-500"><?= (int)$stats['canceled'] ?></p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Vinculados</p>
        <p class="text-2xl font-bold text-brand"><?= (int)$stats['linked'] ?></p>
        <p class="text-[10px] text-gray-400">ao cliente no CRM</p>
      </div>
    </div>

    <!-- Último sync -->
    <?php if ($lastSync): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-5 py-3 mb-4 flex flex-wrap items-center gap-3 text-sm text-gray-500">
      <span>Última sincronização: <strong><?= formatDate($lastSync['created_at'], true) ?></strong></span>
      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold
        <?= $lastSync['status'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
        <?= $lastSync['status'] === 'success' ? '✅ Sucesso' : '⚠️ Parcial' ?>
      </span>
      <span><?= $lastSync['created'] ?> criados · <?= $lastSync['updated'] ?> atualizados · <?= $lastSync['duration_ms'] ?>ms</span>
    </div>
    <?php endif; ?>

    <!-- Resultado sync em tempo real -->
    <div id="syncResult" class="hidden mb-4"></div>

    <!-- Filtros -->
    <form method="GET" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
      <div class="flex flex-wrap gap-3">
        <input type="text" name="search" value="<?= sanitize($search) ?>"
               placeholder="Título, cliente ou código..."
               class="flex-1 min-w-[160px] px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
        <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Todos os status</option>
          <option value="open"      <?= $status === 'open'      ? 'selected' : '' ?>>Aberto</option>
          <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Concluído</option>
          <option value="canceled"  <?= $status === 'canceled'  ? 'selected' : '' ?>>Cancelado</option>
        </select>
        <select name="phase" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">Todas as fases</option>
          <?php foreach ($phases as $ph): ?>
          <option value="<?= sanitize($ph) ?>" <?= $phase === $ph ? 'selected' : '' ?>><?= sanitize($ph) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-blue-800 transition">Filtrar</button>
        <a href="/pages/pipedrive/projects.php" class="bg-gray-100 text-gray-700 text-sm px-4 py-2 rounded-lg hover:bg-gray-200 transition">Limpar</a>
      </div>
    </form>

    <!-- Tabela de projetos -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100 text-sm text-gray-500">
        <?= $total ?> projeto(s) encontrado(s)
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr class="text-xs text-gray-500 uppercase tracking-wider">
              <th class="text-left px-4 py-3">Título</th>
              <th class="text-left px-4 py-3">Cliente CRM</th>
              <th class="text-left px-4 py-3">Fase</th>
              <th class="text-left px-4 py-3">Status</th>
              <th class="text-left px-4 py-3">Início</th>
              <th class="text-left px-4 py-3">Ações</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="text-center py-10 text-gray-400">
              <?= $tableExists ? 'Nenhum projeto encontrado. Clique em "Sincronizar Projetos".' : 'Execute o script SQL primeiro.' ?>
            </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $proj):
              $statusColor = match($proj['status']) {
                'open'      => 'bg-green-100 text-green-800',
                'completed' => 'bg-blue-100 text-blue-800',
                'canceled'  => 'bg-red-100 text-red-700',
                default     => 'bg-gray-100 text-gray-600',
              };
              $statusLabel = match($proj['status']) {
                'open'      => 'Aberto',
                'completed' => 'Concluído',
                'canceled'  => 'Cancelado',
                default     => $proj['status'],
              };
            ?>
            <tr class="hover:bg-gray-50 transition">
              <td class="px-4 py-3">
                <p class="font-medium text-gray-800 truncate max-w-[280px]" title="<?= sanitize($proj['title']) ?>"><?= sanitize($proj['title']) ?></p>
                <?php if ($proj['client_code']): ?>
                <p class="text-xs text-gray-400 font-mono"><?= sanitize($proj['client_code']) ?></p>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <?php if ($proj['client_name'] && $proj['client_code']): ?>
                <a href="/pages/clients/view.php?code=<?= urlencode($proj['client_code']) ?>"
                   class="text-brand hover:underline text-sm"><?= sanitize($proj['client_name']) ?></a>
                <?php else: ?>
                <span class="text-gray-400 text-xs">Não vinculado</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-600 text-xs"><?= sanitize($proj['phase_name'] ?? '—') ?></td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $statusColor ?>">
                  <?= $statusLabel ?>
                </span>
              </td>
              <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">
                <?= $proj['start_date'] ? date('d/m/Y', strtotime($proj['start_date'])) : '—' ?>
              </td>
              <td class="px-4 py-3">
                <a href="https://tvdoutor.pipedrive.com/projects/<?= $proj['pipedrive_id'] ?>"
                   target="_blank"
                   class="text-xs text-[#0F4C81] hover:underline">Ver no Pipe →</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-t border-gray-100 text-sm text-gray-500">
        <span>Página <?= $page ?> de <?= $totalPages ?></span>
        <div class="flex gap-1">
          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
             class="px-3 py-1 rounded <?= $p === $page ? 'bg-brand text-white' : 'bg-gray-100 hover:bg-gray-200' ?>">
            <?= $p ?>
          </a>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </div>
</main>

<script>
async function runSync() {
    const btn    = document.getElementById('btnSync');
    const icon   = document.getElementById('syncIcon');
    const label  = document.getElementById('syncLabel');
    const result = document.getElementById('syncResult');

    btn.disabled = true;
    icon.textContent  = '⏳';
    result.classList.remove('hidden');

    // Estado acumulado entre páginas
    let cursor      = null;
    let pipeStatus  = 'open';
    let accCreated  = 0, accUpdated = 0, accKanban = 0, accTotal = 0;
    let accErrors   = [];
    let page        = 0;
    const statusLabel = { open: 'abertos', completed: 'concluídos', canceled: 'cancelados' };

    const showProgress = (msg) => {
        label.textContent = msg;
        result.innerHTML = `
          <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
            <div class="flex items-center gap-3 mb-2">
              <div class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
              <span class="text-blue-800 font-semibold text-sm">${msg}</span>
            </div>
            <div class="flex flex-wrap gap-4 text-xs text-gray-600">
              <span>Processados: <strong>${accTotal}</strong></span>
              <span class="text-green-700">Novos: <strong>+${accCreated}</strong></span>
              <span class="text-yellow-700">Atualizados: <strong>↺${accUpdated}</strong></span>
              ${accKanban > 0 ? `<span class="text-blue-700">Kanban: <strong>⇄${accKanban}</strong></span>` : ''}
            </div>
          </div>`;
    };

    try {
        let done = false;
        while (!done) {
            page++;
            showProgress(`Sincronizando ${statusLabel[pipeStatus] || pipeStatus}... (${accTotal} processados)`);

            const body = {
                csrf_token:         '<?= csrfToken() ?>',
                cursor:             cursor,
                pipe_status:        pipeStatus,
                acc_created:        accCreated,
                acc_updated:        accUpdated,
                acc_kanban_updated: accKanban,
                acc_total:          accTotal,
                acc_errors:         accErrors,
            };

            const resp = await fetch('<?= BASE_URL ?>/pages/api/pipedrive_projects_sync.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(body),
            });

            const rawText = await resp.text();
            if (!rawText.trim()) throw new Error('Resposta vazia do servidor (possível timeout). Tente novamente.');
            const data = JSON.parse(rawText);
            if (!data.success) throw new Error(data.message || 'Erro na sincronização');

            // Atualiza estado
            cursor     = data.cursor;
            pipeStatus = data.pipe_status;
            accCreated = data.acc_created;
            accUpdated = data.acc_updated;
            accKanban  = data.acc_kanban_updated;
            accTotal   = data.acc_total;
            accErrors  = data.acc_errors || [];
            done       = data.done;
        }

        // Concluído
        const hasErr = accErrors.length > 0;
        result.innerHTML = `
          <div class="rounded-xl border ${hasErr ? 'border-yellow-200 bg-yellow-50' : 'border-green-200 bg-green-50'} p-4">
            <p class="font-bold ${hasErr ? 'text-yellow-800' : 'text-green-800'} mb-2">
              ${hasErr ? '⚠️ Sincronizado com avisos' : '✅ Projetos sincronizados!'}
            </p>
            <div class="flex flex-wrap gap-4 text-sm">
              <span class="text-gray-600">Total: <strong>${accTotal}</strong></span>
              <span class="text-green-700">Novos: <strong>+${accCreated}</strong></span>
              <span class="text-yellow-700">Atualizados: <strong>↺${accUpdated}</strong></span>
              ${accKanban > 0 ? `<span class="text-blue-700">Kanban movido: <strong>⇄${accKanban}</strong></span>` : ''}
            </div>
            ${accErrors.length ? `<div class="mt-2 text-xs text-red-600">${accErrors.join('<br>')}</div>` : ''}
          </div>`;
        setTimeout(() => window.location.reload(), 3000);

    } catch(e) {
        result.innerHTML = `<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700 text-sm">❌ Erro: ${e.message}</div>`;
    } finally {
        btn.disabled     = false;
        icon.textContent = '🔄';
        label.textContent = 'Sincronizar Projetos';
    }
}

async function runUpdate() {
    const btn    = document.getElementById('btnUpdate');
    const icon   = document.getElementById('updateIcon');
    const label  = document.getElementById('updateLabel');
    const result = document.getElementById('importResult');

    if (!confirm('Isso vai atualizar modelo, MAC, lote e data de compra de todos os equipamentos importados.\n\nContinuar?')) return;

    btn.disabled = true;
    icon.textContent  = '⏳';
    label.textContent = 'Atualizando...';
    result.classList.add('hidden');

    try {
        const resp = await fetch('<?= BASE_URL ?>/pages/api/pipedrive_update_equipment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: '<?= csrfToken() ?>' })
        });

        const rawText = await resp.text();
        if (!rawText.trim()) throw new Error('Resposta vazia do servidor.');
        const data = JSON.parse(rawText);

        const isOk = data.success;
        const bg   = isOk ? 'bg-violet-50 border-violet-200' : 'bg-red-50 border-red-200';
        const txt  = isOk ? 'text-violet-800' : 'text-red-800';

        result.innerHTML = `
            <div class="rounded-xl border ${bg} p-4">
              <p class="font-bold ${txt} mb-2">${isOk ? '✅ Equipamentos atualizados!' : '❌ Erro na atualização'} — ${data.duration}</p>
              <p class="text-sm text-gray-700 mb-2">${data.message}</p>
              <div class="flex flex-wrap gap-4 text-sm">
                <span class="text-gray-600">Projetos processados: <strong>${data.total_found}</strong></span>
                <span class="text-violet-700">Atualizados: <strong>${data.updated}</strong></span>
                <span class="text-gray-500">Ignorados: <strong>${data.skipped}</strong></span>
              </div>
              ${data.errors?.length ? `<div class="mt-2 text-xs text-red-600">${data.errors.join('<br>')}</div>` : ''}
            </div>`;
        result.classList.remove('hidden');

    } catch(e) {
        result.innerHTML = `<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700 text-sm">Erro: ${e.message}</div>`;
        result.classList.remove('hidden');
    } finally {
        btn.disabled = false;
        icon.textContent  = '🔧';
        label.textContent = 'Atualizar Dados';
    }
}

async function runImport() {
    const btn    = document.getElementById('btnImport');
    const icon   = document.getElementById('importIcon');
    const label  = document.getElementById('importLabel');
    const result = document.getElementById('importResult');

    if (!confirm('Isso vai importar equipamentos do Pipedrive para o CRM.\nEquipamentos já existentes serão ignorados.\n\nContinuar?')) return;

    btn.disabled = true;
    icon.textContent  = '⏳';
    label.textContent = 'Importando...';
    result.classList.add('hidden');

    try {
        const resp = await fetch('<?= BASE_URL ?>/pages/api/pipedrive_import_equipment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: '<?= csrfToken() ?>' })
        });

        const rawText = await resp.text();
        if (!rawText.trim()) throw new Error('Resposta vazia do servidor.');
        const data = JSON.parse(rawText);

        const isOk = data.success;
        const bg   = isOk ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200';
        const txt  = isOk ? 'text-green-800' : 'text-red-800';

        result.innerHTML = `
            <div class="rounded-xl border ${bg} p-4">
              <p class="font-bold ${txt} mb-2">${isOk ? '✅ Importação concluída!' : '❌ Erro na importação'} — ${data.duration}</p>
              <p class="text-sm text-gray-700 mb-2">${data.message}</p>
              <div class="flex flex-wrap gap-4 text-sm">
                <span class="text-gray-600">Projetos encontrados: <strong>${data.total_found}</strong></span>
                <span class="text-green-700">Criados: <strong>+${data.created}</strong></span>
                <span class="text-gray-500">Ignorados: <strong>${data.skipped}</strong></span>
              </div>
              ${data.errors?.length ? `<div class="mt-2 text-xs text-red-600">${data.errors.join('<br>')}</div>` : ''}
            </div>`;
        result.classList.remove('hidden');
        if (data.created > 0) setTimeout(() => window.location.reload(), 3000);

    } catch(e) {
        result.innerHTML = `<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700 text-sm">Erro: ${e.message}</div>`;
        result.classList.remove('hidden');
    } finally {
        btn.disabled = false;
        icon.textContent  = '📥';
        label.textContent = 'Importar Equipamentos';
    }
}
</script>
</body>
</html>
