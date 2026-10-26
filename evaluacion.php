<?php
/**
 * evaluacion.php — Registra el resultado de una evaluación (copia alfa).
 *
 * Equivale al POST /evaluacion/clase-{lesson} de parsenas. Si el usuario
 * aprueba, desbloquea la clase siguiente actualizando users.numero_clase.
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
$passed = !empty($datos['passed']);

if ($lesson < 0 || $lesson > 8) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'mensaje' => 'Clase fuera de rango.']);
    exit;
}

$claseActual = (int) ($usuario['numero_clase'] ?? 0);
if ($lesson !== $claseActual) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensaje' => 'Debes completar la evaluación de tu clase actual.']);
    exit;
}

$nuevaClase = $passed ? max($claseActual, min(8, $lesson + 1)) : $claseActual;
$nuevoPorcentaje = $passed && $lesson === 8 ? 100 : 0;

$conexion = alfa_db();
$stmt = $conexion->prepare('UPDATE users SET numero_clase = ?, porcentaje_clase = ?, updated_at = NOW() WHERE id = ?');
$stmt->bind_param('iii', $nuevaClase, $nuevoPorcentaje, $usuario['id']);
$stmt->execute();
$stmt->close();
$conexion->close();

$_SESSION['usuario']['numero_clase'] = $nuevaClase;
$_SESSION['usuario']['porcentaje_clase'] = $nuevoPorcentaje;

echo json_encode([
    'ok' => true,
    'lesson' => $lesson,
    'passed' => $passed,
    'numero_clase' => $nuevaClase,
]);
