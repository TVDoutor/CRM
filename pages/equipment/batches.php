<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

$db     = getDB();
$errors = [];
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── Criar lote ──────────────────────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '') ?: null;
    $received_at = trim($_POST['received_at'] ?? '') ?: null;

    if (!$name) $errors[] = 'Nome do lote é obrigatório.';

    if (empty($errors)) {
        $dup = $db->prepare("SELECT id FROM batches WHERE name = ?");
        $dup->execute([$name]);
        if ($dup->fetch()) $errors[] = 'Já existe um lote com esse nome.';
    }

    if (empty($errors)) {
        $db->prepare("INSERT INTO batches (name, description, received_at, created_by) VALUES (?,?,?,?)")
           ->execute([$name, $description, $received_at, $_SESSION['user_id']]);
        $newId = (int)$db->lastInsertId();
        auditLog('CREATE', 'batches', $newId, null, ['name' => $name], "Lote criado: $name");
        flashSet('success', "Lote \"$name\" criado com sucesso.");
        header('Location: ' . BASE_URL . '/pages/equipment/batches.php');
        exit;
    }
}

// ── Manutenção: excluir lotes vazios e sincronizar batch nos equipamentos ─────
if ($action === 'cleanup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $db->beginTransaction();
    try {
        // 1. Excluir lotes com 0 equipamentos
        $toDelete = $db->query("
            SELECT b.id, b.name
            FROM batches b
            LEFT JOIN equipment e ON e.batch_id = b.id
            GROUP BY b.id
            HAVING COUNT(e.id) = 0
        ")->fetchAll();
        $deleted = 0;
        foreach ($toDelete as $row) {
            $db->prepare("DELETE FROM batches WHERE id = ?")->execute([$row['id']]);
            $deleted++;
            auditLog('DELETE', 'batches', (int)$row['id'], ['name' => $row['name']], null, "Lote excluído (0 equipamentos): {$row['name']}");
        }
        // 2. Sincronizar campo legado batch nos equipamentos que têm batch_id
        $stmtUpd = $db->prepare("
            UPDATE equipment e
            JOIN batches b ON b.id = e.batch_id
            SET e.batch = b.name
            WHERE e.batch_id IS NOT NULL AND (e.batch IS NULL OR e.batch != b.name)
        ");
        $stmtUpd->execute();
        $updated = $stmtUpd->rowCount();
        $db->commit();
        $msg = "Lotes vazios excluídos: $deleted.";
        if ($updated > 0) $msg .= " Campo lote sincronizado em $updated equipamento(s).";
        flashSet('success', $msg);
    } catch (Throwable $e) {
        $db->rollBack();
        flashSet('error', 'Erro na manutenção: ' . $e->getMessage());
    }
    header('Location: ' . BASE_URL . '/pages/equipment/batches.php');
    exit;
}

// ── Editar lote ─────────────────────────────────────────────────────────────
if ($action === 'edit' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfValidate();
    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '') ?: null;
    $received_at = trim($_POST['received_at'] ?? '') ?: null;

    if (!$name) $errors[] = 'Nome do lote é obrigatório.';

    if (empty($errors)) {
        $dup = $db->prepare("SELECT id FROM batches WHERE name = ? AND id != ?");
        $dup->execute([$name, $id]);
        if ($dup->fetch()) $errors[] = 'Já existe outro lote com esse nome.';
    }

    if (empty($errors)) {
        $stmt = $db->prepare("SELECT * FROM batches WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();

        $db->prepare("UPDATE batches SET name=?, description=?, received_at=? WHERE id=?")
           ->execute([$name, $description, $received_at, $id]);
        auditLog('UPDATE', 'batches', $id, ['name' => $old['name']], ['name' => $name], "Lote editado: $name");
        flashSet('success', "Lote \"$name\" atualizado com sucesso.");
        header('Location: ' . BASE_URL . '/pages/equipment/batches.php');
        exit;
    }
}

// ── Dados para edição ────────────────────────────────────────────────────────
$batch = null;
if (in_array($action, ['edit', 'view']) && $id) {
    $stmt = $db->prepare("SELECT * FROM batches WHERE id = ?");
    $stmt->execute([$id]);
    $batch = $stmt->fetch();
    if (!$batch) {
        flashSet('error', 'Lote não encontrado.');
        header('Location: ' . BASE_URL . '/pages/equipment/batches.php');
        exit;
    }
}

// ── Listagem ─────────────────────────────────────────────────────────────────
$batches = [];
if ($action === 'list') {
    $batches = $db->query("
        SELECT b.*, u.name as creator_name,
               COUNT(e.id) as equipment_count
        FROM batches b
        LEFT JOIN users u ON u.id = b.created_by
        LEFT JOIN equipment e ON e.batch_id = b.id
        GROUP BY b.id
        ORDER BY b.created_at DESC
    ")->fetchAll();
}

// ── Equipamentos do lote (view) ───────────────────────────────────────────────
$batchEquipments = [];
if ($action === 'view' && $batch) {
    $batchEquipments = $db->prepare("
        SELECT e.id, e.asset_tag, e.mac_address, e.serial_number, e.kanban_status, e.condition_status,
               e.contract_type, e.entry_date, e.purchase_date,
               em.brand, em.model_name,
               c.name as client_name
        FROM equipment e
        JOIN equipment_models em ON em.id = e.model_id
        LEFT JOIN clients c ON c.id = e.current_client_id
        WHERE e.batch_id = ?
        ORDER BY e.asset_tag
    ");
    $batchEquipments->execute([$id]);
    $batchEquipments = $batchEquipments->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lotes — S8 Conect CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-5xl mx-auto">

    <?= flashRender() ?>

    <?php if ($action === 'list'): ?>
    <!-- ── LISTAGEM ── -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-800">Lotes</h1>
        <p class="text-gray-500 text-sm mt-0.5">Gerencie os lotes de compra de equipamentos</p>
      </div>
      <div class="flex items-center gap-3">
        <?php
        $emptyCount = count(array_filter($batches ?? [], fn($b) => (int)($b['equipment_count'] ?? 0) === 0));
        if ($emptyCount > 0):
        ?>
        <form method="post" action="?action=cleanup" class="inline"
              onsubmit="return confirm('Excluir <?= $emptyCount ?> lote(s) com 0 equipamentos e sincronizar o campo lote nos demais equipamentos?');">
          <?= csrfField() ?>
          <button type="submit"
                  class="flex items-center gap-2 bg-amber-100 text-amber-800 px-4 py-2.5 rounded-lg text-sm font-semibold hover:bg-amber-200 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
            Limpar lotes vazios (<?= $emptyCount ?>)
          </button>
        </form>
        <?php endif; ?>
        <a href="?action=create"
           class="flex items-center gap-2 bg-brand text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
          Novo Lote
        </a>
      </div>
    </div>

    <?php if (empty($batches)): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-12 text-center">
      <div class="w-14 h-14 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-7 h-7 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
        </svg>
      </div>
      <p class="text-gray-500 text-sm">Nenhum lote cadastrado.</p>
      <a href="?action=create" class="inline-block mt-4 text-brand text-sm font-semibold hover:underline">Criar primeiro lote</a>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Lote</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden sm:table-cell">Descrição</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Equipamentos</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Recebimento</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Criado por</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($batches as $b): ?>
          <tr class="hover:bg-gray-50 transition-colors">
            <td class="px-4 py-3">
              <a href="?action=view&id=<?= $b['id'] ?>"
                 class="font-semibold text-brand hover:underline font-mono"><?= sanitize($b['name']) ?></a>
            </td>
            <td class="px-4 py-3 text-gray-500 hidden sm:table-cell max-w-xs truncate">
              <?= sanitize($b['description'] ?? '—') ?>
            </td>
            <td class="px-4 py-3 text-center">
              <span class="inline-flex items-center justify-center w-8 h-8 rounded-full
                           <?= $b['equipment_count'] > 0 ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500' ?>
                           text-xs font-bold">
                <?= $b['equipment_count'] ?>
              </span>
            </td>
            <td class="px-4 py-3 text-gray-600 hidden md:table-cell">
              <?= $b['received_at'] ? formatDate($b['received_at']) : '—' ?>
            </td>
            <td class="px-4 py-3 text-gray-500 text-xs hidden lg:table-cell">
              <?= sanitize($b['creator_name'] ?? '—') ?>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2 justify-end">
                <a href="?action=view&id=<?= $b['id'] ?>"
                   class="text-gray-400 hover:text-brand transition-colors" title="Ver equipamentos">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                  </svg>
                </a>
                <a href="?action=edit&id=<?= $b['id'] ?>"
                   class="text-gray-400 hover:text-amber-500 transition-colors" title="Editar">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                  </svg>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php elseif ($action === 'create' || $action === 'edit'): ?>
    <!-- ── FORMULÁRIO CREATE / EDIT ── -->
    <div class="flex items-center gap-3 mb-6">
      <a href="?" class="text-gray-400 hover:text-gray-600 text-sm">← Lotes</a>
    </div>
    <h1 class="text-2xl font-bold text-gray-800 mb-1">
      <?= $action === 'create' ? 'Novo Lote' : 'Editar Lote' ?>
    </h1>
    <p class="text-gray-500 text-sm mb-6">
      <?= $action === 'create' ? 'Crie um lote para agrupar equipamentos de uma mesma compra.' : 'Atualize as informações do lote.' ?>
    </p>

    <?php if ($errors): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 rounded-lg">
      <?php foreach ($errors as $e): ?><p class="text-sm text-red-700"><span class="material-symbols-outlined text-sm">error</span> <?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php $f = $_POST ?: ($batch ?? []); ?>
    <form method="POST" action="?action=<?= $action ?><?= $id ? "&id=$id" : '' ?>"
          class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 space-y-5 max-w-xl">
      <?= csrfField() ?>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nome do Lote *</label>
        <input type="text" name="name" value="<?= sanitize($f['name'] ?? '') ?>" required
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand"
               placeholder="Ex: 032026, Lote Março 2026">
        <p class="text-xs text-gray-400 mt-1">Identificador único do lote.</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Descrição</label>
        <textarea name="description" rows="2"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand resize-none"
                  placeholder="Ex: Compra de 20 unidades AQUARIO STV-3000"><?= sanitize($f['description'] ?? '') ?></textarea>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Data de Recebimento</label>
        <input type="date" name="received_at" value="<?= sanitize($f['received_at'] ?? '') ?>"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand">
      </div>

      <div class="flex gap-3 pt-2">
        <button type="submit"
                class="bg-brand text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-800 transition">
          <span class="material-symbols-outlined text-base">save</span> <?= $action === 'create' ? 'Criar Lote' : 'Salvar Alterações' ?>
        </button>
        <a href="?" class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
          Cancelar
        </a>
      </div>
    </form>

    <?php elseif ($action === 'view' && $batch): ?>
    <!-- ── VISUALIZAÇÃO DO LOTE ── -->
    <div class="flex items-center gap-3 mb-6">
      <a href="?" class="text-gray-400 hover:text-gray-600 text-sm">← Lotes</a>
    </div>

    <div class="flex items-start justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-800 font-mono"><?= sanitize($batch['name']) ?></h1>
        <?php if ($batch['description']): ?>
        <p class="text-gray-500 text-sm mt-1"><?= sanitize($batch['description']) ?></p>
        <?php endif; ?>
      </div>
      <a href="?action=edit&id=<?= $id ?>"
         class="flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 transition shadow-sm">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
        </svg>
        Editar Lote
      </a>
    </div>

    <!-- Cards de resumo -->
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Equipamentos</p>
        <p class="text-2xl font-bold text-brand"><?= count($batchEquipments) ?></p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Recebimento</p>
        <p class="text-sm font-semibold text-gray-700 mt-1">
          <?= $batch['received_at'] ? formatDate($batch['received_at']) : '—' ?>
        </p>
      </div>
      <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 col-span-2 sm:col-span-1">
        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Criado em</p>
        <p class="text-sm font-semibold text-gray-700 mt-1"><?= formatDate($batch['created_at'], true) ?></p>
      </div>
    </div>

    <!-- Lista de equipamentos -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
      <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
        <h2 class="text-sm font-bold text-gray-700">Equipamentos neste lote</h2>
        <a href="/pages/equipment/batch_entry.php"
           class="text-xs text-brand hover:underline font-semibold">+ Entrada de Lote</a>
      </div>

      <?php if (empty($batchEquipments)): ?>
      <div class="p-8 text-center text-gray-400 text-sm">Nenhum equipamento neste lote.</div>
      <?php else: ?>
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-100">
          <tr>
            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Etiqueta</th>
            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase hidden md:table-cell">Modelo</th>
            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase hidden sm:table-cell">Cliente</th>
            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 uppercase">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($batchEquipments as $eq): ?>
          <tr class="hover:bg-gray-50 transition-colors">
            <td class="px-4 py-2.5">
              <a href="/pages/equipment/view.php?id=<?= $eq['id'] ?>"
                 class="font-mono text-brand font-semibold hover:underline text-xs">
                <?= sanitize(displayTag($eq['asset_tag'], $eq['mac_address'] ?? null)) ?>
              </a>
            </td>
            <td class="px-4 py-2.5 text-gray-600 hidden md:table-cell">
              <?= sanitize(displayModelName($eq['brand'], $eq['model_name'])) ?>
            </td>
            <td class="px-4 py-2.5 text-gray-500 text-xs hidden sm:table-cell">
              <?= sanitize($eq['client_name'] ?? '—') ?>
            </td>
            <td class="px-4 py-2.5"><?= kanbanBadge($eq['kanban_status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</main>
</body>
</html>
