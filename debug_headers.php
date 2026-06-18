<?php
// ============================================================
//  DIGITALMARKET — debug_headers.php
//  FICHEIRO TEMPORÁRIO DE DIAGNÓSTICO — apagar depois de usar!
//  Mostra exactamente o que o PHP recebe do Apache.
// ============================================================

header('Content-Type: application/json; charset=utf-8');

$result = [
    'HTTP_AUTHORIZATION_in_SERVER'          => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'REDIRECT_HTTP_AUTHORIZATION_in_SERVER' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    'getallheaders_exists'                  => function_exists('getallheaders'),
    'apache_request_headers_exists'         => function_exists('apache_request_headers'),
    'getallheaders_result'                  => function_exists('getallheaders') ? getallheaders() : null,
    'apache_request_headers_result'         => function_exists('apache_request_headers') ? apache_request_headers() : null,
    'php_sapi'                              => php_sapi_name(),
    'all_SERVER_keys_with_AUTH_or_HTTP'     => array_filter(
        array_keys($_SERVER),
        fn($k) => str_contains($k, 'AUTH') || str_starts_with($k, 'HTTP_')
    ),
];

echo json_encode($result, JSON_PRETTY_PRINT);
