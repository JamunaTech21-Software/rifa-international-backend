<?php
/**
 * To the office inbox for every new application.
 *
 * @var callable    $e
 * @var string      $reference
 * @var string      $fullName
 * @var string      $submittedDate
 * @var string      $submittedTime
 * @var string      $category
 * @var string      $mobile
 * @var string|null $email
 * @var int         $age
 * @var string|null $gender
 * @var string|null $passportStatus
 * @var string|null $experience
 * @var string|null $education
 * @var bool        $ageFlagged
 * @var bool        $duplicateFlagged
 * @var string      $adminUrl
 */
$rows = [
    'Reference' => $reference,
    'Name' => $fullName,
    'Category' => $category,
    'Mobile' => $mobile,
    'Email' => $email ?: '—',
    'Age' => (string) $age,
    'Gender' => $gender ?? '—',
    'Passport' => $passportStatus ?? '—',
    'Experience' => $experience ?? '—',
    'Education' => $education ?? '—',
    'Submitted' => "{$submittedDate}, {$submittedTime}",
];
?>
<h1 style="margin:0 0 12px;font-size:20px;color:#0B1F3A;">New job application</h1>

<?php if ($ageFlagged || $duplicateFlagged): ?>
<div style="background:#FFF7E6;border:1px solid #F5C77E;border-radius:8px;padding:10px 14px;margin:0 0 16px;font-size:14px;color:#8A5300;">
  <?= $ageFlagged ? '⚠ Applicant is older than 45.<br>' : '' ?>
  <?= $duplicateFlagged ? '⚠ Possible duplicate: same mobile or NID applied in the last 30 days.' : '' ?>
</div>
<?php endif; ?>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 20px;font-size:14px;">
  <?php foreach ($rows as $label => $value): ?>
  <tr>
    <td style="padding:6px 10px 6px 0;color:#475569;width:120px;border-bottom:1px solid #E2E8F0;"><?= $e($label) ?></td>
    <td style="padding:6px 0;color:#0B1F3A;border-bottom:1px solid #E2E8F0;"><strong><?= $e($value) ?></strong></td>
  </tr>
  <?php endforeach; ?>
</table>

<a href="<?= $e($adminUrl) ?>" style="display:inline-block;background:#1463D6;color:#FFFFFF;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:8px;">Open in admin panel</a>
