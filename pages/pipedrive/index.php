<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/pipedrive.php';
requireLogin();
requireRole(['admin', 'manager']);

$db = getDB();

// Último sync
$lastSync = $db->query("SELECT * FROM pipedrive_sync_log ORDER BY created_at DESC LIMIT 1")->fetch();

// Histórico
$logs = $db->query("SELECT l.*, u.name as user_name
    FROM pipedrive_sync_log l
    LEFT JOIN users u ON u.id = l.performed_by
    ORDER BY l.created_at DESC LIMIT 20")->fetchAll();

// Totais de clientes sincronizados
$syncedCount = $db->query("SELECT COUNT(*) FROM clients WHERE pipedrive_org_id IS NOT NULL OR pipedrive_person_id IS NOT NULL")->fetchColumn();
$totalClients = $db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Integração Pipedrive — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-4">
        <div class="w-12 h-12 bg-[#0F4C81] rounded-xl flex items-center justify-center text-white font-bold text-lg">P</div>
        <div>
          <h1 class="text-2xl font-bold text-gray-800">Integração Pipedrive</h1>
          <p class="text-gray-500 text-sm">
            Organizações do filtro
            <a href="https://tvdoutor.pipedrive.com/organizations/list/filter/<?= PIPEDRIVE_FILTER_ID ?>"
               target="_blank"
               class="text-blue-600 hover:underline font-medium">#<?= PIPEDRIVE_FILTER_ID ?></a>
            → Clientes do CRM
          </p>
        </div>
      </div>
      <button id="btnSync"
              onclick="runSync()"
              class="flex items-center gap-2 bg-[#0F4C81] hover:bg-blue-900 text-white px-5 py-2.5 rounded-xl font-semibold text-sm transition">
        <span id="syncIcon">🔄</span>
        <span id="syncLabel">Sincronizar Agora</span>
      </button>
    </div>

    <?php flashRender(); ?>

    <!-- Acesso rápido a Projetos -->
    <div class="bg-white rounded-xl border border-blue-100 shadow-sm p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 bg-blue-50 rounded-lg flex items-center justify-center">
          <svg class="h-5 w-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
          </svg>
        </div>
        <div>
          <p class="text-sm font-semibold text-gray-800">Projetos Pipedrive (Board Jornada)</p>
          <p class="text-xs text-gray-400">Sincronize os projetos e visualize por cliente no CRM</p>
        </div>
      </div>
      <div class="flex gap-2">
        <a href="/pages/pipedrive/projects.php"
           class="bg-brand text-white text-sm px-4 py-2 rounded-lg hover:bg-blue-800 transition font-semibold">
          Ver Projetos →
        </a>
        <a href="/pages/pipedrive/diagnostic.php"
           class="bg-amber-500 text-white text-sm px-4 py-2 rounded-lg hover:bg-amber-600 transition font-semibold">
          Diagnóstico
        </a>
      </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Clientes Sincronizados</p>
        <p class="text-3xl font-bold text-brand"><?= $syncedCount ?></p>
        <p class="text-xs text-gray-400 mt-1">de <?= $totalClients ?> no total</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Última Sincronização</p>
        <?php if ($lastSync): ?>
          <p class="text-sm font-bold text-gray-700"><?= formatDate($lastSync['created_at'], true) ?></p>
          <p class="text-xs mt-1">
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold
              <?= $lastSync['status'] === 'success' ? 'bg-green-100 text-green-800' : ($lastSync['status'] === 'partial' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
              <?= $lastSync['status'] === 'success' ? '✅ Sucesso' : ($lastSync['status'] === 'partial' ? '⚠️ Parcial' : '❌ Erro') ?>
            </span>
            <span class="text-gray-400 ml-1"><?= $lastSync['duration_ms'] ?>ms</span>
          </p>
        <?php else: ?>
          <p class="text-gray-400 text-sm">Nunca sincronizado</p>
        <?php endif; ?>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Cron Job (HostGator)</p>
        <p class="text-xs font-mono bg-gray-50 rounded p-2 mt-1 text-gray-600 break-all">
          0 */8 * * * php <?= BASE_URL ?>/pages/api/pipedrive_sync.php?cron_key=<?= PIPEDRIVE_CRON_KEY ?>
        </p>
        <p class="text-xs text-gray-400 mt-1">Executa a cada 8 horas (3x ao dia)</p>
      </div>
    </div>

    <!-- Resultado da última sync -->
    <?php if ($lastSync): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-6">
      <h2 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-4">Último Resultado</h2>
      <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
        <div class="bg-blue-50 rounded-lg p-3 text-center">
          <p class="text-xs text-blue-500 mb-1">Organizações Encontradas</p>
          <p class="text-2xl font-bold text-blue-700"><?= $lastSync['orgs_found'] ?></p>
        </div>
        <div class="bg-green-50 rounded-lg p-3 text-center">
          <p class="text-xs text-green-500 mb-1">Orgs Criadas</p>
          <p class="text-2xl font-bold text-green-700"><?= $lastSync['orgs_created'] ?></p>
        </div>
        <div class="bg-yellow-50 rounded-lg p-3 text-center">
          <p class="text-xs text-yellow-600 mb-1">Orgs Atualizadas</p>
          <p class="text-2xl font-bold text-yellow-700"><?= $lastSync['orgs_updated'] ?></p>
        </div>
        <div class="bg-blue-50 rounded-lg p-3 text-center">
          <p class="text-xs text-blue-500 mb-1">Pessoas Encontradas</p>
          <p class="text-2xl font-bold text-blue-700"><?= $lastSync['persons_found'] ?></p>
        </div>
        <div class="bg-green-50 rounded-lg p-3 text-center">
          <p class="text-xs text-green-500 mb-1">Pessoas Criadas</p>
          <p class="text-2xl font-bold text-green-700"><?= $lastSync['persons_created'] ?></p>
        </div>
        <div class="bg-yellow-50 rounded-lg p-3 text-center">
          <p class="text-xs text-yellow-600 mb-1">Pessoas Atualizadas</p>
          <p class="text-2xl font-bold text-yellow-700"><?= $lastSync['persons_updated'] ?></p>
        </div>
      </div>
      <?php if ($lastSync['errors']): ?>
      <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
        <p class="text-xs font-bold text-red-700 mb-1">Erros registrados:</p>
        <pre class="text-xs text-red-600 whitespace-pre-wrap"><?= htmlspecialchars($lastSync['errors']) ?></pre>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Resultado em tempo real (aparece após sync manual) -->
    <div id="syncResult" class="hidden mb-6"></div>

    <!-- Histórico de sincronizações -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-gray-100">
        <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider">Histórico de Sincronizações</h2>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 border-b border-gray-100">
            <tr class="text-xs text-gray-500 uppercase tracking-wider">
              <th class="text-left px-4 py-3">Data/Hora</th>
              <th class="text-left px-4 py-3">Tipo</th>
              <th class="text-left px-4 py-3">Status</th>
              <th class="text-left px-4 py-3">Orgs</th>
              <th class="text-left px-4 py-3">Pessoas</th>
              <th class="text-left px-4 py-3">Tempo</th>
              <th class="text-left px-4 py-3">Usuário</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php if (empty($logs)): ?>
            <tr><td colspan="7" class="text-center py-10 text-gray-400">Nenhuma sincronização registrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap"><?= formatDate($log['created_at'], true) ?></td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $log['sync_type'] === 'cron' ? 'bg-gray-100 text-gray-700' : 'bg-blue-100 text-blue-800' ?>">
                  <?= $log['sync_type'] === 'cron' ? '⏰ Cron' : '👤 Manual' ?>
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold
                  <?= $log['status'] === 'success' ? 'bg-green-100 text-green-800' : ($log['status'] === 'partial' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                  <?= $log['status'] === 'success' ? '✅ Sucesso' : ($log['status'] === 'partial' ? '⚠️ Parcial' : '❌ Erro') ?>
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-gray-600">
                <?= $log['orgs_found'] ?> enc. ·
                <span class="text-green-600">+<?= $log['orgs_created'] ?></span> ·
                <span class="text-yellow-600">↺<?= $log['orgs_updated'] ?></span>
              </td>
              <td class="px-4 py-3 text-xs text-gray-600">
                <?= $log['persons_found'] ?> enc. ·
                <span class="text-green-600">+<?= $log['persons_created'] ?></span> ·
                <span class="text-yellow-600">↺<?= $log['persons_updated'] ?></span>
              </td>
              <td class="px-4 py-3 text-xs text-gray-400"><?= $log['duration_ms'] ?>ms</td>
              <td class="px-4 py-3 text-xs text-gray-500"><?= $log['user_name'] ? sanitize($log['user_name']) : '— cron —' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Instruções do cron -->
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-5">
      <h3 class="text-sm font-bold text-blue-800 mb-2">📋 Como configurar o Cron Job na HostGator</h3>
      <ol class="text-sm text-blue-700 space-y-1.5 list-decimal list-inside">
        <li>Acesse o cPanel da HostGator</li>
        <li>Vá em <strong>Cron Jobs</strong></li>
        <li>Em "Adicionar novo cron job", selecione a frequência desejada (a cada 8 horas — 3x ao dia)</li>
        <li>No campo comando, coloque:</li>
      </ol>
      <div class="mt-2 bg-white rounded-lg p-3 font-mono text-xs text-gray-700 border border-blue-200 break-all">
        /usr/local/bin/php <?= htmlspecialchars('/home2/tvdout68/crm.tvdoutor.com.br/pages/api/pipedrive_sync.php') ?> cron
      </div>
      <p class="text-xs text-blue-600 mt-2">Ou via URL (se preferir HTTP):</p>
      <div class="mt-1 bg-white rounded-lg p-3 font-mono text-xs text-gray-700 border border-blue-200 break-all">
        curl -s "https://crm.tvdoutor.com.br/pages/api/pipedrive_sync.php?cron_key=<?= PIPEDRIVE_CRON_KEY ?>"
      </div>
    </div>

  </div>
</main>

<script>
async function runSync() {
    const btn   = document.getElementById('btnSync');
    const icon  = document.getElementById('syncIcon');
    const label = document.getElementById('syncLabel');
    const result = document.getElementById('syncResult');

    btn.disabled = true;
    icon.textContent  = '⏳';
    label.textContent = 'Sincronizando...';
    result.classList.add('hidden');

    try {
        const resp = await fetch('<?= BASE_URL ?>/pages/api/pipedrive_sync.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: '<?= csrfToken() ?>' })
        });

        const rawText = await resp.text();
        if (!rawText || rawText.trim() === '') {
            throw new Error('O servidor retornou resposta vazia. Verifique os logs do servidor.');
        }
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseErr) {
            throw new Error('Resposta inválida do servidor: ' + rawText.substring(0, 300));
        }

        const isOk     = data.success;
        const bgClass  = isOk ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200';
        const txtClass = isOk ? 'text-green-800' : 'text-red-800';
        const s = data.stats || {};

        result.innerHTML = `
            <div class="rounded-xl border ${bgClass} p-5">
              <p class="font-bold ${txtClass} mb-3">${isOk ? '✅ Sincronização concluída!' : '❌ Sincronização com erros'} — ${data.duration}</p>
              <div class="grid grid-cols-3 md:grid-cols-6 gap-3 text-center text-sm">
                <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-400">Orgs enc.</p><p class="font-bold text-blue-700">${s.orgs_found||0}</p></div>
                <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-400">Orgs criadas</p><p class="font-bold text-green-700">+${s.orgs_created||0}</p></div>
                <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-400">Orgs atualizadas</p><p class="font-bold text-yellow-700">↺${s.orgs_updated||0}</p></div>
                <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-400">Pessoas enc.</p><p class="font-bold text-blue-700">${s.persons_found||0}</p></div>
                <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-400">Criadas</p><p class="font-bold text-green-700">+${s.persons_created||0}</p></div>
                <div class="bg-white rounded-lg p-2"><p class="text-xs text-gray-400">Atualizadas</p><p class="font-bold text-yellow-700">↺${s.persons_updated||0}</p></div>
              </div>
              ${data.errors?.length ? `<div class="mt-3 p-2 bg-red-100 rounded text-xs text-red-700">${data.errors.join('<br>')}</div>` : ''}
            </div>`;
        result.classList.remove('hidden');

        setTimeout(() => window.location.reload(), 3000);

    } catch(e) {
        result.innerHTML = `<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700 text-sm">Erro de conexão: ${e.message}</div>`;
        result.classList.remove('hidden');
    } finally {
        btn.disabled = false;
        icon.textContent  = '🔄';
        label.textContent = 'Sincronizar Agora';
    }
}
</script>
</body>
</html>
