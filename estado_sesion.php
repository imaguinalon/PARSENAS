<?php
/**
 * estado_sesion.php — Devuelve en JSON el estado de la sesión actual.
 *
 * index.html es HTML estático, así que no puede ejecutar Blade ni saber
 * si hay usuario logueado. Este endpoint mínimo le permite al JS preguntar
 * por el usuario y ajustar la interfaz (saludo, botones de acceso, admin).
 */

declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$usuario = alfa_usuario();

if ($usuario === null) {
    echo json_encode([
        'autenticado' => false,
        'usuario' => null,
        'es_admin' => false,
    ]);
    exit;
}

$conexion = alfa_db();
alfa_ensure_messages_table($conexion);
alfa_import_legacy_messages($conexion);
$usuarioId = (int) $usuario['id'];
$stmtNoLeidos = $conexion->prepare(
    'SELECT COUNT(*) AS total
     FROM messages m
     INNER JOIN conversations c ON c.id = m.conversation_id
     WHERE m.read_at IS NULL
       AND (m.sender_id IS NULL OR m.sender_id <> ?)
       AND (? = 1 OR c.user_id = ? OR c.recipient_id = ?)'
);
$esAdmin = alfa_es_admin() ? 1 : 0;
$stmtNoLeidos->bind_param('iiii', $usuarioId, $esAdmin, $usuarioId, $usuarioId);
$stmtNoLeidos->execute();
$mensajesNoLeidos = (int) ($stmtNoLeidos->get_result()->fetch_assoc()['total'] ?? 0);
$stmtNoLeidos->close();
$conexion->close();

echo json_encode([
    'autenticado' => true,
    'es_admin' => alfa_es_admin(),
    'mensajes_no_leidos' => $mensajesNoLeidos,
    'csrf_token' => alfa_csrf_token(),
    'usuario' => [
        'id' => (int) $usuario['id'],
        'name' => $usuario['name'],
        'apellido' => $usuario['apellido'] ?? '',
        'email' => $usuario['email'] ?? '',
        'numero_clase' => (int) ($usuario['numero_clase'] ?? 0),
        'porcentaje_clase' => (int) ($usuario['porcentaje_clase'] ?? 0),
        'mascota_path' => alfa_mascota_ruta_publica($usuario['mascota_path'] ?? null),
        'mascota_nombre' => $usuario['mascota_nombre'] ?? '',
    ],
]);
