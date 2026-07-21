<?php
// ============================================================
// Galeria Millia — assinaturas dos artistas (Fase 1: estrutura + perks)
//
// Fase 1 (aqui): cada artista ganha uma linha de assinatura ('free' por
//   padrão); os primeiros da fila ganham os perks de lançamento. NÃO cobra
//   nada e NÃO bloqueia nada — só monta o estado.
// Fase 2 (depois): telas de assinatura + Mercado Pago Assinaturas (recorrente).
// Fase 3 (depois): aplicar os limites do plano Free (com "grandfathering").
// ============================================================

// Quantos artistas ganham cada perk de lançamento (por ordem de cadastro).
const PERK_INSTAGRAM_FIRST  = 5;   // publicações no Instagram da galeria sem custo
const PERK_TWO_MONTHS_FIRST = 10;  // 2 meses de Gold sem cobrança
const PERK_CYCLE_QUOTA      = 10;  // cota de novas obras por ciclo durante o período grátis (montar a galeria)

// IDs dos planos de assinatura criados no painel do Mercado Pago (Assinaturas).
// Quando existir, a recorrência (valor, ciclo, trial) vem do plano do MP.
// Para criar os planos anuais: crie-os no painel do MP e cole os IDs aqui.
const MP_PREAPPROVAL_PLANS = [
  'gold_monthly'    => 'e8fce928b5d741e99ec17f0c0c9a27db',
  'diamond_monthly' => '6ca9949e94254f199b442a43f0ec86ab',
  'gold_annual'     => '67a07097381548a39dcfdbb643a7b082',
  'diamond_annual'  => '530ba4fcd51848fb8443b79a54740d40',
];

// Limites/recursos de um plano (JSON gravado em plans.features).
function plan_features(PDO $pdo, string $code): array {
  static $cache = [];
  if (!array_key_exists($code, $cache)) {
    $stmt = $pdo->prepare('SELECT features FROM plans WHERE code = ?');
    $stmt->execute([$code]);
    $f = $stmt->fetchColumn();
    $cache[$code] = $f ? (json_decode($f, true) ?: []) : [];
  }
  return $cache[$code];
}

// Garante que o artista tem uma linha de assinatura (default 'free').
function ensure_subscription(PDO $pdo, int $artistId): void {
  $pdo->prepare('INSERT IGNORE INTO subscriptions (artist_id, plan_code, status, started_at) VALUES (?, "free", "active", NOW())')
    ->execute([$artistId]);
}

// Posição do artista na fila (1 = primeiro a se cadastrar), só entre artistas
// ativos. Base dos perks "primeiros N".
function artist_rank(PDO $pdo, int $artistId): int {
  $s = $pdo->prepare('SELECT created_at FROM users WHERE id = ?');
  $s->execute([$artistId]);
  $ca = $s->fetchColumn();
  if ($ca === false) return PHP_INT_MAX;
  $q = $pdo->prepare(
    "SELECT COUNT(*) + 1 FROM users
      WHERE role = 'artist' AND deactivated = 0
        AND (created_at < ? OR (created_at = ? AND id < ?))"
  );
  $q->execute([$ca, $ca, $artistId]);
  return (int)$q->fetchColumn();
}

// Concede um perk se: o artista ainda não tem, está dentro do rank e o total
// concedido não estourou o limite. Idempotente. Devolve true se concedeu agora.
function maybe_grant_perk(PDO $pdo, int $artistId, string $perk, int $rank, int $cap): bool {
  $has = $pdo->prepare('SELECT 1 FROM artist_perks WHERE artist_id = ? AND perk_code = ?');
  $has->execute([$artistId, $perk]);
  if ($has->fetch()) return false;
  if ($rank > $cap) return false;
  $cnt = $pdo->prepare('SELECT COUNT(*) FROM artist_perks WHERE perk_code = ?');
  $cnt->execute([$perk]);
  if ((int)$cnt->fetchColumn() >= $cap) return false;
  try {
    $pdo->prepare('INSERT INTO artist_perks (artist_id, perk_code) VALUES (?, ?)')->execute([$artistId, $perk]);
    return true;
  } catch (PDOException $e) {
    if ($e->getCode() === '23000') return false; // corrida: concedido em paralelo
    throw $e;
  }
}

// Concede os perks de lançamento ao artista, conforme a posição na fila.
// Chamado no carregamento dos dados — cobre os artistas já cadastrados
// (lys, enzo, ...) e os que forem entrando.
function grant_launch_perks(PDO $pdo, int $artistId): void {
  $rank = artist_rank($pdo, $artistId);
  maybe_grant_perk($pdo, $artistId, 'instagram_free', $rank, PERK_INSTAGRAM_FIRST);
  if (maybe_grant_perk($pdo, $artistId, 'two_months_free', $rank, PERK_TWO_MONTHS_FIRST)) {
    // 2 meses de Gold sem cobrança, a partir de agora — só se ainda não há
    // período grátis nem assinatura paga (não atropela quem já pagou).
    $pdo->prepare(
      "UPDATE subscriptions
          SET plan_code = 'gold', status = 'active',
              free_until = DATE_ADD(NOW(), INTERVAL 2 MONTH),
              started_at = COALESCE(started_at, NOW())
        WHERE artist_id = ? AND free_until IS NULL AND mp_preapproval_id IS NULL"
    )->execute([$artistId]);
  }
}

// Plano ao qual o artista tem direito AGORA (considerando o período grátis).
function effective_plan_code(?array $sub): string {
  if (!$sub) return 'free';
  $now = time();
  $freeUntil = !empty($sub['free_until']) ? strtotime($sub['free_until']) : 0;
  if ($freeUntil > $now) return $sub['plan_code'];                 // dentro do período grátis
  if (($sub['status'] ?? '') === 'active' && !empty($sub['mp_preapproval_id'])) return $sub['plan_code']; // pago e ativo
  return 'free';
}

// ---------- Mercado Pago Assinaturas (preapproval recorrente) ----------

// external_reference que liga a assinatura ao artista.
function sub_external_reference(int $artistId, string $planCode, string $period): string {
  return 'sub-' . $artistId . '-' . $planCode . '-' . $period;
}

// Devolve a URL do checkout de assinatura do Mercado Pago para o artista.
// Usa o fluxo HOSPEDADO (o MP coleta o cartão na tela dele): a assinatura via
// API exige card_token_id (coletar cartão no nosso site), o que não fazemos.
// Registra a intenção (pending) e amarra o artista pelo external_reference.
function mp_create_subscription(PDO $pdo, array $artist, string $planCode, string $period): array {
  if (!in_array($planCode, ['gold', 'diamond'], true)) throw new RuntimeException('Plano inválido para assinatura.');
  if (!in_array($period, ['monthly', 'annual'], true)) $period = 'monthly';

  $mpPlanId = MP_PREAPPROVAL_PLANS[$planCode . '_' . $period] ?? '';
  if ($mpPlanId === '') throw new RuntimeException('Este plano ainda não está disponível para assinatura.');

  // Cancela a assinatura MP anterior (troca de plano) — best-effort.
  $sub = $pdo->prepare('SELECT mp_preapproval_id FROM subscriptions WHERE artist_id = ?');
  $sub->execute([(int)$artist['id']]);
  $oldPre = $sub->fetchColumn();
  if (!empty($oldPre)) {
    try { mp_api('PUT', '/preapproval/' . $oldPre, ['status' => 'cancelled']); } catch (Throwable $e) { /* ignora */ }
  }

  // Registra a escolha como pendente (a ativação vem pelo webhook/confirm).
  $pdo->prepare('UPDATE subscriptions SET plan_code = ?, period = ?, status = "pending", mp_preapproval_id = NULL WHERE artist_id = ?')
    ->execute([$planCode, $period, (int)$artist['id']]);

  $extRef = sub_external_reference((int)$artist['id'], $planCode, $period);
  // external_reference amarra a assinatura ao artista; se o MP não propagar,
  // o webhook ainda mapeia pelo preapproval_plan_id + e-mail do pagador.
  $url = 'https://www.mercadopago.com.br/subscriptions/checkout?preapproval_plan_id=' . rawurlencode($mpPlanId)
    . '&external_reference=' . rawurlencode($extRef);

  return ['initPoint' => $url, 'preapprovalId' => '', 'status' => 'pending'];
}

// Aplica o estado de uma preapproval do MP ao artista. Idempotente.
function apply_preapproval(PDO $pdo, array $pre): void {
  $artistId = 0; $planCode = ''; $period = '';
  $ref = (string)($pre['external_reference'] ?? '');
  if (preg_match('/^sub-(\d+)-(gold|diamond)-(monthly|annual)$/', $ref, $m)) {
    // Caminho preferido: external_reference amarra o artista.
    $artistId = (int)$m[1]; $planCode = $m[2]; $period = $m[3];
  } else {
    // Sem external_reference (checkout hospedado): mapeia pelo plano do MP +
    // e-mail do pagador. array_flip: id do plano -> "gold_monthly".
    $rev = array_flip(array_filter(MP_PREAPPROVAL_PLANS));
    $key = $rev[(string)($pre['preapproval_plan_id'] ?? '')] ?? '';
    if ($key !== '') { [$planCode, $period] = explode('_', $key); }
    $email = strtolower(trim((string)($pre['payer_email'] ?? '')));
    if ($email !== '' && $planCode !== '') {
      $q = $pdo->prepare("SELECT id FROM users WHERE role = 'artist' AND deactivated = 0 AND LOWER(email) = ? LIMIT 1");
      $q->execute([$email]);
      $artistId = (int)$q->fetchColumn();
    }
  }
  if (!$artistId || $planCode === '') return;
  $mpStatus = $pre['status'] ?? '';
  $map = ['authorized' => 'active', 'pending' => 'pending', 'paused' => 'paused', 'cancelled' => 'cancelled'];
  $status = $map[$mpStatus] ?? 'pending';
  $periodEnd = !empty($pre['next_payment_date']) ? date('Y-m-d H:i:s', strtotime($pre['next_payment_date'])) : null;

  if ($status === 'active') {
    $pdo->prepare('UPDATE subscriptions SET plan_code = ?, period = ?, status = "active", mp_preapproval_id = ?, current_period_end = ?, started_at = COALESCE(started_at, NOW()), cancel_at = NULL WHERE artist_id = ?')
      ->execute([$planCode, $period, (string)($pre['id'] ?? ''), $periodEnd, $artistId]);
  } elseif ($status === 'cancelled') {
    // Assinatura cancelada no MP: cai pro Free (a menos que ainda esteja no período grátis).
    $pdo->prepare('UPDATE subscriptions SET status = "cancelled", cancel_at = NOW() WHERE artist_id = ? AND mp_preapproval_id = ?')
      ->execute([$artistId, (string)($pre['id'] ?? '')]);
  } else {
    $pdo->prepare('UPDATE subscriptions SET status = ? WHERE artist_id = ? AND mp_preapproval_id = ?')
      ->execute([$status, $artistId, (string)($pre['id'] ?? '')]);
  }
}

// Cancela a assinatura recorrente do artista no MP e volta pro Free.
function cancel_mp_subscription(PDO $pdo, int $artistId): void {
  $s = $pdo->prepare('SELECT mp_preapproval_id FROM subscriptions WHERE artist_id = ?');
  $s->execute([$artistId]);
  $preId = $s->fetchColumn();
  if ($preId) {
    try { mp_api('PUT', '/preapproval/' . $preId, ['status' => 'cancelled']); }
    catch (Throwable $e) { error_log('[assinatura] falha ao cancelar preapproval ' . $preId . ': ' . $e->getMessage()); }
  }
  // Volta pro Free. Mantém free_until (o período grátis do perk continua valendo).
  $pdo->prepare('UPDATE subscriptions SET plan_code = "free", period = "monthly", status = "cancelled", mp_preapproval_id = NULL, cancel_at = NOW() WHERE artist_id = ?')
    ->execute([$artistId]);
}

// Início do ciclo MENSAL atual da cota de obras. Ancorado no dia da renovação
// (dia do mês da assinatura) — vale também pro plano anual, cuja cota é mensal.
// Sem assinatura com data, cai no mês do calendário.
function obra_cycle_start(?array $sub): string {
  $src = null;
  if ($sub && !empty($sub['current_period_end'])) $src = $sub['current_period_end'];
  elseif ($sub && !empty($sub['started_at'])) $src = $sub['started_at'];
  $now = time();
  if (!$src) return date('Y-m-01 00:00:00', $now); // Free sem histórico: mês do calendário
  $anchorDay = (int)date('j', strtotime($src));
  $y = (int)date('Y', $now); $m = (int)date('n', $now); $today = (int)date('j', $now);
  if ($today < $anchorDay) { $m--; if ($m < 1) { $m = 12; $y--; } }
  $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y));   // dias no mês (trata fim de mês)
  $day = min($anchorDay, $dim);
  return sprintf('%04d-%02d-%02d 00:00:00', $y, $m, $day);
}

// Cota de novas obras do artista neste ciclo (plano efetivo + período),
// quantas já usou e o limite de coleções. quota = null significa ilimitado.
function artist_obra_quota(PDO $pdo, int $artistId): array {
  $s = $pdo->prepare('SELECT plan_code, period, status, mp_preapproval_id, free_until, started_at, current_period_end FROM subscriptions WHERE artist_id = ?');
  $s->execute([$artistId]);
  $sub = $s->fetch() ?: null;
  $eff = effective_plan_code($sub);
  $f = plan_features($pdo, $eff);
  $period = $sub['period'] ?? 'monthly';
  $key = ($period === 'annual' && array_key_exists('obras_cycle_annual', $f)) ? 'obras_cycle_annual' : 'obras_cycle';
  $quota = array_key_exists($key, $f) ? $f[$key] : null;
  // Durante o período grátis do perk (2 meses), cota ampliada pra o artista
  // montar a galeria — nunca reduz uma cota que já seja maior.
  if ($sub && !empty($sub['free_until']) && strtotime($sub['free_until']) > time()) {
    $quota = $quota === null ? null : max((int)$quota, PERK_CYCLE_QUOTA);
  }
  $cycleStart = obra_cycle_start($sub);
  $c = $pdo->prepare('SELECT COUNT(*) FROM artworks WHERE artist_id = ? AND created_at >= ?');
  $c->execute([$artistId, $cycleStart]);
  return [
    'plan'         => $eff,
    'quota'        => $quota === null ? null : (int)$quota,   // novas obras por ciclo
    'used'         => (int)$c->fetchColumn(),
    'cycleStart'   => $cycleStart,
    'max_colecoes' => array_key_exists('max_colecoes', $f) ? $f['max_colecoes'] : null,
  ];
}

// Assinatura + perks de um artista, já com o plano efetivo resolvido.
function subscription_summary(PDO $pdo, int $artistId): array {
  $s = $pdo->prepare('SELECT plan_code, period, status, mp_preapproval_id, current_period_end, free_until, cancel_at FROM subscriptions WHERE artist_id = ?');
  $s->execute([$artistId]);
  $sub = $s->fetch() ?: null;
  $p = $pdo->prepare('SELECT perk_code FROM artist_perks WHERE artist_id = ?');
  $p->execute([$artistId]);
  $perks = $p->fetchAll(PDO::FETCH_COLUMN);
  $effective = effective_plan_code($sub);
  $q = artist_obra_quota($pdo, $artistId);
  return [
    'plan'        => $sub['plan_code'] ?? 'free',   // plano contratado
    'effective'   => $effective,                    // plano válido agora (com período grátis)
    'period'      => $sub['period'] ?? 'monthly',
    'status'      => $sub['status'] ?? 'active',
    'freeUntil'   => $sub['free_until'] ?? null,
    'periodEnd'   => $sub['current_period_end'] ?? null,
    'cancelAt'    => $sub['cancel_at'] ?? null,
    'hasMp'       => !empty($sub['mp_preapproval_id']),
    'perks'       => $perks,
    // Cota de novas obras do ciclo atual (pra mostrar "X de Y neste ciclo").
    'obraQuota'   => $q['quota'],                    // null = ilimitado
    'obraUsed'    => $q['used'],
    'cycleStart'  => $q['cycleStart'],
    'maxColecoes' => $q['max_colecoes'],
  ];
}
