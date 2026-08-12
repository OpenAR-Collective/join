<?php
/**
 * Membership application: email confirmation step.
 *
 * Afform sends the verification email only when manual_processing is on. In
 * that mode nothing is written to CiviCRM until the applicant clicks the link,
 * so an unconfirmed address never creates a contact.
 *
 * Tokens come from Civi\Afform\Tokens:
 *   {afformSubmission.validateSubmissionUrl}   plain text
 *   {afformSubmission.validateSubmissionLink}  html anchor
 */
civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\Afform;
use Civi\Api4\MessageTemplate;

echo "manual_processing field present: ";
$fields = Afform::getFields(FALSE)->execute()->indexBy('name');
echo isset($fields['manual_processing']) ? "yes\n" : "NO - abort\n";
if (!isset($fields['manual_processing'])) exit(1);

$subject = 'Confirm your email address for OpenAR Collective membership';

$text = <<<TXT
Thank you for applying for membership in The OpenAR Collective.

Confirm this email address to send your application for review:

{afformSubmission.validateSubmissionUrl}

This link expires in ten minutes. If it lapses, apply again at
https://join.openarcollective.org/apply and a fresh link arrives straight away.

We confirm the address so that your member number and community access reach a
mailbox you actually read, and so that nobody can sign up someone else.

After that, a person at the Foundation reviews your application. We check the
employer or affiliation you gave us, which verifies that you are who you say you
are. It is not a screening for favored participants, and it does not consider
your employer's size, business model, or reputation. Reviews usually take a few
days.

Membership is free forever. It requires no dues or contribution of any kind, and
it gives you no vote or governance role in the Foundation.

Questions are welcome at info@openarcollective.org.

The OpenAR Collective
openarcollective.org
TXT;

$html = <<<HTML
<p>Thank you for applying for membership in The OpenAR Collective.</p>

<p><strong>Confirm this email address to send your application for review:</strong></p>

<p>{afformSubmission.validateSubmissionLink}</p>

<p>This link expires in ten minutes. If it lapses, apply again at
<a href="https://join.openarcollective.org/apply">join.openarcollective.org/apply</a>
and a fresh link arrives straight away.</p>

<p>We confirm the address so that your member number and community access reach a
mailbox you actually read, and so that nobody can sign up someone else.</p>

<p>After that, a person at the Foundation reviews your application. We check the
employer or affiliation you gave us, which verifies that you are who you say you
are. It is not a screening for favored participants, and it does not consider
your employer's size, business model, or reputation. Reviews usually take a few
days.</p>

<p>Membership is free forever. It requires no dues or contribution of any kind,
and it gives you no vote or governance role in the Foundation.</p>

<p>Questions are welcome at
<a href="mailto:info@openarcollective.org">info@openarcollective.org</a>.</p>

<p>The OpenAR Collective<br>
<a href="https://openarcollective.org">openarcollective.org</a></p>
HTML;

$title = 'OpenAR - Membership application email confirmation';
$existing = MessageTemplate::get(FALSE)->addWhere('msg_title', '=', $title)->execute()->first();

$vals = [
  'msg_title' => $title,
  'msg_subject' => $subject,
  'msg_text' => $text,
  'msg_html' => $html,
  'is_active' => TRUE,
  'is_reserved' => FALSE,
];

if ($existing) {
  MessageTemplate::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($vals)->execute();
  $tplId = $existing['id'];
  echo "updated template id $tplId\n";
} else {
  $tplId = MessageTemplate::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "created template id $tplId\n";
}

Afform::update(FALSE)
  ->addWhere('name', '=', 'afformMembershipApplication')
  ->setValues([
    'manual_processing' => TRUE,
    'allow_verification_by_email' => TRUE,
    'email_confirmation_template_id' => $tplId,
    'confirmation_type' => 'message',
    'confirmation_message' => '<p>Thank you. Check your inbox now and click the confirmation link to send your application for review. The link expires in ten minutes.</p>',
  ])->execute();

$a = Afform::get(FALSE)->addWhere('name', '=', 'afformMembershipApplication')
  ->addSelect('manual_processing', 'allow_verification_by_email', 'email_confirmation_template_id', 'confirmation_type')
  ->execute()->first();
echo "form now: manual_processing=", var_export($a['manual_processing'], TRUE),
     " verify_by_email=", var_export($a['allow_verification_by_email'], TRUE),
     " template=", $a['email_confirmation_template_id'],
     " confirmation_type=", $a['confirmation_type'], "\n";
