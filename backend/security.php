<?php
// ============================================================
// Galeria Millia — utilidades de segurança
//
// Camada leve, sem dependências e sem alteração de schema:
//  - client_ip()         IP do cliente (base do rate limit)
//  - verify_origin()     defesa CSRF: confere Origin/Referer nas ações que mudam estado
//  - rate_limit_or_die() limitador por IP+ação (janela deslizante, baseado em arquivo)
//
// Projetado para "fail-open" em falhas de I/O: nunca trava um usuário legítimo
// se o disco não puder ser escrito — apenas deixa de aplicar o limite naquele caso.
// ============================================================

function client_ip(): string {
  // REMOTE_ADDR é o único valor confiável em hospedagem compartilhada.
  // Cabeçalhos X-Forwarded-* são falsificáveis pelo cliente e NÃO são usados aqui.
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// Defesa em profundidade contra CSRF. O cookie de sessão já é SameSite=Lax
// (que bloqueia envio cross-site em POST), mas aqui confirmamos explicitamente
// que a requisição partiu do próprio site. Chamado apenas em ações que mudam
// estado; o webhook do Mercado Pago é isento (validado por assinatura HMAC).
function verify_origin(): void {
  $siteHost = parse_url(SITE_BASE_URL, PHP_URL_HOST);
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin !== '') {
    if (strcasecmp((string)parse_url($origin, PHP_URL_HOST), (string)$siteHost) !== 0) {
      json_error('Origem não autorizada.', 403);
    }
    return;
  }
  $referer = $_SERVER['HTTP_REFERER'] ?? '';
  if ($referer !== '') {
    if (strcasecmp((string)parse_url($referer, PHP_URL_HOST), (string)$siteHost) !== 0) {
      json_error('Origem não autorizada.', 403);
    }
    return;
  }
  // Nenhum dos dois cabeçalhos presente: caso raro (alguns clientes os omitem).
  // Como o SameSite=Lax já impede o cookie de sessão em POST cross-site, deixamos
  // passar para não quebrar clientes legítimos — o dano de CSRF sem cookie é nulo.
}

// Limitador de taxa por IP+bucket, janela deslizante, persistido em arquivo.
// Ex.: rate_limit_or_die('login', 10, 300) => no máx. 10 tentativas / 5 min / IP.
function rate_limit_or_die(string $bucket, int $max, int $windowSec): void {
  $dir = __DIR__ . '/rate_limit';
  if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return; // fail-open
  $key = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $bucket . '_' . client_ip());
  $file = $dir . '/' . $key . '.txt';
  $now = time();

  $fp = @fopen($file, 'c+');
  if (!$fp) return; // fail-open: não trava login legítimo por falha de disco
  if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
  $raw = stream_get_contents($fp) ?: '';
  $times = array_values(array_filter(
    array_map('intval', array_filter(explode(',', $raw), 'strlen')),
    fn($t) => $t > $now - $windowSec
  ));
  if (count($times) >= $max) {
    flock($fp, LOCK_UN);
    fclose($fp);
    json_error('Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429);
  }
  $times[] = $now;
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, implode(',', $times));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
}
