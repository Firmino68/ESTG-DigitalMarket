<?php
// ============================================================
//  DIGITALMARKET — api/controllers/ProductsController.php
//  GET    /api/products            — listar (filtros, paginação)
//  GET    /api/products?id=X       — detalhe
//  POST   /api/products            — criar  (seller/admin)
//  PUT    /api/products?id=X       — editar (dono/admin)
//  DELETE /api/products?id=X       — apagar (dono/admin)
//  GET    /api/products/download?id=X — download protegido (#16)
// ============================================================

require_once __DIR__ . '/../../api/middleware/auth.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

match(true) {
    $method === 'GET'    && $action === 'download' => handleDownload($id),
    $method === 'GET'    && $id !== null           => handleGetOne($id),
    $method === 'GET'                              => handleList(),
    $method === 'POST'                             => handleCreate(),
    $method === 'PUT'    && $id !== null           => handleUpdate($id),
    $method === 'DELETE' && $id !== null           => handleDelete($id),
    default => jsonErr('Rota não encontrada.', 404)
};

// ── LISTAR ────────────────────────────────────────────────────
function handleList(): void {
    $db   = getDB();
    $cat  = $_GET['cat']    ?? '';
    $type = $_GET['type']   ?? '';
    $q    = $_GET['q']      ?? '';
    $sort = $_GET['sort']   ?? 'sales';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit= 20;
    $off  = ($page - 1) * $limit;

    $where = ['pr.is_active = 1'];
    $params= [];

    if ($cat)  { $where[] = 'pr.category = ?';   $params[] = $cat;  }
    if ($type) { $where[] = 'pr.type = ?';        $params[] = $type; }
    if ($q)    { $where[] = '(pr.title LIKE ? OR pr.description LIKE ?)';
                 $params[] = "%$q%"; $params[] = "%$q%"; }

    $orderMap = [
        'sales'      => 'pr.sales_count DESC',
        'price-asc'  => 'pr.price ASC',
        'price-desc' => 'pr.price DESC',
        'rating'     => 'pr.rating DESC',
        'newest'     => 'pr.created_at DESC',
    ];
    $order = $orderMap[$sort] ?? 'pr.sales_count DESC';
    $wSql  = 'WHERE ' . implode(' AND ', $where);

    // Total
    $stCount = $db->prepare("SELECT COUNT(*) FROM products pr $wSql");
    $stCount->execute($params);
    $total = (int) $stCount->fetchColumn();

    // Dados
    $sql = "
        SELECT pr.id, pr.title, pr.description, pr.price, pr.category,
               pr.type, pr.file_name, pr.rating, pr.sales_count,
               pr.created_at, pr.updated_at,
               u.id AS seller_id, u.name AS seller_name,
               u.email AS seller_email, u.color AS seller_color
        FROM products pr
        JOIN users u ON pr.seller_id = u.id
        $wSql
        ORDER BY $order
        LIMIT $limit OFFSET $off";

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    jsonOk([
        'items'      => $rows,
        'total'      => $total,
        'page'       => $page,
        'totalPages' => (int) ceil($total / $limit),
    ]);
}

// ── DETALHE ───────────────────────────────────────────────────
function handleGetOne(int $id): void {
    $db = getDB();
    $st = $db->prepare("
        SELECT pr.*, u.name AS seller_name, u.email AS seller_email, u.color AS seller_color
        FROM products pr
        JOIN users u ON pr.seller_id = u.id
        WHERE pr.id = ? AND pr.is_active = 1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) jsonErr('Produto não encontrado.', 404);
    // Não expor o path real
    unset($row['file_path']);
    jsonOk($row);
}

// ── CRIAR (#19 — apenas seller/admin) ────────────────────────
function handleCreate(): void {
    $user = requireRole('seller', 'admin');

    // Suporta multipart/form-data (com ou sem upload) ou JSON puro.
    // Detectamos pelo Content-Type, não pela presença do ficheiro —
    // o formulário envia sempre FormData, mesmo sem ficheiro anexado
    // (ex.: produtos do tipo "serviço").
    $isMultipart = isMultipartRequest();
    $body = $isMultipart ? $_POST : getJsonBody();

    $title  = sanitize($body['title']       ?? '');
    $desc   = sanitize($body['description'] ?? '');
    $price  = (float)($body['price']        ?? 0);
    $cat    = $body['category'] ?? '';
    $type   = $body['type']     ?? 'digital';

    $cats  = ['Web Dev','Ebooks','Design','Vídeo','Motivação','Templates'];
    $types = ['digital','service'];
    $hasUploadedFile = $isMultipart && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;

    $errs = [];
    if (strlen($title) < 5)           $errs[] = 'Título demasiado curto (mín. 5 chars)';
    if (strlen($desc) < 20)           $errs[] = 'Descrição demasiado curta (mín. 20 chars)';
    if ($price < 0)                   $errs[] = 'Preço inválido';
    if (!in_array($cat, $cats))       $errs[] = 'Categoria inválida';
    if (!in_array($type, $types))     $errs[] = 'Tipo inválido';
    if ($type === 'digital' && !$hasUploadedFile && empty($body['file_name']))
        $errs[] = 'Ficheiro obrigatório para produtos digitais';
    if ($errs) jsonErr(implode(' | ', $errs));

    $fileName = null;
    $filePath = null;
    $fileSize = 0;
    $mimeType = null;

    // Upload real do ficheiro (#16)
    if ($hasUploadedFile) {
        [$fileName, $filePath, $fileSize, $mimeType] = saveUploadedFile($_FILES['file']);
    } elseif (!empty($body['file_name'])) {
        $fileName = sanitize($body['file_name']);
    }


    $db = getDB();
    $st = $db->prepare("
        INSERT INTO products
          (seller_id, title, description, price, category, type,
           file_name, file_path, file_size, mime_type)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $user['id'], $title, $desc, $price, $cat, $type,
        $fileName, $filePath, $fileSize, $mimeType
    ]);
    $newId = (int) $db->lastInsertId();

    jsonOk(['id' => $newId, 'message' => 'Produto criado com sucesso.'], 201);
}

// ── EDITAR (#19 — dono ou admin) ─────────────────────────────
function handleUpdate(int $id): void {
    $user = requireAuth();
    $db   = getDB();

    $prod = getProductOrFail($db, $id);
    if ($user['role'] !== 'admin' && $prod['seller_id'] !== $user['id'])
        jsonErr('Não tens permissão para editar este produto.', 403);

    $isMultipart = isMultipartRequest();
    $body = $isMultipart ? $_POST : getJsonBody();

    $title = sanitize($body['title']       ?? $prod['title']);
    $desc  = sanitize($body['description'] ?? $prod['description']);
    $price = isset($body['price']) ? (float)$body['price'] : $prod['price'];
    $cat   = $body['category'] ?? $prod['category'];
    $type  = $body['type']     ?? $prod['type'];

    $errs = [];
    if (strlen($title) < 5)  $errs[] = 'Título demasiado curto';
    if (strlen($desc)  < 20) $errs[] = 'Descrição demasiado curta';
    if ($price < 0)          $errs[] = 'Preço inválido';
    if ($errs) jsonErr(implode(' | ', $errs));

    $fileName = $prod['file_name'];
    $filePath = $prod['file_path'];
    $fileSize = $prod['file_size'];
    $mimeType = $prod['mime_type'];

    // Novo ficheiro enviado?
    if ($isMultipart && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // Apagar ficheiro antigo
        if ($filePath && file_exists($filePath)) unlink($filePath);
        [$fileName, $filePath, $fileSize, $mimeType] = saveUploadedFile($_FILES['file']);
    }

    $db->prepare("
        UPDATE products
        SET title=?, description=?, price=?, category=?, type=?,
            file_name=?, file_path=?, file_size=?, mime_type=?
        WHERE id=?"
    )->execute([$title, $desc, $price, $cat, $type,
                $fileName, $filePath, $fileSize, $mimeType, $id]);

    jsonOk(['message' => 'Produto actualizado.']);
}

// ── APAGAR (#19) ──────────────────────────────────────────────
function handleDelete(int $id): void {
    $user = requireAuth();
    $db   = getDB();

    $prod = getProductOrFail($db, $id);
    if ($user['role'] !== 'admin' && $prod['seller_id'] !== $user['id'])
        jsonErr('Sem permissão para apagar este produto.', 403);

    // Apagar ficheiro físico
    if ($prod['file_path'] && file_exists($prod['file_path'])) {
        unlink($prod['file_path']);
    }

    $db->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([$id]);
    jsonOk(['message' => 'Produto removido.']);
}

// ── DOWNLOAD SEGURO (#16) ─────────────────────────────────────
// Só compradores que tenham adquirido o produto podem descarregar
function handleDownload(?int $id): void {
    if (!$id) jsonErr('ID do produto em falta.');
    $user = requireAuth();
    $db   = getDB();

    // Verifica se o utilizador comprou o produto (ou é o vendedor/admin)
    $prod = getProductOrFail($db, $id);
    $ownedByUser = $user['role'] === 'admin'
        || $prod['seller_id'] === $user['id'];

    if (!$ownedByUser) {
        $st = $db->prepare('SELECT id FROM purchases WHERE buyer_id=? AND product_id=? AND status="completed"');
        $st->execute([$user['id'], $id]);
        if (!$st->fetch()) jsonErr('Acesso negado. Produto não adquirido.', 403);
    }

    if (!$prod['file_path'] || !file_exists($prod['file_path'])) {
        jsonErr('Ficheiro não disponível para download.', 404);
    }

    // Registar download
    $purchaseSt = $db->prepare('SELECT id FROM purchases WHERE buyer_id=? AND product_id=? LIMIT 1');
    $purchaseSt->execute([$user['id'], $id]);
    $purchase = $purchaseSt->fetch();
    if ($purchase) {
        $db->prepare('
            INSERT INTO downloads (purchase_id, user_id, product_id, download_count)
            VALUES (?,?,?,1)
            ON DUPLICATE KEY UPDATE download_count = download_count + 1, downloaded_at = NOW()
        ')->execute([$purchase['id'], $user['id'], $id]);
    }

    // Incrementar contador
    $db->prepare('UPDATE products SET sales_count = sales_count WHERE id = ?')->execute([$id]);

    // Servir ficheiro
    $path = $prod['file_path'];
    $mime = $prod['mime_type'] ?: 'application/octet-stream';
    $name = $prod['file_name'] ?: basename($path);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($path);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────
function isMultipartRequest(): bool {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    return stripos($ct, 'multipart/form-data') === 0;
}

function getProductOrFail(PDO $db, int $id): array {
    $st = $db->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) jsonErr('Produto não encontrado.', 404);
    return $row;
}

function saveUploadedFile(array $file): array {
    $allowedMimes = [
        'application/pdf','application/zip','application/x-zip-compressed',
        'video/mp4','application/epub+zip',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/png','image/jpeg','audio/mpeg'
    ];
    $maxBytes = UPLOAD_MAX_MB * 1024 * 1024;

    if ($file['size'] > $maxBytes)
        jsonErr('Ficheiro demasiado grande (máx. ' . UPLOAD_MAX_MB . 'MB).');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes))
        jsonErr('Tipo de ficheiro não permitido: ' . $mime);

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

    // Nome único para evitar colisões
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $uniqueName = $safeName . '_' . uniqid() . '.' . $ext;
    $destPath   = UPLOAD_DIR . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $destPath))
        jsonErr('Erro ao guardar o ficheiro no servidor.');

    return [$file['name'], $destPath, $file['size'], $mime];
}
