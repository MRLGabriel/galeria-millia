<?php
// ============================================================
// Galeria Millia — API (um único roteador por ?action=)
// ============================================================

require __DIR__ . '/config.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/security.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Defesa CSRF central: toda requisição que muda estado (POST) precisa vir do
// próprio site. Exceção: o webhook do Mercado Pago, que é server-to-server (sem
// Origin do nosso domínio) e é validado por assinatura HMAC dentro do handler.
if ($method === 'POST' && $action !== 'mp_webhook') {
  verify_origin();
}

// ---------- helpers de domínio ----------

function detect_card_brand(string $digits): string {
  if (preg_match('/^4/', $digits)) return 'Visa';
  if (preg_match('/^(5[1-5]|2[2-7])/', $digits)) return 'Mastercard';
  if (preg_match('/^3[47]/', $digits)) return 'American Express';
  if (preg_match('/^(4011|4312|4389|4514|4573|6363|6504|6505|6509|6516|6550|636368|438935|504175|451416|636297)/', $digits)) return 'Elo';
  return 'Cartão';
}

// Chamada genérica à API do Mercado Pago. Lança exceção em erro HTTP/conexão.
function mp_api(string $method, string $path, ?array $payload = null): array {
  $ch = curl_init('https://api.mercadopago.com' . $path);
  $headers = ['Authorization: Bearer ' . MP_ACCESS_TOKEN, 'Accept: application/json'];
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_TIMEOUT => 20,
  ];
  if ($payload !== null) {
    $headers[] = 'Content-Type: application/json';
    $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
  }
  $opts[CURLOPT_HTTPHEADER] = $headers;
  curl_setopt_array($ch, $opts);
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr = curl_error($ch);
  curl_close($ch);
  if ($response === false) throw new RuntimeException('Erro de conexão com o Mercado Pago: ' . $curlErr);
  $data = json_decode($response, true);
  if ($httpCode >= 400) {
    $msg = is_array($data) ? ($data['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE)) : ('HTTP ' . $httpCode);
    throw new RuntimeException('Mercado Pago (' . $httpCode . '): ' . $msg);
  }
  return is_array($data) ? $data : [];
}

// Aplica a confirmação de um pagamento aprovado do MP ao pedido correspondente.
// Idempotente: se o pedido já está pago, não faz nada. Grava a taxa do MP
// rateada por item (proporcional ao valor de cada obra dentro do total pago) —
// é ela que aparece na coluna "Taxa" da aba Repasses.
function mp_apply_payment(PDO $pdo, array $payment): void {
  if (($payment['status'] ?? '') !== 'approved') return;
  $orderId = (int)($payment['external_reference'] ?? 0);
  if (!$orderId) return;

  $stmt = $pdo->prepare('SELECT id, status, total_cents FROM orders WHERE id = ?');
  $stmt->execute([$orderId]);
  $order = $stmt->fetch();
  if (!$order || $order['status'] !== 'pending_payment') return;

  $paidTotalCents = (int)round(((float)($payment['transaction_amount'] ?? 0)) * 100);
  // Defesa em profundidade: só fecha o pedido se o valor aprovado cobre o total.
  // O valor é fixado pela preferência no servidor, mas confirmar aqui evita
  // fechar um pedido com um pagamento de valor menor por qualquer motivo.
  if ($paidTotalCents < (int)$order['total_cents']) {
    error_log('[mercado-pago] pagamento ' . ($payment['id'] ?? '?') . ' (R$ ' . number_format($paidTotalCents / 100, 2) . ') nao cobre o total do pedido ' . $orderId . ' (R$ ' . number_format((int)$order['total_cents'] / 100, 2) . ') — nao aplicado');
    return;
  }

  $feeTotalCents = 0;
  foreach (($payment['fee_details'] ?? []) as $fee) {
    $feeTotalCents += (int)round(((float)($fee['amount'] ?? 0)) * 100);
  }

  $typeMap = ['credit_card' => 'credit_card', 'debit_card' => 'credit_card', 'bank_transfer' => 'pix', 'ticket' => 'boleto'];
  $method = $typeMap[$payment['payment_type_id'] ?? ''] ?? null;

  $pdo->beginTransaction();
  try {
    if ($feeTotalCents > 0 && $paidTotalCents > 0) {
      $items = $pdo->prepare('SELECT id, price_cents FROM order_items WHERE order_id = ?');
      $items->execute([$orderId]);
      $updFee = $pdo->prepare('UPDATE order_items SET gateway_fee_cents = ? WHERE id = ?');
      foreach ($items->fetchAll() as $it) {
        $itemFee = (int)round($feeTotalCents * ((int)$it['price_cents'] / $paidTotalCents));
        $updFee->execute([$itemFee, $it['id']]);
      }
    }
    // A transição pra 'paid' dispara o trigger que marca as obras como
    // vendidas e incrementa edition_sold.
    $pdo->prepare('UPDATE orders SET status = "paid", payment_method = ? WHERE id = ?')->execute([$method, $orderId]);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[mercado-pago] falha ao aplicar pagamento ' . ($payment['id'] ?? '?') . ' no pedido ' . $orderId . ': ' . $e->getMessage());
    throw $e;
  }
}

// Valida a assinatura (x-signature) da notificação do Mercado Pago, provando
// que ela veio mesmo do MP. Se MP_WEBHOOK_SECRET não estiver configurado,
// não bloqueia (o handler ainda re-consulta o pagamento no MP com o token).
function mp_webhook_signature_ok(string $dataId): bool {
  if (!defined('MP_WEBHOOK_SECRET') || MP_WEBHOOK_SECRET === '') return true;
  $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
  $reqId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
  if ($sig === '') return false;
  $ts = '';
  $v1 = '';
  foreach (explode(',', $sig) as $part) {
    $kv = explode('=', trim($part), 2);
    if (count($kv) === 2) {
      if ($kv[0] === 'ts') $ts = $kv[1];
      elseif ($kv[0] === 'v1') $v1 = $kv[1];
    }
  }
  if ($ts === '' || $v1 === '') return false;
  // Modelo exigido pelo MP: id:<data.id>;request-id:<x-request-id>;ts:<ts>;
  $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $reqId . ';ts:' . $ts . ';';
  $calc = hash_hmac('sha256', $manifest, MP_WEBHOOK_SECRET);
  return hash_equals($calc, $v1);
}

function melhor_envio_calculate(string $fromCep, string $toCep, array $products): array {
  $url = MELHOR_ENVIO_BASE_URL . '/api/v2/me/shipment/calculate';
  $payload = json_encode([
    'from' => ['postal_code' => $fromCep],
    'to' => ['postal_code' => $toCep],
    'products' => $products,
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Accept: application/json',
      'Authorization: Bearer ' . MELHOR_ENVIO_TOKEN,
      'User-Agent: Galeria Millia (kelsenferreira@gmail.com)',
    ],
    CURLOPT_TIMEOUT => 15,
  ]);
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr = curl_error($ch);
  curl_close($ch);

  if ($response === false) throw new RuntimeException('Erro de conexão: ' . $curlErr);
  $data = json_decode($response, true);
  if ($httpCode >= 400) {
    $msg = is_array($data) ? ($data['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE)) : ('HTTP ' . $httpCode);
    throw new RuntimeException('Melhor Envio (' . $httpCode . '): ' . $msg);
  }
  return is_array($data) ? $data : [];
}

function ensure_default_blocks(PDO $pdo, array $artist): void {
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM artist_page_blocks WHERE artist_id = ?');
  $stmt->execute([$artist['id']]);
  if ((int)$stmt->fetchColumn() > 0) return;

  $blocks = [
    ['hero',    ['title' => $artist['name'], 'subtitle' => $artist['headline'] ?: 'Artista visual', 'bg' => '#221E19']],
    ['text',    ['text' => $artist['bio'] ?: '', 'align' => 'left']],
    ['heading', ['text' => 'Obras em destaque', 'align' => 'left']],
    ['gallery', ['cols' => 3, 'count' => 6]],
    ['quote',   ['text' => '"A arte existe porque a vida não basta." — Ferreira Gullar']],
    ['button',  ['label' => 'Entrar em contato', 'align' => 'center', 'style' => 'escuro']],
  ];
  $ins = $pdo->prepare('INSERT INTO artist_page_blocks (artist_id, type, position, props) VALUES (?, ?, ?, ?)');
  foreach ($blocks as $i => [$type, $props]) {
    $ins->execute([$artist['id'], $type, $i, json_encode($props, JSON_UNESCAPED_UNICODE)]);
  }
}

function edition_label($editionSize, $editionSold): string {
  if ($editionSize === null) return 'Obra única';
  $size = (int)$editionSize;
  $available = max(0, $size - (int)$editionSold);
  return 'Edição de ' . $size . ' · ' . ($available > 0 ? $available . ' disponíveis' : 'esgotada');
}

function category_id_by_name(PDO $pdo, string $name): ?int {
  $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
  $stmt->execute([$name]);
  $id = $stmt->fetchColumn();
  return $id !== false ? (int)$id : null;
}

function collection_id_for(PDO $pdo, int $artistId, string $name): ?int {
  $name = trim($name);
  if ($name === '') return null;
  $stmt = $pdo->prepare('SELECT id FROM collections WHERE artist_id = ? AND name = ?');
  $stmt->execute([$artistId, $name]);
  $id = $stmt->fetchColumn();
  if ($id !== false) return (int)$id;
  $ins = $pdo->prepare('INSERT INTO collections (artist_id, name) VALUES (?, ?)');
  $ins->execute([$artistId, $name]);
  return (int)$pdo->lastInsertId();
}

// ---------- roteador ----------

$pdo = db();

switch ($action) {

  // ================= LEITURA =================

  case 'data': {
    $me = current_user();
    $isAdmin = $me && $me['role'] === 'admin';

    $categorias = $pdo->query('SELECT name FROM categories WHERE approved = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    // Categorias sugeridas por artistas, ainda aguardando o admin aprovar.
    $pendingCategorias = $pdo->query('SELECT name FROM categories WHERE approved = 0 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

    $users = $pdo->query('
      SELECT u.id, u.role, u.name, u.headline, u.bio, u.avatar_color AS avatarColor, u.avatar_url AS avatarUrl, u.cover_url AS coverUrl, u.blocked, u.email,
             u.email_verified AS emailVerified, u.two_factor_enabled AS twoFactorEnabled, u.deactivated,
             (SELECT COUNT(*) FROM follows f WHERE f.artist_id = u.id) AS followers
      FROM users u ORDER BY u.name
    ')->fetchAll();
    foreach ($users as &$u) {
      $u['blocked'] = (bool)$u['blocked'];
      $u['deactivated'] = (bool)$u['deactivated'];
      $u['followers'] = (int)$u['followers'];
      $u['emailVerified'] = (bool)$u['emailVerified'];
      $u['twoFactorEnabled'] = (bool)$u['twoFactorEnabled'];
      if (!$isAdmin && (!$me || $me['id'] != $u['id'])) { $u['email'] = null; $u['twoFactorEnabled'] = null; }
      if ($u['role'] === 'artist') {
        $artistRow = ['id' => $u['id'], 'name' => $u['name'], 'headline' => $u['headline'], 'bio' => $u['bio']];
        ensure_default_blocks($pdo, $artistRow);
      }
    }
    unset($u);

    $obraSql = '
      SELECT o.id, o.artist_id AS artistId, o.title, c.name AS cat, o.price_cents AS priceCents,
             o.description AS `desc`, o.width_cm AS w, o.height_cm AS h,
             o.edition_size AS editionSize, o.edition_sold AS editionSold,
             o.package_weight_kg AS packageWeightKg, o.package_length_cm AS packageLengthCm,
             o.package_width_cm AS packageWidthCm, o.package_height_cm AS packageHeightCm,
             o.approved, o.sold, o.views, col.name AS collection,
             (SELECT url FROM artwork_images WHERE artwork_id = o.id ORDER BY position LIMIT 1) AS imageUrl,
             (SELECT COUNT(*) FROM favorites fv WHERE fv.artwork_id = o.id) AS likes
      FROM artworks o
      JOIN users au ON au.id = o.artist_id
      LEFT JOIN categories c ON c.id = o.category_id
      LEFT JOIN collections col ON col.id = o.collection_id
      WHERE (o.approved = 1 AND au.blocked = 0)
    ';
    $params = [];
    if ($me && $me['role'] === 'artist') { $obraSql .= ' OR o.artist_id = ?'; $params[] = $me['id']; }
    if ($isAdmin) { $obraSql .= ' OR 1=1'; }
    $stmt = $pdo->prepare($obraSql);
    $stmt->execute($params);
    $obras = $stmt->fetchAll();
    foreach ($obras as &$o) {
      $o['price'] = $o['priceCents'] / 100;
      unset($o['priceCents']);
      $o['approved'] = (bool)$o['approved'];
      $o['sold'] = (bool)$o['sold'];
      $o['w'] = $o['w'] !== null ? (float)$o['w'] : null;
      $o['h'] = $o['h'] !== null ? (float)$o['h'] : null;
      $o['likes'] = (int)$o['likes'];
      $o['views'] = (int)$o['views'];
      $o['edition'] = edition_label($o['editionSize'], $o['editionSold']);
      $o['editionSize'] = $o['editionSize'] !== null ? (int)$o['editionSize'] : null;
      unset($o['editionSold']);
      foreach (['packageWeightKg', 'packageLengthCm', 'packageWidthCm', 'packageHeightCm'] as $pk) {
        $o[$pk] = $o[$pk] !== null ? (float)$o[$pk] : null;
      }
    }
    unset($o);

    $commentSql = 'SELECT id, artwork_id AS obraId, user_id AS userId, body AS text, hidden,
                    DATE_FORMAT(created_at, "%d/%m/%Y %H:%i") AS `when`
                    FROM comments' . ($isAdmin ? '' : ' WHERE hidden = 0');
    $comments = $pdo->query($commentSql)->fetchAll();
    foreach ($comments as &$c) $c['hidden'] = (bool)$c['hidden'];
    unset($c);

    $myFavorites = [];
    $myFollows = [];
    $myAddress = null;
    $myOriginAddress = null;
    $myCards = [];
    if ($me) {
      $s = $pdo->prepare('SELECT artwork_id FROM favorites WHERE user_id = ?');
      $s->execute([$me['id']]);
      $myFavorites = $s->fetchAll(PDO::FETCH_COLUMN);
      $s = $pdo->prepare('SELECT artist_id FROM follows WHERE follower_id = ?');
      $s->execute([$me['id']]);
      $myFollows = $s->fetchAll(PDO::FETCH_COLUMN);
      $s = $pdo->prepare("SELECT recipient_name AS recipientName, cep, street, number, complement, neighborhood, city, state
                           FROM addresses WHERE user_id = ? AND kind = 'delivery' ORDER BY is_default DESC, id DESC LIMIT 1");
      $s->execute([$me['id']]);
      $myAddress = $s->fetch() ?: null;

      if ($me['role'] === 'artist') {
        $s = $pdo->prepare("SELECT recipient_name AS recipientName, cep, street, number, complement, neighborhood, city, state
                             FROM addresses WHERE user_id = ? AND kind = 'origin' ORDER BY id DESC LIMIT 1");
        $s->execute([$me['id']]);
        $myOriginAddress = $s->fetch() ?: null;
      }

      $s = $pdo->prepare('SELECT id, brand, holder_name AS holderName, last4, exp_month AS expMonth, exp_year AS expYear, is_default AS isDefault
                           FROM payment_cards WHERE user_id = ? ORDER BY is_default DESC, id DESC');
      $s->execute([$me['id']]);
      $myCards = $s->fetchAll();
      foreach ($myCards as &$c) { $c['isDefault'] = (bool)$c['isDefault']; }
      unset($c);
    }

    $blockRows = $pdo->query('SELECT id, artist_id AS artistId, type, props FROM artist_page_blocks ORDER BY artist_id, position')->fetchAll();
    $pages = [];
    foreach ($blockRows as $b) {
      $pages[$b['artistId']][] = ['id' => (string)$b['id'], 'type' => $b['type'], 'props' => json_decode($b['props'], true)];
    }

    json_out([
      'me' => $me,
      'categorias' => $categorias,
      'pendingCategorias' => $pendingCategorias,
      'users' => $users,
      'obras' => $obras,
      'comments' => $comments,
      'myFavorites' => $myFavorites,
      'myFollows' => $myFollows,
      'myAddress' => $myAddress,
      'myOriginAddress' => $myOriginAddress,
      'myCards' => $myCards,
      'pages' => $pages,
    ]);
  }

  // ================= AUTENTICAÇÃO =================

  case 'login': {
    // Anti-brute-force: no máx. 10 tentativas por IP a cada 5 minutos.
    rate_limit_or_die('login', 10, 300);
    $b = body();
    $email = trim($b['email'] ?? '');
    $pass = (string)($b['pass'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || !password_verify($pass, $u['password_hash'])) json_error('E-mail ou senha incorretos', 401);
    if ($u['deactivated']) json_error('Esta conta foi desativada. Entre em contato com o suporte se isso foi um engano.', 403);
    if ($u['blocked']) json_error('Esta conta foi bloqueada pela administração', 403);

    if ($u['two_factor_enabled']) {
      $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
      $pdo->prepare('UPDATE users SET two_factor_code_hash = ?, two_factor_expires = ? WHERE id = ?')
        ->execute([hash('sha256', $code), date('Y-m-d H:i:s', time() + 600), $u['id']]);
      unset($_SESSION['user_id']);
      // Se o e-mail não sai (SMTP fora do ar), NÃO prende o usuário na tela do
      // código — devolve erro claro em vez de um beco sem saída.
      if (!send_two_factor_email($u['email'], $u['name'], $code)) {
        json_error('Não conseguimos enviar o código de verificação para o seu e-mail agora. Tente novamente em instantes.', 502);
      }
      $_SESSION['pending_2fa_user_id'] = $u['id'];
      json_out(['needsTwoFactor' => true]);
    }

    $_SESSION['user_id'] = $u['id'];
    json_out(['user' => current_user()]);
  }

  case 'verify_2fa': {
    // Anti-brute-force do código de 6 dígitos: limite por IP e por sessão.
    // Sem isso, 1 milhão de combinações ficariam abertas dentro dos 10 min.
    rate_limit_or_die('verify_2fa', 15, 300);
    $b = body();
    $code = trim((string)($b['code'] ?? ''));
    $pendingId = $_SESSION['pending_2fa_user_id'] ?? null;
    if (!$pendingId) json_error('Sessão de login expirada. Entre novamente.', 401);

    // Após 5 códigos errados nesta sessão, invalida o desafio e força re-login.
    $_SESSION['twofa_attempts'] = ($_SESSION['twofa_attempts'] ?? 0) + 1;
    if ($_SESSION['twofa_attempts'] > 5) {
      unset($_SESSION['pending_2fa_user_id'], $_SESSION['twofa_attempts']);
      $pdo->prepare('UPDATE users SET two_factor_code_hash = NULL, two_factor_expires = NULL WHERE id = ?')->execute([$pendingId]);
      json_error('Muitas tentativas incorretas. Faça login novamente.', 429);
    }

    $stmt = $pdo->prepare('SELECT id, two_factor_code_hash, two_factor_expires FROM users WHERE id = ?');
    $stmt->execute([$pendingId]);
    $u = $stmt->fetch();
    if (!$u || !$u['two_factor_code_hash'] || strtotime($u['two_factor_expires']) < time() || !hash_equals($u['two_factor_code_hash'], hash('sha256', $code))) {
      json_error('Código inválido ou expirado');
    }
    $pdo->prepare('UPDATE users SET two_factor_code_hash = NULL, two_factor_expires = NULL WHERE id = ?')->execute([$u['id']]);
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['twofa_attempts']);
    $_SESSION['user_id'] = $u['id'];
    json_out(['user' => current_user()]);
  }

  case 'resend_2fa': {
    rate_limit_or_die('resend_2fa', 5, 600);
    $pendingId = $_SESSION['pending_2fa_user_id'] ?? null;
    if (!$pendingId) json_error('Sessão de login expirada. Entre novamente.', 401);
    $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $stmt->execute([$pendingId]);
    $u = $stmt->fetch();
    if (!$u) json_error('Sessão de login expirada. Entre novamente.', 401);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE users SET two_factor_code_hash = ?, two_factor_expires = ? WHERE id = ?')
      ->execute([hash('sha256', $code), date('Y-m-d H:i:s', time() + 600), $u['id']]);
    if (!send_two_factor_email($u['email'], $u['name'], $code)) {
      json_error('Não conseguimos enviar o código agora. Tente novamente em instantes.', 502);
    }
    json_out(['ok' => true]);
  }

  case 'register': {
    rate_limit_or_die('register', 8, 600);
    $b = body();
    $name = trim($b['name'] ?? '');
    $email = trim($b['email'] ?? '');
    $pass = (string)($b['pass'] ?? '');
    $role = in_array($b['role'] ?? '', ['artist', 'visitor'], true) ? $b['role'] : 'visitor';
    if ($name === '' || $email === '' || $pass === '') json_error('Preencha todos os campos');
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) json_error('E-mail já cadastrado');

    $headline = $role === 'artist' ? 'Novo artista' : '';
    $bio = $role === 'artist' ? 'Escreva sua biografia no painel.' : '';
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));
    $ins = $pdo->prepare('INSERT INTO users (role, name, email, password_hash, headline, bio, avatar_color, email_verify_token, email_verify_expires, email_verify_last_sent)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$role, $name, $email, $hash, $headline, $bio, avatar_color($name), $token, date('Y-m-d H:i:s', time() + 86400), date('Y-m-d H:i:s')]);
    $newId = (int)$pdo->lastInsertId();

    if ($role === 'artist') {
      ensure_default_blocks($pdo, ['id' => $newId, 'name' => $name, 'headline' => $headline, 'bio' => $bio]);
    }
    send_verification_email($email, $name, $token);
    $_SESSION['user_id'] = $newId;
    json_out(['user' => current_user()]);
  }

  case 'verify_email': {
    $token = $_GET['token'] ?? '';
    $ok = false;
    if ($token !== '') {
      $stmt = $pdo->prepare('SELECT id, email_verify_expires FROM users WHERE email_verify_token = ?');
      $stmt->execute([$token]);
      $u = $stmt->fetch();
      $ok = $u && $u['email_verify_expires'] && strtotime($u['email_verify_expires']) >= time();
      if ($ok) {
        $pdo->prepare('UPDATE users SET email_verified = 1, email_verify_token = NULL, email_verify_expires = NULL WHERE id = ?')->execute([$u['id']]);
      }
    }
    header('Location: ' . SITE_BASE_URL . '/index.html?emailVerified=' . ($ok ? '1' : '0'));
    exit;
  }

  case 'resend_verification': {
    $me = require_login();
    if ($me['emailVerified']) json_out(['ok' => true, 'alreadyVerified' => true]);
    $stmt = $pdo->prepare('SELECT email_verify_last_sent FROM users WHERE id = ?');
    $stmt->execute([$me['id']]);
    $lastSent = $stmt->fetchColumn();
    if ($lastSent && strtotime($lastSent) > time() - 60) json_error('Aguarde um minuto antes de reenviar.', 429);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET email_verify_token = ?, email_verify_expires = ?, email_verify_last_sent = ? WHERE id = ?')
      ->execute([$token, date('Y-m-d H:i:s', time() + 86400), date('Y-m-d H:i:s'), $me['id']]);
    send_verification_email($me['email'], $me['name'], $token);
    json_out(['ok' => true]);
  }

  case 'mail_test': {
    // Diagnóstico (admin): tenta enviar um e-mail de verdade e devolve o
    // resultado + o erro exato do SMTP, pra descobrir por que não chega.
    $me = require_role('admin');
    $b = body();
    $to = trim($b['to'] ?? $me['email']);
    $GLOBALS['mail_last_error'] = null;
    $ok = send_mail($to, $me['name'], 'Teste de envio — Galeria Millia', mail_layout('Teste de envio', '<p>Se você recebeu este e-mail, o SMTP de produção está funcionando.</p>'));
    json_out([
      'ok' => $ok,
      'to' => $to,
      'host' => MAIL_SMTP_HOST,
      'port' => MAIL_SMTP_PORT,
      'secure' => MAIL_SMTP_SECURE,
      'mode' => MAIL_MODE,
      'error' => $GLOBALS['mail_last_error'] ?? null,
    ]);
  }

  case 'set_two_factor': {
    $me = require_login();
    $b = body();
    $enabled = !empty($b['enabled']);
    $pdo->prepare('UPDATE users SET two_factor_enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $me['id']]);
    json_out(['ok' => true, 'twoFactorEnabled' => $enabled]);
  }

  case 'forgot_password': {
    rate_limit_or_die('forgot_password', 5, 600);
    $b = body();
    $email = trim($b['email'] ?? '');
    if ($email !== '') {
      $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ?');
      $stmt->execute([$email]);
      $u = $stmt->fetch();
      if ($u) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?')
          ->execute([$token, date('Y-m-d H:i:s', time() + 3600), $u['id']]);
        send_password_reset_email($email, $u['name'], $token);
      }
    }
    // Resposta sempre genérica — não revela se o e-mail existe cadastrado ou não.
    json_out(['ok' => true]);
  }

  case 'reset_password': {
    $b = body();
    $token = trim((string)($b['token'] ?? ''));
    $pass = (string)($b['pass'] ?? '');
    if ($token === '') json_error('Link inválido. Solicite uma nova recuperação de senha.');
    if ($pass === '') json_error('Digite uma senha');
    $stmt = $pdo->prepare('SELECT id, password_reset_expires FROM users WHERE password_reset_token = ?');
    $stmt->execute([$token]);
    $u = $stmt->fetch();
    if (!$u || !$u['password_reset_expires'] || strtotime($u['password_reset_expires']) < time()) {
      json_error('Link inválido ou expirado. Solicite uma nova recuperação de senha.');
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?')
      ->execute([$hash, $u['id']]);
    $_SESSION['user_id'] = $u['id'];
    json_out(['user' => current_user()]);
  }

  case 'deactivate_account': {
    $me = require_login();
    $pdo->prepare('UPDATE users SET deactivated = 1, deactivated_at = ? WHERE id = ?')
      ->execute([date('Y-m-d H:i:s'), $me['id']]);
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
  }

  case 'save_card': {
    $me = require_login();
    $b = body();
    $holderName = trim($b['holderName'] ?? '');
    $expMonth = (int)($b['expMonth'] ?? 0);
    $expYear = (int)($b['expYear'] ?? 0);
    $isDefault = !empty($b['isDefault']);
    $id = !empty($b['id']) ? (int)$b['id'] : null;

    if ($holderName === '') json_error('Informe o nome impresso no cartão');
    if ($expMonth < 1 || $expMonth > 12) json_error('Mês de validade inválido');
    if ($expYear < (int)date('Y')) json_error('Cartão vencido');

    if ($id) {
      $chk = $pdo->prepare('SELECT id FROM payment_cards WHERE id = ? AND user_id = ?');
      $chk->execute([$id, $me['id']]);
      if (!$chk->fetch()) json_error('Cartão não encontrado', 404);
      $pdo->beginTransaction();
      if ($isDefault) $pdo->prepare('UPDATE payment_cards SET is_default = 0 WHERE user_id = ?')->execute([$me['id']]);
      $pdo->prepare('UPDATE payment_cards SET holder_name = ?, exp_month = ?, exp_year = ?, is_default = ? WHERE id = ?')
        ->execute([$holderName, $expMonth, $expYear, $isDefault ? 1 : 0, $id]);
      $pdo->commit();
      json_out(['ok' => true, 'id' => $id]);
    }

    // Cartão novo: só o número entra aqui pra extrair bandeira e últimos 4
    // dígitos — o valor completo nunca é gravado no banco.
    $cardNumberDigits = preg_replace('/\D/', '', $b['cardNumber'] ?? '');
    if (strlen($cardNumberDigits) < 13 || strlen($cardNumberDigits) > 19) json_error('Número de cartão inválido');
    $last4 = substr($cardNumberDigits, -4);
    $brand = detect_card_brand($cardNumberDigits);
    unset($cardNumberDigits);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM payment_cards WHERE user_id = ?');
    $countStmt->execute([$me['id']]);
    if ((int)$countStmt->fetchColumn() === 0) $isDefault = true;

    $pdo->beginTransaction();
    if ($isDefault) $pdo->prepare('UPDATE payment_cards SET is_default = 0 WHERE user_id = ?')->execute([$me['id']]);
    $ins = $pdo->prepare('INSERT INTO payment_cards (user_id, brand, holder_name, last4, exp_month, exp_year, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$me['id'], $brand, $holderName, $last4, $expMonth, $expYear, $isDefault ? 1 : 0]);
    $newId = (int)$pdo->lastInsertId();
    $pdo->commit();
    json_out(['ok' => true, 'id' => $newId]);
  }

  case 'set_default_card': {
    $me = require_login();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $chk = $pdo->prepare('SELECT id FROM payment_cards WHERE id = ? AND user_id = ?');
    $chk->execute([$id, $me['id']]);
    if (!$chk->fetch()) json_error('Cartão não encontrado', 404);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE payment_cards SET is_default = 0 WHERE user_id = ?')->execute([$me['id']]);
    $pdo->prepare('UPDATE payment_cards SET is_default = 1 WHERE id = ?')->execute([$id]);
    $pdo->commit();
    json_out(['ok' => true]);
  }

  case 'delete_card': {
    $me = require_login();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $chk = $pdo->prepare('SELECT id, is_default FROM payment_cards WHERE id = ? AND user_id = ?');
    $chk->execute([$id, $me['id']]);
    $card = $chk->fetch();
    if (!$card) json_error('Cartão não encontrado', 404);
    $pdo->prepare('DELETE FROM payment_cards WHERE id = ?')->execute([$id]);
    if ($card['is_default']) {
      $pdo->prepare('UPDATE payment_cards SET is_default = 1 WHERE id = (SELECT id FROM (SELECT id FROM payment_cards WHERE user_id = ? ORDER BY id DESC LIMIT 1) t)')
        ->execute([$me['id']]);
    }
    json_out(['ok' => true]);
  }

  case 'logout': {
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
  }

  // ================= OBRAS (ARTISTA) =================

  case 'save_obra': {
    $me = require_role('artist');
    $b = body();
    $title = trim($b['title'] ?? '');
    if ($title === '') json_error('Dê um título à obra');
    $catName = trim($b['cat'] ?? '');
    $catId = category_id_by_name($pdo, $catName);
    if ($catId === null) {
      // Artista sugeriu uma categoria nova: cria como pendente (approved=0),
      // pra o admin aprovar depois. Sem a flag, mantém o erro de sempre.
      if (!empty($b['proposeCat']) && $catName !== '') {
        try {
          $pdo->prepare('INSERT INTO categories (name, approved) VALUES (?, 0)')->execute([$catName]);
          $catId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
          if ($e->getCode() === '23000') $catId = category_id_by_name($pdo, $catName);
          else throw $e;
        }
      }
      if ($catId === null) json_error('Categoria inválida');
    }
    $price = round((float)($b['price'] ?? 0) * 100);
    if ($price <= 0) json_error('Informe um preço válido, maior que zero');
    $colId = collection_id_for($pdo, (int)$me['id'], $b['collection'] ?? '');
    $desc = $b['desc'] ?? '';
    $editionSize = isset($b['editionSize']) && $b['editionSize'] !== '' && $b['editionSize'] !== null ? (int)$b['editionSize'] : null;
    if ($editionSize !== null && $editionSize < 1) json_error('O tamanho da edição deve ser pelo menos 1');

    $numOrNull = function ($v) { return ($v === '' || $v === null) ? null : (float)$v; };
    $pkgWeight = $numOrNull($b['packageWeightKg'] ?? null);
    $pkgLength = $numOrNull($b['packageLengthCm'] ?? null);
    $pkgWidth  = $numOrNull($b['packageWidthCm'] ?? null);
    $pkgHeight = $numOrNull($b['packageHeightCm'] ?? null);
    foreach ([$pkgWeight, $pkgLength, $pkgWidth, $pkgHeight] as $v) {
      if ($v !== null && $v <= 0) json_error('Peso e dimensões da embalagem devem ser maiores que zero');
    }

    if (!empty($b['id'])) {
      $chk = $pdo->prepare('SELECT artist_id, edition_sold FROM artworks WHERE id = ?');
      $chk->execute([$b['id']]);
      $row = $chk->fetch();
      if ($row === false || (int)$row['artist_id'] !== (int)$me['id']) json_error('Sem permissão', 403);
      if ($editionSize !== null && $editionSize < (int)$row['edition_sold']) {
        json_error('Já foram vendidas ' . $row['edition_sold'] . ' cópias — o tamanho da edição não pode ser menor que isso');
      }
      $upd = $pdo->prepare('UPDATE artworks SET title=?, category_id=?, price_cents=?, collection_id=?, description=?, edition_size=?,
                             package_weight_kg=?, package_length_cm=?, package_width_cm=?, package_height_cm=? WHERE id=?');
      $upd->execute([$title, $catId, $price, $colId, $desc, $editionSize, $pkgWeight, $pkgLength, $pkgWidth, $pkgHeight, $b['id']]);
      json_out(['ok' => true, 'id' => (int)$b['id']]);
    } else {
      require_verified_email($me);
      $ins = $pdo->prepare('INSERT INTO artworks (artist_id, category_id, collection_id, title, description, price_cents, width_cm, height_cm, edition_size,
                             package_weight_kg, package_length_cm, package_width_cm, package_height_cm, approved, sold)
                             VALUES (?, ?, ?, ?, ?, ?, 60, 45, ?, ?, ?, ?, ?, 0, 0)');
      $ins->execute([$me['id'], $catId, $colId, $title, $desc, $price, $editionSize, $pkgWeight, $pkgLength, $pkgWidth, $pkgHeight]);
      // Confirma pro artista que a obra entrou na fila de curadoria.
      send_obra_submitted_email($me['email'], $me['name'], $title);
      json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
  }

  case 'upload_artwork_image': {
    $me = require_role('artist', 'admin');
    rate_limit_or_die('upload', 40, 300);
    $artworkId = (int)($_POST['id'] ?? 0);
    $chk = $pdo->prepare('SELECT artist_id FROM artworks WHERE id = ?');
    $chk->execute([$artworkId]);
    $owner = $chk->fetchColumn();
    if ($owner === false) json_error('Obra não encontrada', 404);
    if ($me['role'] !== 'admin' && (int)$owner !== (int)$me['id']) json_error('Sem permissão', 403);

    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) json_error('Envie um arquivo de imagem válido');
    if ($_FILES['image']['size'] > 8 * 1024 * 1024) json_error('Imagem muito grande (máximo 8MB)');
    $info = getimagesize($_FILES['image']['tmp_name']);
    if ($info === false) json_error('Arquivo não é uma imagem válida');
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? null;
    if (!$ext) json_error('Formato não suportado. Use JPG, PNG ou WebP.');

    $dir = __DIR__ . '/../uploads/artworks';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) json_error('Não foi possível preparar a pasta de upload', 500);
    $filename = 'obra-' . $artworkId . '-' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $filename)) json_error('Falha ao salvar a imagem', 500);
    $url = 'uploads/artworks/' . $filename;

    $pdo->prepare('DELETE FROM artwork_images WHERE artwork_id = ?')->execute([$artworkId]);
    $pdo->prepare('INSERT INTO artwork_images (artwork_id, url, position) VALUES (?, ?, 0)')->execute([$artworkId, $url]);
    json_out(['ok' => true, 'url' => $url]);
  }

  case 'upload_block_image': {
    // Imagem de um bloco do editor de página do artista. A URL retorna pro
    // front, que a guarda nas props do bloco (persistidas via save_page) —
    // por isso dá pra trocar a imagem quantas vezes quiser.
    $me = require_role('artist', 'admin');
    rate_limit_or_die('upload', 40, 300);
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) json_error('Envie um arquivo de imagem válido');
    if ($_FILES['image']['size'] > 8 * 1024 * 1024) json_error('Imagem muito grande (máximo 8MB)');
    $info = getimagesize($_FILES['image']['tmp_name']);
    if ($info === false) json_error('Arquivo não é uma imagem válida');
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? null;
    if (!$ext) json_error('Formato não suportado. Use JPG, PNG ou WebP.');

    $dir = __DIR__ . '/../uploads/blocks';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) json_error('Não foi possível preparar a pasta de upload', 500);
    $filename = 'bloco-' . $me['id'] . '-' . time() . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $filename)) json_error('Falha ao salvar a imagem', 500);
    json_out(['ok' => true, 'url' => 'uploads/blocks/' . $filename]);
  }

  case 'upload_avatar': {
    $me = require_login();
    rate_limit_or_die('upload', 40, 300);
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) json_error('Envie um arquivo de imagem válido');
    if ($_FILES['image']['size'] > 4 * 1024 * 1024) json_error('Imagem muito grande (máximo 4MB)');
    $info = getimagesize($_FILES['image']['tmp_name']);
    if ($info === false) json_error('Arquivo não é uma imagem válida');
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? null;
    if (!$ext) json_error('Formato não suportado. Use JPG, PNG ou WebP.');

    $dir = __DIR__ . '/../uploads/avatars';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) json_error('Não foi possível preparar a pasta de upload', 500);
    $filename = 'avatar-' . $me['id'] . '-' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $filename)) json_error('Falha ao salvar a imagem', 500);
    $url = 'uploads/avatars/' . $filename;
    $pdo->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$url, $me['id']]);
    json_out(['ok' => true, 'url' => $url]);
  }

  case 'upload_cover': {
    // Capa (banner) da página pública do artista.
    $me = require_role('artist', 'admin');
    rate_limit_or_die('upload', 40, 300);
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) json_error('Envie um arquivo de imagem válido');
    if ($_FILES['image']['size'] > 8 * 1024 * 1024) json_error('Imagem muito grande (máximo 8MB)');
    $info = getimagesize($_FILES['image']['tmp_name']);
    if ($info === false) json_error('Arquivo não é uma imagem válida');
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? null;
    if (!$ext) json_error('Formato não suportado. Use JPG, PNG ou WebP.');

    $dir = __DIR__ . '/../uploads/covers';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) json_error('Não foi possível preparar a pasta de upload', 500);
    $filename = 'capa-' . $me['id'] . '-' . time() . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . '/' . $filename)) json_error('Falha ao salvar a imagem', 500);
    $url = 'uploads/covers/' . $filename;
    $pdo->prepare('UPDATE users SET cover_url = ? WHERE id = ?')->execute([$url, $me['id']]);
    json_out(['ok' => true, 'url' => $url]);
  }

  case 'delete_obra': {
    $me = require_login();
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $chk = $pdo->prepare('SELECT artist_id FROM artworks WHERE id = ?');
    $chk->execute([$id]);
    $owner = $chk->fetchColumn();
    if ($owner === false) json_error('Obra não encontrada', 404);
    if ($me['role'] !== 'admin' && (int)$owner !== (int)$me['id']) json_error('Sem permissão', 403);
    try {
      $pdo->prepare('DELETE FROM artworks WHERE id = ?')->execute([$id]);
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') json_error('Não é possível excluir: esta obra já faz parte de um pedido.');
      throw $e;
    }
    json_out(['ok' => true]);
  }

  case 'approve_obra': {
    require_role('admin');
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $pdo->prepare('UPDATE artworks SET approved = 1 WHERE id = ?')->execute([$id]);
    // Avisa o artista por e-mail que a obra foi publicada.
    $q = $pdo->prepare('SELECT a.title, u.email, u.name FROM artworks a JOIN users u ON u.id = a.artist_id WHERE a.id = ?');
    $q->execute([$id]);
    if ($row = $q->fetch()) send_obra_approved_email($row['email'], $row['name'], $row['title']);
    json_out(['ok' => true]);
  }

  case 'refuse_obra': {
    require_role('admin');
    $b = body();
    $pdo->prepare('DELETE FROM artworks WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
    json_out(['ok' => true]);
  }

  // ================= SOCIAL =================

  case 'toggle_favorite': {
    $me = require_login();
    $b = body();
    $artworkId = (int)($b['artworkId'] ?? 0);
    $chk = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND artwork_id = ?');
    $chk->execute([$me['id'], $artworkId]);
    if ($chk->fetch()) {
      $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND artwork_id = ?')->execute([$me['id'], $artworkId]);
      $favorited = false;
    } else {
      $pdo->prepare('INSERT INTO favorites (user_id, artwork_id) VALUES (?, ?)')->execute([$me['id'], $artworkId]);
      $favorited = true;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM favorites WHERE artwork_id = ?');
    $count->execute([$artworkId]);
    json_out(['favorited' => $favorited, 'likes' => (int)$count->fetchColumn()]);
  }

  case 'toggle_follow': {
    $me = require_login();
    $b = body();
    $artistId = (int)($b['artistId'] ?? 0);
    if ($artistId === (int)$me['id']) json_error('Não é possível seguir a si mesmo');
    $chk = $pdo->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND artist_id = ?');
    $chk->execute([$me['id'], $artistId]);
    if ($chk->fetch()) {
      $pdo->prepare('DELETE FROM follows WHERE follower_id = ? AND artist_id = ?')->execute([$me['id'], $artistId]);
      $following = false;
    } else {
      $pdo->prepare('INSERT INTO follows (follower_id, artist_id) VALUES (?, ?)')->execute([$me['id'], $artistId]);
      $following = true;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM follows WHERE artist_id = ?');
    $count->execute([$artistId]);
    json_out(['following' => $following, 'followers' => (int)$count->fetchColumn()]);
  }

  case 'add_comment': {
    $me = require_login();
    $b = body();
    $artworkId = (int)($b['artworkId'] ?? 0);
    $text = trim($b['text'] ?? '');
    if ($text === '') json_error('Escreva um comentário');
    $ins = $pdo->prepare('INSERT INTO comments (artwork_id, user_id, body) VALUES (?, ?, ?)');
    $ins->execute([$artworkId, $me['id'], $text]);
    json_out([
      'id' => (int)$pdo->lastInsertId(),
      'obraId' => $artworkId, 'userId' => $me['id'], 'text' => $text, 'when' => 'agora', 'hidden' => false,
    ]);
  }

  case 'toggle_comment_hidden': {
    require_role('admin');
    $b = body();
    $pdo->prepare('UPDATE comments SET hidden = NOT hidden WHERE id = ?')->execute([(int)($b['id'] ?? 0)]);
    json_out(['ok' => true]);
  }

  // ================= PERFIL E PÁGINA DO ARTISTA =================

  case 'save_profile': {
    $me = require_role('artist');
    $b = body();
    // O nome não é editável aqui de propósito — só o administrador pode
    // renomear uma conta (ação rename_user), pra evitar impersonação.
    $headline = $b['headline'] ?? $me['headline'];
    $bio = $b['bio'] ?? $me['bio'];
    $pdo->prepare('UPDATE users SET headline=?, bio=? WHERE id=?')->execute([$headline, $bio, $me['id']]);
    json_out(['ok' => true]);
  }

  case 'save_origin_address': {
    $me = require_role('artist');
    $b = body();
    $required = ['recipientName', 'cep', 'street', 'number', 'neighborhood', 'city', 'state'];
    foreach ($required as $field) {
      if (trim($b[$field] ?? '') === '') json_error('Preencha o endereço de origem completo');
    }
    $existing = $pdo->prepare("SELECT id FROM addresses WHERE user_id = ? AND kind = 'origin' ORDER BY id DESC LIMIT 1");
    $existing->execute([$me['id']]);
    $addressId = $existing->fetchColumn();
    if ($addressId) {
      $upd = $pdo->prepare('UPDATE addresses SET recipient_name=?, cep=?, street=?, number=?, complement=?, neighborhood=?, city=?, state=? WHERE id=?');
      $upd->execute([$b['recipientName'], $b['cep'], $b['street'], $b['number'], $b['complement'] ?? '', $b['neighborhood'], $b['city'], $b['state'], $addressId]);
    } else {
      $ins = $pdo->prepare("INSERT INTO addresses (user_id, kind, recipient_name, cep, street, number, complement, neighborhood, city, state, is_default) VALUES (?, 'origin', ?, ?, ?, ?, ?, ?, ?, ?, 1)");
      $ins->execute([$me['id'], $b['recipientName'], $b['cep'], $b['street'], $b['number'], $b['complement'] ?? '', $b['neighborhood'], $b['city'], $b['state']]);
    }
    json_out(['ok' => true]);
  }

  case 'rename_user': {
    require_role('admin');
    $b = body();
    $userId = (int)($b['userId'] ?? 0);
    $name = trim($b['name'] ?? '');
    if ($name === '') json_error('Nome não pode ser vazio');
    $chk = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $chk->execute([$userId]);
    if (!$chk->fetch()) json_error('Usuário não encontrado', 404);
    $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $userId]);
    json_out(['ok' => true]);
  }

  case 'save_page': {
    $me = require_login();
    rate_limit_or_die('save_page', 60, 300);
    $b = body();
    $artistId = (int)($b['artistId'] ?? 0);
    // Só o dono da página (ou admin) pode salvar — isolamento por artista.
    if ($me['role'] !== 'admin' && (int)$me['id'] !== $artistId) json_error('Sem permissão', 403);
    $blocks = is_array($b['blocks'] ?? null) ? $b['blocks'] : [];

    // Validação de estrutura do editor (defesa em profundidade):
    //  - tipo de bloco tem que estar na lista permitida (nada arbitrário no banco);
    //  - número de blocos e tamanho das props limitados (evita abuso/DoS de payload).
    $allowedTypes = ['hero', 'text', 'heading', 'gallery', 'quote', 'button', 'image', 'divider', 'spacer'];
    if (count($blocks) > 100) json_error('A página tem blocos demais (máximo 100).', 413);
    $clean = [];
    foreach ($blocks as $blk) {
      if (!is_array($blk)) json_error('Bloco inválido.');
      $type = $blk['type'] ?? '';
      if (!in_array($type, $allowedTypes, true)) json_error('Tipo de bloco não permitido: ' . $type);
      $propsJson = json_encode(is_array($blk['props'] ?? null) ? $blk['props'] : [], JSON_UNESCAPED_UNICODE);
      if ($propsJson === false) json_error('Propriedades do bloco inválidas.');
      if (strlen($propsJson) > 40000) json_error('Um dos blocos é grande demais.', 413);
      $clean[] = ['type' => $type, 'props' => $propsJson];
    }

    $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM artist_page_blocks WHERE artist_id = ?')->execute([$artistId]);
      $ins = $pdo->prepare('INSERT INTO artist_page_blocks (artist_id, type, position, props) VALUES (?, ?, ?, ?)');
      foreach ($clean as $i => $blk) {
        $ins->execute([$artistId, $blk['type'], $i, $blk['props']]);
      }
      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
    json_out(['ok' => true]);
  }

  // ================= ADMIN =================

  case 'add_category': {
    require_role('admin');
    $b = body();
    $name = trim($b['name'] ?? '');
    if ($name === '') json_error('Nome inválido');
    try {
      $pdo->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
    } catch (PDOException $e) {
      if ($e->getCode() === '23000') json_error('Categoria já existe');
      throw $e;
    }
    json_out(['ok' => true]);
  }

  case 'approve_category': {
    require_role('admin');
    $b = body();
    $name = trim($b['name'] ?? '');
    if ($name === '') json_error('Nome inválido');
    $pdo->prepare('UPDATE categories SET approved = 1 WHERE name = ?')->execute([$name]);
    json_out(['ok' => true]);
  }

  case 'delete_category': {
    require_role('admin');
    $b = body();
    $name = $b['name'] ?? '';
    // Bloqueia a exclusão se alguma obra ainda usa a categoria — antes, o
    // banco deixava as obras órfãs (sem categoria) silenciosamente.
    $count = $pdo->prepare('SELECT COUNT(*) FROM artworks a JOIN categories c ON c.id = a.category_id WHERE c.name = ?');
    $count->execute([$name]);
    $inUse = (int)$count->fetchColumn();
    if ($inUse > 0) {
      json_error('Não dá pra excluir "' . $name . '": ' . $inUse . ' obra(s) usam essa categoria. Mude a categoria dessas obras primeiro.');
    }
    $pdo->prepare('DELETE FROM categories WHERE name = ?')->execute([$name]);
    json_out(['ok' => true]);
  }

  case 'set_user_role': {
    $me = require_role('admin');
    $b = body();
    $userId = (int)($b['userId'] ?? 0);
    $role = $b['role'] ?? '';
    if ($userId === (int)$me['id']) json_error('Você não pode alterar sua própria permissão');
    if (!in_array($role, ['admin', 'artist', 'visitor'], true)) json_error('Papel inválido');
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
    if ($role === 'artist') {
      $stmt = $pdo->prepare('SELECT id, name, headline, bio FROM users WHERE id = ?');
      $stmt->execute([$userId]);
      ensure_default_blocks($pdo, $stmt->fetch());
    }
    json_out(['ok' => true]);
  }

  case 'toggle_user_block': {
    $me = require_role('admin');
    $b = body();
    $userId = (int)($b['userId'] ?? 0);
    if ($userId === (int)$me['id']) json_error('Você não pode bloquear a si mesmo');
    $pdo->prepare('UPDATE users SET blocked = NOT blocked WHERE id = ?')->execute([$userId]);
    json_out(['ok' => true]);
  }

  case 'reactivate_user': {
    require_role('admin');
    $b = body();
    $userId = (int)($b['userId'] ?? 0);
    $pdo->prepare('UPDATE users SET deactivated = 0, deactivated_at = NULL WHERE id = ?')->execute([$userId]);
    json_out(['ok' => true]);
  }

  // ================= FRETE =================

  case 'calculate_shipping': {
    $me = require_login();
    $b = body();
    $destCep = preg_replace('/\D/', '', $b['cep'] ?? '');
    $ids = array_map('intval', is_array($b['artworkIds'] ?? null) ? $b['artworkIds'] : []);
    if (strlen($destCep) !== 8) json_error('CEP de destino inválido');
    if (!$ids) json_error('Nenhuma obra selecionada');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
      SELECT a.id, a.artist_id AS artistId, a.package_weight_kg AS weight, a.package_length_cm AS length,
             a.package_width_cm AS width, a.package_height_cm AS height, u.name AS artistName
      FROM artworks a JOIN users u ON u.id = a.artist_id
      WHERE a.id IN ($placeholders)
    ");
    $stmt->execute($ids);
    $items = $stmt->fetchAll();

    $groups = [];
    foreach ($items as $it) { $groups[$it['artistId']][] = $it; }

    $originStmt = $pdo->prepare("SELECT cep FROM addresses WHERE user_id = ? AND kind = 'origin' ORDER BY id DESC LIMIT 1");

    $result = [];
    foreach ($groups as $artistId => $groupItems) {
      $artistName = $groupItems[0]['artistName'];
      $originStmt->execute([$artistId]);
      $originCep = $originStmt->fetchColumn();

      $missingPkg = array_filter($groupItems, function ($it) {
        return $it['weight'] === null || $it['length'] === null || $it['width'] === null || $it['height'] === null;
      });

      if (!$originCep) {
        $result[] = ['artistId' => $artistId, 'artistName' => $artistName, 'ok' => false, 'reason' => 'O artista ainda não cadastrou o endereço de origem.'];
        continue;
      }
      if ($missingPkg) {
        $result[] = ['artistId' => $artistId, 'artistName' => $artistName, 'ok' => false, 'reason' => 'Uma das obras não tem peso/dimensões de embalagem cadastrados.'];
        continue;
      }

      $products = array_map(function ($it) {
        return [
          'id' => (string)$it['id'],
          'width' => (float)$it['width'], 'height' => (float)$it['height'],
          'length' => (float)$it['length'], 'weight' => (float)$it['weight'],
          'insurance_value' => 0, 'quantity' => 1,
        ];
      }, array_values($groupItems));

      try {
        $quotes = melhor_envio_calculate(preg_replace('/\D/', '', $originCep), $destCep, $products);
        $options = [];
        foreach ($quotes as $q) {
          if (!is_array($q) || isset($q['error']) || !isset($q['price'])) continue;
          $options[] = [
            'id' => $q['id'] ?? null,
            'name' => $q['name'] ?? '',
            'company' => $q['company']['name'] ?? '',
            'price' => (float)$q['price'],
            'deliveryTime' => $q['delivery_time'] ?? null,
          ];
        }
        if (!$options) {
          $result[] = ['artistId' => $artistId, 'artistName' => $artistName, 'ok' => false, 'reason' => 'Nenhuma transportadora disponível para esse trajeto.'];
        } else {
          usort($options, function ($a, $b) { return $a['price'] <=> $b['price']; });
          $result[] = ['artistId' => $artistId, 'artistName' => $artistName, 'ok' => true, 'options' => $options];
        }
      } catch (Throwable $e) {
        error_log('[melhor-envio] ' . $e->getMessage());
        $result[] = ['artistId' => $artistId, 'artistName' => $artistName, 'ok' => false, 'reason' => 'Não foi possível calcular o frete agora.'];
      }
    }

    json_out(['groups' => $result]);
  }

  // ================= CHECKOUT =================

  case 'checkout': {
    $me = require_login();
    require_verified_email($me);
    $b = body();
    $ids = array_map('intval', is_array($b['artworkIds'] ?? null) ? $b['artworkIds'] : []);
    if (!$ids) json_error('Sacola vazia');

    $addr = is_array($b['address'] ?? null) ? $b['address'] : [];
    $required = ['recipientName', 'cep', 'street', 'number', 'neighborhood', 'city', 'state'];
    foreach ($required as $field) {
      if (trim($addr[$field] ?? '') === '') json_error('Preencha o endereço de entrega completo');
    }
    $shippingSel = is_array($b['shipping'] ?? null) ? $b['shipping'] : [];
    $destCep = preg_replace('/\D/', '', $addr['cep'] ?? '');

    // Recalcula o frete no servidor (nunca confia no preço mandado pelo cliente).
    $shippingCents = 0;
    $shippingLabels = []; // ex.: "Correios PAC" por artista, pra o admin saber o serviço escolhido
    if ($shippingSel && strlen($destCep) === 8) {
      $placeholders = implode(',', array_fill(0, count($ids), '?'));
      $pkgStmt = $pdo->prepare("
        SELECT a.id, a.artist_id AS artistId, a.package_weight_kg AS weight, a.package_length_cm AS length,
               a.package_width_cm AS width, a.package_height_cm AS height
        FROM artworks a WHERE a.id IN ($placeholders)
      ");
      $pkgStmt->execute($ids);
      $pkgGroups = [];
      foreach ($pkgStmt->fetchAll() as $it) { $pkgGroups[$it['artistId']][] = $it; }
      $originStmt = $pdo->prepare("SELECT cep FROM addresses WHERE user_id = ? AND kind = 'origin' ORDER BY id DESC LIMIT 1");
      foreach ($pkgGroups as $artistId => $groupItems) {
        $optionId = $shippingSel[$artistId] ?? null;
        if ($optionId === null) continue;
        $missingPkg = array_filter($groupItems, fn($it) => $it['weight'] === null || $it['length'] === null || $it['width'] === null || $it['height'] === null);
        if ($missingPkg) continue;
        $originStmt->execute([$artistId]);
        $originCep = $originStmt->fetchColumn();
        if (!$originCep) continue;
        $products = array_map(fn($it) => [
          'id' => (string)$it['id'], 'width' => (float)$it['width'], 'height' => (float)$it['height'],
          'length' => (float)$it['length'], 'weight' => (float)$it['weight'], 'insurance_value' => 0, 'quantity' => 1,
        ], array_values($groupItems));
        try {
          $quotes = melhor_envio_calculate(preg_replace('/\D/', '', $originCep), $destCep, $products);
          foreach ($quotes as $q) {
            if (is_array($q) && !isset($q['error']) && isset($q['price']) && (int)($q['id'] ?? -1) === (int)$optionId) {
              $shippingCents += (int)round(((float)$q['price']) * 100);
              $label = trim(($q['company']['name'] ?? '') . ' ' . ($q['name'] ?? ''));
              if ($label !== '') $shippingLabels[] = $label;
              break;
            }
          }
        } catch (Throwable $e) {
          error_log('[melhor-envio] checkout: ' . $e->getMessage());
        }
      }
    }

    $pdo->beginTransaction();
    try {
      $existing = $pdo->prepare("SELECT id FROM addresses WHERE user_id = ? AND kind = 'delivery' ORDER BY is_default DESC, id DESC LIMIT 1");
      $existing->execute([$me['id']]);
      $addressId = $existing->fetchColumn();
      if ($addressId) {
        $updAddr = $pdo->prepare('UPDATE addresses SET recipient_name=?, cep=?, street=?, number=?, complement=?, neighborhood=?, city=?, state=? WHERE id=?');
        $updAddr->execute([$addr['recipientName'], $addr['cep'], $addr['street'], $addr['number'], $addr['complement'] ?? '', $addr['neighborhood'], $addr['city'], $addr['state'], $addressId]);
      } else {
        $insAddr = $pdo->prepare("INSERT INTO addresses (user_id, kind, recipient_name, cep, street, number, complement, neighborhood, city, state, is_default) VALUES (?, 'delivery', ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $insAddr->execute([$me['id'], $addr['recipientName'], $addr['cep'], $addr['street'], $addr['number'], $addr['complement'] ?? '', $addr['neighborhood'], $addr['city'], $addr['state']]);
        $addressId = (int)$pdo->lastInsertId();
      }

      // O pedido nasce aguardando pagamento — só vira "pago" (e a obra só é
      // marcada como vendida, via trigger) quando o Mercado Pago confirmar.
      $shippingService = $shippingLabels ? implode('; ', array_unique($shippingLabels)) : null;
      $ins = $pdo->prepare('INSERT INTO orders (buyer_id, address_id, status, shipping_cents, shipping_service) VALUES (?, ?, "pending_payment", ?, ?)');
      $ins->execute([$me['id'], $addressId, $shippingCents, $shippingService]);
      $orderId = (int)$pdo->lastInsertId();

      $priceStmt = $pdo->prepare('SELECT price_cents, title FROM artworks WHERE id = ? AND approved = 1 AND sold = 0');
      $itemIns = $pdo->prepare('INSERT INTO order_items (order_id, artwork_id, price_cents) VALUES (?, ?, ?)');
      $mpItems = [];
      foreach ($ids as $id) {
        $priceStmt->execute([$id]);
        $row = $priceStmt->fetch();
        if ($row === false) throw new RuntimeException('Uma das obras não está mais disponível.');
        $itemIns->execute([$orderId, $id, $row['price_cents']]);
        $mpItems[] = [
          'id' => (string)$id,
          'title' => $row['title'],
          'quantity' => 1,
          'unit_price' => round($row['price_cents'] / 100, 2),
          'currency_id' => 'BRL',
        ];
      }
      // O trigger de order_items recalcula total_cents só com base nos itens; soma o frete por cima.
      $pdo->prepare('UPDATE orders SET total_cents = total_cents + ? WHERE id = ?')->execute([$shippingCents, $orderId]);
      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      json_error($e->getMessage() ?: 'Não foi possível concluir a compra.');
    }

    if ($shippingCents > 0) {
      $mpItems[] = ['id' => 'frete', 'title' => 'Frete', 'quantity' => 1, 'unit_price' => round($shippingCents / 100, 2), 'currency_id' => 'BRL'];
    }

    // Cria a preferência do Checkout Pro. Se falhar, o pedido recém-criado é
    // cancelado pra não ficar pendurado como "aguardando pagamento" pra sempre.
    try {
      $preference = [
        'items' => $mpItems,
        'external_reference' => (string)$orderId,
        'statement_descriptor' => 'GALERIAMILLIA',
        'payer' => ['name' => $me['name'], 'email' => $me['email']],
        'back_urls' => [
          'success' => SITE_BASE_URL . '/index.html',
          'pending' => SITE_BASE_URL . '/index.html',
          'failure' => SITE_BASE_URL . '/index.html',
        ],
        'notification_url' => SITE_BASE_URL . '/backend/api.php?action=mp_webhook',
      ];
      // auto_return exige URL pública — em localhost o MP recusa a preferência.
      if (strpos(SITE_BASE_URL, 'localhost') === false) $preference['auto_return'] = 'approved';
      $pref = mp_api('POST', '/checkout/preferences', $preference);
    } catch (Throwable $e) {
      $pdo->prepare('UPDATE orders SET status = "cancelled" WHERE id = ?')->execute([$orderId]);
      error_log('[mercado-pago] falha ao criar preferência do pedido ' . $orderId . ': ' . $e->getMessage());
      json_error('Não foi possível iniciar o pagamento. Tente novamente em instantes.', 502);
    }

    $total = $pdo->prepare('SELECT total_cents FROM orders WHERE id = ?');
    $total->execute([$orderId]);
    json_out(['ok' => true, 'orderId' => $orderId, 'total' => $total->fetchColumn() / 100, 'initPoint' => $pref['init_point'] ?? '']);
  }

  case 'confirm_payment': {
    // Chamado pelo front quando o comprador volta do Checkout Pro. Consulta o
    // pagamento direto na API (nunca confia no status vindo da URL de retorno).
    $me = require_login();
    $b = body();
    $paymentId = preg_replace('/\D/', '', (string)($b['paymentId'] ?? ''));
    if ($paymentId === '') json_error('Pagamento não informado');

    $payment = mp_api('GET', '/v1/payments/' . $paymentId);
    $orderId = (int)($payment['external_reference'] ?? 0);
    $chk = $pdo->prepare('SELECT buyer_id, status FROM orders WHERE id = ?');
    $chk->execute([$orderId]);
    $order = $chk->fetch();
    if (!$order || (int)$order['buyer_id'] !== (int)$me['id']) json_error('Pedido não encontrado', 404);

    if ($order['status'] !== 'pending_payment') {
      json_out(['status' => $order['status'] === 'cancelled' ? 'rejected' : 'approved', 'orderId' => $orderId]);
    }
    mp_apply_payment($pdo, $payment);
    $status = $payment['status'] ?? '';
    json_out(['status' => $status === 'approved' ? 'approved' : (in_array($status, ['rejected', 'cancelled'], true) ? 'rejected' : 'pending'), 'orderId' => $orderId]);
  }

  case 'resume_payment': {
    // Retoma o pagamento de um pedido que ficou "aguardando pagamento":
    // recria a preferência do Checkout Pro pro MESMO pedido (external_reference
    // = orderId) e devolve o link. Não cria pedido novo.
    $me = require_login();
    $b = body();
    $orderId = (int)($b['orderId'] ?? 0);
    $chk = $pdo->prepare('SELECT buyer_id, status, shipping_cents FROM orders WHERE id = ?');
    $chk->execute([$orderId]);
    $order = $chk->fetch();
    if (!$order || (int)$order['buyer_id'] !== (int)$me['id']) json_error('Pedido não encontrado', 404);
    if ($order['status'] !== 'pending_payment') json_error('Este pedido não está aguardando pagamento.', 409);

    $its = $pdo->prepare('SELECT oi.price_cents, a.title, a.sold FROM order_items oi JOIN artworks a ON a.id = oi.artwork_id WHERE oi.order_id = ?');
    $its->execute([$orderId]);
    $rows = $its->fetchAll();
    if (!$rows) json_error('Pedido sem itens.', 409);
    $mpItems = [];
    foreach ($rows as $i => $r) {
      if ((int)$r['sold'] === 1) json_error('Uma das obras deste pedido já foi vendida.', 409);
      $mpItems[] = ['id' => (string)($i + 1), 'title' => $r['title'], 'quantity' => 1, 'unit_price' => round($r['price_cents'] / 100, 2), 'currency_id' => 'BRL'];
    }
    $shippingCents = (int)$order['shipping_cents'];
    if ($shippingCents > 0) $mpItems[] = ['id' => 'frete', 'title' => 'Frete', 'quantity' => 1, 'unit_price' => round($shippingCents / 100, 2), 'currency_id' => 'BRL'];

    try {
      $preference = [
        'items' => $mpItems,
        'external_reference' => (string)$orderId,
        'statement_descriptor' => 'GALERIAMILLIA',
        'payer' => ['name' => $me['name'], 'email' => $me['email']],
        'back_urls' => [
          'success' => SITE_BASE_URL . '/index.html',
          'pending' => SITE_BASE_URL . '/index.html',
          'failure' => SITE_BASE_URL . '/index.html',
        ],
        'notification_url' => SITE_BASE_URL . '/backend/api.php?action=mp_webhook',
      ];
      if (strpos(SITE_BASE_URL, 'localhost') === false) $preference['auto_return'] = 'approved';
      $pref = mp_api('POST', '/checkout/preferences', $preference);
    } catch (Throwable $e) {
      error_log('[mercado-pago] falha ao retomar pagamento do pedido ' . $orderId . ': ' . $e->getMessage());
      json_error('Não foi possível iniciar o pagamento. Tente novamente em instantes.', 502);
    }
    json_out(['ok' => true, 'orderId' => $orderId, 'initPoint' => $pref['init_point'] ?? '']);
  }

  case 'cancel_order': {
    // Comprador cancela um pedido próprio que ainda não foi pago.
    $me = require_login();
    $b = body();
    $orderId = (int)($b['orderId'] ?? 0);
    $chk = $pdo->prepare('SELECT buyer_id, status FROM orders WHERE id = ?');
    $chk->execute([$orderId]);
    $order = $chk->fetch();
    if (!$order || (int)$order['buyer_id'] !== (int)$me['id']) json_error('Pedido não encontrado', 404);
    if ($order['status'] !== 'pending_payment') json_error('Só dá pra cancelar um pedido que ainda está aguardando pagamento.', 409);
    $pdo->prepare('UPDATE orders SET status = "cancelled" WHERE id = ?')->execute([$orderId]);
    json_out(['ok' => true]);
  }

  case 'reconcile_order': {
    // Rede de segurança pra quando o webhook não chega: pergunta ativamente ao
    // Mercado Pago os pagamentos deste pedido e, se houver um aprovado, fecha o
    // pedido. Idempotente. Pode ser chamada pelo dono do pedido ou pelo admin.
    $me = require_login();
    $b = body();
    $orderId = (int)($b['orderId'] ?? 0);
    $chk = $pdo->prepare('SELECT buyer_id, status FROM orders WHERE id = ?');
    $chk->execute([$orderId]);
    $order = $chk->fetch();
    if (!$order) json_error('Pedido não encontrado', 404);
    if ($me['role'] !== 'admin' && (int)$order['buyer_id'] !== (int)$me['id']) json_error('Sem permissão', 403);
    if ($order['status'] !== 'pending_payment') json_out(['status' => $order['status'], 'changed' => false]);

    try {
      $search = mp_api('GET', '/v1/payments/search?external_reference=' . $orderId . '&sort=date_created&criteria=desc');
      $approved = null;
      foreach (($search['results'] ?? []) as $p) {
        if (($p['status'] ?? '') === 'approved') { $approved = $p; break; }
      }
      if ($approved) mp_apply_payment($pdo, $approved);
    } catch (Throwable $e) {
      error_log('[mercado-pago] reconciliar pedido ' . $orderId . ': ' . $e->getMessage());
      json_error('Não foi possível consultar o Mercado Pago agora.', 502);
    }
    // relê o status atualizado
    $s2 = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    $s2->execute([$orderId]);
    $newStatus = $s2->fetchColumn();
    json_out(['status' => $newStatus, 'changed' => $newStatus !== 'pending_payment']);
  }

  case 'mp_webhook': {
    // Notificação server-to-server do Mercado Pago (produção). Sempre responde
    // 200 pra não gerar re-tentativas infinitas; erros vão pro error_log.
    $paymentId = $_GET['data_id'] ?? ($_GET['id'] ?? '');
    if ($paymentId === '') {
      $raw = json_decode(file_get_contents('php://input'), true);
      $paymentId = $raw['data']['id'] ?? '';
    }
    $type = $_GET['type'] ?? ($_GET['topic'] ?? '');
    if ($paymentId !== '' && ($type === '' || $type === 'payment')) {
      if (!mp_webhook_signature_ok((string)$paymentId)) {
        // Assinatura ausente/inválida: responde 200 (pra não gerar re-tentativas)
        // mas NÃO processa. A reconciliação manual/automática continua disponível.
        error_log('[mercado-pago] webhook com assinatura invalida — ignorado (payment ' . $paymentId . ')');
        json_out(['ok' => true]);
      }
      try {
        $payment = mp_api('GET', '/v1/payments/' . preg_replace('/\D/', '', (string)$paymentId));
        mp_apply_payment($pdo, $payment);
      } catch (Throwable $e) {
        error_log('[mercado-pago] webhook: ' . $e->getMessage());
      }
    }
    json_out(['ok' => true]);
  }

  case 'my_orders': {
    $me = require_login();
    $stmt = $pdo->prepare('
      SELECT o.id, o.status, o.payment_method AS paymentMethod, o.total_cents AS totalCents,
             o.shipping_cents AS shippingCents, o.shipping_service AS shippingService, o.created_at AS createdAt,
             a.recipient_name AS recipientName, a.street, a.number, a.neighborhood, a.city, a.state
      FROM orders o
      LEFT JOIN addresses a ON a.id = o.address_id
      WHERE o.buyer_id = ? AND o.status != "cart"
      ORDER BY o.created_at DESC
    ');
    $stmt->execute([$me['id']]);
    $orders = $stmt->fetchAll();

    $itemsStmt = $pdo->prepare('
      SELECT oi.artwork_id AS artworkId, oi.price_cents AS priceCents, a.title,
             u.name AS artistName
      FROM order_items oi
      JOIN artworks a ON a.id = oi.artwork_id
      JOIN users u ON u.id = a.artist_id
      WHERE oi.order_id = ?
    ');
    foreach ($orders as &$o) {
      $o['total'] = $o['totalCents'] / 100;
      unset($o['totalCents']);
      $o['shipping'] = $o['shippingCents'] / 100;
      unset($o['shippingCents']);
      $itemsStmt->execute([$o['id']]);
      $items = $itemsStmt->fetchAll();
      foreach ($items as &$it) { $it['price'] = $it['priceCents'] / 100; unset($it['priceCents']); }
      unset($it);
      $o['items'] = $items;
    }
    unset($o);
    json_out(['orders' => $orders]);
  }

  case 'admin_orders': {
    require_role('admin');
    $orders = $pdo->query('
      SELECT o.id, o.status, o.payment_method AS paymentMethod, o.total_cents AS totalCents, o.created_at AS createdAt,
             o.shipping_cents AS shippingCents, o.shipping_service AS shippingService,
             u.name AS buyerName, u.email AS buyerEmail,
             a.recipient_name AS recipientName, a.cep, a.street, a.number, a.complement, a.neighborhood, a.city, a.state
      FROM orders o
      JOIN users u ON u.id = o.buyer_id
      LEFT JOIN addresses a ON a.id = o.address_id
      WHERE o.status != "cart"
      ORDER BY o.created_at DESC
    ')->fetchAll();
    $itemsStmt = $pdo->prepare('SELECT a.title FROM order_items oi JOIN artworks a ON a.id = oi.artwork_id WHERE oi.order_id = ?');
    foreach ($orders as &$o) {
      $o['total'] = $o['totalCents'] / 100;
      unset($o['totalCents']);
      $o['shipping'] = ((int)$o['shippingCents']) / 100;
      unset($o['shippingCents']);
      $itemsStmt->execute([$o['id']]);
      $o['itemTitles'] = $itemsStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    unset($o);
    json_out(['orders' => $orders]);
  }

  case 'update_order_status': {
    require_role('admin');
    $b = body();
    $id = (int)($b['id'] ?? 0);
    $status = $b['status'] ?? '';
    if (!in_array($status, ['paid', 'shipped', 'delivered', 'cancelled'], true)) json_error('Status inválido');
    $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
    json_out(['ok' => true]);
  }

  // ================= REPASSES (ADMIN) =================
  // Repasse = preço da obra - taxa do gateway (100% do líquido vai pro artista;
  // a galeria não fica com comissão de venda). Pedidos cancelados não geram repasse.

  case 'admin_payouts': {
    require_role('admin');
    $rows = $pdo->query('
      SELECT oi.id AS itemId, oi.price_cents AS priceCents, oi.gateway_fee_cents AS feeCents,
             oi.payout_done AS payoutDone, oi.payout_at AS payoutAt,
             o.id AS orderId, o.created_at AS orderDate,
             a.title AS artworkTitle, a.artist_id AS artistId, u.name AS artistName
      FROM order_items oi
      JOIN orders o ON o.id = oi.order_id
      JOIN artworks a ON a.id = oi.artwork_id
      JOIN users u ON u.id = a.artist_id
      WHERE o.status IN ("paid", "shipped", "delivered")
      ORDER BY u.name, oi.payout_done, o.created_at DESC
    ')->fetchAll();

    $artists = [];
    foreach ($rows as $r) {
      $aid = (int)$r['artistId'];
      if (!isset($artists[$aid])) {
        $artists[$aid] = ['artistId' => $aid, 'artistName' => $r['artistName'], 'pendingCents' => 0, 'items' => []];
      }
      $netCents = max(0, (int)$r['priceCents'] - (int)$r['feeCents']);
      if (!$r['payoutDone']) $artists[$aid]['pendingCents'] += $netCents;
      $artists[$aid]['items'][] = [
        'itemId' => (int)$r['itemId'],
        'orderId' => (int)$r['orderId'],
        'orderDate' => $r['orderDate'],
        'artworkTitle' => $r['artworkTitle'],
        'price' => $r['priceCents'] / 100,
        'fee' => $r['feeCents'] / 100,
        'net' => $netCents / 100,
        'payoutDone' => (bool)$r['payoutDone'],
        'payoutAt' => $r['payoutAt'],
      ];
    }
    foreach ($artists as &$a) { $a['pending'] = $a['pendingCents'] / 100; unset($a['pendingCents']); }
    unset($a);
    json_out(['artists' => array_values($artists)]);
  }

  case 'mark_payouts': {
    require_role('admin');
    $b = body();
    $ids = array_map('intval', is_array($b['itemIds'] ?? null) ? $b['itemIds'] : []);
    if (!$ids) json_error('Nenhum item selecionado');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE order_items SET payout_done = 1, payout_at = ? WHERE id IN ($placeholders) AND payout_done = 0");
    $stmt->execute(array_merge([date('Y-m-d H:i:s')], $ids));
    json_out(['ok' => true, 'marked' => $stmt->rowCount()]);
  }

  case 'unmark_payout': {
    require_role('admin');
    $b = body();
    $id = (int)($b['itemId'] ?? 0);
    $pdo->prepare('UPDATE order_items SET payout_done = 0, payout_at = NULL WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
  }

  default:
    json_error('Ação desconhecida: ' . $action, 404);
}
