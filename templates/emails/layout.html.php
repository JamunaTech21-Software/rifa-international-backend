<?php
/**
 * Shared email layout. Inline styles only (email clients ignore <style> blocks).
 *
 * @var string   $content  rendered inner template (already escaped)
 * @var string   $subject
 * @var callable $e        HTML escape helper
 * @var array    $site     contact details from config/mail.php
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background:#F5F9FE;font-family:Arial,Helvetica,sans-serif;color:#0B1F3A;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F9FE;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E2E8F0;">
        <tr>
          <td style="background:#0A2647;padding:20px 28px;">
            <div style="font-size:24px;font-weight:800;letter-spacing:1px;color:#FFFFFF;">RIFA</div>
            <div style="font-size:11px;letter-spacing:3px;color:#9FB8D9;">INTERNATIONAL · <?= $e($site['licence'] ?? 'RL-693') ?></div>
          </td>
        </tr>
        <tr>
          <td style="padding:28px;font-size:15px;line-height:1.6;color:#334155;">
            <?= $content ?>
          </td>
        </tr>
        <tr>
          <td style="background:#F5F9FE;padding:20px 28px;font-size:12px;line-height:1.6;color:#475569;border-top:1px solid #E2E8F0;">
            <strong style="color:#0B1F3A;"><?= $e($site['name'] ?? 'Rifa International') ?> (<?= $e($site['licence'] ?? 'RL-693') ?>)</strong><br>
            <?= $e($site['address'] ?? '') ?><br>
            Phone: <?= $e($site['phone'] ?? '') ?> · WhatsApp: <?= $e($site['whatsapp'] ?? '') ?><br>
            Email: <?= $e($site['email'] ?? '') ?> · <?= $e($site['hours'] ?? '') ?><br>
            <span style="color:#94A3B8;">Rifa International does not charge any fee for online application.</span>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
