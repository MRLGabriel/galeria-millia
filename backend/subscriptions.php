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

// Assinatura + perks de um artista, já com o plano efetivo resolvido.
function subscription_summary(PDO $pdo, int $artistId): array {
  $s = $pdo->prepare('SELECT plan_code, status, mp_preapproval_id, current_period_end, free_until, cancel_at FROM subscriptions WHERE artist_id = ?');
  $s->execute([$artistId]);
  $sub = $s->fetch() ?: null;
  $p = $pdo->prepare('SELECT perk_code FROM artist_perks WHERE artist_id = ?');
  $p->execute([$artistId]);
  $perks = $p->fetchAll(PDO::FETCH_COLUMN);
  $effective = effective_plan_code($sub);
  return [
    'plan'        => $sub['plan_code'] ?? 'free',   // plano contratado
    'effective'   => $effective,                    // plano válido agora (com período grátis)
    'status'      => $sub['status'] ?? 'active',
    'freeUntil'   => $sub['free_until'] ?? null,
    'periodEnd'   => $sub['current_period_end'] ?? null,
    'cancelAt'    => $sub['cancel_at'] ?? null,
    'perks'       => $perks,
  ];
}
