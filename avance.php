<?php
/**
 * avance.php — Guarda el progreso del usuario en una clase (copia alfa).
 *
 * Equivale al POST /avance de parsenas: actualiza users.numero_clase y
 * users.porcentaje_clase con el paso actual del stepper.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = alfa_usuario();
if ($usuario === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'No has iniciado sesión.']);
    exit;
}

$datos = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($datos)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Datos inválidos.']);
    exit;
}

$lesson = (int) ($datos['lesson'] ?? -1);
$percentage = (int) ($datos['percentage'] ?? 0);

if ($lesson < 0 || $lesson > 8 || $percentage < 0 || $percentage > 100) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Valores fuera de rango.']);
    exit;
}

// Anti-saltos: no se puede guardar avance en una clase que aún no se alcanzó.
$claseActual = (int) ($usuario['numero_clase'] ?? 0);
if ($lesson !== $claseActual) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensaje' => 'Solo puedes guardar el avance de tu clase actual.']);
    exit;
}

$conexion = alfa_db();
$stmt = $conexion->prepare('UPDATE users SET numero_clase = ?, porcentaje_clase = ?, updated_at = NOW() WHERE id = ?');
$stmt->bind_param('iii', $lesson, $percentage, $usuario['id']);
$stmt->execute();
$stmt->close();
$conexion->close();

$_SESSION['usuario']['numero_clase'] = $lesson;
$_SESSION['usuario']['porcentaje_clase'] = $percentage;

echo json_encode(['ok' => true, 'lesson' => $lesson, 'percentage' => $percentage]);
