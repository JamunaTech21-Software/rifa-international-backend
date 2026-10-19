<?php
/**
 * To the office inbox for every Contact Us message. Reply-To is the sender.
 *
 * @var callable $e
 * @var string   $subjectLabel
 * @var string   $fullName
 * @var string   $email
 * @var string   $phone
 * @var string   $message
 * @var string   $receivedAt
 * @var string   $adminUrl
 */
$rows = ['Subject' => $subjectLabel, 'Name' => $fullName, 'Email' => $email, 'Phone' => $phone, 'Received' => $receivedAt];
?>
<h1 style="margin:0 0 12px;font-size:20px;color:#0B1F3A;">New contact message</h1>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 16px;font-size:14px;">
  <?php foreach ($rows as $label => $value): ?>
  <tr>
    <td style="padding:6px 10px 6px 0;color:#475569;width:100px;border-bottom:1px solid #E2E8F0;"><?= $e($label) ?></td>
    <td style="padding:6px 0;color:#0B1F3A;border-bottom:1px solid #E2E8F0;"><strong><?= $e($value) ?></strong></td>
  </tr>
  <?php endforeach; ?>
</table>

<div style="background:#F5F9FE;border:1px solid #E2E8F0;border-radius:8px;padding:14px 16px;margin:0 0 20px;white-space:normal;color:#0B1F3A;">
  <?= nl2br($e($message)) ?>
</div>

<p style="margin:0 0 16px;font-size:13px;color:#475569;">Reply directly to this email, or answer from the admin panel so the reply is saved with the message.</p>
<a href="<?= $e($adminUrl) ?>" style="display:inline-block;background:#1463D6;color:#FFFFFF;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:8px;">Open in admin panel</a>
