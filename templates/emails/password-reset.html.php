<?php
/**
 * Password reset link for an admin.
 *
 * @var callable $e
 * @var string   $name
 * @var string   $resetUrl
 * @var int      $minutes
 */
?>
<h1 style="margin:0 0 12px;font-size:20px;color:#0B1F3A;">Reset your admin password</h1>
<p style="margin:0 0 12px;">Hello <?= $e($name) ?>,</p>
<p style="margin:0 0 20px;">We received a request to reset the password of your RIFA admin account. The link below works once and expires in <?= $e($minutes) ?> minutes.</p>
<a href="<?= $e($resetUrl) ?>" style="display:inline-block;background:#1463D6;color:#FFFFFF;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:8px;">Reset password</a>
<p style="margin:20px 0 0;font-size:13px;color:#475569;">If you did not request this, you can ignore this email — your password will not change.</p>
