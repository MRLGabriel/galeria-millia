<?php
// ============================================================
// MODELO de configuração — copie para config.php e preencha os segredos.
//   cp config.example.php config.php
// O config.php real é gitignorado (nunca vai pro Git).
// ============================================================

// Fixa o fuso do PHP em UTC (prazos de expiração são calculados só no PHP).
date_default_timezone_set('UTC');

// ---- Banco de dados ----
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'sua_base');
define('DB_USER', 'seu_usuario');
define('DB_PASS', 'sua_senha');

// ---- Melhor Envio (frete) ----
// Sandbox: https://sandbox.melhorenvio.com.br | Produção: https://www.melhorenvio.com.br
define('MELHOR_ENVIO_BASE_URL', 'https://sandbox.melhorenvio.com.br');
define('MELHOR_ENVIO_TOKEN', 'SEU_TOKEN_MELHOR_ENVIO');

// ---- Mercado Pago (Checkout Pro) ----
// Teste: TEST-... | Produção: APP_USR-...
define('MP_PUBLIC_KEY', 'SUA_PUBLIC_KEY');
define('MP_ACCESS_TOKEN', 'SEU_ACCESS_TOKEN');
// Segredo da assinatura do webhook (MP → sua aplicação → Webhooks → "Segredo").
// Deixe vazio pra não validar (o webhook ainda re-consulta o pagamento no MP).
define('MP_WEBHOOK_SECRET', '');

// ---- E-mail ----
define('MAIL_MODE', 'log'); // 'log' (dev: grava em backend/mail_log/) ou 'smtp'
define('MAIL_FROM_ADDR', 'naoresponda@seudominio.com');
define('MAIL_FROM_NAME', 'Galeria Millia');
define('MAIL_SMTP_HOST', 'mail.seudominio.com');
define('MAIL_SMTP_PORT', 465);
define('MAIL_SMTP_SECURE', 'ssl'); // 'ssl' (465) ou 'tls' (587)
define('MAIL_SMTP_USER', 'naoresponda@seudominio.com');
define('MAIL_SMTP_PASS', 'SENHA_DA_CAIXA');

// ---- URL pública do site ----
define('SITE_BASE_URL', 'http://localhost');

// ============================================================
// A partir daqui não precisa mexer.
// ============================================================
set_exception_handler(function (Throwable $e) {
  error_log('[galeria-millia] Erro não tratado: ' . $e->getMessage());
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode(['error' => 'Erro interno. Tente novamente em instantes.'], JSON_UNESCAPED_UNICODE);
  exit;
});

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
      $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);
    } catch (PDOException $e) {
      error_log('[galeria-millia] Falha ao conectar ao banco: ' . $e->getMessage());
      json_error('Serviço temporariamente indisponível. Tente novamente em instantes.', 503);
    }
  }
  return $pdo;
}

session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'samesite' => 'Lax']);
session_start();

header('Content-Type: application/json; charset=utf-8');

function json_out($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_error(string $message, int $status = 400): void {
  json_out(['error' => $message], $status);
}

function body(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function current_user(): ?array {
  if (empty($_SESSION['user_id'])) return null;
  $stmt = db()->prepare('SELECT id, role, name, email, headline, bio, avatar_color, avatar_url AS avatarUrl, cover_url AS coverUrl, blocked, deactivated, email_verified AS emailVerified, two_factor_enabled AS twoFactorEnabled FROM users WHERE id = ?');
  $stmt->execute([$_SESSION['user_id']]);
  $u = $stmt->fetch();
  if ($u && $u['deactivated']) {
    $_SESSION = [];
    session_destroy();
    return null;
  }
  return $u ?: null;
}

function require_login(): array {
  $u = current_user();
  if (!$u) json_error('Não autenticado', 401);
  if ($u['blocked']) json_error('Conta bloqueada', 403);
  return $u;
}

function require_role(string ...$roles): array {
  $u = require_login();
  if (!in_array($u['role'], $roles, true)) json_error('Sem permissão', 403);
  return $u;
}

function require_verified_email(array $user): void {
  if (!$user['emailVerified']) {
    json_error('Confirme seu e-mail antes de continuar. Reenvie a confirmação no seu perfil.', 403);
  }
}

function avatar_color(string $name): string {
  $palette = ['#8A6240', '#5A6650', '#5B6470', '#7A5A66', '#A67B2E'];
  $sum = 0;
  foreach (str_split($name) as $ch) $sum += ord($ch);
  return $palette[$sum % count($palette)];
}
