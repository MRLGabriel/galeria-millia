# Deploy da Galeria Millia no HostGator — Lançamento de produção

Guia atualizado para o site completo (contas + e-mail + upload de imagens + frete Melhor Envio + pagamento Mercado Pago).

## Visão geral do que sobe

```
public_html/
  index.html                      ← o site inteiro (front-end)
  backend/
    api.php                       ← a API
    config.php                    ← credenciais (EDITAR antes de subir)
    mailer.php                    ← envio de e-mail
    lib/PHPMailer/                ← biblioteca de e-mail (3 arquivos)
      Exception.php
      PHPMailer.php
      SMTP.php
  uploads/                        ← imagens enviadas pelos usuários (criar, gravável)
    artworks/
    avatars/
    blocks/
    covers/
```

**NÃO suba:** `*.sql`, `DEPLOY_HOSTGATOR.md`, `backend/mail_log/`, `sqlite-src-*`, `.claude/`, `*.ORIGINAL_BACKUP.html`. São de trabalho local.

---

## Antes de começar — junte estas 4 coisas

Você vai precisar ter em mãos, nesta ordem:

1. **Banco MySQL** (criado no cPanel — passo 1) → nome do banco, usuário e senha.
2. **Caixa de e-mail** `naoresponda@galeriamillia.com` (criada no cPanel — passo 2) → senha.
3. **Credenciais de PRODUÇÃO do Mercado Pago** → Public Key + Access Token (começam com `APP_USR-...`).
   - Em https://www.mercadopago.com.br/developers → sua aplicação → **Credenciais de produção**.
   - Pode ser preciso "Ativar credenciais de produção" e informar dados da conta.
4. **Token de PRODUÇÃO do Melhor Envio** → em https://melhorenvio.com.br → Configurações → Tokens/Integrações (ambiente de produção, não sandbox).

Me passe esses valores e eu preencho o `backend/config.php` certinho pra você subir.

---

## 1. Criar o banco de dados MySQL

1. cPanel → **"Bancos de Dados MySQL®"**.
2. **Criar novo banco**: `galeriamillia` → Criar. O nome final vem prefixado, ex.: `gabr7283_galeriamillia`.
3. **MySQL Usuários** → Criar novo usuário (ex.: `galeriaadmin`) com **senha forte** (use o gerador). Anote.
4. **Adicionar usuário ao banco** → selecione os dois → **ALL PRIVILEGES**.
5. Anote os 3 valores finais (com prefixo): banco, usuário, senha.

## 2. Criar a caixa de e-mail

1. cPanel → **"Contas de E-mail"** → Criar.
2. Endereço: `naoresponda@galeriamillia.com`. Defina uma senha forte e anote.
3. Depois de criada, veja **"Conectar dispositivos"** / configurações de e-mail e confirme o **servidor SMTP de saída** (geralmente `mail.galeriamillia.com`, porta `465` SSL). Se o cPanel indicar outro host/porta, me avise.

## 3. Importar o banco (instalação limpa)

1. cPanel → **phpMyAdmin** → clique no banco `..._galeriamillia` na lateral.
2. Aba **Importar** → **Escolher arquivo** → selecione **`producao_instalacao.sql`** (este arquivo já vem limpo: todas as tabelas, categorias, configurações e só a sua conta admin — sem artistas/obras de exemplo).
3. **Executar**. Ao final devem aparecer **14 tabelas** na lateral e `SELECT * FROM users` mostra 1 usuário: `gabrielmirallia@gmail.com`.

## 4. Editar o backend/config.php

Troque os blocos abaixo pelos valores de produção (eu faço isso pra você quando tiver as credenciais):

```php
// Banco (passo 1)
define('DB_HOST', 'localhost');            // normalmente continua localhost
define('DB_NAME', 'gabr7283_galeriamillia');
define('DB_USER', 'gabr7283_galeriaadmin');
define('DB_PASS', 'a-senha-do-banco');

// Melhor Envio — PRODUÇÃO
define('MELHOR_ENVIO_BASE_URL', 'https://www.melhorenvio.com.br');
define('MELHOR_ENVIO_TOKEN', 'SEU-TOKEN-DE-PRODUCAO');

// Mercado Pago — PRODUÇÃO
define('MP_PUBLIC_KEY', 'APP_USR-...');
define('MP_ACCESS_TOKEN', 'APP_USR-...');

// E-mail — PRODUÇÃO (passo 2)
define('MAIL_MODE', 'smtp');
define('MAIL_SMTP_HOST', 'mail.galeriamillia.com');   // confirme no cPanel
define('MAIL_SMTP_PORT', 465);
define('MAIL_SMTP_SECURE', 'ssl');
define('MAIL_SMTP_USER', 'naoresponda@galeriamillia.com');
define('MAIL_SMTP_PASS', 'a-senha-do-email');

// URL pública do site (troca os links de e-mail e o retorno do pagamento)
define('SITE_BASE_URL', 'https://galeriamillia.com');
```

## 5. Subir os arquivos

Pelo **Gerenciador de Arquivos** do cPanel → entre em `public_html`:

1. Suba **`index.html`**.
2. Crie a pasta `backend/` e suba dentro: `api.php`, `config.php` (já editado), `mailer.php`.
3. Dentro de `backend/`, crie `lib/PHPMailer/` e suba os 3 arquivos da biblioteca.
4. Crie a pasta `uploads/` e, dentro dela, as 4 subpastas vazias: `artworks/`, `avatars/`, `blocks/`, `covers/`. Marque as permissões dessas pastas como **755** (o PHP precisa gravar imagens nelas).

## 6. Ajustes finais no cPanel

- **PHP 8.0+**: "MultiPHP Manager" → selecione o domínio → PHP 8.0 ou superior (testado em 8.2).
- **HTTPS**: "SSL/TLS Status" / "Let's Encrypt" → ative para o domínio. Depois force HTTP→HTTPS.
   - O pagamento e os e-mails usam `https://galeriamillia.com` — o HTTPS **precisa** estar ativo pro fluxo funcionar direito.
- **Webhook do Mercado Pago**: já está configurado no código para `https://galeriamillia.com/backend/api.php?action=mp_webhook`. No painel do Mercado Pago (sua aplicação → Webhooks/Notificações), cadastre essa mesma URL para o evento **"Pagamentos"**.

## 7. Testar no ar

1. Acesse `https://galeriamillia.com` — a galeria carrega (vazia, sem obras ainda).
2. **Entrar** com `gabrielmirallia@gmail.com` → painel de administração aparece.
3. Crie um usuário artista de teste, confirme o e-mail (deve chegar de verdade em uma caixa sua), cadastre uma obra com foto.
4. Faça uma compra de teste real de valor baixo (ex.: R$ 1) para validar Mercado Pago + Melhor Envio + confirmação do pedido. Depois estorne pelo painel do Mercado Pago.

## 8. Segurança

- [ ] **Trocar a senha do admin** depois do primeiro acesso. Gere um hash bcrypt novo (`password_hash('SUA_SENHA', PASSWORD_BCRYPT)` no PHP) e rode `UPDATE users SET password_hash='...' WHERE email='SEU_EMAIL_ADMIN'` no phpMyAdmin. Nunca deixe senha em texto em arquivos versionados.
- [ ] Ative a **verificação em duas etapas** na sua conta admin (menu do perfil → Configuração… na verdade: menu do perfil → aba de segurança em "Meus pedidos").
- [ ] Confirme que a pasta `backend/mail_log/` **não** subiu (ela é só de teste local).
- [ ] Não deixe o `config.php` com as credenciais de produção ir para o Git/backup público.
