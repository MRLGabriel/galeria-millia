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

// Cria a assinatura recorrente no Mercado Pago e devolve o init_point (URL do
// checkout do MP onde o artista cadastra o cartão). Status nasce 'pending' até
// o artista autorizar. Honra o período grátis do perk via free_trial.
function mp_create_subscription(PDO $pdo, array $artist, string $planCode, string $period): array {
  if (!in_array($planCode, ['gold', 'diamond'], true)) throw new RuntimeException('Plano inválido para assinatura.');
  if (!in_array($period, ['monthly', 'annual'], true)) $period = 'monthly';

  $pl = $pdo->prepare('SELECT name, price_cents, price_annual_cents FROM plans WHERE code = ? AND active = 1');
  $pl->execute([$planCode]);
  $plan = $pl->fetch();
  if (!$plan) throw new RuntimeException('Plano não encontrado.');

  $amountCents = $period === 'annual' ? (int)$plan['price_annual_cents'] : (int)$plan['price_cents'];
  if ($amountCents <= 0) throw new RuntimeException('Plano sem preço configurado.');
  $amount = round($amountCents / 100, 2);
  $freq = $period === 'annual' ? 12 : 1; // a cada 12 ou 1 mês

  // Período grátis restante (perk "2 meses"): vira free_trial no MP, adiando a 1ª cobrança.
  $sub = $pdo->prepare('SELECT free_until, mp_preapproval_id FROM subscriptions WHERE artist_id = ?');
  $sub->execute([$artist['id']]);
  $cur = $sub->fetch() ?: [];
  $freeTrial = null;
  if (!empty($cur['free_until']) && strtotime($cur['free_until']) > time()) {
    $months = (int)ceil((strtotime($cur['free_until']) - time()) / (30 * 24 * 3600));
    if ($months > 0) $freeTrial = ['frequency' => $months, 'frequency_type' => 'months'];
  }

  $auto = [
    'frequency' => $freq,
    'frequency_type' => 'months',
    'transaction_amount' => $amount,
    'currency_id' => 'BRL',
  ];
  if ($freeTrial) $auto['free_trial'] = $freeTrial;

  $payload = [
    'reason' => 'Galeria Millia — ' . $plan['name'] . ($period === 'annual' ? ' (anual)' : ' (mensal)'),
    'external_reference' => 'sub-' . (int)$artist['id'] . '-' . $planCode . '-' . $period,
    'payer_email' => $artist['email'],
    'back_url' => SITE_BASE_URL . '/',
    'status' => 'pending',
    'auto_recurring' => $auto,
  ];

  // Cancela uma assinatura MP anterior (troca de plano) — best-effort.
  if (!empty($cur['mp_preapproval_id'])) {
    try { mp_api('PUT', '/preapproval/' . $cur['mp_preapproval_id'], ['status' => 'cancelled']); } catch (Throwable $e) { /* ignora */ }
  }

  $pre = mp_api('POST', '/preapproval', $payload);
  $preId = $pre['id'] ?? '';
  if ($preId === '') throw new RuntimeException('Mercado Pago não retornou a assinatura.');

  $pdo->prepare('UPDATE subscriptions SET plan_code = ?, period = ?, status = "pending", mp_preapproval_id = ? WHERE artist_id = ?')
    ->execute([$planCode, $period, $preId, (int)$artist['id']]);

  return ['initPoint' => $pre['init_point'] ?? '', 'preapprovalId' => $preId, 'status' => $pre['status'] ?? 'pending'];
}

// Aplica o estado de uma preapproval do MP ao artista. Idempotente.
function apply_preapproval(PDO $pdo, array $pre): void {
  $ref = (string)($pre['external_reference'] ?? '');
  // external_reference = "sub-<artistId>-<plan>-<period>"
  if (!preg_match('/^sub-(\d+)-(gold|diamond)-(monthly|annual)$/', $ref, $m)) return;
  $artistId = (int)$m[1]; $planCode = $m[2]; $period = $m[3];
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

// Assinatura + perks de um artista, já com o plano efetivo resolvido.
function subscription_summary(PDO $pdo, int $artistId): array {
  $s = $pdo->prepare('SELECT plan_code, period, status, mp_preapproval_id, current_period_end, free_until, cancel_at FROM subscriptions WHERE artist_id = ?');
  $s->execute([$artistId]);
  $sub = $s->fetch() ?: null;
  $p = $pdo->prepare('SELECT perk_code FROM artist_perks WHERE artist_id = ?');
  $p->execute([$artistId]);
  $perks = $p->fetchAll(PDO::FETCH_COLUMN);
  $effective = effective_plan_code($sub);
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
  ];
}
