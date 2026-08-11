<?php
/** Reviewer notification template. Editable later in Administer > Message Templates. */
civicrm_initialize();
use Civi\Api4\MessageTemplate;

$title = 'OpenAR - New membership application for review';
$view = 'https://join.openarcollective.org/civicrm/?page=CiviCRM&q=civicrm/contact/view&reset=1&cid={contact.id}';

$text = <<<TXT
A new membership application has been confirmed and is waiting for review.

Name:       {contact.display_name}
Email:      {contact.email_primary.email}
Employer:   {contact.Membership.employer_affiliation}
Role:       {contact.job_title}
LinkedIn:   {contact.Membership.linkedin_url}

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

<table cellpadding="4" style="border-collapse:collapse">
  <tr><td><strong>Name</strong></td><td>{contact.display_name}</td></tr>
  <tr><td><strong>Email</strong></td><td>{contact.email_primary.email}</td></tr>
  <tr><td><strong>Employer</strong></td><td>{contact.Membership.employer_affiliation}</td></tr>
  <tr><td><strong>Role</strong></td><td>{contact.job_title}</td></tr>
  <tr><td><strong>LinkedIn</strong></td><td>{contact.Membership.linkedin_url}</td></tr>
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
