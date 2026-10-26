<?php
/**
 * cerrar_sesion.php — Cierre de sesión (copia alfa de PARSEÑAS).
 * Destruye la sesión y vuelve a la página de inicio.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

header('Location: index.html');
exit;
