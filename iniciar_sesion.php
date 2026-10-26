<?php
/**
 * iniciar_sesion.php — Login (copia alfa de PARSEÑAS).
 *
 * Acepta como identificador el correo, el teléfono o el nombre de usuario,
 * igual que el login de parsenas (campo único "login").
 * Responde JSON con errores legibles para login.html.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errores' => ['form' => 'Método no permitido.']]);
    exit;
}

$loginValue = $_POST['login'] ?? '';
$passwordValue = $_POST['password'] ?? '';
if (!is_string($loginValue) || !is_string($passwordValue)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errores' => ['login' => 'Introduce tus credenciales.']]);
    exit;
}
$login = trim($loginValue);
$password = $passwordValue;

if ($login === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errores' => ['login' => 'Introduce tus credenciales.']]);
    exit;
}

$conexion = alfa_db();

if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
    $stmt = $conexion->prepare(
        'SELECT id, name, apellido, email, telefono, password, is_admin, numero_clase, porcentaje_clase, mascota_path, mascota_nombre
         FROM users WHERE email = ?'
    );
    $stmt->bind_param('s', $login);
} elseif (preg_match('/^\+?[\d ]+$/', $login) === 1) {
    $telefonoNormalizado = preg_replace('/\s+/', '', $login);
    $telefonoBuscado = '%'.$telefonoNormalizado.'%';
    $stmt = $conexion->prepare(
        'SELECT id, name, apellido, email, telefono, password, is_admin, numero_clase, porcentaje_clase, mascota_path, mascota_nombre
         FROM users WHERE REPLACE(telefono, " ", "") LIKE ?'
    );
    $stmt->bind_param('s', $telefonoBuscado);
} else {
    $stmt = $conexion->prepare(
        'SELECT id, name, apellido, email, telefono, password, is_admin, numero_clase, porcentaje_clase, mascota_path, mascota_nombre
         FROM users WHERE name = ?'
    );
    $stmt->bind_param('s', $login);
}
$stmt->execute();
$resultado = $stmt->get_result();
$usuario = null;
while ($candidato = $resultado->fetch_assoc()) {
    if (password_verify($password, (string) $candidato['password'])) {
        $usuario = $candidato;
        break;
    }
}

if ($usuario === null) {
    $stmt->close();
    $conexion->close();
    http_response_code(422);
    echo json_encode(['ok' => false, 'errores' => ['login' => 'Credenciales incorrectas.']]);
    exit;
}

$redirect = 'index.html';
if ($usuario !== null && (int) $usuario['is_admin'] === 1) {
    $redirect = 'admin_usuarios.php';
} else {
    $destinoSolicitado = trim((string) ($_POST['redirect'] ?? ''));
    if (preg_match('/^clase-[0-8]\.html(?:\?paso=[1-9][0-9]*)?$/', $destinoSolicitado)) {
        $redirect = $destinoSolicitado;
    } elseif ($destinoSolicitado === 'mensajes.php') {
        $redirect = $destinoSolicitado;
    }
}
$stmt->close();

if ($usuario !== null && (int) $usuario['is_admin'] !== 1) {
    alfa_ensure_messages_table($conexion);
    $stmt = $conexion->prepare(
        'SELECT id FROM alfa_messages
         WHERE recipient_id = ? AND sender_is_admin = 1 AND read_at IS NULL
         ORDER BY created_at ASC, id ASC LIMIT 1'
    );
    $stmt->bind_param('i', $usuario['id']);
    $stmt->execute();
    $mensajePrioritario = $stmt->get_result()->fetch_assoc();
    if ($mensajePrioritario !== null) {
        $redirect = 'mensajes.php?prioritario=1';
    }
    $stmt->close();
}
$conexion->close();

// Guardamos los datos públicos del usuario en la sesión (sin la contraseña).
unset($usuario['password']);
session_regenerate_id(true);
$_SESSION['usuario'] = $usuario;

echo json_encode(['ok' => true, 'redirect' => $redirect]);
