<?php
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
if (!function_exists('navActive')) {
    function navActive(string $path): bool {
        global $currentPath;
        return str_contains($currentPath, $path);
    }
}

$userName  = $_SESSION['user_name'] ?? '';
$userRole  = $_SESSION['user_role'] ?? 'user';
$initials  = '';
foreach (explode(' ', trim($userName)) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (strlen($initials) >= 2) break;
}
if (!$initials) $initials = 'U';
?>

<!-- Botão hamburguer mobile (topo da página) -->
<div id="mobileTopBar" class="lg:hidden fixed top-0 left-0 right-0 z-40 bg-[#1B4F8C] text-white flex items-center px-4 py-3 shadow-lg">
  <button id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Abrir menu"
          class="mr-3 p-1.5 rounded-lg hover:bg-white/10 transition">
    <svg id="hamburgerIcon" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
  </button>
  <div class="flex items-center space-x-2">
    <div class="w-7 h-7 bg-blue-500 rounded-md flex items-center justify-center flex-shrink-0">
      <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
      </svg>
    </div>
    <span class="font-bold text-sm">TV Doutor CRM</span>
  </div>
</div>

<!-- Overlay escuro mobile -->
<div id="sidebarOverlay"
     onclick="toggleSidebar()"
     class="hidden fixed inset-0 bg-black/50 z-40 lg:hidden"></div>

<!-- Sidebar -->
<aside id="navAside"
       class="fixed lg:relative top-0 left-0 z-50 w-64 bg-[#1B4F8C] text-white flex flex-col h-full shadow-xl
              -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out"
       style="flex-shrink:0; height:100vh;">

  <!-- Header -->
  <div class="p-5 flex items-center space-x-3 border-b border-blue-900/50 shrink-0">
    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center flex-shrink-0 shadow-lg">
      <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
      </svg>
    </div>
    <div>
      <h1 class="font-bold text-lg leading-tight">TV Doutor</h1>
      <p class="text-xs text-blue-300 font-medium">CRM de Equipamentos</p>
    </div>
    <!-- Botão fechar (visível só no mobile) -->
    <button onclick="toggleSidebar()" class="ml-auto lg:hidden p-1 rounded hover:bg-white/10 transition">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
      </svg>
    </button>
  </div>

  <!-- Navigation -->
  <nav class="flex-1 overflow-y-auto py-4 sidebar-nav">

    <!-- Principal -->
    <div class="px-3 mb-5 space-y-1">
      <a href="/pages/dashboard.php" onclick="closeSidebarMobile()"
         class="flex items-center space-x-3 px-3 py-2.5 rounded-lg transition-colors group
                <?= navActive('dashboard') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
        <svg class="h-5 w-5 <?= navActive('dashboard') ? '' : 'opacity-70 group-hover:opacity-100' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
        </svg>
        <span class="<?= navActive('dashboard') ? 'font-semibold' : 'font-medium' ?>">Dashboard</span>
      </a>

      <a href="/pages/kanban.php" onclick="closeSidebarMobile()"
         class="flex items-center space-x-3 px-3 py-2.5 rounded-lg transition-colors group
                <?= navActive('kanban') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
        <svg class="h-5 w-5 <?= navActive('kanban') ? '' : 'opacity-70 group-hover:opacity-100' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7"/>
        </svg>
        <span class="<?= navActive('kanban') ? 'font-semibold' : 'font-medium' ?>">Kanban</span>
      </a>
    </div>

    <!-- Movimentações -->
    <div class="mb-5 px-3">
      <h2 class="px-3 mb-2 text-[10px] uppercase tracking-widest text-blue-300/60 font-bold">Movimentações</h2>
      <div class="space-y-1">
        <a href="/pages/operations/saida.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('saida') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
          </svg>
          <span class="text-sm">Envios</span>
        </a>

        <a href="/pages/operations/retorno.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('retorno') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3"/>
          </svg>
          <span class="text-sm">Devolução / Retorno</span>
        </a>

        <a href="/pages/equipment/batch_entry.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('batch_entry') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
          </svg>
          <span class="text-sm">Entrada de Lote</span>
        </a>

        <a href="/pages/operations/history.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('history') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          <span class="text-sm">Histórico de Operações</span>
        </a>
      </div>
    </div>

    <!-- Cadastros -->
    <div class="mb-5 px-3">
      <h2 class="px-3 mb-2 text-[10px] uppercase tracking-widest text-blue-300/60 font-bold">Cadastros</h2>
      <div class="space-y-1">
        <a href="/pages/equipment/index.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('/equipment/') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
          </svg>
          <span class="text-sm">Equipamentos</span>
        </a>

        <a href="/pages/clients/index.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('/clients/') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
          </svg>
          <span class="text-sm">Clientes</span>
        </a>

        <a href="/pages/equipment/batches.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('/equipment/batches') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
          </svg>
          <span class="text-sm">Lotes</span>
        </a>
      </div>
    </div>

    <!-- Relatórios (todos os usuários; Auditoria somente admin) -->
    <div class="mb-5 px-3">
      <h2 class="px-3 mb-2 text-[10px] uppercase tracking-widest text-blue-300/60 font-bold">Relatórios</h2>
      <div class="space-y-1">
        <a href="/pages/reports/stock.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('stock') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
          </svg>
          <span class="text-sm">Estoque Atual</span>
        </a>

        <a href="/pages/reports/entries_exits.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('entries_exits') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
          </svg>
          <span class="text-sm">Entradas e Saídas</span>
        </a>

        <?php if ($userRole === 'admin'): ?>
        <a href="/pages/reports/by_user.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('by_user') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
          <span class="text-sm">Auditoria por Usuário</span>
        </a>
        <?php endif; ?>

        <a href="/pages/reports/by_equipment.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('by_equipment') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <span class="text-sm">Histórico Equipamento</span>
        </a>

        <a href="/pages/reports/by_client.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('by_client') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
          </svg>
          <span class="text-sm">Relatório por Cliente</span>
        </a>
      </div>
    </div>

    <!-- Integrações (admin e manager) -->
    <?php if (in_array($userRole, ['admin', 'manager'], true)): ?>
    <div class="mb-5 px-3">
      <h2 class="px-3 mb-2 text-[10px] uppercase tracking-widest text-blue-300/60 font-bold">Integrações</h2>
      <div class="space-y-1">
        <a href="/pages/pipedrive/index.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('/pipedrive/index') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
          </svg>
          <span class="text-sm">Pipedrive — Clientes</span>
        </a>
        <a href="/pages/pipedrive/projects.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('/pipedrive/projects') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
          </svg>
          <span class="text-sm">Pipedrive — Projetos</span>
        </a>
        <a href="/pages/pipedrive/diagnostic.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('/pipedrive/diagnostic') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
          </svg>
          <span class="text-sm">Pipedrive — Diagnóstico</span>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Admin -->
    <?php if ($userRole === 'admin'): ?>
    <div class="mb-5 px-3">
      <h2 class="px-3 mb-2 text-[10px] uppercase tracking-widest text-blue-300/60 font-bold">Admin</h2>
      <div class="space-y-1">
        <a href="/pages/users/index.php" onclick="closeSidebarMobile()"
           class="flex items-center space-x-3 px-3 py-2 rounded-lg transition-colors group
                  <?= navActive('/users/') ? 'bg-blue-600 text-white shadow-md' : 'text-blue-100 hover:bg-white/10' ?>">
          <svg class="h-4 w-4 opacity-70 group-hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
          </svg>
          <span class="text-sm">Usuários</span>
        </a>
      </div>
    </div>
    <?php endif; ?>

  </nav>

  <!-- User Profile Footer -->
  <div class="p-4 bg-blue-900/80 border-t border-blue-800/50 shrink-0">
    <a href="/pages/profile/edit.php"
       class="flex items-center space-x-3 mb-3 rounded-lg hover:bg-white/10 transition-colors p-1 -m-1 group">
      <div class="relative shrink-0">
        <div class="w-9 h-9 rounded-full bg-blue-500 flex items-center justify-center border-2 border-white/20 shadow-inner group-hover:border-white/40 transition-colors">
          <span class="text-xs font-bold text-white"><?= htmlspecialchars($initials) ?></span>
        </div>
        <div class="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-400 border-2 border-blue-900 rounded-full"></div>
      </div>
      <div class="overflow-hidden flex-1">
        <p class="text-sm font-semibold truncate leading-tight"><?= htmlspecialchars($userName) ?></p>
        <p class="text-[10px] text-blue-300 uppercase font-bold tracking-wider"><?= htmlspecialchars(roleLabel($userRole)) ?></p>
      </div>
      <svg class="h-3.5 w-3.5 text-blue-400 opacity-0 group-hover:opacity-100 transition-opacity shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
      </svg>
    </a>
    <div class="flex items-center justify-between mt-2">
      <a href="/pages/logout.php"
         class="flex items-center space-x-2 text-xs text-orange-400 hover:text-orange-300 transition-colors font-medium">
        <svg class="h-3.5 w-3.5 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
        </svg>
        <span>Sair do sistema</span>
      </a>
      <!-- Toggle Dark Mode -->
      <button id="darkModeToggle" onclick="toggleDarkMode()"
              title="Alternar modo escuro"
              class="p-1.5 rounded-lg hover:bg-white/10 transition-colors text-blue-300 hover:text-white">
        <svg id="darkIcon" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
        </svg>
        <svg id="lightIcon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
      </button>
    </div>
  </div>

</aside>

<style>
  .sidebar-nav::-webkit-scrollbar       { width: 4px; }
  .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
  .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(59,130,246,0.5); border-radius: 10px; }
  .sidebar-nav { scrollbar-width: thin; scrollbar-color: rgba(59,130,246,0.5) transparent; }

  /* ── Dark Mode — conteúdo principal ── */
  body.dark-mode { background-color: #111827 !important; color: #e5e7eb !important; }
  body.dark-mode main { background-color: #111827 !important; }

  body.dark-mode .bg-white              { background-color: #1f2937 !important; }
  body.dark-mode .bg-gray-50            { background-color: #111827 !important; }
  body.dark-mode .bg-gray-100           { background-color: #374151 !important; }
  body.dark-mode .border-gray-100       { border-color: #374151 !important; }
  body.dark-mode .border-gray-200       { border-color: #4b5563 !important; }
  body.dark-mode .border-gray-300       { border-color: #4b5563 !important; }

  body.dark-mode .text-gray-800         { color: #f9fafb !important; }
  body.dark-mode .text-gray-700         { color: #e5e7eb !important; }
  body.dark-mode .text-gray-600         { color: #d1d5db !important; }
  body.dark-mode .text-gray-500         { color: #b1b5bb !important; }
  body.dark-mode .text-gray-400         { color: #9ca3af !important; }

  body.dark-mode input, body.dark-mode select, body.dark-mode textarea {
    background-color: #374151 !important;
    border-color: #4b5563 !important;
    color: #e5e7eb !important;
  }
  body.dark-mode table thead            { background-color: #1f2937 !important; }
  body.dark-mode .hover\:bg-gray-50:hover { background-color: #374151 !important; }
  body.dark-mode .divide-gray-50 > * + * { border-color: #374151 !important; }
  body.dark-mode .shadow-sm             { box-shadow: 0 1px 3px 0 rgba(0,0,0,.4) !important; }

  /* ── Dark Mode — menu lateral e topo mobile ── */
  body.dark-mode #navAside {
    background-color: #0d1b2e !important;
    border-right: 1px solid #1e3a5f !important;
  }
  body.dark-mode #navAside .bg-blue-900\/80 {
    background-color: #0a1627 !important;
  }
  body.dark-mode #navAside .border-blue-800\/50 {
    border-color: #1e3a5f !important;
  }
  body.dark-mode #navAside .bg-blue-600,
  body.dark-mode #navAside .bg-blue-600.shadow-md {
    background-color: #1d4ed8 !important;
  }
  body.dark-mode #navAside .hover\:bg-white\/10:hover {
    background-color: rgba(255,255,255,0.08) !important;
  }
  body.dark-mode #mobileTopBar {
    background-color: #0d1b2e !important;
  }
  /* Seções do menu: mais visíveis no dark */
  body.dark-mode #navAside .text-blue-300\/60 {
    color: rgba(147,197,253,0.9) !important;
  }
  /* Ícones do menu: menos opacidade = mais visíveis */
  body.dark-mode #navAside .sidebar-nav svg.opacity-70 {
    opacity: 0.9 !important;
  }
  /* Link Sair e toggle dark: contraste garantido */
  body.dark-mode #navAside a[href*="logout"],
  body.dark-mode #navAside #darkModeToggle {
    color: #fbbf24 !important;
  }
  body.dark-mode #navAside a[href*="logout"]:hover,
  body.dark-mode #navAside #darkModeToggle:hover {
    color: #fcd34d !important;
  }
  /* Badges de operação no dashboard: legíveis no dark */
  body.dark-mode .bg-blue-100 { background-color: #1B4F8C !important; }
  body.dark-mode .text-blue-800 { color: #93c5fd !important; }
  body.dark-mode .bg-green-100 { background-color: #14532d !important; }
  body.dark-mode .text-green-800 { color: #86efac !important; }
  body.dark-mode .bg-orange-100 { background-color: #78350f !important; }
  body.dark-mode .text-orange-800 { color: #fdba74 !important; }
  body.dark-mode .border-orange-200 { border-color: #92400e !important; }
  /* Link e texto brand/primary mais visíveis */
  body.dark-mode .text-brand,
  body.dark-mode .text-primary { color: #60a5fa !important; }
  body.dark-mode a.text-brand:hover { color: #93c5fd !important; }

  /* Texto cinza claro (labels vazios, "—") */
  body.dark-mode .text-gray-300 { color: #9ca3af !important; }

  /* Placeholder de inputs mais legível */
  body.dark-mode input::placeholder,
  body.dark-mode textarea::placeholder { color: #9ca3af !important; opacity: 1; }

  /* Badges Kanban/Equipment: roxo, teal, amarelo — contraste no dark */
  body.dark-mode .bg-purple-100 { background-color: #4c1d95 !important; }
  body.dark-mode .text-purple-800 { color: #c4b5fd !important; }
  body.dark-mode .bg-teal-100 { background-color: #134e4a !important; }
  body.dark-mode .text-teal-800 { color: #5eead4 !important; }
  body.dark-mode .bg-yellow-100 { background-color: #713f12 !important; }
  body.dark-mode .text-yellow-800 { color: #fde047 !important; }
  body.dark-mode .bg-emerald-100 { background-color: #064e3b !important; }
  body.dark-mode .text-emerald-700 { color: #6ee7b7 !important; }
  body.dark-mode .bg-amber-100 { background-color: #78350f !important; }
  body.dark-mode .text-amber-700 { color: #fcd34d !important; }
  body.dark-mode .text-amber-500 { color: #fbbf24 !important; }

  /* Botão Ver — mais visível */
  body.dark-mode .bg-brand\/10 { background-color: rgba(96,165,250,0.2) !important; }
  body.dark-mode .bg-brand\/10.text-brand { color: #93c5fd !important; }

  /* Hover em linhas de tabela */
  body.dark-mode .hover\:bg-blue-50\/30:hover { background-color: rgba(59,130,246,0.15) !important; }

  /* ── Kanban: cores slate e fundo ── */
  body.dark-mode .bg-background-light { background-color: #111827 !important; }
  body.dark-mode .text-slate-900 { color: #f3f4f6 !important; }
  body.dark-mode .text-slate-700 { color: #d1d5db !important; }
  body.dark-mode .text-slate-600 { color: #d1d5db !important; }
  body.dark-mode .text-slate-500 { color: #b1b5bb !important; }
  body.dark-mode .text-slate-400 { color: #9ca3af !important; }
  body.dark-mode .bg-slate-100 { background-color: #374151 !important; }
  body.dark-mode .bg-slate-100\/70 { background-color: rgba(55,65,81,0.7) !important; }
  body.dark-mode .bg-slate-50 { background-color: #1f2937 !important; }
  body.dark-mode .bg-slate-300 { background-color: #4b5563 !important; }
  body.dark-mode .border-slate-100 { border-color: #374151 !important; }
  body.dark-mode .border-slate-200 { border-color: #4b5563 !important; }
  body.dark-mode .border-slate-300 { border-color: #6b7280 !important; }
  body.dark-mode .border-dashed.border-slate-300 { border-color: #6b7280 !important; }
  body.dark-mode .divide-slate-100 > * + * { border-color: #374151 !important; }
  body.dark-mode .hover\:bg-slate-50:hover { background-color: #374151 !important; }
  body.dark-mode .placeholder\:text-slate-400::placeholder { color: #9ca3af !important; }
  /* Kanban: tags comodo/equip.cliente (bg-blue-50, bg-purple-50, bg-teal-50) */
  body.dark-mode .bg-blue-50 { background-color: #1B4F8C !important; }
  body.dark-mode .text-blue-600 { color: #93c5fd !important; }
  body.dark-mode .bg-purple-50 { background-color: #4c1d95 !important; }
  body.dark-mode .text-purple-600 { color: #c4b5fd !important; }
  body.dark-mode .bg-teal-50 { background-color: #134e4a !important; }
  body.dark-mode .text-teal-600 { color: #5eead4 !important; }
  /* Kanban: link Pipedrive */
  body.dark-mode a[href*="tvdoutor.pipedrive.com"] { color: #60a5fa !important; }
  body.dark-mode .drag-over { outline: 2px dashed #60a5fa !important; background: rgba(59,130,246,0.2) !important; }
</style>

<script>
function toggleSidebar() {
    const aside   = document.getElementById('navAside');
    const overlay = document.getElementById('sidebarOverlay');
    const isOpen  = !aside.classList.contains('-translate-x-full');
    if (isOpen) {
        aside.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
    } else {
        aside.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
}

function closeSidebarMobile() {
    if (window.innerWidth < 1024) {
        const aside   = document.getElementById('navAside');
        const overlay = document.getElementById('sidebarOverlay');
        aside.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
    }
}

// Fechar sidebar ao redimensionar para desktop
window.addEventListener('resize', function() {
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebarOverlay').classList.add('hidden');
        document.body.style.overflow = '';
    }
});

// Dark mode
function toggleDarkMode() {
    const isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('tvdcrm_dark', isDark ? '1' : '0');
    document.getElementById('darkIcon').classList.toggle('hidden', !isDark);
    document.getElementById('lightIcon').classList.toggle('hidden', isDark);
}
(function() {
    if (localStorage.getItem('tvdcrm_dark') === '1') {
        document.body.classList.add('dark-mode');
        document.addEventListener('DOMContentLoaded', function() {
            const di = document.getElementById('darkIcon');
            const li = document.getElementById('lightIcon');
            if (di) di.classList.remove('hidden');
            if (li) li.classList.add('hidden');
        });
    }
})();

// PWA: registra service worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
</script>

<?php
// Injeta meta tags de PWA no <head> via output buffering trick (inclui via JS inline)
// Como o navbar é incluído após o <head>, usa meta tags adicionais via DOM
?>
<script>
(function() {
    // Manifest
    if (!document.querySelector('link[rel="manifest"]')) {
        var l = document.createElement('link');
        l.rel = 'manifest'; l.href = '/manifest.json';
        document.head.appendChild(l);
    }
    // Theme color
    if (!document.querySelector('meta[name="theme-color"]')) {
        var m = document.createElement('meta');
        m.name = 'theme-color'; m.content = '#1B4F8C';
        document.head.appendChild(m);
    }
    // Apple touch icon
    if (!document.querySelector('link[rel="apple-touch-icon"]')) {
        var a = document.createElement('link');
        a.rel = 'apple-touch-icon'; a.href = '/icons/icon-192.png';
        document.head.appendChild(a);
    }
})();
</script>
