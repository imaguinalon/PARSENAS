<?php
declare(strict_types=1);

require __DIR__.'/config.php';

$usuario = alfa_usuario();
if ($usuario === null) {
    header('Location: login.html?redirect=mensajes.php');
    exit;
}

$conexion = alfa_db();
alfa_ensure_messages_table($conexion);
alfa_import_legacy_messages($conexion);
$usuarioId = (int) $usuario['id'];
$esAdmin = alfa_es_admin();
$error = '';
$oldMessage = [];
$statusMessages = [
    'message-sent' => 'El mensaje fue enviado al usuario.',
    'message-forbidden' => 'No tienes permiso para realizar esa acción.',
    'conversation-deleted' => 'La conversación fue eliminada.',
];
$status = (string) ($_GET['status'] ?? '');
$exito = $statusMessages[$status] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldMessage = $_POST;
    $action = (string) ($_POST['action'] ?? '');
    $rateKey = 'messages_'.$action;
    if (!alfa_validar_csrf(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
        $error = 'La sesión venció. Recarga la página e inténtalo de nuevo.';
    } elseif (!alfa_rate_limit($rateKey, 10, 60)) {
        $error = 'Has enviado varios mensajes seguidos. Espera un minuto e inténtalo de nuevo.';
    } elseif ($action === 'start') {
        $recipientId = filter_var($_POST['recipient_id'] ?? null, FILTER_VALIDATE_INT);
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($recipientId === false || $recipientId < 1 || $recipientId === $usuarioId) {
            $error = 'Selecciona una persona válida para iniciar la conversación.';
        } elseif ($subject === '' || mb_strlen($subject) > 150) {
            $error = 'El asunto es obligatorio y debe tener máximo 150 caracteres.';
        } elseif (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
            $error = 'El mensaje debe tener entre 1 y 5000 caracteres.';
        } else {
            $recipientQuery = $conexion->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $recipientQuery->bind_param('i', $recipientId);
            $recipientQuery->execute();
            $recipientExists = $recipientQuery->get_result()->num_rows === 1;
            $recipientQuery->close();
            if (!$recipientExists) {
                $error = 'No se encontró a la persona seleccionada.';
            } else {
                $conexion->begin_transaction();
                try {
                    $type = 'chat';
                    $createConversation = $conexion->prepare(
                        'INSERT INTO conversations (user_id, recipient_id, type, subject, created_at, updated_at)
                         VALUES (?, ?, ?, ?, NOW(), NOW())'
                    );
                    $createConversation->bind_param('iiss', $usuarioId, $recipientId, $type, $subject);
                    $createConversation->execute();
                    $conversationId = $conexion->insert_id;
                    $createConversation->close();

                    $createMessage = $conexion->prepare(
                        'INSERT INTO messages (conversation_id, sender_id, body, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), NOW())'
                    );
                    $createMessage->bind_param('iis', $conversationId, $usuarioId, $body);
                    $createMessage->execute();
                    $createMessage->close();
                    $conexion->commit();
                    $conexion->close();
                    header('Location: mensajes.php?conversation='.$conversationId);
                    exit;
                } catch (Throwable $exception) {
                    $conexion->rollback();
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'reply') {
        $conversationId = filter_var($_POST['conversation_id'] ?? null, FILTER_VALIDATE_INT);
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($conversationId === false || $conversationId < 1) {
            $error = 'La conversación seleccionada no es válida.';
        } elseif (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
            $error = 'La respuesta debe tener entre 1 y 5000 caracteres.';
        } else {
            $conversationQuery = $conexion->prepare(
                'SELECT id, user_id, recipient_id FROM conversations WHERE id = ? LIMIT 1'
            );
            $conversationQuery->bind_param('i', $conversationId);
            $conversationQuery->execute();
            $conversation = $conversationQuery->get_result()->fetch_assoc();
            $conversationQuery->close();
            $isParticipant = $conversation !== null
                && ((int) ($conversation['user_id'] ?? 0) === $usuarioId
                    || (int) ($conversation['recipient_id'] ?? 0) === $usuarioId);
            if ($conversation === null || (!$esAdmin && !$isParticipant)) {
                $error = 'No tienes permiso para responder esta conversación.';
            } else {
                $conexion->begin_transaction();
                try {
                    $createMessage = $conexion->prepare(
                        'INSERT INTO messages (conversation_id, sender_id, body, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), NOW())'
                    );
                    $createMessage->bind_param('iis', $conversationId, $usuarioId, $body);
                    $createMessage->execute();
                    $createMessage->close();
                    $touchConversation = $conexion->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?');
                    $touchConversation->bind_param('i', $conversationId);
                    $touchConversation->execute();
                    $touchConversation->close();
                    $conexion->commit();
                    $conexion->close();
                    header('Location: mensajes.php?conversation='.$conversationId);
                    exit;
                } catch (Throwable $exception) {
                    $conexion->rollback();
                    throw $exception;
                }
            }
        }
    } elseif ($action === 'delete' && $esAdmin) {
        $conversationId = filter_var($_POST['conversation_id'] ?? null, FILTER_VALIDATE_INT);
        if ($conversationId === false || $conversationId < 1) {
            $error = 'La conversación seleccionada no es válida.';
        } else {
            $delete = $conexion->prepare('DELETE FROM conversations WHERE id = ?');
            $delete->bind_param('i', $conversationId);
            $delete->execute();
            $deleted = $delete->affected_rows === 1;
            $delete->close();
            $conexion->close();
            header('Location: mensajes.php?status='.($deleted ? 'conversation-deleted' : 'message-forbidden'));
            exit;
        }
    } else {
        $error = 'No tienes permiso para realizar esta acción.';
    }
}

$selectedId = filter_var($_GET['conversation'] ?? null, FILTER_VALIDATE_INT);
$selectedId = $selectedId === false ? 0 : (int) $selectedId;
$where = $esAdmin ? '' : 'WHERE c.user_id = ? OR c.recipient_id = ?';
$sql = 'SELECT c.*, u.name AS user_name, u.apellido AS user_apellido, u.email AS user_email,
               r.name AS recipient_name, r.apellido AS recipient_apellido,
               (SELECT body FROM messages lm WHERE lm.conversation_id = c.id ORDER BY lm.created_at DESC, lm.id DESC LIMIT 1) AS last_message,
               COALESCE((SELECT MAX(lm.created_at) FROM messages lm WHERE lm.conversation_id = c.id), c.updated_at) AS latest_at
        FROM conversations c
        LEFT JOIN users u ON u.id = c.user_id
        LEFT JOIN users r ON r.id = c.recipient_id
        '.$where.'
        ORDER BY latest_at DESC, c.id DESC';
$conversationQuery = $conexion->prepare($sql);
if (!$esAdmin) {
    $conversationQuery->bind_param('ii', $usuarioId, $usuarioId);
}
$conversationQuery->execute();
$conversations = $conversationQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$conversationQuery->close();

$selected = null;
foreach ($conversations as $conversation) {
    if ((int) $conversation['id'] === $selectedId) {
        $selected = $conversation;
        break;
    }
}
if ($selected === null && $conversations !== []) {
    $selected = $conversations[0];
}

$messages = [];
if ($selected !== null) {
    $selectedId = (int) $selected['id'];
    $markRead = $conexion->prepare(
        'UPDATE messages SET read_at = NOW()
         WHERE conversation_id = ? AND read_at IS NULL AND (sender_id IS NULL OR sender_id <> ?)'
    );
    $markRead->bind_param('ii', $selectedId, $usuarioId);
    $markRead->execute();
    $markRead->close();

    $messageQuery = $conexion->prepare(
        'SELECT m.id, m.sender_id, m.body, m.created_at, u.name AS sender_name, u.is_admin AS sender_is_admin
         FROM messages m LEFT JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = ? ORDER BY m.created_at, m.id'
    );
    $messageQuery->bind_param('i', $selectedId);
    $messageQuery->execute();
    $messages = $messageQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $messageQuery->close();
}

$usersQuery = $conexion->prepare(
    'SELECT id, name, apellido, is_admin FROM users WHERE id <> ? ORDER BY name, apellido, id'
);
$usersQuery->bind_param('i', $usuarioId);
$usersQuery->execute();
$users = $usersQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$usersQuery->close();
$csrfToken = alfa_csrf_token();
$conexion->close();

$selectedIsParticipant = $selected !== null
    && ((int) ($selected['user_id'] ?? 0) === $usuarioId || (int) ($selected['recipient_id'] ?? 0) === $usuarioId);
$canReply = $selected !== null
    && ($esAdmin || $selectedIsParticipant)
    && ($selected['type'] !== 'contact' || $selected['user_id'] !== null);
$selectedPerson = '';
if ($selected !== null) {
    if ($selected['type'] === 'contact') {
        $selectedPerson = $selected['user_id'] !== null
            ? trim((string) $selected['user_name'].' '.(string) ($selected['user_apellido'] ?? '')).' · '.(string) ($selected['user_email'] ?? '')
            : trim((string) ($selected['guest_name'] ?? 'Visitante').' · '.(string) ($selected['guest_email'] ?? ''));
    } elseif ((int) ($selected['user_id'] ?? 0) === $usuarioId) {
        $selectedPerson = 'Conversación con '.trim((string) ($selected['recipient_name'] ?? 'Administración').' '.(string) ($selected['recipient_apellido'] ?? ''));
    } else {
        $selectedPerson = 'Conversación con '.trim((string) ($selected['user_name'] ?? 'Administración').' '.(string) ($selected['user_apellido'] ?? ''));
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensajes - PARSEÑAS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="base.css?v=alfa-mascota-position-4">
    <script defer src="inicio.js?v=alfa-pet-upload-1"></script>
    <script defer src="mensajes.js?v=recipient-search-3"></script>
</head>
<body>
<header class="top-bar" aria-label="Navegación principal"></header>
<main class="messages-page">
    <div class="messages-heading">
        <nav class="messages-return-actions" aria-label="Volver">
            <a class="btn-hero btn-hero-outline" href="index.html">← Volver al inicio</a>
            <?php if ($esAdmin): ?><a class="btn-hero btn-hero-outline" href="admin_usuarios.php">← Volver al panel de administración</a><?php endif; ?>
        </nav>
        <span class="section-label"><?= $esAdmin ? 'Administración' : 'Tu buzón' ?></span>
        <h1 class="section-title"><?= $esAdmin ? 'Buzón y conversaciones' : 'Mensajes' ?></h1>
        <p class="section-sub"><?= $esAdmin ? 'Revisa los contactos y conversa con los usuarios registrados.' : 'Aquí encontrarás tus conversaciones y los mensajes del equipo.' ?></p>
    </div>

    <?php if ($status === 'message-sent'): ?><p class="messages-status" role="status"><?= alfa_e($statusMessages[$status]) ?></p><?php endif; ?>
    <?php if ($status === 'message-forbidden'): ?><p class="messages-status messages-errors" role="alert"><?= alfa_e($statusMessages[$status]) ?></p><?php endif; ?>
    <?php if ($status === 'conversation-deleted'): ?><p class="messages-status" role="status"><?= alfa_e($statusMessages[$status]) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="messages-status messages-errors" role="alert"><?= alfa_e($error) ?></p><?php endif; ?>

    <?php if ($selected !== null): ?>
        <section class="message-thread" aria-labelledby="selected-thread-title">
            <header>
                <div>
                    <span class="section-label">
                        <?php if ($selected['type'] === 'contact'): ?>
                            <?= ($selected['contact_origin'] ?? ($selected['user_id'] ? 'registered' : 'guest')) === 'registered' ? 'Contacto de usuario registrado' : 'Contacto de visitante' ?>
                        <?php else: ?>Conversación<?php endif; ?>
                    </span>
                    <h2 id="selected-thread-title"><?= alfa_e((string) ($selected['subject'] ?: 'Conversación')) ?></h2>
                    <p><?= alfa_e($selectedPerson) ?></p>
                </div>
                <?php if ($esAdmin): ?>
                    <form method="post" onsubmit="return confirm('¿Eliminar esta conversación y todos sus mensajes? Esta acción no se puede deshacer.')">
                        <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="conversation_id" value="<?= $selectedId ?>">
                        <button class="conversation-delete-btn" type="submit">Eliminar conversación</button>
                    </form>
                <?php endif; ?>
            </header>
            <div class="message-list" aria-live="polite">
                <?php foreach ($messages as $message): ?>
                    <?php $senderLabel = $message['sender_id'] === null ? 'Visitante' : (!empty($message['sender_is_admin']) ? 'Administración' : (string) $message['sender_name']); ?>
                    <article class="message-bubble <?= (int) ($message['sender_id'] ?? 0) === $usuarioId ? 'message-own' : '' ?>">
                        <div class="message-meta">
                            <strong><?= alfa_e($senderLabel) ?></strong>
                            <time datetime="<?= alfa_e(date(DATE_ATOM, strtotime((string) $message['created_at']))) ?>"><?= alfa_e(date('d/m/Y H:i', strtotime((string) $message['created_at']))) ?></time>
                        </div>
                        <p><?= alfa_e((string) $message['body']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($canReply): ?>
                <form class="message-reply" method="post">
                    <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="conversation_id" value="<?= $selectedId ?>">
                    <label for="reply-body">Responder
                        <textarea id="reply-body" name="body" rows="3" maxlength="5000" required><?= alfa_e(($oldMessage['action'] ?? '') === 'reply' ? (string) ($oldMessage['body'] ?? '') : '') ?></textarea>
                    </label>
                    <button class="btn-gold" type="submit">Enviar respuesta</button>
                </form>
            <?php elseif ($selected['type'] === 'contact' && $selected['user_id'] === null): ?>
                <p class="messages-note">Este mensaje fue enviado por un visitante. Su respuesta no puede mostrarse en una cuenta de PARSEÑAS.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php
        $oldRecipientId = $oldMessage['recipient_id'] ?? '';
        $oldRecipient = null;
        foreach ($users as $candidate) {
            if ((string) $candidate['id'] === (string) $oldRecipientId) {
                $oldRecipient = $candidate;
                break;
            }
        }
    ?>
    <form class="messages-compose" method="post" data-recipient-form>
        <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
        <input type="hidden" name="action" value="start">
        <input type="hidden" name="recipient_id" value="<?= $oldRecipient === null ? '' : (int) $oldRecipient['id'] ?>" data-recipient-id>
        <h2>Iniciar una conversación</h2>
        <label for="recipient-search">Destinatario
            <input id="recipient-search" type="search" autocomplete="off" placeholder="Escribe el nombre de la persona..." value="<?= $oldRecipient === null ? '' : alfa_e(trim($oldRecipient['name'].' '.($oldRecipient['apellido'] ?? ''))) ?>" required>
        </label>
        <div class="recipient-search-results" id="recipient-search-results" role="listbox" aria-label="Coincidencias de usuarios" hidden>
            <?php foreach ($users as $recipient): ?>
                <button type="button" class="recipient-search-result" role="option"
                    data-recipient-option data-recipient-id="<?= (int) $recipient['id'] ?>"
                    data-recipient-name="<?= alfa_e(trim($recipient['name'].' '.($recipient['apellido'] ?? ''))) ?>">
                    <?= alfa_e(trim($recipient['name'].' '.($recipient['apellido'] ?? ''))) ?>
                </button>
            <?php endforeach; ?>
            <p class="recipient-search-empty" data-recipient-empty hidden>No se encontraron coincidencias.</p>
        </div>
        <p class="recipient-search-status" data-recipient-status role="status" aria-live="polite">
            <?= $oldRecipient === null ? 'Escribe un nombre y selecciona una coincidencia.' : 'Destinatario seleccionado.' ?>
        </p>
        <label for="conversation-subject">Asunto
            <input id="conversation-subject" name="subject" maxlength="150" required value="<?= alfa_e(($oldMessage['action'] ?? '') === 'start' ? (string) ($oldMessage['subject'] ?? '') : '') ?>">
        </label>
        <label for="conversation-body">Mensaje
            <textarea id="conversation-body" name="body" rows="3" maxlength="5000" required><?= alfa_e(($oldMessage['action'] ?? '') === 'start' ? (string) ($oldMessage['body'] ?? '') : '') ?></textarea>
        </label>
        <button class="btn-gold" type="submit">Enviar mensaje e iniciar conversación</button>
    </form>

    <section class="messages-conversations" aria-labelledby="conversations-title">
        <h2 id="conversations-title"><?= $esAdmin ? 'Buzón de entrada' : 'Tus conversaciones' ?></h2>
        <?php if ($conversations === []): ?>
            <p class="messages-empty">Aún no hay mensajes.</p>
        <?php else: foreach ($conversations as $conversation): ?>
            <?php
                $isContact = $conversation['type'] === 'contact';
                $isGuest = $isContact && $conversation['user_id'] === null;
                $kind = $isContact ? ($isGuest ? 'Visitante' : 'Usuario registrado') : 'Conversación';
                if ($isContact) {
                    $person = $isGuest
                        ? trim((string) ($conversation['guest_name'] ?? 'Visitante').' · '.(string) ($conversation['guest_email'] ?? ''))
                        : trim((string) $conversation['user_name'].' '.(string) ($conversation['user_apellido'] ?? ''));
                } elseif ((int) ($conversation['user_id'] ?? 0) === $usuarioId) {
                    $person = 'Con '.trim((string) ($conversation['recipient_name'] ?? 'Administración').' '.(string) ($conversation['recipient_apellido'] ?? ''));
                } else {
                    $person = 'Con '.trim((string) ($conversation['user_name'] ?? 'Administración').' '.(string) ($conversation['user_apellido'] ?? ''));
                }
            ?>
            <a class="conversation-link <?= (int) $conversation['id'] === $selectedId ? 'is-selected' : '' ?>" href="mensajes.php?conversation=<?= (int) $conversation['id'] ?>">
                <span class="conversation-kind"><?= alfa_e($kind) ?></span>
                <strong><?= alfa_e((string) ($conversation['subject'] ?: 'Conversación')) ?></strong>
                <span><?= alfa_e($person) ?></span>
                <small><?= alfa_e((string) ($conversation['last_message'] ?? '')) ?></small>
            </a>
        <?php endforeach; endif; ?>
    </section>
</main>
</body>
</html>
