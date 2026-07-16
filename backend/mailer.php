<?php
// ============================================================
// Envio de e-mail — confirmação de cadastro e código de 2FA.
//
// MAIL_MODE 'log' (dev): grava o e-mail em backend/mail_log/ em vez de
// enviar de verdade, pra dar pra testar o fluxo sem precisar de SMTP.
// MAIL_MODE 'smtp' (produção): envia de verdade via PHPMailer.
// ============================================================

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
  if (MAIL_MODE === 'log') {
    $dir = __DIR__ . '/mail_log';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $file = $dir . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $toEmail) . '.html';
    file_put_contents($file, "Para: {$toName} <{$toEmail}>\nAssunto: {$subject}\n\n{$htmlBody}");
    error_log('[mail-log] e-mail gravado em ' . $file);
    return true;
  }

  $mail = new PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host = MAIL_SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = MAIL_SMTP_USER;
    $mail->Password = MAIL_SMTP_PASS;
    $mail->SMTPSecure = MAIL_SMTP_SECURE === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = MAIL_SMTP_PORT;
    $mail->Timeout = 20;
    // Em hospedagem compartilhada o servidor de mail às vezes apresenta um
    // certificado que não bate com o hostname usado (ex.: localhost) — relaxar
    // a verificação evita falha de handshake sem comprometer a entrega.
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(MAIL_FROM_ADDR, MAIL_FROM_NAME);
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = trim(strip_tags($htmlBody));
    $mail->send();
    return true;
  } catch (PHPMailerException|Throwable $e) {
    $GLOBALS['mail_last_error'] = $mail->ErrorInfo ?: $e->getMessage();
    error_log('[mail] Falha ao enviar e-mail para ' . $toEmail . ': ' . $GLOBALS['mail_last_error']);
    return false;
  }
}

function mail_layout(string $title, string $bodyHtml): string {
  return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#221E19">'
    . '<div style="background:#221E19;color:#F6F4EF;padding:20px 24px;font-weight:700;letter-spacing:.06em">GALERIA MILLIA</div>'
    . '<div style="padding:24px;border:1px solid #E4DFD3;border-top:none">'
    . '<h2 style="margin:0 0 14px;font-weight:600">' . $title . '</h2>'
    . $bodyHtml
    . '</div>'
    . '<div style="padding:14px 24px;color:#8C8679;font-size:12px">Galeria Millia · Goiânia/GO</div>'
    . '</div>';
}

function send_verification_email(string $toEmail, string $toName, string $token): bool {
  $link = SITE_BASE_URL . '/backend/api.php?action=verify_email&token=' . urlencode($token);
  $html = mail_layout('Confirme seu e-mail', '
    <p>Olá, ' . htmlspecialchars($toName) . '!</p>
    <p>Clique no botão abaixo para confirmar seu e-mail na Galeria Millia. O link expira em 24 horas.</p>
    <p style="margin:24px 0"><a href="' . $link . '" style="background:#221E19;color:#F6F4EF;text-decoration:none;padding:12px 22px;border-radius:3px;display:inline-block">Confirmar e-mail</a></p>
    <p style="font-size:12px;color:#8C8679">Se o botão não funcionar, copie e cole este link no navegador:<br>' . $link . '</p>
  ');
  return send_mail($toEmail, $toName, 'Confirme seu e-mail — Galeria Millia', $html);
}

function send_two_factor_email(string $toEmail, string $toName, string $code): bool {
  $html = mail_layout('Seu código de acesso', '
    <p>Olá, ' . htmlspecialchars($toName) . '!</p>
    <p>Use o código abaixo para concluir seu login na Galeria Millia. Ele expira em 10 minutos.</p>
    <p style="margin:24px 0;font:700 32px/1 monospace;letter-spacing:.1em">' . htmlspecialchars($code) . '</p>
    <p style="font-size:12px;color:#8C8679">Se você não tentou entrar na sua conta agora, ignore este e-mail.</p>
  ');
  return send_mail($toEmail, $toName, 'Seu código de acesso — Galeria Millia', $html);
}

// Avisa o administrador que entrou obra nova na fila de curadoria.
// (O artista recebe o send_obra_submitted_email; este é o aviso do outro lado.)
function send_obra_pending_admin_email(string $toEmail, string $toName, string $artistName, string $obraTitle): bool {
  $link = SITE_BASE_URL;
  $html = mail_layout('Obra nova na curadoria', '
    <p>Olá, ' . htmlspecialchars($toName) . '!</p>
    <p><b>' . htmlspecialchars($artistName) . '</b> enviou a obra <b>' . htmlspecialchars($obraTitle) . '</b> e ela está aguardando sua análise.</p>
    <p style="margin:24px 0"><a href="' . $link . '" style="background:#221E19;color:#F6F4EF;text-decoration:none;padding:12px 22px;border-radius:3px;display:inline-block">Abrir a galeria</a></p>
    <p style="font-size:12px;color:#8C8679">No site, vá em <b>Administração → Curadoria</b> para aprovar ou recusar. A obra só fica visível ao público depois de aprovada.</p>
  ');
  return send_mail($toEmail, $toName, 'Obra nova na curadoria: ' . $obraTitle . ' — Galeria Millia', $html);
}

function send_obra_approved_email(string $toEmail, string $toName, string $obraTitle): bool {
  $link = SITE_BASE_URL;
  $html = mail_layout('Sua obra foi aprovada', '
    <p>Olá, ' . htmlspecialchars($toName) . '!</p>
    <p>Boa notícia: a obra <b>' . htmlspecialchars($obraTitle) . '</b> passou pela curadoria e já está <b>publicada</b> na Galeria Millia.</p>
    <p style="margin:24px 0"><a href="' . $link . '" style="background:#221E19;color:#F6F4EF;text-decoration:none;padding:12px 22px;border-radius:3px;display:inline-block">Ver na galeria</a></p>
  ');
  return send_mail($toEmail, $toName, 'Sua obra foi aprovada — Galeria Millia', $html);
}

function send_obra_submitted_email(string $toEmail, string $toName, string $obraTitle): bool {
  $html = mail_layout('Obra enviada para a curadoria', '
    <p>Olá, ' . htmlspecialchars($toName) . '!</p>
    <p>Recebemos a obra <b>' . htmlspecialchars($obraTitle) . '</b>. Ela está <b>em análise</b> pela curadoria — assim que for aprovada, você recebe um aviso e ela aparece publicada na galeria.</p>
    <p style="font-size:12px;color:#8C8679">Você pode acompanhar o status em "Minhas obras" no seu painel.</p>
  ');
  return send_mail($toEmail, $toName, 'Obra recebida para curadoria — Galeria Millia', $html);
}

function send_password_reset_email(string $toEmail, string $toName, string $token): bool {
  $link = SITE_BASE_URL . '/index.html?resetToken=' . urlencode($token);
  $html = mail_layout('Redefinir senha', '
    <p>Olá, ' . htmlspecialchars($toName) . '!</p>
    <p>Recebemos um pedido pra redefinir a senha da sua conta na Galeria Millia. Clique no botão abaixo para escolher uma nova senha. O link expira em 1 hora.</p>
    <p style="margin:24px 0"><a href="' . $link . '" style="background:#221E19;color:#F6F4EF;text-decoration:none;padding:12px 22px;border-radius:3px;display:inline-block">Redefinir senha</a></p>
    <p style="font-size:12px;color:#8C8679">Se você não pediu isso, pode ignorar este e-mail — sua senha continua a mesma.<br>Se o botão não funcionar, copie e cole este link no navegador:<br>' . $link . '</p>
  ');
  return send_mail($toEmail, $toName, 'Redefinir sua senha — Galeria Millia', $html);
}
