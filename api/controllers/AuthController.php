<?php
// ============================================================
//  DIGITALMARKET — api/controllers/AuthController.php
//  POST /api/auth/register
//  POST /api/auth/login
//  POST /api/auth/logout
//  GET  /api/auth/me
// ============================================================

require_once __DIR__ . '/../../api/middleware/auth.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match(true) {
    $method === 'POST' && $action === 'register' => handleRegister(),
    $method === 'POST' && $action === 'login'    => handleLogin(),
    $method === 'POST' && $action === 'logout'   => handleLogout(),
    $method === 'GET'  && $action === 'me'       => handleMe(),
    default => jsonErr('Rota não encontrada.', 404)
};

// ── REGISTO ───────────────────────────────────────────────────
function handleRegister(): void {
    $body = getJsonBody();

    $name     = sanitize($body['name']     ?? '');
    $email    = strtolower(trim($body['email']    ?? ''));
    $pass     = $body['password']  ?? '';
    $pass2    = $body['password2'] ?? '';
    $role     = $body['role']      ?? 'buyer';

    // Validações (#18)
    $errs = [];
    if (!$name || count(array_filter(explode(' ', $name))) < 2)
        $errs[] = 'Nome completo obrigatório (nome e apelido)';
    if (!$email)
        $errs[] = 'Email obrigatório';
    elseif (!validateEmail($email))
        $errs[] = 'Apenas emails @piaget.pt são aceites';
    if (!$pass)
        $errs[] = 'Password obrigatória';
    elseif (!validatePasswordStrength($pass))
        $errs[] = 'Password fraca — mínimo 8 chars, 1 maiúscula, 1 número';
    if ($pass !== $pass2)
        $errs[] = 'As passwords não coincidem';
    if (!in_array($role, ['buyer','seller']))
        $errs[] = 'Papel inválido';

    if ($errs) jsonErr(implode(' | ', $errs));

    $db = getDB();

    // Verifica duplicado (#17)
    $st = $db->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) jsonErr('Este email já está registado.');

    $colors = ['#7c6cfc','#2dd4bf','#f97316','#fb7185','#f59e0b','#4ade80'];
    $color  = $colors[rand(0, count($colors) - 1)];

    $st = $db->prepare(
        'INSERT INTO users (name, email, password, role, color) VALUES (?,?,?,?,?)'
    );
    $st->execute([$name, $email, hashPassword($pass), $role, $color]);
    $userId = (int) $db->lastInsertId();

    // Criar perfil do vendedor
    if ($role === 'seller') {
        $db->prepare('INSERT INTO seller_profiles (user_id) VALUES (?)')
           ->execute([$userId]);
    }

    $token = jwtCreate(['id' => $userId, 'email' => $email, 'role' => $role, 'name' => $name, 'color' => $color]);
    jsonOk(['token' => $token, 'user' => compact('userId','name','email','role','color')], 201);
}

// ── LOGIN (#17) ───────────────────────────────────────────────
function handleLogin(): void {
    $body  = getJsonBody();
    $email = strtolower(trim($body['email']    ?? ''));
    $pass  = $body['password'] ?? '';

    if (!$email || !$pass) jsonErr('Email e password obrigatórios.');

    $db = getDB();
    $st = $db->prepare('SELECT id,name,email,password,role,color FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user)                           jsonErr('Email não encontrado.',   401);
    if (!verifyPassword($pass, $user['password'])) jsonErr('Password incorreta.', 401);

    $token = jwtCreate([
        'id'    => $user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
        'name'  => $user['name'],
        'color' => $user['color'],
    ]);

    // Guardar token na BD (para poder invalidar)
    $db->prepare(
        'INSERT INTO sessions (user_id, token, expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 7 DAY))'
    )->execute([$user['id'], $token]);

    unset($user['password']);
    jsonOk(['token' => $token, 'user' => $user]);
}

// ── LOGOUT ────────────────────────────────────────────────────
function handleLogout(): void {
    $auth = getAuthorizationHeader();
    if ($auth && str_starts_with($auth, 'Bearer ')) {
        $token = substr($auth, 7);
        getDB()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
    }
    jsonOk(['message' => 'Sessão terminada.']);
}

// ── ME (dados do utilizador actual) ──────────────────────────
function handleMe(): void {
    $user = requireAuth();
    $db   = getDB();
    $st   = $db->prepare('SELECT id,name,email,role,color,bio,portfolio,skill FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $data = $st->fetch();
    if (!$data) jsonErr('Utilizador não encontrado.', 404);
    jsonOk($data);
}
