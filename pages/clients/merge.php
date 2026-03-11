<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin', 'manager']);

$db   = getDB();
$from = trim($_GET['from'] ?? '');  // client_code do cliente a ser mesclado (origem)

$source = null;
if ($from) {
    $stmt = $db->prepare('SELECT c.*, (SELECT COUNT(*) FROM equipment WHERE current_client_id = c.id AND kanban_status = "alocado") as active_equipment FROM clients c WHERE client_code = ?');
    $stmt->execute([$from]);
    $source = $stmt->fetch();
}

$clients = $db->query('SELECT c.id, c.client_code, c.name, c.cnpj, c.phone, c.city, c.state,
    (SELECT COUNT(*) FROM equipment WHERE current_client_id = c.id AND kanban_status = "alocado") as active_equipment
FROM clients c WHERE c.is_active = 1 ORDER BY c.name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mesclar Clientes — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-2xl mx-auto">
    <a href="/pages/clients/index.php" class="inline-flex items-center gap-1 text-gray-400 hover:text-gray-600 text-sm">
      <span class="material-symbols-outlined text-base">arrow_back</span> Clientes
    </a>
    <h1 class="text-2xl font-bold text-gray-800 mt-4 mb-2 flex items-center gap-2">
      <span class="material-symbols-outlined text-brand">merge</span>
      Mesclar Clientes
    </h1>
    <p class="text-gray-500 text-sm mb-6">
      Transfere equipamentos, histórico e operações do cliente <strong>origem</strong> para o cliente <strong>destino</strong>.
      O cliente origem será desativado.
    </p>

    <div id="msg" class="hidden mb-4 p-4 rounded-lg"></div>

    <form id="mergeForm" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 space-y-5">
      <?= csrfField() ?>

      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">Cliente origem (será desativado)</label>
        <?php if ($source): ?>
        <div class="p-3 rounded-lg bg-amber-50 border border-amber-200 flex items-center justify-between">
          <div>
            <span class="font-mono text-brand font-semibold"><?= sanitize($source['client_code']) ?></span>
            <span class="ml-2 text-gray-800"><?= sanitize($source['name']) ?></span>
            <span class="ml-2 text-xs text-gray-500">(<?= (int)$source['active_equipment'] ?> equip.)</span>
          </div>
        </div>
        <input type="hidden" name="source_id" value="<?= (int)$source['id'] ?>">
        <a href="/pages/clients/merge.php" class="text-xs text-gray-500 hover:underline mt-1 inline-block">Trocar cliente origem</a>
        <?php else: ?>
        <select name="source_id" id="source_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">— Selecione —</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>" data-name="<?= sanitize($c['client_code']) ?>" <?= $from && $c['client_code'] === $from ? 'selected' : '' ?>>
            <?= sanitize($c['client_code']) ?> — <?= sanitize($c['name']) ?> (<?= (int)$c['active_equipment'] ?> equip.)
          </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>

      <div class="flex justify-center">
        <span class="material-symbols-outlined text-3xl text-gray-300">south</span>
      </div>

      <div>
        <label class="block text-sm font-bold text-gray-700 mb-2">Cliente destino (receberá os dados)</label>
        <select name="target_id" id="target_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
          <option value="">— Selecione —</option>
          <?php foreach ($clients as $c): ?>
          <?php if ($source && (int)$c['id'] === (int)$source['id']) continue; ?>
          <option value="<?= $c['id'] ?>">
            <?= sanitize($c['client_code']) ?> — <?= sanitize($c['name']) ?> (<?= (int)$c['active_equipment'] ?> equip.)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="pt-4 flex gap-3">
        <button type="submit" id="btnSubmit"
                class="bg-amber-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-amber-700 transition flex items-center gap-1.5">
          <span class="material-symbols-outlined text-base">merge</span> Mesclar
        </button>
        <a href="/pages/clients/index.php" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">Cancelar</a>
      </div>
    </form>
  </div>
</main>
<script>
document.getElementById('mergeForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const src = document.querySelector('select[name="source_id"], input[name="source_id"]');
  const tgt = document.querySelector('select[name="target_id"]');
  const sourceId = src.tagName === 'SELECT' ? src.value : src.value;
  const targetId = tgt.value;
  if (!sourceId || !targetId || sourceId === targetId) {
    showMsg('error', 'Selecione cliente origem e destino diferentes.');
    return;
  }
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">progress_activity</span> Mesclando...';

  const formData = new FormData();
  formData.append('source_id', sourceId);
  formData.append('target_id', targetId);
  formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

  try {
    const r = await fetch('/pages/api/merge_clients.php', { method: 'POST', body: formData });
    const data = await r.json();
    if (data.ok) {
      showMsg('success', data.msg);
      setTimeout(() => { window.location.href = data.redirect || '/pages/clients/index.php'; }, 1500);
    } else {
      showMsg('error', data.error || 'Erro ao mesclar.');
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-outlined text-base">merge</span> Mesclar';
    }
  } catch (err) {
    showMsg('error', 'Erro de conexão: ' + err.message);
    btn.disabled = false;
    btn.innerHTML = '<span class="material-symbols-outlined text-base">merge</span> Mesclar';
  }
});

function showMsg(type, text) {
  const el = document.getElementById('msg');
  el.className = 'mb-4 p-4 rounded-lg ' + (type === 'success' ? 'bg-green-50 border border-green-400 text-green-800' : 'bg-red-50 border border-red-400 text-red-800');
  el.textContent = text;
  el.classList.remove('hidden');
}

// Ao trocar origem, desabilitar mesma opção no destino
const srcSel = document.getElementById('source_id');
const tgtSel = document.getElementById('target_id');
if (srcSel && tgtSel) {
  srcSel.addEventListener('change', function() {
    Array.from(tgtSel.options).forEach(function(opt) {
      opt.disabled = opt.value && opt.value === srcSel.value;
      if (opt.disabled && opt.selected) tgtSel.value = '';
    });
  });
}
</script>
</body>
</html>
