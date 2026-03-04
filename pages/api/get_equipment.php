<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');
requireLogin();

$id      = (int)($_GET['id']  ?? 0);
$search  = trim($_GET['q']    ?? '');

$db = getDB();

if ($id) {
    $stmt = $db->prepare("SELECT e.id, e.asset_tag, e.serial_number, e.mac_address,
        e.condition_status, e.kanban_status, e.contract_type,
        em.brand, em.model_name,
        c.name as client_name, c.client_code
    FROM equipment e
    JOIN equipment_models em ON em.id = e.model_id
    LEFT JOIN clients c ON c.id = e.current_client_id
    WHERE e.id = ?");
    $stmt->execute([$id]);
    $eq = $stmt->fetch();
    echo json_encode($eq ?: null);
    exit;
}

if ($search) {
    $stmt = $db->prepare("SELECT e.id, e.asset_tag, e.serial_number, e.condition_status, e.kanban_status,
        em.brand, em.model_name
    FROM equipment e
    JOIN equipment_models em ON em.id = e.model_id
    WHERE e.asset_tag LIKE ? OR e.serial_number LIKE ?
    ORDER BY e.asset_tag LIMIT 10");
    $stmt->execute(["%$search%", "%$search%"]);
    echo json_encode($stmt->fetchAll());
    exit;
}

echo json_encode([]);
