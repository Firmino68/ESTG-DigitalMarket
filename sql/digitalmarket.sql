-- ============================================================
--  DIGITALMARKET — ESTG-ESH
--  Schema MySQL completo para phpMyAdmin
--  Importar em: phpMyAdmin > Nova BD > Importar > este ficheiro
-- ============================================================

CREATE DATABASE IF NOT EXISTS digitalmarket
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE digitalmarket;

-- ── UTILIZADORES ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120)  NOT NULL,
  email      VARCHAR(180)  NOT NULL UNIQUE,
  password   VARCHAR(255)  NOT NULL,          -- bcrypt hash
  role       ENUM('buyer','seller','admin') NOT NULL DEFAULT 'buyer',
  color      VARCHAR(10)   DEFAULT '#7c6cfc',
  bio        TEXT,
  portfolio  VARCHAR(255),
  skill      VARCHAR(120),
  created_at DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SESSÕES JWT (tokens activos) ─────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT           NOT NULL,
  token      VARCHAR(512)  NOT NULL UNIQUE,
  expires_at DATETIME      NOT NULL,
  created_at DATETIME      DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PRODUTOS / GIGS ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS products (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  seller_id   INT           NOT NULL,
  title       VARCHAR(255)  NOT NULL,
  description TEXT          NOT NULL,
  price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  category    ENUM('Web Dev','Ebooks','Design','Vídeo','Motivação','Templates') NOT NULL,
  type        ENUM('digital','service') NOT NULL DEFAULT 'digital',
  file_name   VARCHAR(255),                   -- nome do ficheiro no disco
  file_path   VARCHAR(500),                   -- caminho relativo em /uploads/products/
  file_size   BIGINT DEFAULT 0,
  mime_type   VARCHAR(100),
  rating      DECIMAL(3,2) DEFAULT 5.00,
  sales_count INT DEFAULT 0,
  is_active   TINYINT(1) DEFAULT 1,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── CARRINHO PERSISTENTE ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS cart_items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  product_id INT NOT NULL,
  added_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart (user_id, product_id),
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── COMPRAS / VENDAS ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS purchases (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  buyer_id       INT           NOT NULL,
  product_id     INT           NOT NULL,
  seller_id      INT           NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  buyer_name     VARCHAR(120),
  buyer_email    VARCHAR(180),
  message        TEXT,
  status         ENUM('completed','refunded') DEFAULT 'completed',
  purchased_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (buyer_id)   REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (seller_id)  REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── DOWNLOADS (acesso pós-compra) ────────────────────────────
CREATE TABLE IF NOT EXISTS downloads (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  purchase_id  INT NOT NULL,
  user_id      INT NOT NULL,
  product_id   INT NOT NULL,
  downloaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  download_count INT DEFAULT 0,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
  FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PERFIS PÚBLICOS DOS VENDEDORES ───────────────────────────
CREATE TABLE IF NOT EXISTS seller_profiles (
  user_id    INT PRIMARY KEY,
  bio        TEXT,
  portfolio  VARCHAR(255),
  skill      VARCHAR(120),
  linkedin   VARCHAR(255),
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  DADOS INICIAIS (seed)
-- ============================================================

-- Utilizadores (passwords em bcrypt; texto: Admin123, Ana12345, Buyer123)
INSERT IGNORE INTO users (name, email, password, role, color) VALUES
('Admin Geral',        'admin@piaget.pt', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',  '#f97316'),
('Ana Rodrigues',      'ana@piaget.pt',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'seller', '#7c6cfc'),
('Estudante Piaget',   'buyer@piaget.pt', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'buyer',  '#2dd4bf'),
('Carlos Barbosa',     'carlos@piaget.pt','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'seller', '#fb7185');

-- Produtos de seed
INSERT IGNORE INTO products (seller_id, title, description, price, category, type, file_name, rating, sales_count) VALUES
(2, 'Template E-Commerce Premium',     'Ficheiro UI completo em Figma para lojas modernas. Inclui 40+ ecrãs, sistema de design e documentação.', 29.99, 'Design',  'digital', 'ecommerce_template.fig', 4.80, 14),
(2, 'Ebook: Domina o TypeScript',      'Guia do zero ao avançado com padrões de arquitetura. 280 páginas com exercícios práticos.',               19.90, 'Ebooks',  'digital', 'typescript_mastery.pdf', 4.90, 38),
(4, 'Consultoria de Arquitetura Web',  '1 hora de mentoria focada em escalabilidade e performance. Inclui gravação e relatório.',                 75.00, 'Web Dev', 'service', NULL,                     5.00,  6),
(2, 'Pack Assets Vídeo Synthwave',     'Transições e overlays em 4K para criadores de conteúdo. 120+ elementos, compatível com Premiere.',        14.50, 'Vídeo',   'digital', 'synthwave_pack.zip',     4.60, 22);

-- Venda histórica de exemplo
INSERT IGNORE INTO purchases (buyer_id, product_id, seller_id, amount, buyer_name, buyer_email, status, purchased_at) VALUES
(3, 2, 2, 19.90, 'Estudante Piaget', 'buyer@piaget.pt', 'completed', '2026-05-01 14:30:00');

INSERT IGNORE INTO downloads (purchase_id, user_id, product_id, download_count) VALUES
(1, 3, 2, 1);

-- ============================================================
--  VIEWS ÚTEIS para phpMyAdmin
-- ============================================================

-- Vista: vendas com todos os detalhes
CREATE OR REPLACE VIEW v_sales_detail AS
SELECT
  p.id            AS purchase_id,
  p.purchased_at,
  p.amount,
  p.status,
  b.name          AS buyer_name,
  b.email         AS buyer_email,
  pr.title        AS product_title,
  pr.category,
  pr.type         AS product_type,
  s.name          AS seller_name,
  s.email         AS seller_email
FROM purchases p
JOIN users    b  ON p.buyer_id   = b.id
JOIN products pr ON p.product_id = pr.id
JOIN users    s  ON p.seller_id  = s.id;

-- Vista: estatísticas por produto
CREATE OR REPLACE VIEW v_product_stats AS
SELECT
  pr.id,
  pr.title,
  pr.category,
  pr.price,
  pr.sales_count,
  COALESCE(SUM(pu.amount), 0) AS total_revenue,
  COUNT(DISTINCT pu.buyer_id) AS unique_buyers,
  u.name  AS seller_name,
  u.email AS seller_email
FROM products pr
JOIN users u ON pr.seller_id = u.id
LEFT JOIN purchases pu ON pu.product_id = pr.id AND pu.status = 'completed'
GROUP BY pr.id;

-- Vista: dashboard do vendedor
CREATE OR REPLACE VIEW v_seller_dashboard AS
SELECT
  u.id            AS seller_id,
  u.name          AS seller_name,
  u.email         AS seller_email,
  COUNT(DISTINCT pr.id)  AS total_products,
  COUNT(DISTINCT pu.id)  AS total_sales,
  COALESCE(SUM(pu.amount), 0) AS total_revenue,
  COUNT(DISTINCT pu.buyer_id) AS unique_buyers
FROM users u
LEFT JOIN products pr ON pr.seller_id = u.id AND pr.is_active = 1
LEFT JOIN purchases pu ON pu.seller_id = u.id AND pu.status = 'completed'
WHERE u.role IN ('seller','admin')
GROUP BY u.id;
