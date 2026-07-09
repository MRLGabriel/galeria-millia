# Galeria Millia

Marketplace de arte onde artistas vendem obras originais e edições limitadas **diretamente**, com curadoria da galeria. O repasse da venda é **100% do artista** — a galeria se sustenta por mensalidade, não por comissão.

🔗 Site: [galeriamillia.com](https://galeriamillia.com)

## Funcionalidades

- **Vitrine e busca** de obras por categoria, com página pública para cada artista.
- **Página do artista editável** por blocos (capa, texto, galeria, citação, botão de contato…).
- **Contas e segurança:** cadastro com confirmação de e-mail, login, verificação em duas etapas (2FA) por e-mail, redefinição de senha, desativação de conta.
- **Cadastro de obras** com upload de imagem e **recorte/enquadramento** no navegador, edição limitada e curadoria (aprovação pelo admin).
- **Categorias sugeridas pelo artista** e aprovadas pelo admin.
- **Checkout completo:** endereço de entrega, cálculo de frete via **Melhor Envio**, pagamento via **Mercado Pago (Checkout Pro)** com webhook.
- **Painel do admin:** curadoria, categorias, usuários, pedidos (com serviço de frete e endereço) e repasses.
- **E-mails transacionais** (PHPMailer/SMTP): confirmação, 2FA, curadoria.

## Stack

- **Front-end:** HTML "bundled" único com motor de template próprio (sem build/npm).
- **Back-end:** PHP 8.2, roteador único `backend/api.php` (`?action=...`), sessões e bcrypt.
- **Banco:** MySQL (schema em `galeria_millia_schema.mysql.sql`; variante SQLite para testes).
- **Integrações:** Mercado Pago, Melhor Envio, PHPMailer (SMTP).

## Estrutura

```
index.html                      Front-end (aplicação inteira)
backend/
  api.php                       API (roteador único)
  mailer.php                    Envio de e-mail (PHPMailer)
  config.example.php            Modelo de configuração (copie para config.php)
  lib/PHPMailer/                Biblioteca de e-mail
galeria_millia_schema.mysql.sql Schema MySQL (com dados de exemplo)
galeria_millia_schema.sqlite.sql Schema SQLite (testes)
DEPLOY_HOSTGATOR.md             Guia de deploy em produção
```

## Rodando localmente

Requisitos: PHP 8.0+ e MySQL (ex.: XAMPP).

1. **Configuração:**
   ```bash
   cp backend/config.example.php backend/config.php
   ```
   Edite `backend/config.php` com os dados do seu banco. Em desenvolvimento, deixe `MAIL_MODE` como `'log'` (os e-mails são gravados em `backend/mail_log/` em vez de enviados).

2. **Banco de dados:** crie um banco e importe o schema:
   ```bash
   mysql -u root galeria_millia < galeria_millia_schema.mysql.sql
   ```

3. **Servidor:** sirva a raiz do projeto com PHP:
   ```bash
   php -S 127.0.0.1:8000
   ```
   Abra `http://127.0.0.1:8000/index.html`.

> `backend/config.php` é **gitignorado** — segredos nunca vão para o repositório.

## Deploy

Passo a passo de produção (HostGator/cPanel: banco, e-mail SMTP, uploads, Mercado Pago, Melhor Envio, HTTPS e webhook) em [`DEPLOY_HOSTGATOR.md`](DEPLOY_HOSTGATOR.md).

## Licença

**Proprietária — todos os direitos reservados.** O código é público apenas para visualização e referência; **nenhum direito de uso é concedido** (comercial ou não-comercial) sem autorização expressa e por escrito do titular. Veja [`LICENSE`](LICENSE).
