<?php
/**
 * To the applicant after a successful submission.
 *
 * @var callable $e
 * @var array    $site
 * @var string   $reference
 * @var string   $fullName
 * @var string   $submittedDate
 * @var string   $submittedTime
 * @var string   $category
 */
$steps = [
    ['Application Received', 'We have received your information.'],
    ['Document Review', 'Our team will verify your documents.'],
    ['Shortlisting', 'You will be contacted if you are shortlisted.'],
    ['Further Process', 'We will guide you on the next steps.'],
];
?>
<h1 style="margin:0 0 8px;font-size:22px;color:#0B1F3A;">Application Submitted <span style="color:#16A34A;">Successfully!</span></h1>
<p style="margin:0 0 16px;">Dear <?= $e($fullName) ?>,</p>
<p style="margin:0 0 20px;">Thank you for applying through <strong>Rifa International (RL-693)</strong> for a <strong><?= $e($category) ?></strong> job in Malaysia. We have received your application and documents. Our team will review your information and contact you soon regarding the next steps.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EAF3FD;border-radius:10px;margin:0 0 20px;">
  <tr>
    <td style="padding:16px 20px;">
      <div style="font-size:13px;color:#475569;">Application Reference No.</div>
      <div style="font-size:24px;font-weight:800;color:#0A2647;letter-spacing:.5px;"><?= $e($reference) ?></div>
      <div style="font-size:12px;color:#475569;">Please keep this reference number for future communication.</div>
      <div style="font-size:13px;color:#0B1F3A;margin-top:10px;">Submitted: <?= $e($submittedDate) ?>, <?= $e($submittedTime) ?></div>
    </td>
  </tr>
</table>

<h2 style="margin:0 0 10px;font-size:16px;color:#0B1F3A;">What happens next?</h2>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
  <?php foreach ($steps as $i => [$title, $text]): ?>
  <tr>
    <td width="32" valign="top" style="padding:6px 0;">
      <div style="width:24px;height:24px;line-height:24px;border-radius:12px;text-align:center;font-size:12px;font-weight:700;color:#FFFFFF;background:<?= $i === 0 ? '#16A34A' : '#1463D6' ?>;"><?= $i + 1 ?></div>
    </td>
    <td style="padding:6px 0;"><strong style="color:#0B1F3A;"><?= $e($title) ?></strong> — <?= $e($text) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<p style="margin:0;">For any assistance, contact our recruitment team at <strong><?= $e($site['phone'] ?? '') ?></strong> (WhatsApp <?= $e($site['whatsapp'] ?? '') ?>) or reply to <?= $e($site['email'] ?? '') ?>.</p>
