<?php
/**
 * Diagnóstico Pipedrive ↔ CRM
 * Mostra lacunas entre projetos no Pipedrive e equipamentos no Kanban do CRM.
 * Útil para identificar: projetos sem asset_tag, projetos sem equipamento no CRM.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/pipedrive.php';
requireLogin();
requireRole(['admin', 'manager']);

$db = getDB();

$tableExists = false;
$byPhase     = [];
$kanbanCount = [];
$phaseMap    = PIPEDRIVE_PHASE_MAP ?? [];
$phaseNames  = [
    50 => 'Entrada', 54 => 'Pontos Ativos Onsign', 57 => 'Offline +31 Dias',
    106 => 'Encaminhado ao CS', 55 => 'Processo de Cancelamento',
];

try {
    $db->query("SELECT 1 FROM pipedrive_projects LIMIT 1");
    $tableExists = true;
} catch (\Exception $e) {}

if ($tableExists) {
    // Projetos abertos por fase (board 8)
    $projects = $db->query("SELECT pipedrive_id, phase_id, phase_name, asset_tag, title, client_code
        FROM pipedrive_projects WHERE board_id = 8 AND status = 'open'")->fetchAll(\PDO::FETCH_ASSOC);

    // Contar por fase e verificar vínculo com equipamento
    $byPhase = [];
    foreach ($projects as $p) {
        $pid  = (int)$p['phase_id'];
        $name = $p['phase_name'] ?? $phaseNames[$pid] ?? "Fase $pid";
        if (!isset($byPhase[$pid])) {
            $byPhase[$pid] = ['name' => $name, 'total' => 0, 'com_tag' => 0, 'com_equipamento' => 0, 'sem_equipamento' => []];
        }
        $byPhase[$pid]['total']++;
        $hasTag = !empty(trim($p['asset_tag'] ?? ''));
        if ($hasTag) $byPhase[$pid]['com_tag']++;

        // Verificar se existe equipamento no CRM
        $eqExists = false;
        if ($hasTag) {
            $tag = trim($p['asset_tag']);
            $exact = $db->prepare("SELECT id FROM equipment WHERE asset_tag = ? LIMIT 1");
            $exact->execute([$tag]);
            if ($exact->fetchColumn()) $eqExists = true;
            if (!$eqExists) {
                $like = $db->prepare("SELECT id FROM equipment WHERE asset_tag LIKE ? LIMIT 1");
                $like->execute(['%' . $tag]);
                if ($like->fetchColumn()) $eqExists = true;
            }
        }
        if ($eqExists) {
            $byPhase[$pid]['com_equipamento']++;
        } elseif ($hasTag) {
            $byPhase[$pid]['sem_equipamento'][] = ['title' => $p['title'], 'asset_tag' => $p['asset_tag'], 'client_code' => $p['client_code']];
        }
    }
    ksort($byPhase);

    // Contagem por kanban_status no CRM
    $stmt = $db->query("SELECT kanban_status, COUNT(*) as cnt FROM equipment GROUP BY kanban_status");
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $kanbanCount[$r['kanban_status']] = (int)$r['cnt'];
    }
}

$lastSync = null;
try {
    $lastSync = $db->query("SELECT * FROM pipedrive_projects_sync_log ORDER BY created_at DESC LIMIT 1")->fetch();
} catch (\Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Diagnóstico Pipedrive — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-amber-500 rounded-xl flex items-center justify-center text-white">🔍</div>
        <div>
          <h1 class="text-xl lg:text-2xl font-bold text-gray-800">Diagnóstico Pipedrive ↔ CRM</h1>
          <p class="text-gray-500 text-sm">Lacunas entre projetos Pipedrive e equipamentos no Kanban</p>
        </div>
      </div>
      <div class="flex gap-2">
        <a href="/pages/pipedrive/index.php" class="text-sm text-gray-500 hover:text-brand px-3 py-2 rounded-lg hover:bg-gray-100">← Integração</a>
        <a href="/pages/pipedrive/projects.php" class="text-sm text-gray-500 hover:text-brand px-3 py-2 rounded-lg hover:bg-gray-100">Projetos</a>
      </div>
    </div>

    <?php if (!$tableExists): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-6">
        <p class="text-amber-800 font-medium">Tabela pipedrive_projects não encontrada.</p>
        <p class="text-amber-700 text-sm mt-1">Execute a sincronização de projetos primeiro em <a href="/pages/pipedrive/projects.php" class="underline">Projetos Pipedrive</a>.</p>
      </div>
    <?php else: ?>

    <?php if ($lastSync): ?>
    <div class="text-xs text-gray-500 mb-4">Último sync: <?= htmlspecialchars($lastSync['created_at'] ?? '-') ?></div>
    <?php endif; ?>

    <!-- Resumo: Pipedrive → CRM -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
      <div class="px-4 py-3 bg-gray-50 border-b font-semibold text-gray-800">Projetos Pipedrive (board 8, abertos) por fase</div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-100 text-left">
              <th class="px-4 py-2">Fase Pipedrive</th>
              <th class="px-4 py-2">→ CRM Kanban</th>
              <th class="px-4 py-2 text-right">Total</th>
              <th class="px-4 py-2 text-right">Com asset_tag</th>
              <th class="px-4 py-2 text-right">Com equipamento</th>
              <th class="px-4 py-2 text-right">Lacuna</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($byPhase as $phaseId => $data):
                $expectedCrm = $phaseMap[$phaseId] ?? null;
                $lacuna = max(0, $data['com_tag'] - $data['com_equipamento']);
            ?>
            <tr class="border-t hover:bg-gray-50">
              <td class="px-4 py-2 font-medium"><?= htmlspecialchars($data['name']) ?></td>
              <td class="px-4 py-2 text-gray-600"><?= $expectedCrm ? htmlspecialchars(str_replace('_', ' ', $expectedCrm)) : '—' ?></td>
              <td class="px-4 py-2 text-right"><?= $data['total'] ?></td>
              <td class="px-4 py-2 text-right"><?= $data['com_tag'] ?></td>
              <td class="px-4 py-2 text-right text-green-600 font-medium"><?= $data['com_equipamento'] ?></td>
              <td class="px-4 py-2 text-right <?= $lacuna > 0 ? 'text-amber-600 font-medium' : 'text-gray-500' ?>"><?= $lacuna ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Contagem Kanban CRM -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
      <div class="px-4 py-3 bg-gray-50 border-b font-semibold text-gray-800">Equipamentos no CRM por status Kanban</div>
      <div class="p-4 flex flex-wrap gap-4">
        <?php
        $allStatuses = ['entrada','aguardando_instalacao','alocado','licenca_removida','equipamento_usado','comercial','processo_devolucao','manutencao','baixado'];
        foreach ($allStatuses as $s):
          $cnt = $kanbanCount[$s] ?? 0;
          $label = ucfirst(str_replace('_', ' ', $s));
        ?>
        <div class="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-sm">
          <span class="font-medium"><?= $label ?>:</span> <?= $cnt ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Projetos sem equipamento (amostra) -->
    <?php
    $semEquipamento = [];
    foreach ($byPhase as $d) $semEquipamento = array_merge($semEquipamento, $d['sem_equipamento']);
    $semEquipamento = array_slice($semEquipamento, 0, 30);
    ?>
    <?php if (!empty($semEquipamento)): ?>
    <div class="bg-amber-50 rounded-xl border border-amber-200 overflow-hidden">
      <div class="px-4 py-3 bg-amber-100 border-b font-semibold text-amber-900">
        Amostra: projetos com asset_tag mas sem equipamento no CRM (<?= count($semEquipamento) ?> primeiros)
      </div>
      <p class="px-4 py-2 text-sm text-amber-800">Execute <strong>Importar Equipamentos</strong> na página de Projetos para criar esses equipamentos.</p>
      <div class="overflow-x-auto max-h-64 overflow-y-auto">
        <table class="w-full text-sm">
          <thead class="sticky top-0 bg-amber-100">
            <tr><th class="px-4 py-2 text-left">Título</th><th class="px-4 py-2">asset_tag</th><th class="px-4 py-2">client_code</th></tr>
          </thead>
          <tbody>
            <?php foreach ($semEquipamento as $row): ?>
            <tr class="border-t border-amber-200"><td class="px-4 py-1.5 truncate max-w-xs" title="<?= htmlspecialchars($row['title']) ?>"><?= htmlspecialchars($row['title']) ?></td><td class="px-4 py-1.5 font-mono"><?= htmlspecialchars($row['asset_tag']) ?></td><td class="px-4 py-1.5"><?= htmlspecialchars($row['client_code'] ?? '') ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Mapeamento de fases -->
    <div class="mt-6 bg-slate-50 rounded-xl border border-slate-200 p-4 text-sm text-slate-700">
      <div class="font-semibold text-slate-800 mb-2">Mapeamento Pipedrive → CRM (config/pipedrive.php)</div>
      <ul class="space-y-1">
        <?php foreach ($phaseMap as $pid => $kStatus): ?>
        <li>Phase <?= $pid ?> (<?= $phaseNames[$pid] ?? '?' ?>) → <code class="bg-white px-1 rounded"><?= $kStatus ?></code></li>
        <?php endforeach; ?>
      </ul>
      <p class="mt-2 text-slate-600">Nota: Pipedrive "Entrada" (phase 50) mapeia para <strong>aguardando_instalacao</strong>, não para "entrada". O CRM "Entrada" é para equipamentos recém-cadastrados via lote.</p>
    </div>

    <?php endif; ?>
  </div>
</main>
</body>
</html>
