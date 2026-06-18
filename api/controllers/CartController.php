<?php
// ============================================================
//  DIGITALMARKET — api/controllers/CartController.php
//  GET    /api/cart            — listar itens do carrinho
//  POST   /api/cart            — adicionar produto
//  DELETE /api/cart?id=X       — remover item
//  POST   /api/checkout        — finalizar compra (#13/#14)
// ============================================================

require_once __DIR__ . '/../../api/middleware/auth.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isCheckout = str_contains($path, 'checkout');

match(true) {
    $isCheckout && $method === 'POST' => handleCheckout(),
    $method === 'GET'                 => handleCartList(),
    $method === 'POST'                => handleCartAdd(),
    $method === 'DELETE'               => handleCartRemove(),
    default => jsonErr('Rota não encontrada.', 404)
};

// ── LISTAR CARRINHO ───────────────────────────────────────────
function handleCartList(): void {
    $user = requireAuth();
    $db   = getDB();
    $st = $db->prepare("
        SELECT c.id AS cart_id, p.id, p.title, p.price, p.category, p.type,
               p.file_name, u.name AS seller_name
        FROM cart_items c
        JOIN products p ON c.product_id = p.id
        JOIN users u    ON p.seller_id  = u.id
        WHERE c.user_id = ? AND p.is_active = 1
        ORDER BY c.added_at DESC");
    $st->execute([$user['id']]);
    jsonOk($st->fetchAll());
}

// ── ADICIONAR AO CARRINHO ─────────────────────────────────────
function handleCartAdd(): void {
    $user = requireAuth();
    $body = getJsonBody();
    $productId = (int)($body['product_id'] ?? 0);
    if (!$productId) jsonErr('ID do produto obrigatório.');

    $db = getDB();
    $st = $db->prepare('SELECT id FROM products WHERE id=? AND is_active=1');
    $st->execute([$productId]);
    if (!$st->fetch()) jsonErr('Produto não encontrado.', 404);

    $db->prepare('INSERT IGNORE INTO cart_items (user_id, product_id) VALUES (?,?)')
       ->execute([$user['id'], $productId]);

    jsonOk(['message' => 'Adicionado ao carrinho.']);
}

// ── REMOVER DO CARRINHO ───────────────────────────────────────
function handleCartRemove(): void {
    $user = requireAuth();
    $productId = (int)($_GET['id'] ?? 0);
    getDB()->prepare('DELETE FROM cart_items WHERE user_id=? AND product_id=?')
           ->execute([$user['id'], $productId]);
    jsonOk(['message' => 'Removido do carrinho.']);
}

// ── CHECKOUT COMPLETO COM VALIDAÇÃO (#13, #14) ────────────────
function handleCheckout(): void {
    $user = requireAuth();
    $body = getJsonBody();

    $name     = sanitize($body['name']  ?? '');
    $email    = strtolower(trim($body['email'] ?? ''));
    $message  = sanitize($body['message'] ?? '');
    $cardNum  = preg_replace('/\s/', '', $body['card_number'] ?? '');
    $cardExp  = $body['card_exp'] ?? '';
    $cardCvv  = $body['card_cvv'] ?? '';
    $cardName = sanitize($body['card_name'] ?? '');

    $db = getDB();

    // 1. Buscar itens do carrinho
    $st = $db->prepare("
        SELECT p.* FROM cart_items c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ? AND p.is_active = 1");
    $st->execute([$user['id']]);
    $items = $st->fetchAll();

    if (!$items) jsonErr('Carrinho vazio.');

    $total = array_sum(array_column($items, 'price'));
    $errs  = [];

    // Validação de dados pessoais
    if (!$name || count(array_filter(explode(' ', $name))) < 2)
        $errs[] = 'Nome completo obrigatório';
    if (!$email || !validateEmail($email))
        $errs[] = 'Email @piaget.pt obrigatório';

    // Validação de cartão SÓ se houver itens pagos (#14)
    if ($total > 0) {
        if (!preg_match('/^\d{16}$/', $cardNum))
            $errs[] = 'Número de cartão inválido (16 dígitos)';
        elseif (!luhnCheck($cardNum))
            $errs[] = 'Número de cartão inválido (falhou verificação Luhn)';

        if (!preg_match('/^\d{2}\/\d{2}$/', $cardExp)) {
            $errs[] = 'Validade inválida (formato MM/AA)';
        } else {
            [$mm, $yy] = array_map('intval', explode('/', $cardExp));
            $expYear  = 2000 + $yy;
            $now      = new DateTime();
            if ($mm < 1 || $mm > 12) $errs[] = 'Mês inválido';
            elseif ($expYear < (int)$now->format('Y') ||
                   ($expYear === (int)$now->format('Y') && $mm < (int)$now->format('n')))
                $errs[] = 'Cartão expirado';
        }
        if (!preg_match('/^\d{3,4}$/', $cardCvv)) $errs[] = 'CVV inválido';
        if (!$cardName) $errs[] = 'Nome no cartão obrigatório';
    }

    if ($errs) jsonErr(implode(' | ', $errs));

    // 2. Processar a compra (transacção)
    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            $db->prepare("
                INSERT INTO purchases
                  (buyer_id, product_id, seller_id, amount, buyer_name, buyer_email, message)
                VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $user['id'], $item['id'], $item['seller_id'],
                $item['price'], $name, $email, $message
            ]);
            $purchaseId = (int) $db->lastInsertId();

            $db->prepare('INSERT INTO downloads (purchase_id, user_id, product_id) VALUES (?,?,?)')
               ->execute([$purchaseId, $user['id'], $item['id']]);

            $db->prepare('UPDATE products SET sales_count = sales_count + 1 WHERE id = ?')
               ->execute([$item['id']]);
        }

        // Limpar carrinho
        $db->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$user['id']]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        jsonErr('Erro ao processar a compra: ' . $e->getMessage(), 500);
    }

    jsonOk([
        'message' => 'Compra concluída com sucesso!',
        'total'   => $total,
        'items'   => array_map(fn($i) => [
            'id' => $i['id'], 'title' => $i['title'],
            'type' => $i['type'], 'file_name' => $i['file_name']
        ], $items)
    ], 201);
}

function luhnCheck(string $num): bool {
    $sum = 0; $alt = false;
    for ($i = strlen($num) - 1; $i >= 0; $i--) {
        $n = (int)$num[$i];
        if ($alt) { $n *= 2; if ($n > 9) $n -= 9; }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}
