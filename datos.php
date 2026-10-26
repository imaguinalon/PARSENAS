<?php
declare(strict_types=1);

require __DIR__.'/config.php';
$usuario = alfa_usuario();
$errors = $_SESSION['contacto_errors'] ?? [];
$old = $_SESSION['contacto_old'] ?? [];
$enviado = !empty($_SESSION['contacto_enviado']);
unset($_SESSION['contacto_errors'], $_SESSION['contacto_old'], $_SESSION['contacto_enviado']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contáctanos - PARSEÑAS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="base.css?v=alfa-mascota-position-4">
    <script defer src="inicio.js?v=alfa-pet-upload-1"></script>
</head>
<body>
<header class="top-bar" aria-label="Navegación principal"></header>
<main>
    <section class="contact-page">
        <span class="section-label">Estamos para escucharte</span>
        <h1 class="section-title">Contáctanos</h1>
        <p class="section-sub">Envíanos una queja o recomendación. El equipo de administración la recibirá en su buzón.</p>

        <?php if ($enviado): ?>
            <p class="contact-status" role="status">Tu mensaje fue enviado correctamente. Gracias por escribirnos.</p>
        <?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="contact-status contact-errors" role="alert">
                <p>Revisa la información del formulario:</p>
                <ul><?php foreach ($errors as $message): ?><li><?= alfa_e((string) $message) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form class="contact-form" method="post" action="contacto.php">
            <input type="hidden" name="csrf_token" value="<?= alfa_e(alfa_csrf_token()) ?>">
            <?php if ($usuario === null): ?>
                <label for="contact-name">Nombre
                    <input id="contact-name" name="name" maxlength="255" required value="<?= alfa_e((string) ($old['name'] ?? '')) ?>" autocomplete="name">
                </label>
                <label for="contact-email">Correo electrónico
                    <input id="contact-email" type="email" name="email" maxlength="255" required value="<?= alfa_e((string) ($old['email'] ?? '')) ?>" autocomplete="email">
                </label>
            <?php else: ?>
                <p class="contact-identity">Enviado por: <?= alfa_e((string) $usuario['name']) ?> (usuario registrado)</p>
            <?php endif; ?>
            <label for="contact-category">Tipo de mensaje
                <select id="contact-category" name="category" required>
                    <option value="queja" <?= ($old['category'] ?? '') === 'queja' ? 'selected' : '' ?>>Queja</option>
                    <option value="recomendacion" <?= ($old['category'] ?? '') === 'recomendacion' ? 'selected' : '' ?>>Recomendación</option>
                </select>
            </label>
            <label for="contact-subject">Asunto
                <input id="contact-subject" name="subject" maxlength="150" required value="<?= alfa_e((string) ($old['subject'] ?? '')) ?>">
            </label>
            <label for="contact-body">Mensaje
                <textarea id="contact-body" name="body" rows="7" minlength="5" maxlength="5000" required><?= alfa_e((string) ($old['body'] ?? '')) ?></textarea>
            </label>
            <button class="btn-gold" type="submit">Enviar al buzón</button>
        </form>
    </section>
</main>
</body>
</html>
