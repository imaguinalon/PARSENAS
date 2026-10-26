<?php
declare(strict_types=1);

require __DIR__.'/config.php';

function alfa_reset_redirect(string $url, array $errors = [], array $old = [], string $status = ''): void
{
    $_SESSION['password_reset_errors'] = $errors;
    $_SESSION['password_reset_old'] = $old;
    $_SESSION['password_reset_status'] = $status;
    header('Location: '.$url);
    exit;
}

function alfa_reset_smtp_config(): array
{
    $envPath = dirname(__DIR__).'/parsenas/.env';
    $config = is_file($envPath) ? parse_ini_file($envPath, false, INI_SCANNER_RAW) : false;
    if (!is_array($config)) {
        throw new RuntimeException('No se pudo cargar la configuración de correo.');
    }

    $host = trim((string) ($config['MAIL_HOST'] ?? ''));
    $port = filter_var($config['MAIL_PORT'] ?? null, FILTER_VALIDATE_INT);
    $username = trim((string) ($config['MAIL_USERNAME'] ?? ''));
    $password = (string) ($config['MAIL_PASSWORD'] ?? '');
    $from = trim((string) ($config['MAIL_FROM_ADDRESS'] ?? ''));
    $fromName = str_replace(["\r", "\n"], '', trim((string) ($config['MAIL_FROM_NAME'] ?? $config['APP_NAME'] ?? 'PARSEÑAS')));
    $scheme = strtolower(trim((string) ($config['MAIL_SCHEME'] ?? '')));
    if ($scheme === 'null' || $scheme === 'false') {
        $scheme = '';
    }

    if (
        $host === ''
        || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1
        || $port === false
        || $port < 1
        || $port > 65535
        || !filter_var($from, FILTER_VALIDATE_EMAIL)
        || !in_array($scheme, ['', 'smtp', 'tls', 'smtps'], true)
    ) {
        throw new RuntimeException('La configuración SMTP está incompleta o no es válida.');
    }

    return compact('host', 'port', 'username', 'password', 'from', 'fromName', 'scheme');
}

function alfa_smtp_read($socket): array
{
    $lines = [];
    do {
        $line = fgets($socket, 2048);
        if ($line === false) {
            throw new RuntimeException('El servidor SMTP cerró la conexión.');
        }
        $lines[] = rtrim($line, "\r\n");
    } while (preg_match('/^[0-9]{3}-/', end($lines)) === 1);

    $code = (int) substr($lines[count($lines) - 1], 0, 3);
    return [$code, implode("\n", $lines)];
}

function alfa_smtp_expect($socket, array $expected): void
{
    [$code, $response] = alfa_smtp_read($socket);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('Respuesta SMTP inesperada ('.$code.').');
    }
}

function alfa_smtp_command($socket, string $command, array $expected): void
{
    if (fwrite($socket, $command."\r\n") === false) {
        throw new RuntimeException('No se pudo escribir al servidor SMTP.');
    }
    alfa_smtp_expect($socket, $expected);
}

function alfa_send_reset_email(string $recipient, string $resetUrl): void
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('La cuenta no tiene un correo válido.');
    }
    $config = alfa_reset_smtp_config();
    $host = $config['host'];
    $port = (int) $config['port'];
    $scheme = $config['scheme'];
    $implicitTls = $scheme === 'smtps' || ($scheme === '' && $port === 465);
    $startTls = !$implicitTls && ($scheme === 'tls' || $port !== 25);
    $target = ($implicitTls ? 'ssl://' : 'tcp://').$host.':'.$port;
    $socket = stream_socket_client($target, $errorCode, $errorMessage, 15, STREAM_CLIENT_CONNECT);
    if ($socket === false) {
        throw new RuntimeException('No se pudo conectar con el servidor SMTP ('.$errorCode.').');
    }

    try {
        stream_set_timeout($socket, 15);
        alfa_smtp_expect($socket, [220]);
        alfa_smtp_command($socket, 'EHLO localhost', [250]);

        if ($startTls) {
            alfa_smtp_command($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('No se pudo establecer TLS con el servidor SMTP.');
            }
            alfa_smtp_command($socket, 'EHLO localhost', [250]);
        }

        if ($config['username'] !== '' || $config['password'] !== '') {
            if (!$implicitTls && !$startTls) {
                throw new RuntimeException('No se permite autenticar SMTP sin cifrado TLS.');
            }
            alfa_smtp_command($socket, 'AUTH LOGIN', [334]);
            alfa_smtp_command($socket, base64_encode($config['username']), [334]);
            alfa_smtp_command($socket, base64_encode($config['password']), [235]);
        }

        alfa_smtp_command($socket, 'MAIL FROM:<'.$config['from'].'>', [250]);
        alfa_smtp_command($socket, 'RCPT TO:<'.$recipient.'>', [250, 251]);
        alfa_smtp_command($socket, 'DATA', [354]);

        $subject = '=?UTF-8?B?'.base64_encode('Restablecer contraseña - PARSEÑAS').'?=';
        $fromName = '=?UTF-8?B?'.base64_encode($config['fromName']).'?=';
        $body = "Recibimos una solicitud para restablecer la contraseña de tu cuenta de PARSEÑAS.\r\n\r\n"
            ."Abre este enlace dentro de los próximos 60 minutos para elegir una nueva contraseña:\r\n"
            .$resetUrl."\r\n\r\n"
            ."Si no solicitaste el cambio, puedes ignorar este correo.";
        $body = quoted_printable_encode($body);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        $message = 'From: '.$fromName.' <'.$config['from'].">\r\n"
            .'To: <'.$recipient.">\r\n"
            .'Subject: '.$subject."\r\n"
            .'Date: '.date(DATE_RFC2822)."\r\n"
            .'MIME-Version: 1.0'."\r\n"
            .'Content-Type: text/plain; charset=UTF-8'."\r\n"
            .'Content-Transfer-Encoding: quoted-printable'."\r\n\r\n"
            .$body."\r\n.";
        if (fwrite($socket, $message."\r\n") === false) {
            throw new RuntimeException('No se pudo enviar el correo de recuperación.');
        }
        alfa_smtp_expect($socket, [250]);
        alfa_smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function alfa_reset_base_url(): string
{
    $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
    if ($host === '' || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
        throw new RuntimeException('No se pudo determinar la dirección del sitio.');
    }
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $port = (int) ($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
    $portSuffix = ($https && $port !== 443) || (!$https && $port !== 80) ? ':'.$port : '';
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/alfa/recuperar_password.php')));
    $directory = rtrim($directory, '/');

    return $scheme.'://'.$host.$portSuffix.$directory.'/recuperar_password.php';
}

$errors = $_SESSION['password_reset_errors'] ?? [];
$old = $_SESSION['password_reset_old'] ?? [];
$status = (string) ($_SESSION['password_reset_status'] ?? '');
unset($_SESSION['password_reset_errors'], $_SESSION['password_reset_old'], $_SESSION['password_reset_status']);

$tokenQuery = $_GET['token'] ?? '';
$emailQuery = $_GET['email'] ?? '';
$hasResetToken = is_string($tokenQuery) && $tokenQuery !== '' && is_string($emailQuery) && $emailQuery !== '';
$pageToken = $hasResetToken ? $tokenQuery : '';
$pageEmail = $hasResetToken ? $emailQuery : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    if (!alfa_validar_csrf($csrfToken)) {
        alfa_reset_redirect(
            'recuperar_password.php'.($action === 'reset' ? '?token='.rawurlencode((string) ($_POST['token'] ?? '')).'&email='.rawurlencode((string) ($_POST['email'] ?? '')) : ''),
            ['La sesión del formulario venció. Recarga la página e inténtalo de nuevo.']
        );
    }

    if ($action === 'request') {
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            alfa_reset_redirect('recuperar_password.php', ['Escribe un correo electrónico válido.'], ['email' => $email]);
        }
        if (!alfa_rate_limit('password_reset_request', 5, 60)) {
            alfa_reset_redirect('recuperar_password.php', ['Espera un minuto antes de volver a solicitar un enlace.'], ['email' => $email]);
        }

        $conexion = alfa_db();
        $userQuery = $conexion->prepare('SELECT email FROM users WHERE email = ? LIMIT 1');
        $userQuery->bind_param('s', $email);
        $userQuery->execute();
        $user = $userQuery->get_result()->fetch_assoc();
        $userQuery->close();

        if ($user !== null) {
            $existingToken = $conexion->prepare(
                'SELECT created_at,
                        created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) AND created_at <= NOW() AS is_recent
                 FROM password_reset_tokens WHERE email = ? LIMIT 1'
            );
            $userEmail = (string) $user['email'];
            $existingToken->bind_param('s', $userEmail);
            $existingToken->execute();
            $existing = $existingToken->get_result()->fetch_assoc();
            $existingToken->close();
            $recentToken = $existing !== null && (int) $existing['is_recent'] === 1;

            if (!$recentToken) {
                $token = bin2hex(random_bytes(32));
                $hashedToken = password_hash($token, PASSWORD_BCRYPT);
                $conexion->begin_transaction();
                try {
                    $saveToken = $conexion->prepare(
                        'INSERT INTO password_reset_tokens (email, token, created_at)
                         VALUES (?, ?, NOW())
                         ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW()'
                    );
                    $saveToken->bind_param('ss', $userEmail, $hashedToken);
                    $saveToken->execute();
                    $saveToken->close();
                    $conexion->commit();
                } catch (Throwable $exception) {
                    $conexion->rollback();
                    $conexion->close();
                    throw $exception;
                }

                $resetUrl = alfa_reset_base_url().'?token='.rawurlencode($token).'&email='.rawurlencode($userEmail);
                try {
                    alfa_send_reset_email($userEmail, $resetUrl);
                } catch (RuntimeException $exception) {
                    $removeToken = $conexion->prepare('DELETE FROM password_reset_tokens WHERE email = ? AND token = ?');
                    $removeToken->bind_param('ss', $userEmail, $hashedToken);
                    $removeToken->execute();
                    $removeToken->close();
                    error_log('No se pudo enviar un correo de recuperación: '.$exception->getMessage());
                    $conexion->close();
                    alfa_reset_redirect('recuperar_password.php', ['No se pudo enviar el enlace de recuperación. Inténtalo de nuevo más tarde.'], ['email' => $email]);
                }
            }
        }

        $conexion->close();
        alfa_reset_redirect(
            'recuperar_password.php',
            [],
            [],
            'Si el correo está registrado, enviaremos un enlace para restablecer la contraseña. Revisa tu bandeja de entrada.'
        );
    }

    if ($action === 'reset') {
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
        $resetErrors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $resetErrors[] = 'El enlace de recuperación no es válido.';
        }
        if ($token === '' || strlen($token) > 128) {
            $resetErrors[] = 'El enlace de recuperación no es válido.';
        }
        if (mb_strlen($password) < 8) {
            $resetErrors[] = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif (preg_match('/[A-Z]/', $password) !== 1) {
            $resetErrors[] = 'La contraseña debe incluir al menos una letra mayúscula.';
        } elseif (preg_match('/[0-9]/', $password) !== 1) {
            $resetErrors[] = 'La contraseña debe incluir al menos un número.';
        } elseif (preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            $resetErrors[] = 'La contraseña debe incluir al menos un carácter especial.';
        }
        if ($password !== $confirmation) {
            $resetErrors[] = 'La confirmación de contraseña no coincide.';
        }
        if ($resetErrors !== []) {
            alfa_reset_redirect(
                'recuperar_password.php?token='.rawurlencode($token).'&email='.rawurlencode($email),
                $resetErrors,
                ['email' => $email]
            );
        }

        $conexion = alfa_db();
        $conexion->begin_transaction();
        try {
            $tokenQuery = $conexion->prepare(
                'SELECT token,
                        created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE) AND created_at <= NOW() AS is_valid
                 FROM password_reset_tokens WHERE email = ? LIMIT 1 FOR UPDATE'
            );
            $tokenQuery->bind_param('s', $email);
            $tokenQuery->execute();
            $storedToken = $tokenQuery->get_result()->fetch_assoc();
            $tokenQuery->close();

            $validToken = $storedToken !== null
                && (int) $storedToken['is_valid'] === 1
                && password_verify($token, (string) $storedToken['token']);
            if (!$validToken) {
                $conexion->rollback();
                $conexion->close();
                alfa_reset_redirect(
                    'recuperar_password.php?token='.rawurlencode($token).'&email='.rawurlencode($email),
                    ['El enlace de recuperación no es válido o ya venció. Solicita uno nuevo.'],
                    ['email' => $email]
                );
            }

            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $rememberToken = bin2hex(random_bytes(30));
            $updateUser = $conexion->prepare(
                'UPDATE users SET password = ?, remember_token = ?, updated_at = NOW() WHERE email = ?'
            );
            $updateUser->bind_param('sss', $newHash, $rememberToken, $email);
            $updateUser->execute();
            if ($updateUser->affected_rows !== 1) {
                throw new RuntimeException('No se pudo actualizar la contraseña.');
            }
            $updateUser->close();

            $deleteToken = $conexion->prepare('DELETE FROM password_reset_tokens WHERE email = ?');
            $deleteToken->bind_param('s', $email);
            $deleteToken->execute();
            $deleteToken->close();
            $conexion->commit();
        } catch (Throwable $exception) {
            $conexion->rollback();
            $conexion->close();
            throw $exception;
        }
        $conexion->close();
        header('Location: login.html?status=password-reset');
        exit;
    }

    alfa_reset_redirect('recuperar_password.php', ['La acción solicitada no es válida.']);
}

$pageToken = is_string($_POST['token'] ?? null) ? $_POST['token'] : $pageToken;
$pageEmail = is_string($old['email'] ?? null) ? (string) $old['email'] : $pageEmail;
$hasResetToken = $pageToken !== '' && $pageEmail !== '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $hasResetToken ? 'Restablecer contraseña' : 'Recuperar contraseña' ?> - PARSEÑAS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="base.css?v=alfa-mascota-position-4">
    <script defer src="inicio.js?v=alfa-pet-upload-1"></script>
    <script defer src="auth.js"></script>
    <?php if ($hasResetToken): ?><script defer src="password-requirements.js?v=parseñas-rules-1"></script><?php endif; ?>
</head>
<body>
<header class="top-bar top-bar-auth">
    <a href="index.html" class="brand-label">PARSEÑAS</a>
    <div class="profile-menu-wrapper">
        <button type="button" id="profile-btn" aria-label="Abrir preferencias" aria-expanded="false">⚙</button>
        <div class="profile-menu" id="profile-menu" hidden>
            <p class="profile-menu-title">Preferencias</p>
            <label class="profile-option" for="theme-toggle"><span>Tema claro</span><input type="checkbox" id="theme-toggle"></label>
            <label class="profile-option" for="font-size-select"><span>Tamaño de texto</span>
                <select id="font-size-select"><option value="normal">Normal</option><option value="large">Grande</option><option value="xl">Muy grande</option></select>
            </label>
        </div>
    </div>
</header>
<main>
    <div class="form-container" style="position:relative; max-width:560px;">
        <a href="login.html" style="display:inline-flex; align-items:center; gap:8px; color:#d4af37; text-decoration:none; font-weight:700; margin-bottom:16px;">
            <span aria-hidden="true">←</span> Volver al inicio de sesión
        </a>
        <h1 class="brand-title" style="font-size:42px; text-align:center; margin-bottom:16px;">
            <?= $hasResetToken ? 'Restablecer contraseña' : 'Recuperar contraseña' ?>
        </h1>
        <p style="text-align:center; color:#f5e7b0; margin-bottom:24px; line-height:1.6;">
            <?= $hasResetToken
                ? 'Elige una nueva contraseña segura para tu cuenta.'
                : 'Ingresa tu correo electrónico y te enviaremos un enlace para restablecer tu contraseña.' ?>
        </p>

        <?php if ($status !== ''): ?><p class="profile-success" role="status"><?= alfa_e($status) ?></p><?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="error-text" role="alert">
                <ul><?php foreach ($errors as $message): ?><li><?= alfa_e((string) $message) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <?php if ($hasResetToken): ?>
            <form method="post" action="recuperar_password.php">
                <input type="hidden" name="csrf_token" value="<?= alfa_e(alfa_csrf_token()) ?>">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="token" value="<?= alfa_e($pageToken) ?>">
                <input type="hidden" name="email" value="<?= alfa_e($pageEmail) ?>">
                <label for="password" style="display:block; margin-bottom:8px; color:#f5e7b0; font-weight:600;">Nueva contraseña</label>
                <div style="position:relative; margin-bottom:12px;">
                    <input id="password" type="password" name="password" required autocomplete="new-password" class="input-gold w-full" style="display:block; width:100%; padding-right:48px;">
                    <button type="button" class="password-toggle-btn" data-target="password" aria-label="Mostrar contraseña" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:#d4af37; cursor:pointer; font-weight:700;">Ver</button>
                </div>
                <ul data-password-requirements data-input="password" class="password-requirements" hidden>
                    <li data-rule="length">Al menos 8 caracteres</li>
                    <li data-rule="uppercase">Una letra mayúscula</li>
                    <li data-rule="number">Un número</li>
                    <li data-rule="special">Un carácter especial</li>
                </ul>
                <label for="password_confirmation" style="display:block; margin:12px 0 8px; color:#f5e7b0; font-weight:600;">Confirmar contraseña</label>
                <div style="position:relative; margin-bottom:12px;">
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="input-gold w-full" style="display:block; width:100%; padding-right:48px;">
                    <button type="button" class="password-toggle-btn" data-target="password_confirmation" aria-label="Mostrar contraseña" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:#d4af37; cursor:pointer; font-weight:700;">Ver</button>
                </div>
                <button class="btn-gold w-full mt-4" type="submit">Cambiar contraseña</button>
            </form>
        <?php else: ?>
            <form method="post" action="recuperar_password.php">
                <input type="hidden" name="csrf_token" value="<?= alfa_e(alfa_csrf_token()) ?>">
                <input type="hidden" name="action" value="request">
                <label for="email" style="display:block; margin-bottom:8px; color:#f5e7b0; font-weight:600;">Correo electrónico</label>
                <input id="email" type="email" name="email" value="<?= alfa_e((string) ($old['email'] ?? '')) ?>" required autocomplete="email" class="input-gold w-full" style="display:block; width:100%; margin-bottom:12px;">
                <button class="btn-gold w-full mt-4" type="submit">Enviar enlace de recuperación</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
