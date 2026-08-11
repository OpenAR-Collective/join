<?php
/**
 * Mission Supporter path: confirmation, review, and listing emails, plus the
 * form settings that hold an unverified signature out of the database.
 *
 * The Statement of Support is a public statement made in an organization's name.
 * Without a confirmation step anyone could sign on behalf of any company, and
 * the only thing standing between that and the published roster would be a
 * reviewer noticing. Confirming the signer's address first means the roster
 * starts from someone who at least controls that mailbox.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file supporter-setup.php
 */

civicrm_initialize();

use Civi\Api4\Afform;
use Civi\Api4\CustomField;
use Civi\Api4\MessageTemplate;

const FORM = 'afformSupporterStatement';
const SIGN_URL = 'https://join.openarcollective.org/sign';

/** Custom fields reach the token processor as {contact.custom_N}, never by API name. */
function supporter_token(string $field): string {
  $f = CustomField::get(FALSE)
    ->addSelect('id')
    ->addWhere('custom_group_id.name', '=', 'MissionSupporter')
    ->addWhere('name', '=', $field)
    ->execute()->first();
  if (!$f) {
    throw new RuntimeException("custom field MissionSupporter.{$field} not found");
  }
  return '{contact.custom_' . $f['id'] . '}';
}

$tradeName = supporter_token('trade_name');
$website = supporter_token('website_url');
$signerName = supporter_token('signer_name');
$signerTitle = supporter_token('signer_title');
$signerEmail = supporter_token('signer_email');

$view = 'https://join.openarcollective.org/civicrm/?page=CiviCRM&q=civicrm/contact/view&reset=1&cid={contact.id}';

$templates = [];

/* ---------------------------------------------------- confirm the signer -- */

$templates[] = [
  'msg_title' => 'OpenAR - Confirm your Statement of Support',
  'msg_subject' => 'Confirm your organization\'s Statement of Support',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

A Mission Supporter Statement of Support was signed in your organization's name, giving this address as the signer. One step is left: confirm that this address is yours.

Open this link to confirm:

{$verifyUrl}

Nothing is recorded, and your organization is not listed anywhere, until you confirm. The link works once and is good for {$expiryDays} days. If it lapses, you can sign again at SIGN_URL_HERE.

After you confirm, someone at the Foundation checks the signature before the organization appears on the public roster. Signing costs nothing and carries no financial commitment of any kind, now or ever.

If you did not sign, and did not ask anyone to sign on your behalf, please tell us at membership@openarcollective.org. Ignoring this message also works: nothing happens without your confirmation.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>A Mission Supporter Statement of Support was signed in your organization's name, giving this address as the signer. One step is left: confirm that this address is yours.</p>

<p><a href="{$verifyUrl}" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Confirm this signature</a></p>

<p>Nothing is recorded, and your organization is not listed anywhere, until you confirm. The link works once and is good for {$expiryDays} days. If it lapses, you can sign again at <a href="SIGN_URL_HERE">join.openarcollective.org/sign</a>.</p>

<p>After you confirm, someone at the Foundation checks the signature before the organization appears on the public roster. Signing costs nothing and carries no financial commitment of any kind, now or ever.</p>

<p>If the button does not work, copy this address into your browser:</p>

<p style="font-family:monospace;font-size:13px;word-break:break-all;">{$verifyUrl}</p>

<p>If you did not sign, and did not ask anyone to sign on your behalf, please tell us at <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>. Ignoring this message also works: nothing happens without your confirmation.</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
HTML,
];

/* --------------------------------------------------------- for reviewers -- */

$templates[] = [
  'msg_title' => 'OpenAR - New Statement of Support for review',
  'msg_subject' => 'Statement of Support to review: {contact.display_name}',
  'msg_text' => <<<TXT
A Mission Supporter Statement of Support has been confirmed and is waiting for review.
{\$duplicateWarningText}
Organization:  {contact.organization_name}
Trade name:    $tradeName
Website:       $website
Signer:        $signerName
Title:         $signerTitle
Email:         $signerEmail

Review the record:
$view

The signer has confirmed their email address, so the address is known good. What
remains is confirming they can bind the organization. A business address on the
organization's own domain is reasonable evidence. Anything else is worth a
reply-to-confirm before publishing.

**Approving puts this organization on a public page.** Add them to
"Mission Supporters - published" and the roster on openarcollective.org picks
them up on the next sync, with no further step. Withdrawals work the same way in
reverse: remove them from that group and the next sync takes them off.

The OpenAR Collective
TXT,
  'msg_html' => <<<HTML
<p>A Mission Supporter Statement of Support has been confirmed and is waiting for review.</p>
{\$duplicateWarningHtml}
<table cellpadding="4" style="border-collapse:collapse">
  <tr><td><strong>Organization</strong></td><td>{contact.organization_name}</td></tr>
  <tr><td><strong>Trade name</strong></td><td>$tradeName</td></tr>
  <tr><td><strong>Website</strong></td><td>$website</td></tr>
  <tr><td><strong>Signer</strong></td><td>$signerName</td></tr>
  <tr><td><strong>Title</strong></td><td>$signerTitle</td></tr>
  <tr><td><strong>Email</strong></td><td>$signerEmail</td></tr>
</table>

<p><a href="$view">Review the record</a></p>

<p>The signer has confirmed their email address, so the address is known good.
What remains is confirming they can bind the organization. A business address on
the organization's own domain is reasonable evidence. Anything else is worth a
reply-to-confirm before publishing.</p>

<p style="padding:10px 14px;border-left:3px solid #e8a020;background:#fdf6e7;">
<strong>Approving puts this organization on a public page.</strong> Add them to
<strong>Mission Supporters - published</strong> and the roster on
openarcollective.org picks them up on the next sync, with no further step.
Withdrawals work the same way in reverse.</p>

<p>The OpenAR Collective</p>
HTML,
];

/* ------------------------------------------------------------ now listed -- */

$templates[] = [
  'msg_title' => 'OpenAR - Your organization is now listed',
  'msg_subject' => '{$organizationName} is now a Mission Supporter',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

{$organizationName} is now listed as a Mission Supporter of The Open Accounts Receivable Collective Foundation. The roster is at https://openarcollective.org/supporters

Organizations are listed in alphabetical order, on identical terms, with no tiers. Listing is not a review, approval, or endorsement by the Foundation, and it does not make your organization a member, partner, affiliate, or sponsor.

To send a logo for the roster, or to correct anything in your listing, write to membership@openarcollective.org.

You can withdraw at any time by writing to the same address, and your organization will be removed promptly. The Statement asks nothing further of you: no dues, no financial commitment, and no position on industry practices, regulation, or litigation.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p><strong>{$organizationName}</strong> is now listed as a Mission Supporter of The Open Accounts Receivable Collective Foundation. The roster is at <a href="https://openarcollective.org/supporters">openarcollective.org/supporters</a>.</p>

<p>Organizations are listed in alphabetical order, on identical terms, with no tiers. Listing is not a review, approval, or endorsement by the Foundation, and it does not make your organization a member, partner, affiliate, or sponsor.</p>

<p>To send a logo for the roster, or to correct anything in your listing, write to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>.</p>

<p>You can withdraw at any time by writing to the same address, and your organization will be removed promptly. The Statement asks nothing further of you: no dues, no financial commitment, and no position on industry practices, regulation, or litigation.</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
HTML,
];

foreach ($templates as $t) {
  $t['msg_text'] = str_replace('SIGN_URL_HERE', SIGN_URL, $t['msg_text']);
  $t['msg_html'] = str_replace('SIGN_URL_HERE', SIGN_URL, $t['msg_html']);
  $t['is_active'] = TRUE;
  $t['is_reserved'] = FALSE;

  $existing = MessageTemplate::get(FALSE)->addWhere('msg_title', '=', $t['msg_title'])->execute()->first();
  if ($existing) {
    MessageTemplate::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($t)->execute();
    echo "updated  {$t['msg_title']} (id {$existing['id']})\n";
  }
  else {
    $id = MessageTemplate::create(FALSE)->setValues($t)->execute()->first()['id'];
    echo "created  {$t['msg_title']} (id {$id})\n";
  }
}

/* ------------------------------------------------------- the form itself -- */

$form = Afform::get(FALSE)
  ->addSelect('name', 'manual_processing', 'allow_verification_by_email')
  ->addWhere('name', '=', FORM)
  ->execute()->first();

if (!$form) {
  echo "\nWARNING: form " . FORM . " not found\n";
  return;
}

echo "\nbefore: manual_processing=" . json_encode($form['manual_processing'])
  . " allow_verification_by_email=" . json_encode($form['allow_verification_by_email']) . "\n";

Afform::update(FALSE)
  ->addWhere('name', '=', FORM)
  // Holds the signature out of the contact records until the address is
  // confirmed. Without this an unverified statement is written immediately.
  ->addValue('manual_processing', TRUE)
  // We send our own, with a seven-day link. Afform's is ten minutes and, on this
  // form, would never fire anyway: it looks for an Email join, and the signer's
  // address is a custom field on the organization.
  ->addValue('allow_verification_by_email', FALSE)
  ->addValue('create_submission', TRUE)
  ->execute();

echo "after:  manual_processing=true allow_verification_by_email=false\n";
echo "\nUnconfirmed Statements of Support no longer reach the contact records.\n";
