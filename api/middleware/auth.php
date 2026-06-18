<?php
// ============================================================
//  DIGITALMARKET — api/middleware/auth.php
//  JWT manual (sem biblioteca) + helpers de resposta
// ============================================================

require_once __DIR__ . '/../../config/database.php';

// ── Cabeçalhos CORS + JSON ────────────────────────────────────
function setCorsHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// ── Resposta JSON padronizada ─────────────────────────────────
function jsonOk(mixed $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ── JWT simples (HS256) ───────────────────────────────────────
function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwtCreate(array $payload): string {
    $header  = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_EXPIRE;
    $payload['iat'] = time();
    $body    = base64UrlEncode(json_encode($payload));
    $sig     = base64UrlEncode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwtVerify(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64UrlEncode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64UrlDecode($body), true);
    if (!$payload || $payload['exp'] < time()) return null;
    return $payload;
}


function getAuthorizationHeader(): string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) return $value;
        }
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) return $value;
        }
    }
    return '';
}

// ── Obter utilizador autenticado do header Authorization ──────
function requireAuth(): array {
    $auth = getAuthorizationHeader();
    if (!$auth || !str_starts_with($auth, 'Bearer ')) {
        jsonErr('Token de autenticação em falta.', 401);
    }
    $token   = substr($auth, 7);
    $payload = jwtVerify($token);
    if (!$payload) jsonErr('Token inválido ou expirado.', 401);
    return $payload;
}

// ── Apenas roles específicos ──────────────────────────────────
function requireRole(string ...$roles): array {
    $user = requireAuth();
    if (!in_array($user['role'], $roles)) {
        jsonErr('Sem permissão para esta operação.', 403);
    }
    return $user;
}

// ── Corpo JSON do pedido ──────────────────────────────────────
function getJsonBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ── Hashear e verificar password ─────────────────────────────
function hashPassword(string $pass): string {
    return password_hash($pass, PASSWORD_BCRYPT, ['cost' => 10]);
}
function verifyPassword(string $pass, string $hash): bool {
    return password_verify($pass, $hash);
}

// ── Validações comuns ─────────────────────────────────────────
function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && str_ends_with(strtolower($email), '@piaget.pt');
}

function validatePasswordStrength(string $pass): bool {
    return strlen($pass) >= 8
        && preg_match('/[A-Z]/', $pass)
        && preg_match('/[0-9]/', $pass);
}

function sanitize(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}
