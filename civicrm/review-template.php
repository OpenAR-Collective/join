<?php
/** Reviewer notification template. Editable later in Administer > Message Templates. */
civicrm_initialize();
// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

$title = 'OpenAR - New membership application for review';
// wp-admin, not the front end. CiviCRM renders inside the site theme on the
// basepage, where the brand stylesheet is scoped to public forms only and the
// theme's typography fights CiviCRM's own: mismatched fonts and backgrounds,
// and a select box clipped so only its top half shows. The same screen in
// wp-admin is styled by CiviCRM and looks right.
$view = 'https://join.openarcollective.org/wp-admin/admin.php?page=CiviCRM&q=civicrm/contact/view&reset=1&cid={contact.id}';

/**
 * LinkedIn is passed in as a template parameter rather than a token, so the
 * template can render it as a real anchor and can tell an empty optional field
 * from a rendering fault. Employer stays a token because it is always present.
 *
 * Custom fields are exposed to the token processor as {contact.custom_N}, not by
 * the API name. Resolving N here rather than hardcoding it keeps the template
 * correct if a field is ever rebuilt, and fails loudly instead of silently
 * emailing reviewers a blank employer, which is the one field they check.
 */
function openar_token(string $group, string $field): string {
  $f = civicrm_api4('CustomField', 'get', [
    'select' => ['id'],
    'where' => [['custom_group_id.name', '=', $group], ['name', '=', $field]],
    'checkPermissions' => FALSE,
  ])->first();
  if (!$f) {
    throw new RuntimeException("custom field {$group}.{$field} not found; template not written");
  }
  return '{contact.custom_' . $f['id'] . '}';
}

$employer = openar_token('Membership', 'employer_affiliation');
echo "employer token: $employer
";

$text = <<<TXT
A new membership application has been confirmed and is waiting for review.
{\$duplicateWarningText}
Name:       {contact.display_name}
Email:      {contact.email_primary.email}
Employer:   $employer
Role:       {contact.job_title}
LinkedIn:   {\$linkedinUrl|default:'not supplied'}

Review the record:
$view

The applicant has confirmed their email address, so the address is known good.
What remains is confirming that the stated employer or affiliation is
plausible. Per the Membership Application this verifies that they are who they
say they are. It is not a screening for favored participants, and it does not
consider the employer's size, business model, or reputation.

If something does not line up, ask before declining. A decline is issued by a
director, states the reason, and is recorded on the contact.

The OpenAR Collective
TXT;

$html = <<<HTML
<p>A new membership application has been confirmed and is waiting for review.</p>
{\$duplicateWarningHtml}
<table cellpadding="4" style="border-collapse:collapse">
  <tr><td><strong>Name</strong></td><td>{contact.display_name}</td></tr>
  <tr><td><strong>Email</strong></td><td>{contact.email_primary.email}</td></tr>
  <tr><td><strong>Employer</strong></td><td>$employer</td></tr>
  <tr><td><strong>Role</strong></td><td>{contact.job_title}</td></tr>
  <tr><td><strong>LinkedIn</strong></td><td>{if \$linkedinUrl}<a href="{\$linkedinUrl}">{\$linkedinUrl}</a>{else}<em>not supplied</em>{/if}</td></tr>
</table>

<p><a href="$view">Review the record</a></p>

<p>The applicant has confirmed their email address, so the address is known good.
What remains is confirming that the stated employer or affiliation is plausible.
Per the Membership Application this verifies that they are who they say they are.
It is not a screening for favored participants, and it does not consider the
employer's size, business model, or reputation.</p>

<p>If something does not line up, ask before declining. A decline is issued by a
director, states the reason, and is recorded on the contact.</p>

<p>The OpenAR Collective</p>
HTML;

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'Membership application to review: {contact.display_name}',
  'msg_text' => $text,
  'msg_html' => $html,
  'is_active' => TRUE,
  'is_reserved' => FALSE,
];

$existing = MessageTemplate::get(FALSE)->addWhere('msg_title', '=', $title)->execute()->first();
if ($existing) {
  MessageTemplate::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($vals)->execute();
  echo "updated template id {$existing['id']}\n";
} else {
  $id = MessageTemplate::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "created template id $id\n";
}
