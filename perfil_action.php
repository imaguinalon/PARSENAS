<?php
declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function perfil_responder(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuario = alfa_usuario();
if ($usuario === null) {
    perfil_responder(401, ['ok' => false, 'error' => 'Inicia sesión para administrar tu perfil.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    perfil_responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}
if (!alfa_validar_csrf(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    perfil_responder(419, ['ok' => false, 'error' => 'La sesión venció. Recarga la página e inténtalo de nuevo.']);
}

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$userId = (int) $usuario['id'];
$connection = alfa_db();
$countries = [
    '+1', '+34', '+51', '+52', '+54', '+55', '+56', '+57', '+58', '+591', '+593', '+595', '+598',
];

if ($action === 'profile_update') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $surname = trim((string) ($_POST['apellido'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')), 'UTF-8');
    $prefix = trim((string) ($_POST['telefono_prefijo'] ?? ''));
    $phone = trim((string) ($_POST['telefono'] ?? ''));

    if ($name === '' || mb_strlen($name) > 255 || mb_strlen($surname) > 255) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'El nombre es obligatorio y los campos no pueden superar 255 caracteres.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'Introduce un correo electrónico válido.']);
    }
    if ($prefix !== '' && !in_array($prefix, $countries, true)) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'Selecciona un prefijo telefónico válido.']);
    }
    if ($phone !== '' && mb_strlen($phone) > 30) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'El teléfono no puede superar 30 caracteres.']);
    }

    $duplicate = $connection->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $duplicate->bind_param('si', $email, $userId);
    $duplicate->execute();
    $isDuplicate = $duplicate->get_result()->num_rows > 0;
    $duplicate->close();
    if ($isDuplicate) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'Ese correo ya está registrado.']);
    }

    $phone = preg_replace('/^\+\d{1,4}\s*/', '', $phone);
    $phone = trim(preg_replace('/[^0-9 ]+/', '', (string) $phone));
    $fullPhone = $phone !== '' ? trim($prefix.' '.$phone) : null;
    $update = $connection->prepare(
        'UPDATE users SET name = ?, apellido = ?, email = ?, telefono = ?, updated_at = NOW() WHERE id = ?'
    );
    $update->bind_param('ssssi', $name, $surname, $email, $fullPhone, $userId);
    $update->execute();
    $update->close();
    $connection->close();

    $_SESSION['usuario']['name'] = $name;
    $_SESSION['usuario']['apellido'] = $surname;
    $_SESSION['usuario']['email'] = $email;
    $_SESSION['usuario']['telefono'] = $fullPhone;
    perfil_responder(200, ['ok' => true, 'status' => 'profile-updated', 'message' => 'Tus datos se actualizaron correctamente.']);
}

if ($action === 'password_update') {
    $currentPassword = $_POST['current_password'] ?? null;
    $password = $_POST['password'] ?? null;
    $confirmation = $_POST['password_confirmation'] ?? null;
    if (!is_string($currentPassword) || !is_string($password) || !is_string($confirmation)) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'Completa los campos de contraseña.']);
    }

    $query = $connection->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $query->bind_param('i', $userId);
    $query->execute();
    $storedPassword = $query->get_result()->fetch_assoc()['password'] ?? null;
    $query->close();
    if (!is_string($storedPassword) || !password_verify($currentPassword, $storedPassword)) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'La contraseña actual no es correcta.']);
    }
    if (mb_strlen($password) < 8 || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'La nueva contraseña debe tener 8 caracteres, una mayúscula, un número y un carácter especial.']);
    }
    if ($password !== $confirmation) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'La confirmación de contraseña no coincide.']);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $connection->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
    $update->bind_param('si', $hash, $userId);
    $update->execute();
    $update->close();
    $connection->close();
    perfil_responder(200, ['ok' => true, 'status' => 'password-updated', 'message' => 'Tu contraseña se actualizó correctamente.']);
}

if ($action === 'pet_name') {
    $petName = $_POST['mascota_nombre'] ?? null;
    if (!is_string($petName) || mb_strlen(trim($petName)) > 50) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'El nombre de la mascota no puede superar 50 caracteres.']);
    }
    $petName = trim($petName);
    $update = $connection->prepare('UPDATE users SET mascota_nombre = ?, updated_at = NOW() WHERE id = ?');
    $update->bind_param('si', $petName, $userId);
    $update->execute();
    $update->close();
    $connection->close();
    $_SESSION['usuario']['mascota_nombre'] = $petName;
    perfil_responder(200, ['ok' => true, 'status' => 'mascota-name-updated', 'message' => 'El nombre de tu mascota se guardó correctamente.']);
}

if ($action === 'pet_remove') {
    $current = $connection->prepare('SELECT mascota_path FROM users WHERE id = ? LIMIT 1');
    $current->bind_param('i', $userId);
    $current->execute();
    $currentUser = $current->get_result()->fetch_assoc();
    $current->close();
    if ($currentUser === null) {
        $connection->close();
        perfil_responder(401, ['ok' => false, 'error' => 'La cuenta ya no está disponible. Inicia sesión de nuevo.']);
    }

    $update = $connection->prepare('UPDATE users SET mascota_path = NULL, updated_at = NOW() WHERE id = ?');
    $update->bind_param('i', $userId);
    if (!$update->execute()) {
        $update->close();
        $connection->close();
        perfil_responder(500, ['ok' => false, 'error' => 'No se pudo eliminar la mascota.']);
    }
    $update->close();
    $connection->close();
    if (!alfa_eliminar_mascota_subida(is_string($currentUser['mascota_path'] ?? null) ? $currentUser['mascota_path'] : null)) {
        error_log('Alfa could not remove a deleted mascot upload for user '.$userId);
    }
    $_SESSION['usuario']['mascota_path'] = null;
    perfil_responder(200, ['ok' => true, 'status' => 'mascota-removed', 'message' => 'Mascota eliminada. Usas la predeterminada.']);
}

if ($action === 'account_delete') {
    $password = $_POST['password'] ?? null;
    if (!is_string($password)) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'Confirma tu contraseña actual para eliminar la cuenta.']);
    }
    $query = $connection->prepare('SELECT password, mascota_path FROM users WHERE id = ? LIMIT 1');
    $query->bind_param('i', $userId);
    $query->execute();
    $account = $query->get_result()->fetch_assoc();
    $query->close();
    $storedPassword = $account['password'] ?? null;
    if (!is_string($storedPassword) || !password_verify($password, $storedPassword)) {
        $connection->close();
        perfil_responder(422, ['ok' => false, 'error' => 'La contraseña actual no es correcta.']);
    }
    $delete = $connection->prepare('DELETE FROM users WHERE id = ?');
    $delete->bind_param('i', $userId);
    $delete->execute();
    $deleted = $delete->affected_rows === 1;
    $delete->close();
    $connection->close();
    if (!$deleted) {
        perfil_responder(500, ['ok' => false, 'error' => 'No se pudo eliminar la cuenta.']);
    }
    if (!alfa_eliminar_mascota_subida(is_string($account['mascota_path'] ?? null) ? $account['mascota_path'] : null)) {
        error_log('Alfa could not remove the mascot upload for deleted user '.$userId);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
    }
    session_destroy();
    perfil_responder(200, ['ok' => true, 'redirect' => 'index.html']);
}

$connection->close();
perfil_responder(422, ['ok' => false, 'error' => 'La acción de perfil no es válida.']);
