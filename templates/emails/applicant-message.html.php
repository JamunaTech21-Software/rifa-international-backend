<?php
/**
 * Message from an admin to an applicant (P5-01). The body already starts with "Dear …".
 * Reply-To is the office inbox.
 *
 * @var callable $e
 * @var string   $body
 * @var string   $adminName
 * @var string   $reference
 */
?>
<div style="margin:0 0 20px;color:#0B1F3A;"><?= nl2br($e($body)) ?></div>
<p style="margin:0 0 20px;">Best regards,<br><strong><?= $e($adminName) ?></strong><br>Rifa International (RL-693)</p>
<p style="margin:0;font-size:12px;color:#64748B;">Application reference: <strong><?= $e($reference) ?></strong>. Please mention it when you reply or call us.</p>
