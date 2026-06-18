<?php
// ============================================================
//  DIGITALMARKET — api/controllers/DashboardController.php
//  GET /api/dashboard            — KPIs + produtos + vendas (#20)
//  GET /api/dashboard/downloads  — Meus downloads (#16)
//  GET /api/dashboard/users      — Admin: gerir utilizadores (#19)
// ============================================================

require_once __DIR__ . '/../../api/middleware/auth.php';

setCorsHeaders();

$action = $_GET['action'] ?? 'summary';

match($action) {
    'downloads' => handleMyDownloads(),
    'users'     => handleUsersList(),
    default     => handleDashboardSummary(),
};

// ── RESUMO DO DASHBOARD (#20) ─────────────────────────────────
function handleDashboardSummary(): void {
    $user = requireRole('seller', 'admin');
    $db   = getDB();
    $isAdmin = $user['role'] === 'admin';

    // Produtos (admin vê todos; seller só os seus) (#19)
    $prodSql = $isAdmin
        ? "SELECT pr.*, u.name AS seller_name, u.email AS seller_email
           FROM products pr JOIN users u ON pr.seller_id = u.id
           WHERE pr.is_active = 1 ORDER BY pr.created_at DESC"
        : "SELECT pr.*, u.name AS seller_name, u.email AS seller_email
           FROM products pr JOIN users u ON pr.seller_id = u.id
           WHERE pr.is_active = 1 AND pr.seller_id = ? ORDER BY pr.created_at DESC";
    $st = $db->prepare($prodSql);
    $isAdmin ? $st->execute() : $st->execute([$user['id']]);
    $products = $st->fetchAll();

    // Estatísticas por produto individual (#20)
    foreach ($products as &$p) {
        $sst = $db->prepare('
            SELECT COUNT(*) AS sales, COALESCE(SUM(amount),0) AS revenue
            FROM purchases WHERE product_id = ? AND status = "completed"');
        $sst->execute([$p['id']]);
        $stats = $sst->fetch();
        $p['actual_sales']   = (int)$stats['sales'];
        $p['actual_revenue'] = (float)$stats['revenue'];
        $p['can_edit'] = $isAdmin || $p['seller_id'] == $user['id'];
    }

    // Histórico de vendas com comprador (#20)
    $salesSql = $isAdmin
        ? "SELECT * FROM v_sales_detail ORDER BY purchased_at DESC LIMIT 100"
        : "SELECT * FROM v_sales_detail WHERE seller_email = ? ORDER BY purchased_at DESC LIMIT 100";
    $sst = $db->prepare($salesSql);
    $isAdmin ? $sst->execute() : $sst->execute([$user['email']]);
    $sales = $sst->fetchAll();

    // KPIs agregados
    $totalSales   = count($sales);
    $totalRevenue = array_sum(array_column($sales, 'amount'));
    $uniqueBuyers = count(array_unique(array_column($sales, 'buyer_email')));

    jsonOk([
        'kpis' => [
            'total_sales'    => $totalSales,
            'total_revenue'  => round($totalRevenue, 2),
            'total_products' => count($products),
            'unique_buyers'  => $uniqueBuyers,
        ],
        'products' => $products,
        'sales'    => $sales,
        'is_admin' => $isAdmin,
    ]);
}

// ── MEUS DOWNLOADS — persistentes na BD (#16) ─────────────────
function handleMyDownloads(): void {
    $user = requireAuth();
    $db   = getDB();
    $st = $db->prepare("
        SELECT d.id, d.downloaded_at, d.download_count,
               p.id AS product_id, p.title, p.category, p.type,
               p.file_name, pu.amount, pu.purchased_at
        FROM downloads d
        JOIN products  p  ON d.product_id  = p.id
        JOIN purchases pu ON d.purchase_id = pu.id
        WHERE d.user_id = ?
        ORDER BY pu.purchased_at DESC");
    $st->execute([$user['id']]);
    jsonOk($st->fetchAll());
}

// ── ADMIN: LISTAR / GERIR UTILIZADORES (#19) ──────────────────
function handleUsersList(): void {
    $user = requireRole('admin');
    $db   = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $targetId = (int)($_GET['user_id'] ?? 0);
        if ($targetId === $user['id']) jsonErr('Não podes remover a tua própria conta.');
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
        jsonOk(['message' => 'Utilizador removido.']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $body = getJsonBody();
        $targetId = (int)($body['user_id'] ?? 0);
        $newRole  = $body['role'] ?? '';
        if (!in_array($newRole, ['buyer','seller','admin'])) jsonErr('Papel inválido.');
        $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
        jsonOk(['message' => 'Papel actualizado.']);
        return;
    }

    $st = $db->query("
        SELECT u.id, u.name, u.email, u.role, u.color, u.created_at,
               COUNT(DISTINCT pr.id) AS products_count,
               COUNT(DISTINCT pu.id) AS purchases_count
        FROM users u
        LEFT JOIN products  pr ON pr.seller_id = u.id
        LEFT JOIN purchases pu ON pu.buyer_id  = u.id
        GROUP BY u.id
        ORDER BY u.created_at DESC");
    jsonOk($st->fetchAll());
}
