<?php
/**
 * Upload de foto para um equipamento.
 * POST multipart: equipment_id, csrf_token, photo (file)
 * DELETE: equipment_id, photo_id, csrf_token
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

ob_start();

$db = getDB();

// Garante tabela e diretório
try {
    $db->exec("CREATE TABLE IF NOT EXISTS equipment_photos (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        equipment_id INT UNSIGNED NOT NULL,
        filename    VARCHAR(255) NOT NULL,
        original_name VARCHAR(255),
        uploaded_by INT UNSIGNED NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_eq (equipment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Exception $e) {}

$uploadDir = __DIR__ . '/../../uploads/equipment/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$method = $_SERVER['REQUEST_METHOD'];

// ── Exclusão ──────────────────────────────────────────────────────────────
if ($method === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrfValidate();
    if (!in_array($_SESSION['user_role'], ['admin', 'manager'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
        exit;
    }

    $photoId = (int)($_POST['photo_id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM equipment_photos WHERE id = ?");
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch();

    if (!$photo) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Foto não encontrada.']);
        exit;
    }

    $filePath = $uploadDir . $photo['filename'];
    if (file_exists($filePath)) unlink($filePath);
    $db->prepare("DELETE FROM equipment_photos WHERE id = ?")->execute([$photoId]);
    auditLog('DELETE', 'equipment', $photo['equipment_id'], ['photo' => $photo['filename']], null, "Foto removida do equipamento");

    ob_end_clean();
    echo json_encode(['success' => true]);
    exit;
}

// ── Upload ────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    csrfValidate();

    $equipmentId = (int)($_POST['equipment_id'] ?? 0);
    if (!$equipmentId) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'equipment_id obrigatório.']);
        exit;
    }

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Nenhuma foto enviada ou erro no upload.']);
        exit;
    }

    $file     = $_FILES['photo'];
    $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mimeType = mime_content_type($file['tmp_name']);

    if (!in_array($mimeType, $allowed)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Formato não suportado. Use JPG, PNG ou WEBP.']);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Arquivo muito grande. Máximo 5 MB.']);
        exit;
    }

    // Limita a 10 fotos por equipamento
    $count = (int)$db->prepare("SELECT COUNT(*) FROM equipment_photos WHERE equipment_id = ?")->execute([$equipmentId])
           ? $db->prepare("SELECT COUNT(*) FROM equipment_photos WHERE equipment_id = ?")->execute([$equipmentId]) && false : 0;
    $countStmt = $db->prepare("SELECT COUNT(*) FROM equipment_photos WHERE equipment_id = ?");
    $countStmt->execute([$equipmentId]);
    if ((int)$countStmt->fetchColumn() >= 10) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Limite de 10 fotos por equipamento atingido.']);
        exit;
    }

    $ext      = match($mimeType) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };
    $filename = 'eq_' . $equipmentId . '_' . uniqid() . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Falha ao salvar arquivo.']);
        exit;
    }

    $db->prepare("INSERT INTO equipment_photos (equipment_id, filename, original_name, uploaded_by)
                  VALUES (?,?,?,?)")
       ->execute([$equipmentId, $filename, $file['name'], $_SESSION['user_id']]);

    $photoId = $db->lastInsertId();
    auditLog('CREATE', 'equipment', $equipmentId, null, ['photo' => $filename], "Foto adicionada ao equipamento");

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'photo_id' => $photoId,
        'url'      => '/uploads/equipment/' . $filename,
        'filename' => $filename,
    ]);
    exit;
}

ob_end_clean();
echo json_encode(['success' => false, 'message' => 'Método inválido.']);
