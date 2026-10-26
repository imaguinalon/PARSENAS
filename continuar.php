<?php
/**
 * continuar.php — Redirige al usuario a su última clase/paso (copia alfa).
 *
 * Equivale a la ruta /continuar de parsenas: lee numero_clase del usuario
 * en sesión (0 si es invitado) y envía a la clase correspondiente.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

$usuario = alfa_usuario();
$numeroClase = (int) ($usuario['numero_clase'] ?? 0);
$porcentaje = (int) ($usuario['porcentaje_clase'] ?? 0);

if ($numeroClase < 0 || $numeroClase > 8) {
    $numeroClase = 0;
}

// Invitados: siempre comienzan en la Clase 0.
// La clase actual solo cambia después de que evaluacion.php registra la
// aprobación; el porcentaje por sí solo no desbloquea otra clase.
$paso = 1;
if ($usuario !== null) {
    $totales = [9, 17, 9, 9, 9, 9, 9, 9, 9];
    $total = $totales[$numeroClase] ?? 9;
    $paso = max(1, min($total, (int) round(($porcentaje / 100) * $total)));
}

header('Location: clase-'.$numeroClase.'.html?paso='.$paso);
exit;
