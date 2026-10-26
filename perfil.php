<?php
/**
 * perfil.php — Datos del usuario en sesión para perfil.html (JSON).
 * Sustituye a la ruta /profile de parsenas, pero como endpoint mínimo.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = alfa_usuario();

if ($usuario === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No has iniciado sesión.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'usuario' => [
        'id' => (int) $usuario['id'],
        'name' => $usuario['name'],
        'apellido' => $usuario['apellido'] ?? '',
        'email' => $usuario['email'] ?? '',
        'telefono' => $usuario['telefono'] ?? '',
        'numero_clase' => (int) ($usuario['numero_clase'] ?? 0),
        'porcentaje_clase' => (int) ($usuario['porcentaje_clase'] ?? 0),
        'mascota_path' => alfa_mascota_ruta_publica($usuario['mascota_path'] ?? null),
        'mascota_nombre' => $usuario['mascota_nombre'] ?? '',
        'is_admin' => !empty($usuario['is_admin']),
    ],
    'csrf_token' => alfa_csrf_token(),
]);
