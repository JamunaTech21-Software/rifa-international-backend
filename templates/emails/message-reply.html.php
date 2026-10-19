<?php
/**
 * Admin reply to a Contact Us message. Reply-To is the office inbox.
 *
 * @var callable $e
 * @var string   $fullName
 * @var string   $reply
 * @var string   $adminName
 * @var string   $originalMessage
 * @var string   $originalDate
 */
?>
<p style="margin:0 0 16px;">Dear <?= $e($fullName) ?>,</p>
<div style="margin:0 0 20px;color:#0B1F3A;"><?= nl2br($e($reply)) ?></div>
<p style="margin:0 0 24px;">Best regards,<br><strong><?= $e($adminName) ?></strong><br>Rifa International (RL-693)</p>

<div style="border-left:3px solid #E2E8F0;padding:4px 0 4px 14px;color:#64748B;font-size:13px;">
  <div style="margin:0 0 6px;">On <?= $e($originalDate) ?> you wrote:</div>
  <?= nl2br($e($originalMessage)) ?>
</div>
