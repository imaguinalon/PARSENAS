<?php
/**
 * admin_usuarios.php — Panel de administración equivalente a Parseñas.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

if (!alfa_autenticado()) {
    header('Location: login.html');
    exit;
}

if (!alfa_es_admin()) {
    header('Location: login.html?redirect=admin_usuarios.php');
    exit;
}

$countries = [
    '+1' => 'Canadá / Estados Unidos',
    '+34' => 'España',
    '+51' => 'Perú',
    '+52' => 'México',
    '+54' => 'Argentina',
    '+55' => 'Brasil',
    '+56' => 'Chile',
    '+57' => 'Colombia',
    '+58' => 'Venezuela',
    '+591' => 'Bolivia',
    '+593' => 'Ecuador',
    '+595' => 'Paraguay',
    '+598' => 'Uruguay',
];
$statusMessages = [
    'user-created' => 'Usuario creado correctamente.',
    'user-updated' => 'Usuario actualizado correctamente.',
    'users-updated' => 'Todos los usuarios fueron actualizados correctamente.',
    'user-deleted' => 'Usuario eliminado.',
    'mascota-reset' => 'Mascota eliminada; el usuario usará la predeterminada.',
    'mascota-updated' => 'Mascota actualizada correctamente.',
];
$mensaje = $statusMessages[(string) ($_GET['status'] ?? '')] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    if (!is_string($submittedToken) || !alfa_validar_csrf($submittedToken)) {
        http_response_code(419);
        $error = 'La sesión venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $action = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
        $connection = alfa_db();
        $transactionStarted = false;

        $validateUser = static function (array $input, int $userId = 0) use ($connection, $countries): array {
            $name = trim((string) ($input['name'] ?? ''));
            $lastName = trim((string) ($input['apellido'] ?? ''));
            $email = trim((string) ($input['email'] ?? ''));
            $phonePrefix = trim((string) ($input['telefono_prefijo'] ?? ''));
            $phone = trim((string) ($input['telefono'] ?? ''));
            $petName = trim((string) ($input['mascota_nombre'] ?? ''));
            $class = filter_var($input['numero_clase'] ?? null, FILTER_VALIDATE_INT);
            $progress = filter_var($input['porcentaje_clase'] ?? null, FILTER_VALIDATE_INT);
            $password = (string) ($input['password'] ?? '');
            $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

            if ($name === '' || mb_strlen($name) > 255) {
                throw new RuntimeException('El nombre es obligatorio y no puede superar 255 caracteres.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                throw new RuntimeException('Introduce un correo válido.');
            }
            if ($phonePrefix !== '' && !isset($countries[$phonePrefix])) {
                throw new RuntimeException('Selecciona un prefijo telefónico válido.');
            }
            if ($phone !== '' && (mb_strlen($phone) > 30 || preg_match('/^[0-9 +().-]+$/', $phone) !== 1)) {
                throw new RuntimeException('El teléfono contiene caracteres no permitidos.');
            }
            if (mb_strlen($lastName) > 255 || mb_strlen($petName) > 50) {
                throw new RuntimeException('El apellido o nombre de mascota supera la longitud permitida.');
            }
            if ($class === false || $class < 0 || $class > 8 || $progress === false || $progress < 0 || $progress > 100) {
                throw new RuntimeException('El progreso debe tener una clase de 0 a 8 y un porcentaje de 0 a 100.');
            }
            if ($userId === 0 && $password === '') {
                throw new RuntimeException('La contraseña es obligatoria para crear un usuario.');
            }
            if ($password !== '' && $password !== $passwordConfirmation) {
                throw new RuntimeException('La confirmación de contraseña no coincide.');
            }

            $stmt = $connection->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $email, $userId);
            $stmt->execute();
            $duplicate = $stmt->get_result()->fetch_assoc() !== null;
            $stmt->close();
            if ($duplicate) {
                throw new RuntimeException('Ese correo ya está registrado.');
            }

            $phoneDigits = preg_replace('/\D+/', '', $phone);
            $formattedPhone = $phoneDigits !== '' ? trim($phonePrefix.' '.$phone) : null;

            return [
                'name' => $name,
                'apellido' => $lastName,
                'email' => $email,
                'telefono' => $formattedPhone,
                'is_admin' => !empty($input['is_admin']) ? 1 : 0,
                'mascota_nombre' => $petName !== '' ? $petName : null,
                'numero_clase' => $class,
                'porcentaje_clase' => $progress,
                'password' => $password,
            ];
        };

        try {
            if ($action === 'create_user') {
                $data = $validateUser($_POST);
                $hash = password_hash($data['password'], PASSWORD_DEFAULT);
                $stmt = $connection->prepare(
                    'INSERT INTO users (name, apellido, email, telefono, is_admin, mascota_nombre, numero_clase, porcentaje_clase, password, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                );
                $stmt->bind_param(
                    'ssssisiis',
                    $data['name'],
                    $data['apellido'],
                    $data['email'],
                    $data['telefono'],
                    $data['is_admin'],
                    $data['mascota_nombre'],
                    $data['numero_clase'],
                    $data['porcentaje_clase'],
                    $hash
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('No se pudo crear el usuario.');
                }
                $stmt->close();
                $mensaje = $statusMessages['user-created'];
            } elseif ($action === 'save_user' || $action === 'save_all') {
                $postedUsers = $_POST['users'] ?? [];
                if (!is_array($postedUsers) || $postedUsers === []) {
                    throw new RuntimeException('No se recibieron cambios para guardar.');
                }

                $connection->begin_transaction();
                $transactionStarted = true;
                foreach ($postedUsers as $userId => $input) {
                    $userId = filter_var($userId, FILTER_VALIDATE_INT);
                    if ($userId === false || $userId < 1 || !is_array($input)) {
                        throw new RuntimeException('Los datos de usuario enviados no son válidos.');
                    }
                    $data = $validateUser($input, $userId);
                    $sql = 'UPDATE users SET name = ?, apellido = ?, email = ?, telefono = ?, is_admin = ?, mascota_nombre = ?, numero_clase = ?, porcentaje_clase = ?';
                    if ($data['password'] !== '') {
                        $sql .= ', password = ?';
                    }
                    $sql .= ', updated_at = NOW() WHERE id = ?';
                    $stmt = $connection->prepare($sql);
                    if ($data['password'] !== '') {
                        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
                        $stmt->bind_param(
                            'ssssisiisi',
                            $data['name'],
                            $data['apellido'],
                            $data['email'],
                            $data['telefono'],
                            $data['is_admin'],
                            $data['mascota_nombre'],
                            $data['numero_clase'],
                            $data['porcentaje_clase'],
                            $hash,
                            $userId
                        );
                    } else {
                        $stmt->bind_param(
                            'ssssisiii',
                            $data['name'],
                            $data['apellido'],
                            $data['email'],
                            $data['telefono'],
                            $data['is_admin'],
                            $data['mascota_nombre'],
                            $data['numero_clase'],
                            $data['porcentaje_clase'],
                            $userId
                        );
                    }
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('No se pudieron guardar los cambios del usuario.');
                    }
                    $stmt->close();
                }
                $connection->commit();
                $transactionStarted = false;
                $mensaje = $statusMessages[$action === 'save_all' ? 'users-updated' : 'user-updated'];
            } elseif ($action === 'upload_mascota') {
                $userId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
                $file = $_FILES['mascota'] ?? null;
                if ($userId === false || $userId < 1 || !is_array($file)) {
                    throw new InvalidArgumentException('Selecciona un usuario y una imagen válidos.');
                }
                $current = $connection->prepare('SELECT mascota_path FROM users WHERE id = ? LIMIT 1');
                $current->bind_param('i', $userId);
                $current->execute();
                $currentUser = $current->get_result()->fetch_assoc();
                $current->close();
                if ($currentUser === null) {
                    throw new RuntimeException('No se encontró al usuario seleccionado.');
                }
                $oldPath = $currentUser['mascota_path'] ?? null;
                $stmt = $connection->prepare('UPDATE users SET mascota_path = ?, updated_at = NOW() WHERE id = ?');
                $path = alfa_guardar_mascota_subida($file);
                $stmt->bind_param('si', $path, $userId);
                try {
                    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                        if (!alfa_eliminar_mascota_subida($path)) {
                            error_log('Alfa could not clean up an unreferenced mascot upload: '.$path);
                        }
                        throw new RuntimeException('No se pudo guardar la imagen de la mascota.');
                    }
                } catch (mysqli_sql_exception $exception) {
                    if (!alfa_eliminar_mascota_subida($path)) {
                        error_log('Alfa could not clean up an unreferenced mascot upload: '.$path);
                    }
                    $stmt->close();
                    throw new RuntimeException('No se pudo guardar la imagen de la mascota.', 0, $exception);
                }
                $stmt->close();
                if (!alfa_eliminar_mascota_subida(is_string($oldPath) ? $oldPath : null)) {
                    error_log('Alfa could not remove a replaced mascot upload.');
                }
                if ($userId === (int) alfa_usuario()['id']) {
                    $_SESSION['usuario']['mascota_path'] = $path;
                }
                $mensaje = $statusMessages['mascota-updated'];
            } elseif ($action === 'remove_mascota') {
                $userId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
                if ($userId === false || $userId < 1) {
                    throw new RuntimeException('El usuario seleccionado no es válido.');
                }
                $current = $connection->prepare('SELECT mascota_path FROM users WHERE id = ? LIMIT 1');
                $current->bind_param('i', $userId);
                $current->execute();
                $currentUser = $current->get_result()->fetch_assoc();
                $current->close();
                if ($currentUser === null) {
                    throw new RuntimeException('No se encontró al usuario seleccionado.');
                }
                $stmt = $connection->prepare('UPDATE users SET mascota_path = NULL, updated_at = NOW() WHERE id = ?');
                $stmt->bind_param('i', $userId);
                if (!$stmt->execute()) {
                    throw new RuntimeException('No se pudo quitar la mascota.');
                }
                $stmt->close();
                if (!alfa_eliminar_mascota_subida(is_string($currentUser['mascota_path'] ?? null) ? $currentUser['mascota_path'] : null)) {
                    error_log('Alfa could not remove a deleted mascot upload for user '.$userId);
                }
                if ($userId === (int) alfa_usuario()['id']) {
                    $_SESSION['usuario']['mascota_path'] = null;
                }
                $mensaje = $statusMessages['mascota-reset'];
            } elseif ($action === 'delete_user') {
                $userId = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT);
                if ($userId === false || $userId < 1 || $userId === (int) alfa_usuario()['id']) {
                    throw new RuntimeException('No puedes eliminar tu propio usuario administrador.');
                }
                $current = $connection->prepare('SELECT mascota_path FROM users WHERE id = ? LIMIT 1');
                $current->bind_param('i', $userId);
                $current->execute();
                $currentUser = $current->get_result()->fetch_assoc();
                $current->close();
                if ($currentUser === null) {
                    throw new RuntimeException('No se encontró al usuario seleccionado.');
                }
                $stmt = $connection->prepare('DELETE FROM users WHERE id = ?');
                $stmt->bind_param('i', $userId);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new RuntimeException('No se pudo eliminar el usuario.');
                }
                $stmt->close();
                if (!alfa_eliminar_mascota_subida(is_string($currentUser['mascota_path'] ?? null) ? $currentUser['mascota_path'] : null)) {
                    error_log('Alfa could not remove a deleted mascot upload for user '.$userId);
                }
                $mensaje = $statusMessages['user-deleted'];
            } else {
                throw new RuntimeException('La acción solicitada no es válida.');
            }
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $connection->rollback();
            }
            $error = $exception instanceof RuntimeException || $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Ocurrió un error al guardar los cambios.';
        }
        $connection->close();
        if ($mensaje !== '') {
            $status = array_search($mensaje, $statusMessages, true);
            header('Location: admin_usuarios.php?status='.rawurlencode((string) $status));
            exit;
        }
    }
}

$connection = alfa_db();
$result = $connection->query(
    'SELECT id, name, apellido, email, telefono, is_admin, numero_clase, porcentaje_clase, mascota_path, mascota_nombre
     FROM users ORDER BY name ASC, id ASC'
);
if (!$result) {
    $connection->close();
    http_response_code(500);
    exit('No se pudo cargar la lista de usuarios.');
}
$users = $result->fetch_all(MYSQLI_ASSOC);
$connection->close();
$csrfToken = alfa_csrf_token();

$phoneDetails = static function (?string $phone): array {
    $phone = trim((string) $phone);
    if (preg_match('/^(\+\d{1,4})\s*(.*)$/', $phone, $matches) === 1) {
        return [$matches[1], trim($matches[2])];
    }

    return ['', $phone];
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar usuarios - PARSEÑAS</title>
    <meta name="mascota-url" content="mascota.php">
    <meta name="class-search-url" content="buscar_clases.php">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="base.css?v=alfa-mascota-position-4">
    <script defer src="inicio.js?v=alfa-pet-upload-1"></script>
</head>
<body class="theme-dark">
<header class="top-bar" aria-label="Navegación principal">
    <a href="index.html" class="brand-label">PARSEÑAS</a>
    <nav class="menu-rectangulo" aria-label="Secciones">
        <a href="index.html#top">Inicio</a>
        <a href="index.html#clases">Clases</a>
        <a href="index.html#contacto">Contáctanos</a>
    </nav>
    <form class="search-form" action="index.html#clases" method="get" role="search">
        <input class="search-input" name="q" type="search" placeholder="Buscar palabra o una clase..." aria-label="Buscar">
        <button class="search-btn" type="submit" aria-label="Buscar">&#128269;</button>
    </form>
    <div class="auth-actions" id="auth-actions">
        <a href="register.html" class="auth-btn" id="btn-registrarme">Registrarme</a>
        <a href="login.html" class="auth-btn" id="btn-login">Iniciar sesión</a>
        <span class="user-greeting" id="user-greeting" hidden></span>
    </div>
    <div class="top-bar-tools">
        <a href="mensajes.php" class="messages-nav-link" id="messages-link" aria-label="Mensajes recibidos">
            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                <path d="M3.5 6.5h17v11h-17zM4 7l8 6 8-6" />
            </svg>
            <span class="messages-unread-badge messages-nav-count" data-messages-unread data-message-count hidden></span>
        </a>
        <div class="profile-menu-wrapper" hidden>
            <button type="button" id="profile-btn" aria-label="Abrir preferencias" aria-expanded="false">⚙</button>
            <div class="profile-menu" id="profile-menu" hidden>
                <p class="profile-menu-title">Preferencias</p>
                <label class="profile-option" for="theme-toggle">
                    <span>Tema claro</span>
                    <input type="checkbox" id="theme-toggle">
                </label>
                <label class="profile-option" for="font-size-select">
                    <span>Tamaño de texto</span>
                    <select id="font-size-select">
                        <option value="normal">Normal</option>
                        <option value="large">Grande</option>
                        <option value="xl">Muy grande</option>
                    </select>
                </label>
                <div class="profile-menu-actions">
                    <button type="button" class="profile-action-btn" id="toggle-mascota-btn">Ocultar mascota</button>
                    <a href="perfil.html" class="profile-action-btn" id="link-perfil" hidden>Editar perfil</a>
                    <a href="cerrar_sesion.php" class="profile-action-btn" id="link-logout" hidden>Cerrar sesión</a>
                </div>
            </div>
        </div>
    </div>
</header>

<div id="admin-panel" hidden>
    <button type="button" class="admin-toggle" id="admin-toggle" aria-label="Abrir panel de administración" aria-controls="admin-drawer" aria-expanded="false">‹<small>Admin</small></button>
    <aside class="admin-drawer" id="admin-drawer" aria-label="Panel de administración" aria-hidden="true">
        <button type="button" class="admin-close" id="admin-close" aria-label="Cerrar panel">×</button>
        <span class="admin-kicker">Administración</span>
        <h2>Usuarios</h2>
        <p>Gestiona cuentas, datos y permisos.</p>
        <a href="admin_usuarios.php" class="admin-open-link">Abrir gestión completa →</a>
    </aside>
    <div class="admin-scrim" id="admin-scrim"></div>
</div>

<main>
<div class="admin-page" style="padding-top:48px; margin-top:24px;">
    <div class="admin-topbar">
        <a href="index.html" class="btn-hero btn-hero-outline admin-back">← Volver a la página de inicio</a>
        <a href="mensajes.php" class="btn-hero btn-hero-outline admin-inbox-link">✉ Buzón y conversaciones</a>
    </div>
    <span class="section-label">Administración</span>
    <h1 class="section-title">Usuarios</h1>

    <?php if ($mensaje !== ''): ?><p class="profile-success" role="status"><?= alfa_e($mensaje) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="profile-success admin-error" role="alert"><?= alfa_e($error) ?></p><?php endif; ?>

    <section class="admin-create-card" aria-labelledby="create-user-title">
        <h2 id="create-user-title">➕ Crear nuevo usuario</h2>
        <form method="post" class="admin-create-form">
            <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
            <input type="hidden" name="accion" value="create_user">
            <div class="admin-create-grid">
                <div class="admin-field">
                    <label for="create-name">Nombre <span class="req">*</span></label>
                    <input id="create-name" name="name" required placeholder="Ej. María">
                </div>
                <div class="admin-field">
                    <label for="create-lastname">Apellido <span class="opt">(opcional)</span></label>
                    <input id="create-lastname" name="apellido" placeholder="Ej. García">
                </div>
                <div class="admin-field">
                    <label for="create-pet-name">Nombre de la mascota <span class="opt">(opcional)</span></label>
                    <input id="create-pet-name" name="mascota_nombre" maxlength="50" placeholder="Ej. Luna">
                </div>
                <div class="admin-field">
                    <label for="create-email">Correo <span class="req">*</span></label>
                    <input id="create-email" type="email" name="email" required placeholder="correo@ejemplo.com">
                </div>
                <div class="admin-field">
                    <label for="create-phone">Teléfono <span class="opt">(opcional)</span></label>
                    <div class="phone-wrapper">
                        <select name="telefono_prefijo" class="phone-prefix" aria-label="Prefijo internacional">
                            <option value="">Prefijo</option>
                            <?php foreach ($countries as $prefix => $country): ?>
                                <option value="<?= alfa_e($prefix) ?>" <?= $prefix === '+57' ? 'selected' : '' ?>><?= alfa_e($prefix.' — '.$country) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input id="create-phone" type="tel" name="telefono" placeholder="300 123 4567">
                    </div>
                </div>
                <div class="admin-field admin-create-progress">
                    <label>Progreso inicial</label>
                    <div class="admin-create-progress-controls">
                        <div>
                            <label for="admin-create-class">Clase actual</label>
                            <select id="admin-create-class" name="numero_clase" data-create-class>
                                <?php for ($class = 0; $class <= 8; $class++): ?>
                                    <option value="<?= $class ?>">Clase <?= $class ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label for="admin-create-percent">Porcentaje de avance</label>
                            <input id="admin-create-percent" type="number" name="porcentaje_clase" min="0" max="100" value="0" data-create-percent>
                        </div>
                    </div>
                    <span class="admin-badge admin-badge-class" data-create-class-label>Clase 0 de 8</span>
                    <div class="admin-progress is-low" data-create-progress role="progressbar" aria-label="Progreso inicial" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <span class="admin-progress-bar" data-create-progress-bar style="width:0%"></span>
                    </div>
                    <small class="admin-progress-label"><span data-create-percent-label>0</span>% completado</small>
                </div>
                <div class="admin-field">
                    <label for="admin-new-password">Contraseña <span class="req">*</span></label>
                    <div class="admin-password-field">
                        <input type="password" id="admin-new-password" name="password" required autocomplete="new-password" placeholder="••••••••">
                        <button type="button" class="admin-password-toggle" data-password-toggle aria-label="Mostrar contraseña">Ver</button>
                    </div>
                </div>
                <div class="admin-field">
                    <label for="admin-new-password-confirmation">Confirmar contraseña <span class="req">*</span></label>
                    <div class="admin-password-field">
                        <input type="password" id="admin-new-password-confirmation" name="password_confirmation" required autocomplete="new-password" placeholder="••••••••">
                        <button type="button" class="admin-password-toggle" data-password-toggle aria-label="Mostrar contraseña">Ver</button>
                    </div>
                </div>
            </div>
            <p class="admin-modal-hint">Como administrador puedes definir una contraseña sin los requisitos de complejidad del registro.</p>
            <div class="admin-form-actions">
                <button class="btn-gold admin-create-btn" type="submit">Crear usuario</button>
                <button class="profile-action-btn" type="button" id="admin-create-preview">👁️ Ver datos</button>
            </div>
            <div class="admin-preview" id="admin-create-preview-box" hidden>
                <h3>Vista previa del nuevo usuario</h3>
                <ul id="admin-create-preview-list"></ul>
                <p class="admin-modal-hint">La contraseña no se muestra en la vista previa. Verifica que la hayas escrito correctamente.</p>
            </div>
        </form>
    </section>

    <form method="post" id="admin-bulk-update" hidden>
        <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
        <input type="hidden" name="accion" value="save_all">
    </form>
    <label class="admin-user-search" for="admin-user-search">
        <span>Buscar usuarios</span>
        <input id="admin-user-search" type="search" placeholder="Escribe un nombre, apellido o correo..." autocomplete="off">
    </label>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr>
                <th>Usuario</th><th>Contacto</th><th>Rol</th><th>Progreso</th>
                <th>Cambiar contraseña</th><th>Mascota</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <?php
                    $id = (int) $user['id'];
                    $class = max(0, min(8, (int) $user['numero_clase']));
                    $percent = max(0, min(100, (int) $user['porcentaje_clase']));
                    $tone = $percent >= 100 ? 'is-full' : ($percent >= 50 ? 'is-mid' : 'is-low');
                    [$phonePrefix, $phoneNumber] = $phoneDetails($user['telefono'] ?? null);
                    $formId = 'admin-user-update-'.$id;
                    $userName = trim($user['name'].' '.($user['apellido'] ?? ''));
                    $petPath = alfa_mascota_ruta_publica($user['mascota_path'] ?? null);
                ?>
                <tr data-user-row data-search-name="<?= alfa_e($userName.' '.$user['email'].' '.($user['telefono'] ?? '')) ?>">
                    <td>
                        <input form="<?= $formId ?>" name="users[<?= $id ?>][name]" value="<?= alfa_e($user['name']) ?>" required aria-label="Nombre">
                        <input form="<?= $formId ?>" name="users[<?= $id ?>][apellido]" value="<?= alfa_e($user['apellido'] ?? '') ?>" placeholder="Apellido" aria-label="Apellido">
                    </td>
                    <td>
                        <input form="<?= $formId ?>" type="email" name="users[<?= $id ?>][email]" value="<?= alfa_e($user['email']) ?>" required aria-label="Correo">
                        <div class="phone-wrapper">
                            <select form="<?= $formId ?>" name="users[<?= $id ?>][telefono_prefijo]" class="phone-prefix" aria-label="Prefijo internacional">
                                <option value="">Prefijo</option>
                                <?php foreach ($countries as $prefix => $country): ?>
                                    <option value="<?= alfa_e($prefix) ?>" <?= $phonePrefix === $prefix ? 'selected' : '' ?>><?= alfa_e($prefix) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input form="<?= $formId ?>" name="users[<?= $id ?>][telefono]" value="<?= alfa_e($phoneNumber) ?>" placeholder="Teléfono" aria-label="Teléfono">
                        </div>
                    </td>
                    <td>
                        <label class="admin-check">
                            <input form="<?= $formId ?>" type="checkbox" name="users[<?= $id ?>][is_admin]" value="1" <?= (int) $user['is_admin'] === 1 ? 'checked' : '' ?>>
                            Admin
                        </label>
                    </td>
                    <td class="admin-progress-cell">
                        <span class="admin-badge admin-badge-class">Clase <?= $class ?> de 8</span>
                        <div class="admin-progress <?= $tone ?>" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>" aria-label="Avance de <?= alfa_e($user['name']) ?>">
                            <span class="admin-progress-bar" style="width:<?= $percent ?>%"></span>
                        </div>
                        <small class="admin-progress-label"><?= $percent ?>% completado</small>
                        <input form="<?= $formId ?>" type="hidden" name="users[<?= $id ?>][numero_clase]" value="<?= $class ?>" data-progress-class>
                        <input form="<?= $formId ?>" type="hidden" name="users[<?= $id ?>][porcentaje_clase]" value="<?= $percent ?>" data-progress-percent>
                        <button type="button" class="profile-action-btn admin-progress-edit" data-progress-open data-user-name="<?= alfa_e($userName) ?>" data-class="<?= $class ?>" data-percent="<?= $percent ?>">✏️ Ajustar progreso</button>
                    </td>
                    <td>
                        <div class="admin-password-field">
                            <input form="<?= $formId ?>" type="password" name="users[<?= $id ?>][password]" placeholder="Nueva (opcional)" autocomplete="new-password" aria-label="Nueva contraseña">
                            <button type="button" class="admin-password-toggle" data-password-toggle aria-label="Mostrar contraseña">Ver</button>
                        </div>
                        <div class="admin-password-field">
                            <input form="<?= $formId ?>" type="password" name="users[<?= $id ?>][password_confirmation]" placeholder="Confirmar" autocomplete="new-password" aria-label="Confirmar contraseña">
                            <button type="button" class="admin-password-toggle" data-password-toggle aria-label="Mostrar contraseña">Ver</button>
                        </div>
                    </td>
                    <td class="admin-mascota-cell">
                        <?php if ($petPath !== null): ?>
                            <img src="<?= alfa_e($petPath) ?>" alt="Mascota de <?= alfa_e($userName) ?>" class="admin-mascota-thumb" onerror="this.style.display='none'">
                            <small class="admin-mascota-path" title="<?= alfa_e($petPath) ?>"><?= alfa_e($petPath) ?></small>
                        <?php else: ?>
                            <small>Predeterminada</small>
                        <?php endif; ?>
                        <label class="admin-mascota-name-label" for="pet-name-<?= $id ?>">Nombre</label>
                        <input form="<?= $formId ?>" id="pet-name-<?= $id ?>" name="users[<?= $id ?>][mascota_nombre]" maxlength="50" value="<?= alfa_e($user['mascota_nombre'] ?? '') ?>" placeholder="Nombre de la mascota">
                    </td>
                    <td class="admin-actions">
                        <form id="<?= $formId ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
                            <input type="hidden" name="accion" value="save_user">
                        </form>
                        <button class="profile-action-btn" type="submit" form="<?= $formId ?>">💾 Guardar cambios</button>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
                            <input type="hidden" name="accion" value="upload_mascota">
                            <input type="hidden" name="usuario_id" value="<?= $id ?>">
                            <label class="admin-mascota-upload-label" for="pet-upload-<?= $id ?>">Imagen de la mascota</label>
                            <input id="pet-upload-<?= $id ?>" type="file" name="mascota" accept="image/jpeg,image/png,image/gif,image/webp" required>
                            <button class="profile-action-btn" type="submit">Subir imagen</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
                            <input type="hidden" name="accion" value="remove_mascota">
                            <input type="hidden" name="usuario_id" value="<?= $id ?>">
                            <button class="profile-action-btn" type="submit">🚫 Eliminar mascota</button>
                        </form>
                        <?php if ($id !== (int) alfa_usuario()['id']): ?>
                            <form method="post" onsubmit="return confirm('¿Eliminar este usuario? Esta acción no se puede deshacer.');">
                                <input type="hidden" name="csrf_token" value="<?= alfa_e($csrfToken) ?>">
                                <input type="hidden" name="accion" value="delete_user">
                                <input type="hidden" name="usuario_id" value="<?= $id ?>">
                                <button class="logout-btn" type="submit">🗑️ Eliminar usuario</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <button class="btn-gold admin-save-all" type="button" id="admin-save-all">Guardar todos los cambios</button>
</div>
</main>

<aside class="search-panel" id="search-panel" aria-label="Resultados de búsqueda" hidden>
    <button type="button" class="search-panel-close" id="search-panel-close" aria-label="Cerrar resultados">×</button>
    <h3 class="search-panel-title">🔍 Resultados</h3>
    <p class="search-panel-hint" id="search-panel-hint"></p>
    <div class="search-panel-results" id="search-panel-results"></div>
</aside>
<div class="search-scrim" id="search-scrim" hidden></div>

<div class="admin-modal-scrim" id="progress-modal-scrim" hidden></div>
<div class="admin-modal" id="progress-modal" role="dialog" aria-modal="true" aria-labelledby="progress-modal-title" hidden>
    <h3 id="progress-modal-title">Ajustar progreso</h3>
    <p class="admin-modal-sub" id="progress-modal-user"></p>
    <label for="progress-modal-class">Clase actual</label>
    <select id="progress-modal-class">
        <?php for ($class = 0; $class <= 8; $class++): ?><option value="<?= $class ?>">Clase <?= $class ?></option><?php endfor; ?>
    </select>
    <label for="progress-modal-percent">Porcentaje de avance</label>
    <div class="admin-modal-range">
        <input type="range" id="progress-modal-range" min="0" max="100" step="5" value="0">
        <output id="progress-modal-output">0%</output>
    </div>
    <input type="number" id="progress-modal-percent" min="0" max="100" step="1" value="0">
    <div class="admin-modal-actions">
        <button type="button" class="btn-gold" id="progress-modal-save">Guardar en el formulario</button>
        <button type="button" class="profile-action-btn" id="progress-modal-cancel">Cancelar</button>
    </div>
    <p class="admin-modal-hint">Los cambios se aplican al formulario. Pulsa “Guardar cambios” en el usuario o “Guardar todos los cambios” para confirmarlos.</p>
</div>

<aside class="mascota" id="mascota-container" data-mascota-available="0" aria-label="Mascota" hidden>
    <button type="button" class="mascota-trigger" id="mascota-trigger" aria-label="Escuchar mensaje de la mascota">
        <img id="mascota-img" alt="Tu mascota" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" style="display:none;">
    </button>
    <span class="mascota-nombre" id="mascota-nombre" hidden></span>
    <div id="mascota-dialogo" class="mascota-dialogo" role="status" aria-live="polite"></div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('progress-modal');
    const scrim = document.getElementById('progress-modal-scrim');
    const title = document.getElementById('progress-modal-user');
    const classSelect = document.getElementById('progress-modal-class');
    const range = document.getElementById('progress-modal-range');
    const output = document.getElementById('progress-modal-output');
    const number = document.getElementById('progress-modal-percent');
    const search = document.getElementById('admin-user-search');
    const createClass = document.querySelector('[data-create-class]');
    const createPercent = document.querySelector('[data-create-percent]');
    const createProgress = document.querySelector('[data-create-progress]');
    const createProgressBar = document.querySelector('[data-create-progress-bar]');
    const createClassLabel = document.querySelector('[data-create-class-label]');
    const createPercentLabel = document.querySelector('[data-create-percent-label]');
    let currentRow = null;

    function sync(source, value) {
        const percent = Math.min(100, Math.max(0, Number(value) || 0));
        range.value = String(percent);
        number.value = String(percent);
        output.textContent = percent + '%';
        source.value = String(percent);
        return percent;
    }

    function closeModal() {
        modal.hidden = true;
        scrim.hidden = true;
        currentRow = null;
    }

    document.querySelectorAll('[data-progress-open]').forEach((button) => {
        button.addEventListener('click', () => {
            currentRow = button.closest('tr');
            title.textContent = (button.dataset.userName || 'Usuario').trim();
            classSelect.value = button.dataset.class;
            sync(number, button.dataset.percent);
            modal.hidden = false;
            scrim.hidden = false;
        });
    });
    range.addEventListener('input', () => sync(number, range.value));
    number.addEventListener('input', () => sync(range, number.value));

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('input');
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.textContent = visible ? 'Ocultar' : 'Ver';
            button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });

    function updateCreateProgress() {
        const percent = Math.min(100, Math.max(0, Number(createPercent.value) || 0));
        const classNumber = Number(createClass.value) || 0;
        createClassLabel.textContent = 'Clase ' + classNumber + ' de 8';
        createProgressBar.style.width = percent + '%';
        createProgress.setAttribute('aria-valuenow', String(percent));
        createProgress.classList.toggle('is-low', percent < 50);
        createProgress.classList.toggle('is-mid', percent >= 50 && percent < 100);
        createProgress.classList.toggle('is-full', percent >= 100);
        createPercentLabel.textContent = String(percent);
    }
    createClass.addEventListener('change', updateCreateProgress);
    createPercent.addEventListener('input', updateCreateProgress);

    search.addEventListener('input', () => {
        const term = search.value.trim().toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        let visibleRows = 0;
        document.querySelectorAll('[data-user-row]').forEach((row) => {
            const text = row.dataset.searchName.toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            row.hidden = !text.includes(term);
            if (!row.hidden) visibleRows++;
        });
        document.querySelector('.admin-table-wrap').hidden = term !== '' && visibleRows === 0;
        document.getElementById('admin-save-all').hidden = term !== '' && visibleRows === 0;
    });

    document.getElementById('progress-modal-save').addEventListener('click', () => {
        if (!currentRow) return;
        const percent = sync(number, number.value);
        currentRow.querySelector('[data-progress-class]').value = classSelect.value;
        currentRow.querySelector('[data-progress-percent]').value = String(percent);
        currentRow.querySelector('.admin-badge-class').textContent = 'Clase ' + classSelect.value + ' de 8';
        const progress = currentRow.querySelector('.admin-progress');
        progress.querySelector('.admin-progress-bar').style.width = percent + '%';
        progress.classList.toggle('is-low', percent < 50);
        progress.classList.toggle('is-mid', percent >= 50 && percent < 100);
        progress.classList.toggle('is-full', percent >= 100);
        progress.setAttribute('aria-valuenow', String(percent));
        currentRow.querySelector('.admin-progress-label').textContent = percent + '% completado';
        closeModal();
    });

    document.getElementById('admin-create-preview').addEventListener('click', () => {
        const form = document.querySelector('.admin-create-form');
        const data = new FormData(form);
        const fields = [
            ['Nombre', data.get('name')],
            ['Apellido', data.get('apellido')],
            ['Correo', data.get('email')],
            ['Teléfono', [data.get('telefono_prefijo'), data.get('telefono')].filter(Boolean).join(' ')],
            ['Clase actual', 'Clase ' + data.get('numero_clase') + ' de 8'],
            ['Porcentaje de avance', data.get('porcentaje_clase') + '%'],
            ['Contraseña', data.get('password') ? 'Definida' : 'Sin cambios'],
        ];
        const list = document.getElementById('admin-create-preview-list');
        list.replaceChildren();
        fields.forEach(([label, value]) => {
            const item = document.createElement('li');
            const key = document.createElement('span');
            key.textContent = label + ': ';
            item.append(key, document.createTextNode(String(value || '(vacío)')));
            list.appendChild(item);
        });
        const box = document.getElementById('admin-create-preview-box');
        box.hidden = !box.hidden;
        document.getElementById('admin-create-preview').textContent = box.hidden ? '👁️ Ver datos' : '🙈 Ocultar datos';
    });

    document.getElementById('admin-save-all').addEventListener('click', () => {
        const bulkForm = document.getElementById('admin-bulk-update');
        bulkForm.querySelectorAll('[data-bulk-generated]').forEach((input) => input.remove());
        document.querySelectorAll('form[id^="admin-user-update-"]').forEach((userForm) => {
            for (const [name, value] of new FormData(userForm)) {
                if (name === 'csrf_token' || name === 'accion') continue;
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                input.dataset.bulkGenerated = 'true';
                bulkForm.appendChild(input);
            }
        });
        bulkForm.requestSubmit();
    });

    document.getElementById('progress-modal-cancel').addEventListener('click', closeModal);
    scrim.addEventListener('click', closeModal);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeModal();
    });
});
</script>
</body>
</html>
