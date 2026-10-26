<?php
declare(strict_types=1);

require __DIR__.'/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: datos.php');
    exit;
}

if (!alfa_validar_csrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
    $_SESSION['contacto_errors'] = ['La sesión del formulario venció. Recarga la página e inténtalo de nuevo.'];
    header('Location: datos.php');
    exit;
}

if (!alfa_rate_limit('contact_form', 5, 60)) {
    $_SESSION['contacto_errors'] = ['Has enviado varios mensajes seguidos. Espera un minuto e inténtalo de nuevo.'];
    header('Location: datos.php');
    exit;
}

$usuario = alfa_usuario();
$nombre = trim((string) ($_POST['name'] ?? ''));
$correo = trim((string) ($_POST['email'] ?? ''));
$asunto = trim((string) ($_POST['subject'] ?? ''));
$categoria = (string) ($_POST['category'] ?? '');
$contenido = trim((string) ($_POST['body'] ?? ''));

if ($usuario !== null) {
    $nombre = trim((string) ($usuario['name'] ?? '').' '.(string) ($usuario['apellido'] ?? ''));
    $correo = trim((string) ($usuario['email'] ?? ''));
}

$errors = [];
if ($usuario === null && ($nombre === '' || mb_strlen($nombre) > 255)) {
    $errors[] = 'Escribe un nombre de máximo 255 caracteres.';
}
if ($usuario === null && ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 255)) {
    $errors[] = 'Escribe un correo electrónico válido de máximo 255 caracteres.';
}
if ($asunto === '' || mb_strlen($asunto) > 150) {
    $errors[] = 'Escribe un asunto de máximo 150 caracteres.';
}
if (!in_array($categoria, ['queja', 'recomendacion'], true)) {
    $errors[] = 'Selecciona si tu mensaje es una queja o una recomendación.';
}
if (mb_strlen($contenido) < 5 || mb_strlen($contenido) > 5000) {
    $errors[] = 'El mensaje debe tener entre 5 y 5000 caracteres.';
}

if ($errors !== []) {
    $_SESSION['contacto_errors'] = $errors;
    $_SESSION['contacto_old'] = [
        'name' => $nombre,
        'email' => $correo,
        'subject' => $asunto,
        'category' => $categoria,
        'body' => $contenido,
    ];
    header('Location: datos.php');
    exit;
}

$conexion = alfa_db();
alfa_ensure_messages_table($conexion);
alfa_import_legacy_messages($conexion);
$conexion->begin_transaction();
try {
    $userId = $usuario === null ? null : (int) $usuario['id'];
    $origin = $usuario === null ? 'guest' : 'registered';
    $guestName = $usuario === null ? $nombre : null;
    $guestEmail = $usuario === null ? $correo : null;
    $subject = $asunto.' ('.($categoria === 'queja' ? 'Queja' : 'Recomendación').')';
    $insertConversation = $conexion->prepare(
        'INSERT INTO conversations (user_id, type, contact_origin, guest_name, guest_email, subject)
         VALUES (?, "contact", ?, ?, ?, ?)'
    );
    $insertConversation->bind_param('issss', $userId, $origin, $guestName, $guestEmail, $subject);
    $insertConversation->execute();
    $conversationId = $conexion->insert_id;
    $insertConversation->close();

    $insertMessage = $conexion->prepare(
        'INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)'
    );
    $insertMessage->bind_param('iis', $conversationId, $userId, $contenido);
    $insertMessage->execute();
    $insertMessage->close();
    $conexion->commit();
} catch (Throwable $exception) {
    $conexion->rollback();
    $conexion->close();
    throw $exception;
}
$conexion->close();

unset($_SESSION['contacto_old']);
$_SESSION['contacto_enviado'] = true;
header('Location: datos.php');
exit;
