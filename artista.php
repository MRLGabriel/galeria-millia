<?php
// ============================================================
// Galeria Millia — landing page do artista (/artista/nome)
//
// O site é uma página só (index.html) montada por JavaScript. Crawlers do
// Google/WhatsApp/LinkedIn não executam JS, então toda URL entregava o mesmo
// título e a mesma imagem de prévia. Este arquivo resolve isso: serve o MESMO
// index.html, mas com o <head> reescrito com os dados do artista, e diz ao app
// qual artista abrir.
//
// O .htaccess reescreve /artista/<slug> para cá, sem mudar a URL na barra.
// ============================================================

require __DIR__ . '/backend/config.php';

// config.php define JSON (é a API); aqui a resposta é uma página.
header('Content-Type: text/html; charset=utf-8');

$indexPath = __DIR__ . '/index.html';
$slug = strtolower(trim((string)($_GET['slug'] ?? '')));

$artist = null;
if ($slug !== '' && preg_match('/^[a-z0-9-]{1,160}$/', $slug)) {
  $stmt = db()->prepare(
    "SELECT id, name, headline, bio, avatar_url, cover_url
       FROM users
      WHERE slug = ? AND role = 'artist' AND blocked = 0 AND deactivated = 0"
  );
  $stmt->execute([$slug]);
  $artist = $stmt->fetch() ?: null;
}

$html = file_get_contents($indexPath);
if ($html === false) {
  http_response_code(500);
  echo 'Erro ao carregar a página.';
  exit;
}

// Slug inexistente (artista removido/renomeado, link velho): devolve 404 de
// verdade — evita "soft 404", que o Google penaliza — mas ainda mostra a
// galeria, pra o visitante não cair numa tela morta.
if (!$artist) {
  http_response_code(404);
  echo $html;
  exit;
}

// ---- Monta os textos da prévia ----
$siteName = 'Galeria Millia';
$title = $artist['name'] . ' — ' . $siteName;

$descRaw = trim((string)$artist['headline']);
$bio = trim(preg_replace('/\s+/', ' ', strip_tags((string)$artist['bio'])));
if ($bio !== '') $descRaw = $descRaw !== '' ? ($descRaw . ' — ' . $bio) : $bio;
if ($descRaw === '') $descRaw = 'Obras originais de ' . $artist['name'] . ' na ' . $siteName . '.';
if (mb_strlen($descRaw) > 200) $descRaw = mb_substr($descRaw, 0, 197) . '…';

// Imagem da prévia: capa do ateliê > foto de perfil > imagem padrão da galeria.
$imgRel = $artist['cover_url'] ?: ($artist['avatar_url'] ?: 'og-image.png');
$imgAbs = SITE_BASE_URL . '/' . ltrim($imgRel, '/');
$pageUrl = SITE_BASE_URL . '/artista/' . $slug;

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$meta = '<title>' . $e($title) . '</title>' . "\n"
  . '  <meta name="description" content="' . $e($descRaw) . '">' . "\n"
  . '  <link rel="canonical" href="' . $e($pageUrl) . '">' . "\n"
  . '  <meta name="theme-color" content="#0A0A0A">' . "\n\n"
  . '  <meta property="og:type" content="profile">' . "\n"
  . '  <meta property="og:site_name" content="' . $e($siteName) . '">' . "\n"
  . '  <meta property="og:locale" content="pt_BR">' . "\n"
  . '  <meta property="og:url" content="' . $e($pageUrl) . '">' . "\n"
  . '  <meta property="og:title" content="' . $e($title) . '">' . "\n"
  . '  <meta property="og:description" content="' . $e($descRaw) . '">' . "\n"
  . '  <meta property="og:image" content="' . $e($imgAbs) . '">' . "\n"
  . '  <meta property="og:image:secure_url" content="' . $e($imgAbs) . '">' . "\n"
  . '  <meta property="og:image:alt" content="' . $e($artist['name'] . ' — ' . $siteName) . '">' . "\n\n"
  . '  <meta name="twitter:card" content="summary_large_image">' . "\n"
  . '  <meta name="twitter:title" content="' . $e($title) . '">' . "\n"
  . '  <meta name="twitter:description" content="' . $e($descRaw) . '">' . "\n"
  . '  <meta name="twitter:image" content="' . $e($imgAbs) . '">';

// ---- Troca o bloco entre os marcadores ----
// str_replace por posição, não preg_replace: o texto do artista pode conter
// "$1"/"\\" e seria interpretado como referência de captura na substituição.
$start = strpos($html, '<!-- META:START');
$endTag = '<!-- META:END -->';
$end = strpos($html, $endTag);
if ($start !== false && $end !== false && $end > $start) {
  $html = substr($html, 0, $start) . $meta . substr($html, $end + strlen($endTag));
}

// Obs.: o <base href="/"> que faz as URLs relativas do app funcionarem nesta
// rota mora DENTRO do template (o bundler troca o documento inteiro ao
// renderizar, então um <base> injetado aqui seria descartado).

// ---- Diz ao app qual artista abrir ----
// (O app também aceita ?artista=<id>; isto é o equivalente para a URL limpa.)
$inject = '<script>window.__ARTISTA_ID=' . (int)$artist['id'] . ';</script>';
$posHead = strpos($html, '</head>');
if ($posHead !== false) {
  $html = substr($html, 0, $posHead) . $inject . substr($html, $posHead);
}

echo $html;
