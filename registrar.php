<?php
/**
 * registrar.php — Registro de usuarios (copia alfa de PARSEÑAS).
 *
 * Recibe los datos del formulario de register.html y crea el usuario en la
 * tabla `users`, con la misma estructura que usa parsenas (Laravel):
 *   name, apellido, email, telefono, password, is_admin,
 *   numero_clase, porcentaje_clase, mascota_path
 *
 * La respuesta es JSON para que register.html pueda mostrar los errores
 * en la página (sin recargar) con JavaScript.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errores' => ['form' => 'Método no permitido.']]);
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$apellido = trim((string) ($_POST['apellido'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$telefonoPrefijo = trim((string) ($_POST['telefono_prefijo'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

$errores = [];

// --- Validaciones equivalentes a las de parsenas ---
if ($name === '') {
    $errores['name'] = 'El nombre es obligatorio.';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores['email'] = 'Introduce un correo válido.';
}

if ($phone !== '' && !preg_match('/^[0-9 ]{6,20}$/', $phone)) {
    $errores['phone'] = 'El teléfono solo puede contener números y espacios.';
}

if ($password === '') {
    $errores['password'] = 'La contraseña es obligatoria.';
} elseif ($password !== $passwordConfirmation) {
    $errores['password'] = 'La contraseña no coincide.';
} elseif (mb_strlen($password) < 8) {
    $errores['password'] = 'La contraseña debe tener al menos 8 caracteres.';
} elseif (!preg_match('/[A-Z]/', $password)) {
    $errores['password'] = 'La contraseña debe incluir al menos una letra mayúscula.';
} elseif (!preg_match('/[0-9]/', $password)) {
    $errores['password'] = 'La contraseña debe incluir al menos un número.';
} elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
    $errores['password'] = 'La contraseña debe incluir al menos un carácter especial.';
}

$conexion = alfa_db();

// --- Comprobar si el correo ya existe ---
if ($email !== '' && !isset($errores['email'])) {
    $stmt = $conexion->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $errores['email'] = 'Este correo ya está registrado.';
    }
    $stmt->close();
}

if ($errores !== []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errores' => $errores]);
    $conexion->close();
    exit;
}

// --- Guardar usuario ---
$numero = preg_replace('/\D+/', '', $phone);
$fullPhone = $numero !== '' ? trim($telefonoPrefijo.' '.$numero) : null;
$hash = password_hash($password, PASSWORD_BCRYPT);

// Insertamos name, apellido, email, telefono y password (5 columnas =
// 5 marcadores). is_admin, numero_clase y porcentaje_clase toman su valor
// por defecto de la tabla (0), exactamente igual que al registrarse en parsenas.
$sql = 'INSERT INTO users (name, apellido, email, telefono, password) VALUES (?, ?, ?, ?, ?)';
$stmt = $conexion->prepare($sql);
$stmt->bind_param(
    'sssss',
    $name,
    $apellido,
    $email,
    $fullPhone,
    $hash
);

if (!$stmt->execute()) {
    // Si la tabla no tiene exactamente estas columnas, MySQL devolverá aquí
    // el error; lo mostramos para poder diagnosticarlo.
    http_response_code(500);
    echo json_encode(['ok' => false, 'errores' => ['form' => 'No se pudo crear la cuenta: '.$stmt->error]]);
    $stmt->close();
    $conexion->close();
    exit;
}

$nuevoId = $stmt->insert_id;
$stmt->close();
$conexion->close();

// Autologin: dejamos al usuario en sesión igual que hace parsenas.
$_SESSION['usuario'] = [
    'id' => $nuevoId,
    'name' => $name,
    'apellido' => $apellido,
    'email' => $email,
    'telefono' => $fullPhone,
    'is_admin' => 0,
    'numero_clase' => 0,
    'porcentaje_clase' => 0,
    'mascota_path' => null,
];

echo json_encode(['ok' => true, 'redirect' => 'index.html']);
