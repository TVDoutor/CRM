<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);

if (($data['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
    exit;
}

$code    = trim($data['client_code'] ?? '');
$name    = trim($data['name']        ?? '');
$cnpj    = trim($data['cnpj']        ?? '') ?: null;
$phone   = trim($data['phone']       ?? '') ?: null;
$email   = trim($data['email']       ?? '') ?: null;
$address = trim($data['address']     ?? '') ?: null;
$city    = trim($data['city']        ?? '') ?: null;
$state   = strtoupper(trim($data['state'] ?? '')) ?: null;

if (!$code || !$name) {
    echo json_encode(['success' => false, 'message' => 'Código e nome são obrigatórios.']);
    exit;
}

$db = getDB();

$dup = $db->prepare('SELECT id FROM clients WHERE client_code = ?');
$dup->execute([$code]);
if ($dup->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Código já cadastrado.']);
    exit;
}

$db->prepare("INSERT INTO clients (client_code, name, cnpj, phone, email, address, city, state) VALUES (?,?,?,?,?,?,?,?)")
   ->execute([$code, $name, $cnpj, $phone, $email, $address, $city, $state]);

$newId = (int)$db->lastInsertId();
auditLog('CREATE', 'client', $newId, null, ['client_code' => $code, 'name' => $name], "Cliente criado via modal: $name");

echo json_encode(['success' => true, 'id' => $newId]);
