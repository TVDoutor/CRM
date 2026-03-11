<?php
/**
 * Envia e-mail de alerta de garantia para os administradores.
 * Pode ser executado via cron ou URL com cron_key.
 *
 * Cron recomendado (toda segunda-feira às 8h):
 *   0 8 * * 1 /usr/local/bin/php /home2/tvdout68/crm.tvdoutor.com.br/pages/api/warranty_alert_email.php cron
 *
 * URL manual com proteção:
 *   GET /pages/api/warranty_alert_email.php?cron_key=tvd_cron_pip_2026
 */

ob_start();
error_reporting(0);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/pipedrive.php';
require_once __DIR__ . '/../../includes/helpers.php';

$cronKey = $_GET['cron_key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$isCron = (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'cron')
       || ($cronKey === PIPEDRIVE_CRON_KEY);

if (!$isCron) {
    require_once __DIR__ . '/../../includes/auth.php';
    session_start();
    requireLogin();
    requireRole(['admin']);
}

header('Content-Type: application/json; charset=utf-8');
ob_end_clean();

$db = getDB();

// Adiciona coluna warranty_extended_until se não existir
try {
    $db->query("ALTER TABLE equipment ADD COLUMN warranty_extended_until DATE NULL DEFAULT NULL AFTER purchase_date");
} catch (\Exception $e) {}

// Busca equipamentos com garantia crítica (vencida ou vencendo em ≤30 dias)
$today = date('Y-m-d');
$stmt  = $db->query("
    SELECT e.id, e.asset_tag, e.purchase_date, e.warranty_extended_until,
           em.brand, em.model_name,
           c.name as client_name, c.client_code
    FROM equipment e
    JOIN equipment_models em ON em.id = e.model_id
    LEFT JOIN clients c ON c.id = e.current_client_id
    WHERE e.purchase_date IS NOT NULL
    ORDER BY e.purchase_date ASC
");
$all = $stmt->fetchAll();

$expiring = [];
$expired  = [];

foreach ($all as $eq) {
    $w = warrantyStatus($eq['purchase_date'], $eq['warranty_extended_until'] ?? null);
    if ($w['status'] === 'vencendo') $expiring[] = array_merge($eq, $w);
    if ($w['status'] === 'vencida')  $expired[]  = array_merge($eq, $w);
}

if (empty($expiring) && empty($expired)) {
    echo json_encode(['success' => true, 'message' => 'Nenhum equipamento com garantia crítica. Nenhum e-mail enviado.', 'sent' => 0]);
    exit;
}

// Busca e-mails dos admins ativos
$admins = $db->query("SELECT name, email FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();

if (empty($admins)) {
    echo json_encode(['success' => false, 'message' => 'Nenhum administrador ativo encontrado.', 'sent' => 0]);
    exit;
}

// Monta o corpo do e-mail em HTML
function buildEmailHtml(array $expiring, array $expired): string {
    $date = date('d/m/Y');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background:#f5f5f5; margin:0; padding:20px; }
        .container { max-width:640px; margin:0 auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .header { background:#1B4F8C; color:#fff; padding:24px 28px; }
        .header h1 { margin:0; font-size:20px; }
        .header p { margin:4px 0 0; opacity:.8; font-size:13px; }
        .body { padding:24px 28px; }
        h2 { font-size:15px; margin:0 0 12px; border-bottom:2px solid #eee; padding-bottom:8px; }
        h2.red { color:#dc2626; border-color:#fca5a5; }
        h2.orange { color:#ea580c; border-color:#fdba74; }
        table { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:24px; }
        th { text-align:left; padding:8px 10px; background:#f8f9fa; color:#6b7280; font-size:11px; text-transform:uppercase; }
        td { padding:8px 10px; border-bottom:1px solid #f3f4f6; color:#374151; }
        .tag { font-family:monospace; font-weight:bold; color:#1B4F8C; }
        .days-red { color:#dc2626; font-weight:bold; }
        .days-orange { color:#ea580c; font-weight:bold; }
        .footer { background:#f9fafb; padding:16px 28px; font-size:11px; color:#9ca3af; border-top:1px solid #e5e7eb; }
    </style></head><body>
    <div class="container">
        <div class="header">
            <h1>Alerta de Garantia — S8 Conect CRM</h1>
            <p>Relatório gerado em ' . $date . '</p>
        </div>
        <div class="body">';

    if (!empty($expired)) {
        $html .= '<h2 class="red">Garantia Vencida (' . count($expired) . ' equipamentos)</h2>
        <table><thead><tr>
            <th>Etiqueta</th><th>Modelo</th><th>Cliente</th><th>Venceu em</th><th>Há</th>
        </tr></thead><tbody>';
        foreach ($expired as $e) {
            $html .= '<tr>
                <td class="tag">' . htmlspecialchars($e['asset_tag']) . '</td>
                <td>' . htmlspecialchars(displayModelName($e['brand'], $e['model_name'])) . '</td>
                <td>' . htmlspecialchars($e['client_name'] ?? '—') . '</td>
                <td>' . $e['expires'] . '</td>
                <td class="days-red">' . $e['days'] . ' dias</td>
            </tr>';
        }
        $html .= '</tbody></table>';
    }

    if (!empty($expiring)) {
        $html .= '<h2 class="orange">Garantia Vencendo em ≤30 dias (' . count($expiring) . ' equipamentos)</h2>
        <table><thead><tr>
            <th>Etiqueta</th><th>Modelo</th><th>Cliente</th><th>Vence em</th><th>Dias restantes</th>
        </tr></thead><tbody>';
        foreach ($expiring as $e) {
            $html .= '<tr>
                <td class="tag">' . htmlspecialchars($e['asset_tag']) . '</td>
                <td>' . htmlspecialchars(displayModelName($e['brand'], $e['model_name'])) . '</td>
                <td>' . htmlspecialchars($e['client_name'] ?? '—') . '</td>
                <td>' . $e['expires'] . '</td>
                <td class="days-orange">' . $e['days'] . ' dias</td>
            </tr>';
        }
        $html .= '</tbody></table>';
    }

    $html .= '</div>
        <div class="footer">
            S8 Conect CRM · crm.tvdoutor.com.br ·
            <a href="https://crm.tvdoutor.com.br/pages/equipment/index.php" style="color:#1B4F8C;">Ver equipamentos</a>
        </div>
    </div></body></html>';

    return $html;
}

$subject  = 'Alerta de Garantia — ' . count($expired) . ' vencidas, ' . count($expiring) . ' vencendo | S8 Conect CRM';
$bodyHtml = buildEmailHtml($expiring, $expired);
$bodyText = 'S8 Conect CRM — Alerta de Garantia (' . date('d/m/Y') . ")\n\n"
          . 'Vencidas: ' . count($expired) . " equipamentos\n"
          . 'Vencendo (≤30 dias): ' . count($expiring) . " equipamentos\n\n"
          . "Acesse: https://crm.tvdoutor.com.br/pages/equipment/index.php";

$sent   = 0;
$errors = [];

foreach ($admins as $admin) {
    $to      = $admin['name'] . ' <' . $admin['email'] . '>';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: S8 Conect CRM <noreply@tvdoutor.com.br>',
        'Reply-To: noreply@tvdoutor.com.br',
        'X-Mailer: TVDoutorCRM/1.0',
    ]);

    $ok = mail($to, $subject, $bodyHtml, $headers);
    if ($ok) {
        $sent++;
    } else {
        $errors[] = 'Falha ao enviar para ' . $admin['email'];
    }
}

// Registra no audit_log
if (!$isCron && !empty($_SESSION['user_id'])) {
    auditLog('WARRANTY_EMAIL', 'integration', null,
        null,
        ['vencidas' => count($expired), 'vencendo' => count($expiring), 'enviados' => $sent],
        "Alerta de garantia enviado para $sent admin(s)"
    );
}

echo json_encode([
    'success'  => $sent > 0,
    'sent'     => $sent,
    'vencidas' => count($expired),
    'vencendo' => count($expiring),
    'errors'   => $errors,
    'message'  => $sent > 0
        ? "E-mail enviado para $sent administrador(es). " . count($expired) . " vencidas, " . count($expiring) . " vencendo."
        : "Falha ao enviar e-mails.",
]);
