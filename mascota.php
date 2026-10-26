<?php
/** Stores one uploaded mascot image and saves its public path in the database. */

declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$usuario = alfa_usuario();
if ($usuario === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión para guardar tu mascota.']);
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
if (!alfa_rate_limit('mascota_upload', 5, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Has subido varias imágenes seguidas. Espera un minuto e inténtalo de nuevo.']);
    exit;
}

$file = $_FILES['mascota'] ?? null;
if (!is_array($file)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Selecciona una imagen para tu mascota.']);
    exit;
}

$conexion = alfa_db();
$userId = (int) $usuario['id'];
$current = $conexion->prepare('SELECT mascota_path FROM users WHERE id = ? LIMIT 1');
$current->bind_param('i', $userId);
$current->execute();
$currentUser = $current->get_result()->fetch_assoc();
$current->close();
$oldPath = is_array($currentUser) ? ($currentUser['mascota_path'] ?? null) : null;
if (!is_array($currentUser)) {
    $conexion->close();
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'La cuenta ya no está disponible. Inicia sesión de nuevo.']);
    exit;
}

try {
    $path = alfa_guardar_mascota_subida($file);
} catch (InvalidArgumentException $exception) {
    $conexion->close();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
    exit;
} catch (RuntimeException $exception) {
    $conexion->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
    exit;
}

try {
    $stmt = $conexion->prepare('UPDATE users SET mascota_path = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $path, $userId);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        $stmt->close();
        $conexion->close();
        if (!alfa_eliminar_mascota_subida($path)) {
            error_log('Alfa could not clean up an unreferenced mascot upload: '.$path);
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la imagen de la mascota.']);
        exit;
    }
    $stmt->close();
} catch (mysqli_sql_exception $exception) {
    $conexion->close();
    if (!alfa_eliminar_mascota_subida($path)) {
        error_log('Alfa could not clean up an unreferenced mascot upload: '.$path);
    }
    error_log("Alfa could not update a user's mascot path: ".$exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la imagen de la mascota.']);
    exit;
}
$conexion->close();

if (!alfa_eliminar_mascota_subida(is_string($oldPath) ? $oldPath : null)) {
    error_log('Alfa could not remove a replaced mascot upload.');
}
$_SESSION['usuario']['mascota_path'] = $path;

echo json_encode(['ok' => true, 'path' => $path, 'mascota_path' => $path], JSON_UNESCAPED_UNICODE);
