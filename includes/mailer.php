<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once ROOT_PATH . '/vendor/autoload.php';

function send_verification_email(string $name, string $email, string $verificationUrl): void
{
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = env_value('MAIL_HOST', 'localhost');
    $mailer->Port = (int) env_value('MAIL_PORT', '587');
    $mailer->SMTPAuth = true;
    $mailer->Username = env_value('MAIL_USERNAME', '');
    $mailer->Password = env_value('MAIL_PASSWORD', '');
    $mailer->SMTPSecure = strtolower((string) env_value('MAIL_ENCRYPTION', 'tls')) === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->CharSet = 'UTF-8';
    $smtpUser = env_value('MAIL_USERNAME', 'no-reply@localhost');
    $replyTo = env_value('MAIL_FROM', $smtpUser);
    $mailer->setFrom($smtpUser, env_value('MAIL_FROM_NAME', 'Shoply'));
    if ($replyTo !== $smtpUser && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $mailer->addReplyTo($replyTo, env_value('MAIL_FROM_NAME', 'Shoply'));
    }
    $mailer->addAddress($email, $name);
    $mailer->isHTML(true);
    $mailer->Subject = 'Confirmez votre adresse email - Shoply';
    $safeName = e($name);
    $safeUrl = e($verificationUrl);
    $mailer->Body = <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Confirmer votre adresse</title></head>
<body style="margin:0;background:#f5f1e9;color:#20251f;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 12px"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fffdf9;border:1px solid #e7dfd1;border-radius:18px;overflow:hidden"><tr><td style="background:#153c3b;padding:26px 32px;color:#fffdf9;font-size:22px;font-weight:bold">Shoply<span style="color:#f4a261">.</span></td></tr><tr><td style="padding:34px 32px"><p style="margin:0 0 18px;font-size:16px">Bonjour {$safeName},</p><h1 style="margin:0 0 14px;font-size:28px;line-height:1.15;color:#153c3b">Votre panier commence ici.</h1><p style="font-size:16px;line-height:1.6;color:#53605a">Un dernier clic suffit pour confirmer votre adresse et commencer a organiser vos courses avec Shoply.</p><p style="margin:28px 0;text-align:center"><a href="{$safeUrl}" style="display:inline-block;background:#e76f51;color:#fff;text-decoration:none;font-weight:bold;padding:15px 24px;border-radius:9px">Confirmer mon adresse</a></p><p style="font-size:13px;line-height:1.5;color:#68736e">Ce lien est valable 24 heures et ne peut etre utilise qu'une seule fois. Si vous n'etes pas a l'origine de cette demande, vous pouvez ignorer cet email.</p></td></tr><tr><td style="padding:20px 32px;background:#f8f4ec;color:#68736e;font-size:12px">Shoply · Des courses plus simples, ensemble.</td></tr></table></td></tr></table></body></html>
HTML;
    $mailer->AltBody = "Bonjour {$name},\n\nConfirmez votre adresse Shoply en ouvrant ce lien : {$verificationUrl}\n\nCe lien est valable 24 heures.";
    $mailer->send();
}