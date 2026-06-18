<?php
// ============================================================
//  DIGITALMARKET — api/controllers/ProfileController.php
//  GET /api/profile        — perfil do utilizador actual
//  PUT /api/profile        — actualizar bio/portfolio/skill
// ============================================================

require_once __DIR__ . '/../../api/middleware/auth.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];

match($method) {
    'GET' => handleGetProfile(),
    'PUT' => handleUpdateProfile(),
    default => jsonErr('Método não suportado.', 405)
};

function handleGetProfile(): void {
    $user = requireAuth();
    $db   = getDB();
    $st = $db->prepare('
        SELECT u.id, u.name, u.email, u.role, u.color, u.created_at,
               sp.bio, sp.portfolio, sp.skill, sp.linkedin
        FROM users u
        LEFT JOIN seller_profiles sp ON sp.user_id = u.id
        WHERE u.id = ?');
    $st->execute([$user['id']]);
    $data = $st->fetch();
    if (!$data) jsonErr('Utilizador não encontrado.', 404);
    jsonOk($data);
}

function handleUpdateProfile(): void {
    $user = requireAuth();
    $body = getJsonBody();

    $bio       = sanitize($body['bio']       ?? '');
    $portfolio = sanitize($body['portfolio'] ?? '');
    $skill     = sanitize($body['skill']     ?? '');
    $linkedin  = sanitize($body['linkedin']  ?? '');

    $db = getDB();
    $db->prepare('
        INSERT INTO seller_profiles (user_id, bio, portfolio, skill, linkedin)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE bio=?, portfolio=?, skill=?, linkedin=?'
    )->execute([
        $user['id'], $bio, $portfolio, $skill, $linkedin,
        $bio, $portfolio, $skill, $linkedin
    ]);

    jsonOk(['message' => 'Perfil actualizado com sucesso.']);
}
