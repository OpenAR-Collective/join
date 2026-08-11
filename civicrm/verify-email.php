<?php
/**
 * Replace Afform's ten-minute confirmation email with our own seven-day one.
 *
 * Afform hardcodes the link lifetime in Civi\Afform\Tokens, so the only way to
 * change it is to send the email ourselves. The mu-plugin does that; this script
 * installs the template it uses and switches Afform's built-in email off, which
 * otherwise means the applicant gets two.
 *
 * Turning off allow_verification_by_email does not re-enable immediate writes.
 * manual_processing is what defers them, and that stays on.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file verify-email.php
 */

civicrm_initialize();

const TEMPLATE_TITLE = 'OpenAR - Confirm your email address';
const FORMS = ['afformMembershipApplication'];
const APPLY_URL = 'https://join.openarcollective.org/apply';

$subject = 'Confirm your email address for OpenAR Collective membership';

$html = <<<HTML
<p>Hello {\$firstName},</p>

<p>Thank you for applying for membership in The Open Accounts Receivable Collective Foundation. One step is left: confirm that this email address is yours.</p>

<p><a href="{\$verifyUrl}" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Confirm my email address</a></p>

<p>Your application reaches the review queue only when you confirm, so nothing happens until you do. The link works once and is good for {\$expiryDays} days. If it lapses, you can apply again at <a href="APPLY_URL_HERE">join.openarcollective.org/apply</a> and a new link will be sent.</p>

<p>If the button does not work, copy this address into your browser:</p>

<p style="font-family:monospace;font-size:13px;word-break:break-all;">{\$verifyUrl}</p>

<p>If you did not apply, you can ignore this message and nothing further will happen.</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
HTML;

$text = <<<TEXT
Hello {\$firstName},

Thank you for applying for membership in The Open Accounts Receivable Collective Foundation. One step is left: confirm that this email address is yours.

Open this link to confirm:

{\$verifyUrl}

Your application reaches the review queue only when you confirm, so nothing happens until you do. The link works once and is good for {\$expiryDays} days. If it lapses, you can apply again at APPLY_URL_HERE and a new link will be sent.

If you did not apply, you can ignore this message and nothing further will happen.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT;

$html = str_replace('APPLY_URL_HERE', APPLY_URL, $html);
$text = str_replace('APPLY_URL_HERE', APPLY_URL, $text);

// Reuse whatever template the form already points at, so the old ten-minute one
// is rewritten in place rather than left behind as a confusing duplicate.
$existingId = NULL;
foreach (FORMS as $formName) {
  $form = civicrm_api4('Afform', 'get', [
    'where' => [['name', '=', $formName]],
    'select' => ['name', 'email_confirmation_template_id'],
    'checkPermissions' => FALSE,
  ])->first();
  if ($form && !empty($form['email_confirmation_template_id'])) {
    $existingId = (int) $form['email_confirmation_template_id'];
    break;
  }
}

$byTitle = civicrm_api4('MessageTemplate', 'get', [
  'where' => [['msg_title', '=', TEMPLATE_TITLE]],
  'select' => ['id'],
  'checkPermissions' => FALSE,
])->first();

$templateId = $byTitle['id'] ?? $existingId;

$values = [
  'msg_title' => TEMPLATE_TITLE,
  'msg_subject' => $subject,
  'msg_html' => $html,
  'msg_text' => $text,
  'is_active' => TRUE,
];

if ($templateId) {
  civicrm_api4('MessageTemplate', 'update', [
    'where' => [['id', '=', $templateId]],
    'values' => $values,
    'checkPermissions' => FALSE,
  ]);
  echo "updated message template {$templateId}\n";
}
else {
  $templateId = civicrm_api4('MessageTemplate', 'create', [
    'values' => $values,
    'checkPermissions' => FALSE,
  ])->first()['id'];
  echo "created message template {$templateId}\n";
}

foreach (FORMS as $formName) {
  $form = civicrm_api4('Afform', 'get', [
    'where' => [['name', '=', $formName]],
    'select' => ['name', 'manual_processing', 'allow_verification_by_email'],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$form) {
    echo "WARNING: form {$formName} not found\n";
    continue;
  }

  civicrm_api4('Afform', 'update', [
    'where' => [['name', '=', $formName]],
    'values' => [
      // manual_processing stays on: it is what keeps unconfirmed applications
      // out of the contact records.
      'manual_processing' => TRUE,
      'allow_verification_by_email' => FALSE,
      'email_confirmation_template_id' => $templateId,
    ],
    'checkPermissions' => FALSE,
  ]);

  echo "{$formName}: manual_processing on, Afform's own verification email off\n";
}

echo "\nconfirmation links now last 7 days and are sent by the mu-plugin\n";
