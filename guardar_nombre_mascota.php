<?php
/**
 * guardar_nombre_mascota.php — Guarda el nombre de la mascota del usuario.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$usuario = alfa_usuario();
if ($usuario === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión para guardar el nombre de tu mascota.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (!alfa_validar_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'La sesión venció. Recarga la página e inténtalo de nuevo.']);
    exit;
}

$nombreValue = $_POST['mascota_nombre'] ?? '';
if (!is_string($nombreValue)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El nombre de la mascota no es válido.']);
    exit;
}
$nombre = trim($nombreValue);
if (mb_strlen($nombre, 'UTF-8') > 50) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El nombre no puede superar 50 caracteres.']);
    exit;
}

$conexion = alfa_db();
$stmt = $conexion->prepare('UPDATE users SET mascota_nombre = ?, updated_at = NOW() WHERE id = ?');
$stmt->bind_param('si', $nombre, $usuario['id']);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el nombre de la mascota.']);
    $stmt->close();
    $conexion->close();
    exit;
}
$stmt->close();
$conexion->close();

echo json_encode(['ok' => true, 'mascota_nombre' => $nombre], JSON_UNESCAPED_UNICODE);
