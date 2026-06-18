<?php
// ============================================================
//  DIGITALMARKET — api/index.php
//  Router principal — todas as rotas passam por aqui
//
//  Configurar no Apache/XAMPP:
//    htdocs/digitalmarket/api/  ←  esta pasta
//  Endpoint base: http://localhost/digitalmarket/api/
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');

$uri = $_SERVER['REQUEST_URI'];
$uri = preg_replace('#^.*?/api#', '', $uri); // remove prefixo até /api
$path = trim(parse_url($uri, PHP_URL_PATH), '/');
$segments = explode('/', $path);

// Primeiro segmento decide o controller: auth, products, cart, checkout, dashboard, profile
$resource = $segments[0] ?? '';

$routes = [
    'auth'      => 'controllers/AuthController.php',
    'products'  => 'controllers/ProductsController.php',
    'cart'      => 'controllers/CartController.php',
    'checkout'  => 'controllers/CartController.php',   // mesma controller, rota dedicada
    'dashboard' => 'controllers/DashboardController.php',
    'profile'   => 'controllers/ProfileController.php',
];

if (!isset($routes[$resource])) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Rota '$resource' não encontrada."]);
    exit;
}

// Para /auth/register, /auth/login, etc. — passa o 2º segmento como ?action=
if ($resource === 'auth' && isset($segments[1])) {
    $_GET['action'] = $segments[1];
}
if ($resource === 'dashboard' && isset($segments[1])) {
    $_GET['action'] = $segments[1];
}
// /products/download?id=X — rota especial de download protegido (#16)
if ($resource === 'products' && isset($segments[1])) {
    $_GET['action'] = $segments[1];
}

require __DIR__ . '/' . $routes[$resource];
