<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db = getDB();

$columns = [
    'entrada'               => ['label' => 'Entrada',              'icon' => 'inbox',           'color' => 'text-slate-600',  'border' => 'border-l-slate-400'],
    'aguardando_instalacao' => ['label' => 'Aguardando Instalação','icon' => 'pending',          'color' => 'text-amber-600',  'border' => 'border-l-amber-400'],
    'alocado'               => ['label' => 'Alocado',              'icon' => 'check_circle',     'color' => 'text-green-600',  'border' => 'border-l-green-500'],
    'licenca_removida'      => ['label' => 'Licença Removida',     'icon' => 'lock',             'color' => 'text-purple-600', 'border' => 'border-l-purple-500'],
    'equipamento_usado'     => ['label' => 'Equipamento Usado',    'icon' => 'recycling',        'color' => 'text-blue-600',   'border' => 'border-l-blue-400'],
    'comercial'             => ['label' => 'Comercial',            'icon' => 'business_center',  'color' => 'text-teal-600',   'border' => 'border-l-teal-500'],
    'processo_devolucao'    => ['label' => 'Processo Devolução',   'icon' => 'swap_horiz',       'color' => 'text-red-600',    'border' => 'border-l-red-500'],
    'manutencao'            => ['label' => 'Manutenção',           'icon' => 'build',            'color' => 'text-orange-600', 'border' => 'border-l-orange-500'],
    'baixado'               => ['label' => 'Baixado',              'icon' => 'delete',           'color' => 'text-gray-500',   'border' => 'border-l-gray-400'],
];

$stmt = $db->query("SELECT e.id, e.asset_tag, e.mac_address, e.serial_number, e.kanban_status, e.condition_status,
    e.contract_type, e.current_client_id, e.purchase_date,
    em.brand, em.model_name,
    c.name as client_name, c.client_code,
    (SELECT GROUP_CONCAT(DISTINCT pp.client_code) FROM pipedrive_projects pp
     WHERE pp.client_id = e.current_client_id AND pp.client_code IS NOT NULL AND pp.client_code != '') as pipe_client_codes
FROM equipment e
JOIN equipment_models em ON em.id = e.model_id
LEFT JOIN clients c ON c.id = e.current_client_id
ORDER BY e.asset_tag ASC");

$byStatus = [];
foreach ($columns as $k => $v) $byStatus[$k] = [];

foreach ($stmt->fetchAll() as $eq) {
    $s = $eq['kanban_status'];
    if (isset($byStatus[$s])) $byStatus[$s][] = $eq;
}

$statusLabels = array_map(fn($c) => $c['label'], $columns);

// ── Buscar projetos Pipedrive vinculados (board Pontos de Exibição) ─────────
// Indexado por asset_tag para lookup O(1) nos cards
$pipeProjects = [];
try {
    $ppStmt = $db->query(
        "SELECT asset_tag, phase_name, pipedrive_id, title, client_code
         FROM pipedrive_projects
         WHERE board_id = 8 AND status = 'open' AND asset_tag IS NOT NULL"
    );
    foreach ($ppStmt->fetchAll(\PDO::FETCH_ASSOC) as $pp) {
        $pipeProjects[strtoupper($pp['asset_tag'])] = $pp;
    }
} catch (\Exception $e) {
    // Tabela ainda não existe — ignora
}
?>
<!DOCTYPE html>
<html class="light" lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
  <title>Kanban — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: { primary: '#1B4F8C', 'background-light': '#f6f7f8' },
          fontFamily: { display: ['Inter'] },
        },
      },
    }
  </script>
  <style>
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .kanban-card { cursor: grab; }
    .kanban-card:active { cursor: grabbing; }
    .drag-over { outline: 2px dashed #1B4F8C !important; background: #dbeafe !important; }

    /* Mobile: view toggle */
    @media (max-width: 767px) {
      #kanbanBoardView  { display: none; }
      #kanbanListView   { display: block; }
      .kanban-list-section { margin-bottom: 0.75rem; }
    }
    @media (min-width: 768px) {
      #kanbanBoardView  { display: flex; }
      #kanbanListView   { display: none; }
    }
  </style>
</head>
<body class="bg-background-light font-display text-slate-900" style="height:100vh;overflow:hidden;display:flex;">

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- Área de conteúdo: header + board -->
<div style="flex:1;display:flex;flex-direction:column;overflow:hidden;min-width:0;">

    <!-- Header -->
    <header class="bg-white border-b border-slate-200 shrink-0 mt-14 lg:mt-0">
      <div class="flex items-center px-4 py-3 gap-3 justify-between">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-primary text-2xl hidden sm:inline">view_kanban</span>
          <div>
            <h2 class="text-sm font-bold leading-tight">Kanban de Equipamentos</h2>
            <p class="text-xs text-slate-400 hidden sm:block">Arraste os cards para mover entre colunas</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <!-- Toggle mobile: board / lista -->
          <div class="flex md:hidden rounded-lg border border-slate-200 overflow-hidden text-xs">
            <button id="btnBoard" onclick="setView('board')"
                    class="px-3 py-1.5 bg-primary text-white font-medium transition">Board</button>
            <button id="btnList"  onclick="setView('list')"
                    class="px-3 py-1.5 bg-white text-slate-600 font-medium transition">Lista</button>
          </div>
          <a href="/pages/equipment/batch_entry.php"
             class="flex items-center gap-1 bg-primary text-white text-xs px-3 py-2 rounded-lg hover:bg-blue-900 transition whitespace-nowrap">
            <span class="material-symbols-outlined text-sm">add</span>
            <span class="hidden sm:inline">Entrada de Lote</span>
            <span class="sm:hidden">Entrada</span>
          </a>
        </div>
      </div>
      <!-- Busca -->
      <div class="px-4 pb-3">
        <div class="flex w-full max-w-sm items-stretch rounded-lg bg-slate-100 h-8">
          <div class="text-slate-400 flex items-center justify-center px-2.5">
            <span class="material-symbols-outlined text-base">search</span>
          </div>
          <input id="searchInput"
                 oninput="filterCards(this.value)"
                 class="w-full bg-transparent border-none focus:ring-0 text-xs placeholder:text-slate-400"
                 placeholder="Pesquisar por tag, modelo, cliente, código..."/>
        </div>
      </div>
    </header>

    <!-- Área de conteúdo (board ou lista) -->
    <div style="flex:1;overflow:auto;">

      <?php flashRender(); ?>

      <!-- BOARD (desktop padrão, mobile com botão) -->
      <div id="kanbanBoardView" class="flex gap-3 items-start p-4 no-scrollbar" style="min-width:max-content;min-height:100%;">
        <?php foreach ($columns as $status => $col):
          $cards  = $byStatus[$status];
          $count  = count($cards);
          $isEmpty = empty($cards);
        ?>
        <div class="flex-shrink-0 w-64 sm:w-72 bg-slate-100/70 rounded-xl p-3 flex flex-col gap-2"
             style="max-height:calc(100vh - 190px);">

          <!-- Header da coluna -->
          <div class="flex items-center justify-between px-1 mb-1">
            <h3 class="font-bold text-xs uppercase tracking-wider <?= $col['color'] ?> flex items-center gap-1.5">
              <span class="material-symbols-outlined text-base"><?= $col['icon'] ?></span>
              <?= htmlspecialchars($col['label']) ?>
            </h3>
            <span class="bg-slate-300 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full"><?= $count ?></span>
          </div>

          <!-- Área de cards -->
          <div id="col-<?= $status ?>"
               data-status="<?= $status ?>"
               class="kanban-col flex flex-col gap-2 overflow-y-auto no-scrollbar flex-1"
               style="min-height: 80px;"
               ondragover="handleDragOver(event)"
               ondrop="handleDrop(event)"
               ondragleave="handleDragLeave(event)">

            <?php if ($isEmpty): ?>
            <div class="border-2 border-dashed border-slate-300 rounded-lg h-20 flex items-center justify-center">
              <p class="text-xs text-slate-400 italic">Nenhum item</p>
            </div>
            <?php endif; ?>

            <?php foreach ($cards as $eq): ?>
            <?php
              $condClass = match($eq['condition_status'] ?? '') {
                  'novo'       => 'bg-blue-100 text-blue-700',
                  'bom'        => 'bg-green-100 text-green-700',
                  'regular'    => 'bg-yellow-100 text-yellow-700',
                  'ruim'       => 'bg-orange-100 text-orange-700',
                  'sucateado'  => 'bg-red-100 text-red-700',
                  default      => 'bg-slate-100 text-slate-600',
              };
              $condLabel = match($eq['condition_status'] ?? '') {
                  'novo'      => 'Novo',
                  'bom'       => 'Bom',
                  'regular'   => 'Regular',
                  'ruim'      => 'Ruim',
                  'sucateado' => 'Sucateado',
                  'usado'     => 'Usado',
                  default     => ucfirst($eq['condition_status'] ?? ''),
              };
              $hasBorderLeft = in_array($status, ['alocado','manutencao','licenca_removida','processo_devolucao','comercial']);
              // Lookup Pipedrive: busca pelos últimos 6 dígitos do MAC/asset_tag
              $assetKey   = macLast6($eq['asset_tag']);
              $pipeData   = $pipeProjects[$assetKey] ?? null;
            ?>
            <div class="kanban-card bg-white p-3 rounded-lg shadow-sm border border-slate-200
                        hover:shadow-md transition flex flex-col gap-1.5
                        <?= $hasBorderLeft ? 'border-l-4 ' . $col['border'] : '' ?>"
                 draggable="true"
                 data-id="<?= $eq['id'] ?>"
                 data-status="<?= $eq['kanban_status'] ?>"
                 data-search="<?= strtolower(htmlspecialchars(($eq['asset_tag'] ?? '') . ' ' . (!empty($eq['mac_address']) ? macLast6($eq['mac_address']) : '') . ' ' . $eq['brand'] . ' ' . $eq['model_name'] . ' ' . ($eq['client_name'] ?? '') . ' ' . ($eq['client_code'] ?? '') . ' ' . ($eq['pipe_client_codes'] ?? '') . ' ' . ($pipeData['client_code'] ?? '') . ' ' . ($pipeData['title'] ?? ''))) ?>"
                 ondragstart="handleDragStart(event)">

              <div class="flex justify-between items-start gap-1">
                <a href="/pages/equipment/view.php?id=<?= $eq['id'] ?>"
                   class="text-xs font-bold text-primary hover:underline font-mono leading-tight"
                   onclick="event.stopPropagation()">
                  <?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?>
                </a>
                <?php if ($condLabel): ?>
                <span class="text-[10px] px-1.5 py-0.5 rounded font-semibold <?= $condClass ?>"><?= $condLabel ?></span>
                <?php endif; ?>
              </div>

              <p class="text-xs text-slate-600 font-medium truncate">
                <?= sanitize($eq['brand']) ?> <?= sanitize($eq['model_name']) ?>
              </p>

              <?php if ($eq['client_name']): ?>
              <div class="flex items-center gap-1 text-xs text-slate-400">
                <span class="material-symbols-outlined text-sm">business</span>
                <span class="truncate"><?= sanitize($eq['client_name']) ?></span>
              </div>
              <?php endif; ?>

              <?php if ($eq['contract_type']): ?>
              <div class="flex items-center gap-1">
                <?php
                  $ctColor = match($eq['contract_type']) {
                    'comodato'            => 'bg-blue-50 text-blue-600',
                    'equipamento_cliente' => 'bg-purple-50 text-purple-600',
                    'parceria'            => 'bg-teal-50 text-teal-600',
                    default               => 'bg-slate-50 text-slate-500',
                  };
                ?>
                <span class="text-[10px] px-1.5 py-0.5 rounded font-medium <?= $ctColor ?>">
                  <?= contractLabel($eq['contract_type']) ?>
                </span>
              </div>
              <?php endif; ?>

              <?php
                $wCard = warrantyStatus($eq['purchase_date'] ?? null);
                if ($wCard['status'] === 'vencendo' || $wCard['status'] === 'vencida'):
                  $wCardCls = $wCard['status'] === 'vencendo'
                    ? 'bg-orange-50 text-orange-700 border-orange-200'
                    : 'bg-red-50 text-red-700 border-red-200';
                  $wCardIcon = '';
                  $wCardLabel = $wCard['status'] === 'vencendo'
                    ? "Garantia vence em {$wCard['days']}d"
                    : "Garantia vencida";
              ?>
              <div class="flex items-center gap-1">
                <span class="text-[10px] px-1.5 py-0.5 rounded border font-medium <?= $wCardCls ?>">
                  <?= $wCardLabel ?>
                </span>
              </div>
              <?php endif; ?>

              <?php if ($pipeData): ?>
              <div class="flex items-center gap-1 mt-0.5 pt-1.5 border-t border-slate-100">
                <a href="https://tvdoutor.pipedrive.com/projects/<?= $pipeData['pipedrive_id'] ?>"
                   target="_blank"
                   onclick="event.stopPropagation()"
                   title="<?= htmlspecialchars($pipeData['title']) ?>"
                   class="flex items-center gap-1 text-[10px] text-[#0F4C81] hover:underline font-medium truncate">
                  <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                    <circle cx="12" cy="12" r="10" fill="#0F4C81" opacity=".15"/>
                    <path d="M8 6h8v2H8zm0 4h5v2H8zm0 4h8v2H8z" fill="#0F4C81"/>
                  </svg>
                  <span class="truncate"><?= htmlspecialchars($pipeData['phase_name'] ?? 'Pipedrive') ?></span>
                </a>
              </div>
              <?php endif; ?>

            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- LISTA (modo mobile — accordion por coluna) -->
      <div id="kanbanListView" class="p-3 space-y-2">
        <?php foreach ($columns as $status => $col):
          $cards = $byStatus[$status];
          $count = count($cards);
        ?>
        <div class="kanban-list-section bg-white rounded-xl border border-slate-200 overflow-hidden">
          <button onclick="toggleListSection('ls-<?= $status ?>')"
                  class="w-full flex items-center justify-between px-4 py-3 <?= $col['color'] ?> bg-slate-50">
            <span class="flex items-center gap-2 font-bold text-sm">
              <span class="material-symbols-outlined text-base"><?= $col['icon'] ?></span>
              <?= htmlspecialchars($col['label']) ?>
            </span>
            <span class="flex items-center gap-2">
              <span class="bg-slate-300 text-slate-700 text-[10px] font-bold px-2 py-0.5 rounded-full"><?= $count ?></span>
              <span class="material-symbols-outlined text-slate-400 text-base ls-chevron-<?= $status ?>">expand_more</span>
            </span>
          </button>
          <div id="ls-<?= $status ?>" class="<?= $count > 0 ? '' : 'hidden' ?>">
            <?php if (empty($cards)): ?>
            <p class="text-center text-xs text-slate-400 py-4 italic">Nenhum equipamento</p>
            <?php else: ?>
            <div class="divide-y divide-slate-100">
              <?php foreach ($cards as $eq):
                $assetKeyL = macLast6($eq['asset_tag']);
                $pipeDataL = $pipeProjects[$assetKeyL] ?? null;
              ?>
              <a href="/pages/equipment/view.php?id=<?= $eq['id'] ?>"
                 class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 transition list-card"
                 data-search="<?= strtolower(htmlspecialchars(($eq['asset_tag'] ?? '') . ' ' . (!empty($eq['mac_address']) ? macLast6($eq['mac_address']) : '') . ' ' . $eq['brand'] . ' ' . $eq['model_name'] . ' ' . ($eq['client_name'] ?? '') . ' ' . ($eq['client_code'] ?? '') . ' ' . ($eq['pipe_client_codes'] ?? '') . ' ' . ($pipeDataL['client_code'] ?? '') . ' ' . ($pipeDataL['title'] ?? ''))) ?>">
                <div class="flex-1 min-w-0">
                  <p class="font-mono font-bold text-sm text-primary"><?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?></p>
                  <p class="text-xs text-slate-500 truncate"><?= sanitize($eq['brand']) ?> <?= sanitize($eq['model_name']) ?></p>
                  <?php if ($eq['client_name']): ?>
                  <p class="text-xs text-slate-400 truncate"><?= sanitize($eq['client_name']) ?></p>
                  <?php endif; ?>
                  <?php if ($pipeDataL): ?>
                  <p class="text-[10px] text-[#0F4C81] font-medium mt-0.5">
                    🔵 <?= htmlspecialchars($pipeDataL['phase_name'] ?? 'Pipedrive') ?>
                  </p>
                  <?php endif; ?>
                </div>
                <span class="material-symbols-outlined text-slate-300 text-base">chevron_right</span>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div><!-- fim área de conteúdo scroll -->
</div><!-- fim wrapper conteúdo -->

<!-- Modal de confirmação de movimentação -->
<div id="moveModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-5 lg:p-6">
    <div class="flex items-center gap-3 mb-4">
      <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-primary">swap_horiz</span>
      </div>
      <div>
        <h3 class="text-base font-bold text-slate-800">Mover Equipamento</h3>
        <p class="text-sm text-slate-500" id="moveModalDesc"></p>
      </div>
    </div>

    <div class="mb-5">
      <label class="block text-sm font-medium text-slate-700 mb-1.5">Observação <span class="text-slate-400 font-normal">(opcional)</span></label>
      <textarea id="moveNotes" rows="3"
                class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary resize-none"
                placeholder="Motivo da movimentação..."></textarea>
    </div>

    <div class="flex gap-3 justify-end">
      <button onclick="cancelMove()"
              class="px-4 py-2 text-sm text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition">
        Cancelar
      </button>
      <button onclick="confirmMove()"
              class="px-4 py-2 text-sm text-white bg-primary hover:bg-blue-900 rounded-lg transition flex items-center gap-1.5">
        <span class="material-symbols-outlined text-base">check</span>
        Confirmar
      </button>
    </div>
  </div>
</div>

<script>
const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;

let draggedId    = null;
let draggedFrom  = null;
let targetStatus = null;
let currentView  = 'board';

function handleDragStart(e) {
    draggedId   = e.currentTarget.dataset.id;
    draggedFrom = e.currentTarget.dataset.status;
    e.dataTransfer.effectAllowed = 'move';
}

function handleDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}

function handleDragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}

function handleDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');
    const newStatus = e.currentTarget.dataset.status;
    if (!draggedId || newStatus === draggedFrom) return;

    targetStatus = newStatus;
    const card     = document.querySelector(`[data-id="${draggedId}"]`);
    const assetTag = card?.querySelector('a')?.textContent?.trim() ?? draggedId;
    const fromLabel = statusLabels[draggedFrom] ?? draggedFrom;
    const toLabel   = statusLabels[newStatus]   ?? newStatus;

    document.getElementById('moveModalDesc').textContent =
        `"${assetTag}" — ${fromLabel} → ${toLabel}`;
    document.getElementById('moveNotes').value = '';
    document.getElementById('moveModal').classList.remove('hidden');
}

function cancelMove() {
    draggedId = null; draggedFrom = null; targetStatus = null;
    document.getElementById('moveModal').classList.add('hidden');
}

async function confirmMove() {
    const notes = document.getElementById('moveNotes').value;
    document.getElementById('moveModal').classList.add('hidden');

    const resp = await fetch('/pages/api/kanban_move.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            equipment_id: draggedId,
            to_status:    targetStatus,
            notes:        notes,
            csrf_token:   '<?= csrfToken() ?>'
        })
    });

    const data = await resp.json();
    if (data.success) {
        window.location.reload();
    } else {
        alert('Erro: ' + (data.message || 'Falha na movimentação.'));
    }
}

function filterCards(query) {
    const q = query.toLowerCase().trim();

    // Board view
    document.querySelectorAll('.kanban-card').forEach(card => {
        const text = card.dataset.search ?? '';
        card.style.display = (!q || text.includes(q)) ? '' : 'none';
    });

    // List view
    document.querySelectorAll('.list-card').forEach(card => {
        const text = card.dataset.search ?? '';
        card.closest('a').style.display = (!q || text.includes(q)) ? '' : 'none';
    });

    // Atualizar contadores e estado vazio de cada coluna (board)
    document.querySelectorAll('[id^="col-"]').forEach(col => {
        const visible = [...col.querySelectorAll('.kanban-card')].filter(c => c.style.display !== 'none');
        const header  = col.closest('.flex-shrink-0');
        const counter = header?.querySelector('.rounded-full');
        if (counter) counter.textContent = visible.length;

        let emptyEl = col.querySelector('.border-dashed');
        if (visible.length === 0) {
            if (!emptyEl) {
                emptyEl = document.createElement('div');
                emptyEl.className = 'border-2 border-dashed border-slate-300 rounded-lg h-20 flex items-center justify-center empty-placeholder';
                emptyEl.innerHTML = '<p class="text-xs text-slate-400 italic">Nenhum item</p>';
                col.appendChild(emptyEl);
            }
        } else {
            col.querySelectorAll('.empty-placeholder').forEach(el => el.remove());
        }
    });
}

function setView(view) {
    currentView = view;
    const board = document.getElementById('kanbanBoardView');
    const list  = document.getElementById('kanbanListView');
    const btnB  = document.getElementById('btnBoard');
    const btnL  = document.getElementById('btnList');

    if (view === 'board') {
        board.style.display = 'flex';
        list.style.display  = 'none';
        btnB.classList.add('bg-primary', 'text-white');
        btnB.classList.remove('bg-white', 'text-slate-600');
        btnL.classList.add('bg-white', 'text-slate-600');
        btnL.classList.remove('bg-primary', 'text-white');
    } else {
        board.style.display = 'none';
        list.style.display  = 'block';
        btnL.classList.add('bg-primary', 'text-white');
        btnL.classList.remove('bg-white', 'text-slate-600');
        btnB.classList.add('bg-white', 'text-slate-600');
        btnB.classList.remove('bg-primary', 'text-white');
    }
}

function toggleListSection(id) {
    const el     = document.getElementById(id);
    const status = id.replace('ls-', '');
    const icon   = document.querySelector('.ls-chevron-' + status);
    if (el.classList.contains('hidden')) {
        el.classList.remove('hidden');
        if (icon) icon.textContent = 'expand_less';
    } else {
        el.classList.add('hidden');
        if (icon) icon.textContent = 'expand_more';
    }
}

// Inicializar view correta conforme tamanho da tela
(function() {
    if (window.innerWidth < 768) {
        setView('list');
    }
    // Em mobile, o board é flex mas controlado por CSS
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 768) {
            document.getElementById('kanbanBoardView').style.display = 'flex';
            document.getElementById('kanbanListView').style.display  = 'none';
        }
    });
})();
</script>

</body>
</html>
