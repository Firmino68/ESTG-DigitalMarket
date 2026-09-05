# DigitalMarket

> Plataforma de e-commerce fullstack para produtos digitais e serviços freelance, desenvolvida como projeto académico na ESTG (Instituto Politécnico Jean Piaget do Sul).

DigitalMarket conecta desenvolvedores e criadores de conteúdo a potenciais clientes, permitindo a venda de infoprodutos (eBooks, tutoriais em vídeo) e a oferta de serviços freelance, com uma API REST própria e autenticação via JWT.

![Status](https://img.shields.io/badge/status-em%20desenvolvimento-yellow)
![License](https://img.shields.io/badge/license-MIT-blue)

<!--
📸 Adiciona aqui 2-3 screenshots ou um GIF curto da aplicação em funcionamento.
Exemplo:
![Homepage](docs/screenshot-home.png)
![Página de produto](docs/screenshot-produto.png)
-->

## Funcionalidades

- Catálogo de produtos digitais (eBooks, tutoriais em vídeo)
- Oferta de serviços freelance
- Autenticação de utilizadores com **JWT**
- API REST em PHP para operações de backend
- Base de dados relacional em **MySQL**

## Tecnologias

| Camada | Tecnologia |
|---|---|
| Frontend | HTML, CSS, JavaScript |
| Backend | PHP (API REST) |
| Base de dados | MySQL / MariaDB |
| Autenticação | JWT |

## Estrutura do projeto

```
ESTG-DigitalMarket/
├── api/                 # Endpoints da API REST (PHP)
├── config/              # Configurações da aplicação e base de dados
├── js/                  # Scripts JavaScript do frontend
├── sql/                 # Scripts de criação/migração da base de dados
├── uploads/products/    # Uploads de imagens/ficheiros de produtos
├── index.html           # Página inicial
└── .htaccess            # Configuração do servidor Apache
```

## Como correr o projeto localmente

### Requisitos

- **XAMPP** ou **WAMP** (PHP 8+ e MySQL/MariaDB)
- Browser moderno (Chrome, Firefox, Edge)

### Passos

1. **Clona o repositório**
   ```bash
   git clone https://github.com/Firmino68/ESTG-DigitalMarket.git
   ```

2. **Copia os ficheiros para o servidor local**
   ```
   C:\xampp\htdocs\digitalmarket\        (Windows)
   /Applications/XAMPP/htdocs/digitalmarket/   (Mac)
   /opt/lampp/htdocs/digitalmarket/      (Linux)
   ```

3. **Cria a base de dados**
   - Abre o phpMyAdmin
   - Cria uma base de dados nova
   - Importa o(s) ficheiro(s) `.sql` da pasta `sql/`

4. **Configura a ligação à base de dados**
   - Edita o ficheiro correspondente em `config/` com as tuas credenciais locais (host, utilizador, password, nome da base de dados)

5. **Inicia o Apache e o MySQL** no painel do XAMPP/WAMP

6. **Acede à aplicação**
   ```
   http://localhost/digitalmarket/
   ```

<!-- Se houver variáveis de ambiente (.env) ou passos adicionais de setup, acrescenta aqui -->

## Roadmap

- [ ] Adicionar testes automatizados
- [ ] Migrar frontend para um framework (ex: Vue.js)
- [ ] Adicionar sistema de pagamentos
- [ ] Deploy público com link de demo

## Autor

**Pedro Firmino**
Desenvolvedor Front-End · Setúbal, Portugal
📧 firminopedro04@gmail.com

## Licença

Este projeto está licenciado sob a licença MIT — consulta o ficheiro [LICENSE](LICENSE) para mais detalhes.
