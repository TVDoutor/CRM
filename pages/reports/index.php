<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();
requireRole(['admin','manager','user']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Relatórios — TV Doutor CRM</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:{DEFAULT:'#1B4F8C',dark:'#153d6f',light:'#D6E4F0'}}}}}</script>
</head>
<body class="bg-gray-50 flex h-screen overflow-hidden">
<?php require_once __DIR__ . '/../../includes/navbar.php'; ?>
<main class="flex-1 p-4 lg:p-8 overflow-auto pt-16 lg:pt-4">
  <div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-2">Relatórios</h1>
    <p class="text-gray-500 text-sm mb-8">Selecione o relatório desejado</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
      <a href="/pages/reports/stock.php" class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md hover:border-brand transition group">
        <div class="text-3xl mb-3">📦</div>
        <h2 class="text-base font-bold text-gray-800 group-hover:text-brand">Estoque Atual</h2>
        <p class="text-sm text-gray-500 mt-1">Equipamentos disponíveis para saída, separados por novo e usado.</p>
      </a>
      <a href="/pages/reports/entries_exits.php" class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md hover:border-brand transition group">
        <div class="text-3xl mb-3">📊</div>
        <h2 class="text-base font-bold text-gray-800 group-hover:text-brand">Entradas e Saídas</h2>
        <p class="text-sm text-gray-500 mt-1">Relatório de movimentações por período com totalizadores.</p>
      </a>
      <a href="/pages/reports/by_user.php" class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md hover:border-brand transition group">
        <div class="text-3xl mb-3">👤</div>
        <h2 class="text-base font-bold text-gray-800 group-hover:text-brand">Auditoria por Usuário</h2>
        <p class="text-sm text-gray-500 mt-1">Todas as ações registradas por usuário, com filtro de data.</p>
      </a>
      <a href="/pages/reports/by_equipment.php" class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md hover:border-brand transition group">
        <div class="text-3xl mb-3">🔍</div>
        <h2 class="text-base font-bold text-gray-800 group-hover:text-brand">Histórico de Equipamento</h2>
        <p class="text-sm text-gray-500 mt-1">Linha do tempo completa de um equipamento específico.</p>
      </a>
      <a href="/pages/reports/by_client.php" class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 hover:shadow-md hover:border-brand transition group">
        <div class="text-3xl mb-3">🏥</div>
        <h2 class="text-base font-bold text-gray-800 group-hover:text-brand">Relatório por Cliente</h2>
        <p class="text-sm text-gray-500 mt-1">Equipamentos e histórico de movimentações de um cliente.</p>
      </a>
    </div>
  </div>
</main>
</body>
</html>
