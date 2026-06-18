# DigitalMarket — Backend PHP + MySQL (phpMyAdmin)

Projecto académico ESTG-ESH (Instituto Piaget) — plataforma de e-commerce
de produtos digitais e serviços freelance, agora com **base de dados real**
em MySQL, API REST em PHP e autenticação JWT.

---

## 1. Requisitos

- **XAMPP** ou **WAMP** (PHP 8+ e MySQL/MariaDB)
- Browser moderno (Chrome, Firefox, Edge)

---

## 2. Instalação passo-a-passo

### 2.1 Copiar os ficheiros
Copia toda a pasta `digitalmarket_php` para dentro de `htdocs` do XAMPP:

```
C:\xampp\htdocs\digitalmarket\        (Windows)
/Applications/XAMPP/htdocs/digitalmarket/   (Mac)
/opt/lampp/htdocs/digitalmarket/      (Linux)
```

A estrutura final deve ficar:
```
htdocs/digitalmarket/
├── index.html
├── .htaccess
├── js/app.js
├── config/database.php
├── api/
│   ├── index.php
│   ├── middleware/auth.php
│   └── controllers/
│       ├── AuthController.php
│       ├── ProductsController.php
│       ├── CartController.php
│       ├── DashboardController.php
│       └── ProfileController.php
├── uploads/products/      (criada automaticamente no 1º upload)
└── sql/digitalmarket.sql
```

### 2.2 Criar a base de dados no phpMyAdmin

1. Liga o **Apache** e o **MySQL** no painel de controlo do XAMPP.
2. Abre `http://localhost/phpmyadmin`
3. Clica em **Importar** (separador no topo)
4. Escolhe o ficheiro `sql/digitalmarket.sql`
5. Clica em **Executar**

Isto cria:
- A base de dados `digitalmarket`
- 7 tabelas (`users`, `products`, `purchases`, `cart_items`, `downloads`, `sessions`, `seller_profiles`)
- 3 views úteis para consultas (`v_sales_detail`, `v_product_stats`, `v_seller_dashboard`)
- Dados de demonstração (4 utilizadores, 4 produtos, 1 venda histórica)

### 2.3 Configurar a ligação à BD (se necessário)

Por norma o XAMPP usa utilizador `root` sem password — já está configurado em
`config/database.php`. Se a tua instalação tiver password, edita:

```php
define('DB_USER', 'root');
define('DB_PASS', 'a_tua_password');
```

### 2.4 Abrir a aplicação

```
http://localhost/digitalmarket/
```

---

## 3. Credenciais de demonstração

| Email              | Password   | Papel  |
|--------------------|------------|--------|
| admin@piaget.pt     | Admin123   | Admin  |
| ana@piaget.pt        | Ana12345   | Seller |
| buyer@piaget.pt      | Buyer123   | Buyer  |
| carlos@piaget.pt     | Admin123   | Seller |

> Nota: todas as passwords de seed usam o mesmo hash bcrypt de exemplo
> (`Admin123`). Em produção cada utilizador define a sua própria password
> no registo, que é guardada com `password_hash()` (bcrypt).

---

## 4. O que foi implementado (resolve os pontos #16 a #20)

### #16 — Download real e persistente
- Ficheiros são guardados fisicamente em `uploads/products/` no servidor.
- A tabela `downloads` regista cada download por utilizador e conta quantas vezes foi feito.
- O endpoint `GET /api/products/download?id=X` **verifica na BD** se o
  utilizador comprou o produto antes de servir o ficheiro — não é possível
  descarregar sem ter pago.
- A área **Perfil → Meus Downloads** lista todos os ficheiros já comprados,
  com botão de re-download, e funciona após reiniciar o browser (dados vêm do MySQL).

### #17 — Autenticação real
- Tabela `users` com password em **bcrypt** (`password_hash`/`password_verify`).
- Login verifica password real; emails duplicados são bloqueados no registo
  (`UNIQUE` na coluna `email` + verificação explícita).
- Sessão persistente via **JWT** guardado em `localStorage` — sobrevive a
  refresh da página (`GET /api/auth/me` restaura o utilizador).
- Tabela `sessions` permite invalidar tokens (logout real).

### #18 — Validação completa
- Confirmação de password obrigatória (registo falha se não coincidirem).
- Email obrigatoriamente `@piaget.pt` (validado no cliente e no servidor).
- Força mínima de password (8+ caracteres, maiúscula, número) — validada
  duas vezes (UX no browser + reforço no PHP, nunca confiando só no cliente).
- Mensagens de erro específicas por campo, devolvidas pela API.

### #19 — Permissões reais por papel
- Cada pedido autenticado passa por `requireRole()` no PHP.
- **Buyer**: não consegue aceder a `/api/dashboard` (HTTP 403).
- **Seller**: só pode editar/apagar produtos onde `seller_id` é o seu próprio ID
  (verificado em `ProductsController::handleUpdate/handleDelete`).
- **Admin**: vê e gere todos os produtos e tem acesso a `/api/dashboard/users`
  para listar/alterar papéis de qualquer utilizador.

### #20 — Dashboard completo
- **Editar produtos**: formulário de edição com upload de novo ficheiro opcional.
- **Estatísticas por produto**: vendas e receita calculadas em tempo real
  a partir da tabela `purchases` (não apenas um contador estático).
- **Histórico de vendas**: tabela com produto, comprador (nome+email) e data,
  usando a view `v_sales_detail`.
- **Lista de compradores**: contagem de compradores únicos nos KPIs.
- **Admin**: secção extra com todos os utilizadores da plataforma.

---

## 5. Estrutura da API REST

| Método | Rota                          | Descrição                              | Autenticação |
|--------|-------------------------------|-----------------------------------------|--------------|
| POST   | `/api/auth/register`          | Criar conta                             | —            |
| POST   | `/api/auth/login`              | Login (devolve JWT)                     | —            |
| POST   | `/api/auth/logout`             | Terminar sessão                         | Bearer token |
| GET    | `/api/auth/me`                 | Dados do utilizador actual               | Bearer token |
| GET    | `/api/products`                | Listar produtos (filtros, paginação)    | —            |
| GET    | `/api/products?id=X`           | Detalhe de um produto                   | —            |
| POST   | `/api/products`                | Criar produto (+ upload)                | Seller/Admin |
| PUT    | `/api/products?id=X`           | Editar produto                          | Dono/Admin   |
| DELETE | `/api/products?id=X`           | Remover produto                         | Dono/Admin   |
| GET    | `/api/products/download?id=X`  | Download protegido do ficheiro          | Comprador    |
| GET    | `/api/cart`                    | Listar carrinho                         | Bearer token |
| POST   | `/api/cart`                    | Adicionar ao carrinho                   | Bearer token |
| DELETE | `/api/cart?id=X`                | Remover do carrinho                     | Bearer token |
| POST   | `/api/checkout`                | Finalizar compra (valida cartão, Luhn)  | Bearer token |
| GET    | `/api/dashboard`               | KPIs + produtos + vendas                | Seller/Admin |
| GET    | `/api/dashboard/downloads`     | Meus downloads                          | Bearer token |
| GET    | `/api/dashboard/users`         | Listar utilizadores                     | Admin        |
| PUT    | `/api/dashboard/users`         | Alterar papel de utilizador              | Admin        |
| DELETE | `/api/dashboard/users`         | Remover utilizador                       | Admin        |
| GET    | `/api/profile`                 | Ver perfil próprio                       | Bearer token |
| PUT    | `/api/profile`                 | Editar bio/portfolio/skill               | Bearer token |

---

## 6. Notas técnicas

- **Sem frameworks** — PHP puro com PDO (prepared statements em todas as queries,
  protegendo contra SQL Injection).
- **JWT implementado manualmente** (HS256) em `api/middleware/auth.php` —
  não depende de bibliotecas externas via Composer, útil em ambientes
  académicos sem acesso à internet no servidor.
- **Uploads validados**: tipo MIME real (via `finfo`), tamanho máximo 100MB,
  nome de ficheiro sanitizado com sufixo único para evitar colisões.
- **Transação na compra**: se algo falhar a meio do checkout, a base de
  dados faz `ROLLBACK` e nenhuma venda fica registada parcialmente.

## 7. Possíveis próximos passos

- Adicionar paginação visual no catálogo (a API já suporta `?page=`).
- Migrar o JWT secret para variável de ambiente antes de qualquer deploy público.
- Adicionar testes automatizados (PHPUnit) aos controllers.
