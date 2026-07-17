<?php
// ============================================================
// Galeria Millia — landing page da obra (/obra/nome)
//
// Mesmo princípio do artista.php: o site é uma página só montada por
// JavaScript, e crawlers não executam JS. Aqui o <head> é reescrito com os
// dados da obra — e, o mais importante, a og:image passa a ser a FOTO DA
// OBRA, então compartilhar o link no WhatsApp mostra o quadro.
//
// O .htaccess reescreve /obra/<slug> para cá, sem mudar a URL na barra.
// ============================================================

require __DIR__ . '/backend/config.php';

header('Content-Type: text/html; charset=utf-8');

$indexPath = __DIR__ . '/index.html';
$slug = strtolower(trim((string)($_GET['slug'] ?? '')));

$obra = null;
if ($slug !== '' && preg_match('/^[a-z0-9-]{1,180}$/', $slug)) {
  // Só obra aprovada, de artista ativo, tem página pública — igual à regra
  // de visibilidade do site.
  $stmt = db()->prepare(
    "SELECT o.id, o.title, o.technique, o.description, o.width_cm, o.height_cm,
            o.price_cents, o.sold, u.name AS artist_name,
            (SELECT url FROM artwork_images WHERE artwork_id = o.id ORDER BY position LIMIT 1) AS image_url
       FROM artworks o
       JOIN users u ON u.id = o.artist_id
      WHERE o.slug = ? AND o.approved = 1 AND u.blocked = 0 AND u.deactivated = 0"
  );
  $stmt->execute([$slug]);
  $obra = $stmt->fetch() ?: null;
}

$html = file_get_contents($indexPath);
if ($html === false) {
  http_response_code(500);
  echo 'Erro ao carregar a página.';
  exit;
}

// Slug inexistente (obra recusada/renomeada, link velho): 404 de verdade,
// mas ainda mostrando a galeria.
if (!$obra) {
  http_response_code(404);
  echo $html;
  exit;
}

// ---- Textos da prévia ----
$siteName = 'Galeria Millia';
$title = $obra['title'] . ' — ' . $obra['artist_name'] . ' | ' . $siteName;

// Descrição: técnica e dimensões primeiro (é o que identifica a obra), depois
// a descrição do artista. Preço fica de fora de propósito: WhatsApp e Facebook
// cacheiam a prévia por muito tempo e um preço desatualizado engana o comprador.
$partes = [];
if (trim((string)$obra['technique']) !== '') $partes[] = trim($obra['technique']);
if ($obra['width_cm'] !== null && $obra['height_cm'] !== null) {
  $partes[] = rtrim(rtrim(number_format((float)$obra['width_cm'], 1, ',', ''), '0'), ',')
    . ' × ' . rtrim(rtrim(number_format((float)$obra['height_cm'], 1, ',', ''), '0'), ',') . ' cm';
}
$partes[] = 'por ' . $obra['artist_name'];
$desc = implode(' · ', $partes);
$extra = trim(preg_replace('/\s+/', ' ', strip_tags((string)$obra['description'])));
if ($extra !== '') $desc .= ' — ' . $extra;
if ((int)$obra['sold'] === 1) $desc = '[VENDIDA] ' . $desc;
if (mb_strlen($desc) > 200) $desc = mb_substr($desc, 0, 197) . '…';

// A imagem da obra é o grande ganho da prévia. Sem foto, cai na padrão.
$imgRel = $obra['image_url'] ?: 'og-image.png';
$imgAbs = SITE_BASE_URL . '/' . ltrim($imgRel, '/');
$pageUrl = SITE_BASE_URL . '/obra/' . $slug;

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$meta = '<title>' . $e($title) . '</title>' . "\n"
  . '  <meta name="description" content="' . $e($desc) . '">' . "\n"
  . '  <link rel="canonical" href="' . $e($pageUrl) . '">' . "\n"
  . '  <meta name="theme-color" content="#0A0A0A">' . "\n\n"
  . '  <meta property="og:type" content="article">' . "\n"
  . '  <meta property="og:site_name" content="' . $e($siteName) . '">' . "\n"
  . '  <meta property="og:locale" content="pt_BR">' . "\n"
  . '  <meta property="og:url" content="' . $e($pageUrl) . '">' . "\n"
  . '  <meta property="og:title" content="' . $e($title) . '">' . "\n"
  . '  <meta property="og:description" content="' . $e($desc) . '">' . "\n"
  . '  <meta property="og:image" content="' . $e($imgAbs) . '">' . "\n"
  . '  <meta property="og:image:secure_url" content="' . $e($imgAbs) . '">' . "\n"
  . '  <meta property="og:image:alt" content="' . $e($obra['title'] . ' — ' . $obra['artist_name']) . '">' . "\n\n"
  . '  <meta name="twitter:card" content="summary_large_image">' . "\n"
  . '  <meta name="twitter:title" content="' . $e($title) . '">' . "\n"
  . '  <meta name="twitter:description" content="' . $e($desc) . '">' . "\n"
  . '  <meta name="twitter:image" content="' . $e($imgAbs) . '">';

// Troca por posição (não preg_replace: título/descrição podem conter "$1"/"\").
$start = strpos($html, '<!-- META:START');
$endTag = '<!-- META:END -->';
$end = strpos($html, $endTag);
if ($start !== false && $end !== false && $end > $start) {
  $html = substr($html, 0, $start) . $meta . substr($html, $end + strlen($endTag));
}

// Diz ao app qual obra abrir. O <base href="/"> que faz as URLs relativas
// funcionarem nesta rota mora dentro do template (o bundler troca o documento).
$inject = '<script>window.__OBRA_ID=' . (int)$obra['id'] . ';</script>';
$posHead = strpos($html, '</head>');
if ($posHead !== false) {
  $html = substr($html, 0, $posHead) . $inject . substr($html, $posHead);
}

echo $html;
