<?php
require_once __DIR__ . '/../config/database.php';

function auditLog(string $action, string $entityType, ?int $entityId,
                  ?array $oldValue, ?array $newValue, ?string $description = null): void
{
    if (empty($_SESSION['user_id'])) return;
    $db = getDB();
    $db->prepare("INSERT INTO audit_log
        (user_id, action, entity_type, entity_id, old_value, new_value, description, ip_address)
        VALUES (?,?,?,?,?,?,?,?)")
       ->execute([
           $_SESSION['user_id'], $action, $entityType, $entityId,
           $oldValue  ? json_encode($oldValue,  JSON_UNESCAPED_UNICODE) : null,
           $newValue  ? json_encode($newValue,  JSON_UNESCAPED_UNICODE) : null,
           $description,
           $_SERVER['REMOTE_ADDR'] ?? null,
       ]);
}

function kanbanMove(int $equipmentId, ?string $fromStatus, string $toStatus,
                    ?int $clientId = null, ?string $notes = null): void
{
    $db = getDB();
    $db->prepare("UPDATE equipment SET kanban_status = ?, current_client_id = ?, updated_by = ? WHERE id = ?")
       ->execute([$toStatus, $clientId, $_SESSION['user_id'], $equipmentId]);

    $db->prepare("INSERT INTO kanban_history (equipment_id, from_status, to_status, client_id, moved_by, notes)
                  VALUES (?,?,?,?,?,?)")
       ->execute([$equipmentId, $fromStatus, $toStatus, $clientId, $_SESSION['user_id'], $notes]);

    auditLog('KANBAN_MOVE', 'equipment', $equipmentId,
        ['kanban_status' => $fromStatus],
        ['kanban_status' => $toStatus, 'client_id' => $clientId],
        "Movido: $fromStatus → $toStatus"
    );
}

function kanbanLabel(string $s): string {
    return [
        'entrada'               => 'Entrada',
        'aguardando_instalacao' => 'Aguardando Instalação',
        'alocado'               => 'Alocado',
        'manutencao'            => 'Manutenção',
        'licenca_removida'      => 'Licença Removida',
        'equipamento_usado'     => 'Equipamento Usado',
        'comercial'             => 'Comercial',
        'processo_devolucao'    => 'Processo de Devolução',
        'baixado'               => 'Baixado',
    ][$s] ?? $s;
}

function kanbanBadgeClass(string $s): string {
    return [
        'entrada'               => 'bg-gray-100 text-gray-700',
        'aguardando_instalacao' => 'bg-yellow-100 text-yellow-800',
        'alocado'               => 'bg-green-100 text-green-800',
        'manutencao'            => 'bg-orange-100 text-orange-800',
        'licenca_removida'      => 'bg-purple-100 text-purple-800',
        'equipamento_usado'     => 'bg-blue-100 text-blue-800',
        'comercial'             => 'bg-teal-100 text-teal-800',
        'processo_devolucao'    => 'bg-red-100 text-red-800',
        'baixado'               => 'bg-gray-800 text-gray-100',
    ][$s] ?? 'bg-gray-100 text-gray-700';
}

/**
 * Normaliza MAC para comparação (12 dígitos hex).
 * Remove : - . e espaços, mantém apenas 0-9A-Fa-f.
 */
function normalizeMac(?string $mac): string {
    if (!$mac) return '';
    $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $mac));
    return strlen($hex) >= 12 ? substr($hex, 0, 12) : $hex;
}

/**
 * Retorna os últimos 6 dígitos hex do MAC ou asset_tag (padrão em todo o sistema).
 */
function macLast6(?string $macOrTag): string {
    if (!$macOrTag) return '';
    $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $macOrTag));
    return strlen($hex) >= 6 ? substr($hex, -6) : $hex;
}

/**
 * Retorna a etiqueta para exibição: 6 dígitos do MAC quando disponível, senão asset_tag.
 */
function displayTag(?string $assetTag, ?string $macAddress = null): string {
    $t = $macAddress ? macLast6($macAddress) : '';
    return ($t && strlen($t) >= 6) ? $t : ($assetTag ?? '');
}

function conditionLabel(string $c): string {
    return $c === 'novo' ? 'Novo' : 'Usado';
}

function conditionBadge(string $c): string {
    if ($c === 'novo') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800">✦ NOVO</span>';
    }
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-orange-100 text-orange-800">↩ USADO</span>';
}

function kanbanBadge(string $s): string {
    $cls   = kanbanBadgeClass($s);
    $label = kanbanLabel($s);
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ' . $cls . '">' . htmlspecialchars($label) . '</span>';
}

function contractLabel(?string $t): string {
    if ($t === null) return '—';
    return [
        'comodato'            => 'Comodato',
        'equipamento_cliente' => 'Equip. Cliente',
        'parceria'            => 'Parceria',
    ][$t] ?? $t;
}

function roleLabel(string $r): string {
    return [
        'admin'   => 'Administrador',
        'manager' => 'Gerente',
        'user'    => 'Usuário',
    ][$r] ?? $r;
}

function sanitize(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

function flashSet(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flashGet(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function flashRender(): void {
    $f = flashGet();
    if (!$f) return;
    $colors = [
        'success' => 'bg-green-50 border-green-400 text-green-800',
        'error'   => 'bg-red-50 border-red-400 text-red-800',
        'warning' => 'bg-yellow-50 border-yellow-400 text-yellow-800',
        'info'    => 'bg-blue-50 border-blue-400 text-blue-800',
    ];
    $cls = $colors[$f['type']] ?? $colors['info'];
    echo '<div class="mb-4 p-4 rounded-lg border ' . $cls . ' flex items-start gap-3">';
    echo '<span class="text-lg">' . ($f['type'] === 'success' ? '✅' : ($f['type'] === 'error' ? '❌' : 'ℹ️')) . '</span>';
    echo '<p class="text-sm font-medium">' . htmlspecialchars($f['message']) . '</p>';
    echo '</div>';
}

function formatDate(?string $d, bool $time = false): string {
    if (!$d) return '—';
    $format = $time ? 'd/m/Y H:i' : 'd/m/Y';
    return date($format, strtotime($d));
}

function daysAgo(?string $date): string {
    if (!$date) return '—';
    $diff = (int) ((time() - strtotime($date)) / 86400);
    if ($diff === 0) return 'hoje';
    if ($diff === 1) return '1 dia';
    return $diff . ' dias';
}

/**
 * Calcula o status de garantia baseado na data de compra (12 meses) ou extensão registrada.
 * Retorna array ['status' => 'ok'|'vencendo'|'vencida'|'sem_data', 'days' => int, 'expires' => string, 'extended' => bool]
 */
function warrantyStatus(?string $purchaseDate, ?string $extendedUntil = null): array {
    if (!$purchaseDate) {
        return ['status' => 'sem_data', 'days' => null, 'expires' => null, 'extended' => false];
    }
    $purchased = strtotime($purchaseDate);
    $baseExpiry = strtotime('+12 months', $purchased);

    // Usa extensão se for posterior ao vencimento original
    $extended = false;
    if ($extendedUntil) {
        $extTs = strtotime($extendedUntil);
        if ($extTs > $baseExpiry) {
            $baseExpiry = $extTs;
            $extended = true;
        }
    }

    $today    = strtotime(date('Y-m-d'));
    $daysLeft = (int)(($baseExpiry - $today) / 86400);

    if ($daysLeft < 0) {
        return ['status' => 'vencida',  'days' => abs($daysLeft), 'expires' => date('d/m/Y', $baseExpiry), 'extended' => $extended];
    } elseif ($daysLeft <= 30) {
        return ['status' => 'vencendo', 'days' => $daysLeft,      'expires' => date('d/m/Y', $baseExpiry), 'extended' => $extended];
    } else {
        return ['status' => 'ok',       'days' => $daysLeft,      'expires' => date('d/m/Y', $baseExpiry), 'extended' => $extended];
    }
}

/**
 * Retorna o badge HTML de garantia.
 * $size: 'sm' (padrão para listas) ou 'md' (para página de detalhes)
 */
function warrantyBadge(?string $purchaseDate, string $size = 'sm', ?string $extendedUntil = null): string {
    $w    = warrantyStatus($purchaseDate, $extendedUntil);
    $pad  = $size === 'md' ? 'px-3 py-1 text-sm' : 'px-2 py-0.5 text-xs';
    $ext  = $w['extended'] ? ' title-ext' : '';

    $extSuffix = $w['extended'] ? ' (renovada)' : '';
    switch ($w['status']) {
        case 'ok':
            $cls   = 'bg-green-100 text-green-800 border border-green-200';
            $label = "Garantia" . ($w['extended'] ? ' ★' : '');
            $tip   = "Vence em {$w['expires']} ({$w['days']} dias){$extSuffix}";
            break;
        case 'vencendo':
            $cls   = 'bg-orange-100 text-orange-800 border border-orange-200';
            $label = "Vencendo" . ($w['extended'] ? ' ★' : '');
            $tip   = "Vence em {$w['expires']} ({$w['days']} dias restantes){$extSuffix}";
            break;
        case 'vencida':
            $cls   = 'bg-red-100 text-red-800 border border-red-200';
            $label = "Vencida";
            $tip   = "Venceu em {$w['expires']} (há {$w['days']} dias){$extSuffix}";
            break;
        default:
            return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-400 border border-gray-200">— Sem data</span>';
    }

    return '<span class="inline-flex items-center rounded-full font-semibold ' . $pad . ' ' . $cls . '" title="' . $tip . '">'
         . $label
         . '</span>';
}
