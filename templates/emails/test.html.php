<?php
/**
 * Used by bin/mail-test.php to check SMTP settings on a server.
 *
 * @var callable $e
 * @var string   $sentAt
 * @var string   $environment
 */
?>
<h1 style="margin:0 0 12px;font-size:20px;color:#0B1F3A;">Email delivery test</h1>
<p style="margin:0 0 8px;">If you can read this, outgoing email from the RIFA backend works.</p>
<p style="margin:0;color:#475569;">Environment: <strong><?= $e($environment) ?></strong> · Sent at <?= $e($sentAt) ?></p>
