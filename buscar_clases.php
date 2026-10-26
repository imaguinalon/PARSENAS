<?php
/**
 * buscar_clases.php — Búsqueda temática de clases para index.html.
 */

declare(strict_types=1);

require __DIR__.'/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

$queryValue = $_GET['q'] ?? '';
if (!is_string($queryValue)) {
    http_response_code(422);
    echo json_encode(['error' => 'La búsqueda no es válida.']);
    exit;
}
$query = trim($queryValue);
if (mb_strlen($query) > 120) {
    http_response_code(422);
    echo json_encode(['error' => 'La búsqueda no puede superar 120 caracteres.']);
    exit;
}

$classes = [
    ['number' => 0, 'title' => 'Introducción a la LSC', 'description' => 'Qué es la Lengua de Señas Colombiana, comunicación visual, comunidad sorda y expresiones faciales.', 'keywords' => 'introducción lengua señas colombia lsc comunicación comunidad sorda manos rostro cuerpo expresiones aprendizaje inclusión cultura visual'],
    ['number' => 1, 'title' => 'Alfabeto y deletreo', 'description' => 'Letras, abecedario manual, formar palabras y deletrear nombres.', 'keywords' => 'alfabeto abecedario letras deletrear deletreo mano nombre escribir palabra vocal consonante'],
    ['number' => 2, 'title' => 'Saludos y cortesía', 'description' => 'Hola, buenos días, despedidas, presentarse, por favor y gracias.', 'keywords' => 'saludos hola buenos días tardes noches adiós despedida gracias por favor cortesía presentarse bienvenida'],
    ['number' => 3, 'title' => 'Familia', 'description' => 'Señas para padres, madres, hermanos, hijos, abuelos y demás familiares.', 'keywords' => 'familia familiar papá mamá padre madre hermano hermana hijo hija abuelo abuela pariente parentesco hogar'],
    ['number' => 4, 'title' => 'Números, fechas y tiempo', 'description' => 'Contar, números, edad, días, meses, fechas, calendario y hora.', 'keywords' => 'número números contar cantidad edad cumpleaños fecha calendario día semana mes año hora reloj tiempo cuándo'],
    ['number' => 5, 'title' => 'Lugares cotidianos', 'description' => 'Casa, escuela, ciudad, trabajo, tiendas y cómo dar indicaciones.', 'keywords' => 'lugar lugares casa hogar escuela colegio ciudad calle tienda trabajo mercado parque baño dirección dónde ubicación'],
    ['number' => 6, 'title' => 'Colores y alimentos', 'description' => 'Colores, frutas, comidas, bebidas e ingredientes comunes.', 'keywords' => 'color colores rojo azul verde amarillo comida alimento alimentos fruta verduras comer beber bebida agua pan desayuno almuerzo cena'],
    ['number' => 7, 'title' => 'Emociones', 'description' => 'Expresar sentimientos, estados de ánimo y emociones.', 'keywords' => 'emoción emociones sentimiento sentimientos feliz alegría triste tristeza enojo enojado miedo temor amor ánimo sentir estado'],
    ['number' => 8, 'title' => 'Conversación cotidiana', 'description' => 'Iniciar, mantener y cerrar conversaciones y diálogos en LSC.', 'keywords' => 'conversación conversar charla diálogo dialogar hablar preguntar responder comunicación práctica cotidiano frases interacción'],
];

$synonyms = [
    'abecedario' => ['alfabeto', 'letras'],
    'adios' => ['despedida', 'saludos'],
    'alimentacion' => ['comida', 'alimentos'],
    'animo' => ['emociones', 'sentimientos'],
    'bebida' => ['beber', 'alimentos'],
    'cumpleanos' => ['fecha', 'edad'],
    'deletreo' => ['deletrear', 'alfabeto'],
    'dialogo' => ['conversacion', 'charla'],
    'escribir' => ['letras', 'alfabeto'],
    'familiares' => ['familia', 'parientes'],
    'gestos' => ['senas', 'manos', 'expresiones'],
    'parientes' => ['familia', 'parentesco'],
    'sentir' => ['emociones', 'sentimientos'],
    'ubicacion' => ['lugares', 'direccion'],
    'vocabulario' => ['palabras', 'senas'],
];

$normalize = static function (string $value): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($value, 'UTF-8'));
    $normalized = preg_replace('/[^a-z0-9]+/', ' ', $ascii === false ? strtolower($value) : strtolower($ascii));

    return trim($normalized ?? '');
};

$terms = array_values(array_unique(array_filter(
    explode(' ', $normalize($query)),
    static fn (string $term): bool => strlen($term) > 1
)));
$expandedTerms = $terms;
foreach ($terms as $term) {
    foreach ($synonyms[$term] ?? [] as $synonym) {
        $expandedTerms[] = $normalize($synonym);
    }
}
$expandedTerms = array_values(array_unique($expandedTerms));
$directClass = preg_match('/^clase\s+([0-8])$/', $normalize($query), $matches) === 1
    ? (int) $matches[1]
    : null;
$user = alfa_usuario();
$maxClass = (int) ($user['numero_clase'] ?? 0);

foreach ($classes as &$class) {
    $searchable = $normalize(implode(' ', [$class['title'], $class['description'], $class['keywords']]));
    $score = $directClass === $class['number'] ? 100 : 0;

    foreach ($terms as $term) {
        if (str_contains($searchable, $term)) {
            $score += 3;
        }
    }
    foreach (array_diff($expandedTerms, $terms) as $term) {
        if (str_contains($searchable, $term)) {
            $score += 1;
        }
    }
    $normalizedQuery = $normalize($query);
    if ($normalizedQuery !== '' && str_contains($searchable, $normalizedQuery)) {
        $score += 5;
    }

    $class['score'] = $score;
    $class['accessible'] = $class['number'] === 0 || ($user !== null && $class['number'] <= $maxClass);
    $class['url'] = $class['accessible'] ? 'clase-'.$class['number'].'.html' : null;
}
unset($class);

$hasMatches = count(array_filter($classes, static fn (array $class): bool => $class['score'] > 0)) > 0;
if ($hasMatches) {
    $classes = array_values(array_filter($classes, static fn (array $class): bool => $class['score'] > 0));
    usort($classes, static fn (array $a, array $b): int => ($b['score'] <=> $a['score'])
        ?: ($a['number'] <=> $b['number']));
}

echo json_encode([
    'query' => $query,
    'has_matches' => $hasMatches,
    'results' => array_map(static fn (array $class): array => [
        'number' => $class['number'],
        'title' => $class['title'],
        'description' => $class['description'],
        'accessible' => $class['accessible'],
        'url' => $class['url'],
    ], $classes),
], JSON_UNESCAPED_UNICODE);
