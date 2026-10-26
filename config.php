<?php
/**
 * config.php — Configuración mínima de la copia "alfa" de PARSEÑAS.
 *
 * Igual que en CuatroLunasMakeup, todo el sitio usa una conexión mysqli
 * directa a la base de datos. Aquí centralizamos los datos de conexión
 * en constantes para no repetirlos en cada archivo.
 *
 * Nota: el archivo conexion.php de alfa se mantiene tal cual (mysqli a
 * cuatro_lunas_makeup). Este config.php solo añade constantes y arranque
 * de sesión para los archivos PHP mínimos (login, registro, etc.).
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Alfa y Parseñas comparten la base de datos y la tabla de usuarios.
define('DB_SERVIDOR', 'localhost');
define('DB_USUARIO', 'root');
define('DB_CONTRASENA', '');
define('DB_NOMBRE', 'parsenas');

/**
 * Devuelve una conexión mysqli lista para usar.
 * Lanza una excepción con el error exacto si la conexión falla.
 */
function alfa_db(): mysqli
{
    $conexion = new mysqli(DB_SERVIDOR, DB_USUARIO, DB_CONTRASENA, DB_NOMBRE);

    if ($conexion->connect_error) {
        die('Error de conexión: '.$conexion->connect_error);
    }

    $conexion->set_charset('utf8');

    return $conexion;
}

/** Creates the persistent mailbox used by contact forms and conversations. */
function alfa_ensure_messages_table(mysqli $conexion): void
{
    $conexion->query(
        'CREATE TABLE IF NOT EXISTS alfa_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            conversation_key VARCHAR(80) NULL,
            sender_id BIGINT UNSIGNED NULL,
            recipient_id BIGINT UNSIGNED NULL,
            sender_is_admin TINYINT(1) NOT NULL DEFAULT 0,
            message_type ENUM("contact", "chat") NOT NULL,
            sender_name VARCHAR(160) NOT NULL,
            sender_email VARCHAR(190) NULL,
            content TEXT NOT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_alfa_messages_recipient (recipient_id, sender_is_admin, read_at),
            INDEX idx_alfa_messages_conversation (conversation_key, created_at),
            INDEX idx_alfa_messages_contact (message_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Imports Alfa's original mailbox records into Parseñas' shared message tables. */
function alfa_import_legacy_messages(mysqli $conexion): void
{
    foreach (['conversations', 'messages'] as $table) {
        $result = $conexion->query("SHOW TABLES LIKE '".$conexion->real_escape_string($table)."'");
        if (!$result || $result->num_rows !== 1) {
            throw new RuntimeException('No están disponibles las tablas compartidas de mensajería de Parseñas.');
        }
    }

    $conexion->query(
        'CREATE TABLE IF NOT EXISTS alfa_message_imports (
            source_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            source_conversation_key VARCHAR(80) NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            INDEX idx_alfa_message_import_key (source_conversation_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pending = $conexion->query(
        'SELECT 1 FROM alfa_messages old
         LEFT JOIN alfa_message_imports imported ON imported.source_message_id = old.id
         WHERE imported.source_message_id IS NULL LIMIT 1'
    );
    if ($pending->num_rows === 0) {
        return;
    }

    $lock = $conexion->query("SELECT GET_LOCK('alfa_legacy_message_import', 10) AS acquired")->fetch_assoc();
    if ((int) ($lock['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('No se pudo bloquear la migración del buzón anterior.');
    }

    try {
        $legacy = $conexion->query(
        'SELECT old.id, old.conversation_key, old.sender_id, old.recipient_id, old.sender_is_admin,
                old.message_type, old.sender_name, old.sender_email, old.content, old.read_at,
                old.created_at
         FROM alfa_messages old
         LEFT JOIN alfa_message_imports imported ON imported.source_message_id = old.id
         WHERE imported.source_message_id IS NULL
         ORDER BY old.id'
        );

        while ($message = $legacy->fetch_assoc()) {
            $conexion->begin_transaction();
            try {
            $conversationId = 0;
            $legacyKey = $message['conversation_key'];

            if ($message['message_type'] === 'chat' && $legacyKey !== null) {
                $findConversation = $conexion->prepare(
                    'SELECT conversation_id FROM alfa_message_imports
                     WHERE source_conversation_key = ? ORDER BY source_message_id LIMIT 1'
                );
                $findConversation->bind_param('s', $legacyKey);
                $findConversation->execute();
                $existingConversation = $findConversation->get_result()->fetch_assoc();
                $findConversation->close();
                $conversationId = (int) ($existingConversation['conversation_id'] ?? 0);
            }

            $senderId = $message['sender_id'] === null ? null : (int) $message['sender_id'];
            if ($senderId !== null) {
                $userExists = $conexion->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                $userExists->bind_param('i', $senderId);
                $userExists->execute();
                if ($userExists->get_result()->num_rows === 0) {
                    $senderId = null;
                }
                $userExists->close();
            }

            if ($conversationId === 0) {
                $userId = null;
                $recipientId = null;
                $type = $message['message_type'] === 'contact' ? 'contact' : 'chat';
                $origin = $message['message_type'] === 'contact'
                    ? ($senderId === null ? 'guest' : 'registered')
                    : null;
                $subject = $message['message_type'] === 'contact'
                    ? 'Contacto recibido'
                    : 'Conversación con administración';

                if ($type === 'contact') {
                    $userId = $senderId;
                } elseif (preg_match('/^usuario:([1-9][0-9]*)$/', (string) $legacyKey, $match) === 1) {
                    $userId = (int) $match[1];
                    if (!empty($message['sender_is_admin'])) {
                        $recipientId = $senderId;
                    }
                }
                if ($userId !== null) {
                    $userExists = $conexion->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                    $userExists->bind_param('i', $userId);
                    $userExists->execute();
                    if ($userExists->get_result()->num_rows === 0) {
                        $userId = null;
                    }
                    $userExists->close();
                }

                $insertConversation = $conexion->prepare(
                    'INSERT INTO conversations
                        (user_id, recipient_id, type, contact_origin, guest_name, guest_email, subject, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $createdAt = (string) $message['created_at'];
                $guestName = $userId === null && $type === 'contact' ? (string) $message['sender_name'] : null;
                $guestEmail = $userId === null && $type === 'contact' ? ($message['sender_email'] ?? null) : null;
                $insertConversation->bind_param(
                    'iisssssss',
                    $userId,
                    $recipientId,
                    $type,
                    $origin,
                    $guestName,
                    $guestEmail,
                    $subject,
                    $createdAt,
                    $createdAt
                );
                $insertConversation->execute();
                $conversationId = $conexion->insert_id;
                $insertConversation->close();
            } elseif (!empty($message['sender_is_admin']) && $senderId !== null) {
                $updateRecipient = $conexion->prepare(
                    'UPDATE conversations SET recipient_id = COALESCE(recipient_id, ?) WHERE id = ?'
                );
                $updateRecipient->bind_param('ii', $senderId, $conversationId);
                $updateRecipient->execute();
                $updateRecipient->close();
            }

            $body = (string) $message['content'];
            $readAt = $message['read_at'];
            $createdAt = (string) $message['created_at'];
            $insertMessage = $conexion->prepare(
                'INSERT INTO messages (conversation_id, sender_id, body, read_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertMessage->bind_param('iissss', $conversationId, $senderId, $body, $readAt, $createdAt, $createdAt);
            $insertMessage->execute();
            $messageId = $conexion->insert_id;
            $insertMessage->close();

            $import = $conexion->prepare(
                'INSERT INTO alfa_message_imports (source_message_id, source_conversation_key, conversation_id, message_id)
                 VALUES (?, ?, ?, ?)'
            );
            $sourceId = (int) $message['id'];
            $import->bind_param('isii', $sourceId, $legacyKey, $conversationId, $messageId);
            $import->execute();
            $import->close();
                $conexion->commit();
            } catch (Throwable $exception) {
                $conexion->rollback();
                throw $exception;
            }
        }
    } finally {
        $conexion->query("DO RELEASE_LOCK('alfa_legacy_message_import')");
    }
}

/** Provides a session-bound token for state-changing forms. */
function alfa_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

/** Validates a submitted session-bound token. */
function alfa_validar_csrf(?string $token): bool
{
    return $token !== null
        && isset($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $token);
}

/** Limits repeated form submissions within the current session. */
function alfa_rate_limit(string $key, int $limit, int $windowSeconds): bool
{
    $now = time();
    $bucket = $_SESSION['_rate_limits'][$key] ?? ['started_at' => $now, 'count' => 0];
    if ($now - (int) $bucket['started_at'] >= $windowSeconds) {
        $bucket = ['started_at' => $now, 'count' => 0];
    }
    if ((int) $bucket['count'] >= $limit) {
        $_SESSION['_rate_limits'][$key] = $bucket;
        return false;
    }
    $bucket['count']++;
    $_SESSION['_rate_limits'][$key] = $bucket;

    return true;
}

/** Devuelve el usuario autenticado (o null) leyéndolo de la sesión. */
function alfa_usuario(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

/** true si hay un usuario con sesión iniciada. */
function alfa_autenticado(): bool
{
    return alfa_usuario() !== null;
}

/** true si el usuario en sesión tiene rol de administrador. */
function alfa_es_admin(): bool
{
    $usuario = alfa_usuario();

    return $usuario !== null && !empty($usuario['is_admin']);
}

/** Escapa texto para mostrarlo en HTML sin riesgo de inyección. */
function alfa_e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

/** Returns the browser path of an image saved by Alfa. */
function alfa_mascota_ruta_publica(?string $ruta): ?string
{
    if ($ruta === null || trim($ruta) === '') {
        return null;
    }

    $ruta = trim($ruta);
    if (preg_match('~^https?://~i', $ruta) === 1) {
        return $ruta;
    }

    $legacyPrefix = strpos($ruta, '/uploads/mascotas/') === 0
        ? '/uploads/mascotas/'
        : 'uploads/mascotas/';
    if (strpos($ruta, $legacyPrefix) === 0) {
        return alfa_mascota_prefijo_publico().substr($ruta, strlen($legacyPrefix));
    }
    if ($ruta[0] === '/') {
        return $ruta;
    }

    return $ruta;
}

/** Stores one validated image in Alfa's public mascot folder and returns its DB path. */
function alfa_guardar_mascota_subida(array $archivo): string
{
    $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('La imagen supera el límite permitido de 4 MB.');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Selecciona una imagen para tu mascota.');
        }

        throw new InvalidArgumentException('No se pudo recibir la imagen. Inténtalo de nuevo.');
    }

    $temporaryPath = $archivo['tmp_name'] ?? '';
    if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('El archivo enviado no es una subida válida.');
    }

    $size = filesize($temporaryPath);
    if ($size === false || $size < 1 || $size > 4 * 1024 * 1024) {
        throw new InvalidArgumentException('La imagen debe pesar entre 1 byte y 4 MB.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fileInfo->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!is_string($mime) || !isset($extensions[$mime]) || getimagesize($temporaryPath) === false) {
        throw new InvalidArgumentException('El archivo debe ser una imagen JPEG, PNG, GIF o WebP válida.');
    }

    $directory = __DIR__.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'mascotas';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento de mascotas.');
    }

    $filename = bin2hex(random_bytes(16)).'.'.$extensions[$mime];
    if (!move_uploaded_file($temporaryPath, $directory.DIRECTORY_SEPARATOR.$filename)) {
        throw new RuntimeException('No se pudo guardar la imagen en el servidor.');
    }

    return alfa_mascota_prefijo_publico().$filename;
}

/** Removes a stored Alfa mascot file without accepting paths outside its upload folder. */
function alfa_eliminar_mascota_subida(?string $ruta): bool
{
    if ($ruta === null || $ruta === '') {
        return true;
    }

    $prefix = alfa_mascota_prefijo_publico();
    if (strpos($ruta, $prefix) === 0) {
        $filename = substr($ruta, strlen($prefix));
    } elseif (strpos($ruta, '/uploads/mascotas/') === 0) {
        $filename = substr($ruta, strlen('/uploads/mascotas/'));
    } elseif (strpos($ruta, 'uploads/mascotas/') === 0) {
        $filename = substr($ruta, strlen('uploads/mascotas/'));
    } else {
        return true;
    }

    if ($filename === '' || basename($filename) !== $filename) {
        return false;
    }

    $directory = realpath(__DIR__.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'mascotas');
    if ($directory === false) {
        return true;
    }
    $file = realpath($directory.DIRECTORY_SEPARATOR.$filename);
    if ($file === false) {
        return true;
    }
    if (dirname($file) !== $directory) {
        return false;
    }

    return unlink($file);
}

function alfa_mascota_prefijo_publico(): string
{
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/alfa/index.php')));
    $basePath = $scriptDirectory === '.' || $scriptDirectory === '/' ? '' : rtrim($scriptDirectory, '/');

    return $basePath.'/uploads/mascotas/';
}
