<?php
/**
 * The decline email, and the alert sent when a decline is recorded without a reason.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file decline-templates.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

require_once __DIR__ . '/openar-signature.php';

$templates = [];

/* --------------------------------------------------------------- decline -- */

// The decision is stated in the second sentence on purpose. Softening the news
// by burying it makes the reader hunt for it, and finding it that way is worse
// than being told plainly. What comes after is where the warmth belongs.
$templates[] = [
  'msg_title' => 'OpenAR - Membership application declined',
  'msg_subject' => 'Your membership application to the OpenAR Collective',
  'closing' => 'With thanks for your interest',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

Thank you for applying, and for the time it took you. I am sorry to tell you that the Foundation has not approved your application for the reason stated below.

{$reason}

What this decision is not
-------------------------

The Community Programs and Standards Policy says admission will not be conditioned on agency size, organization type, trade association affiliation, employer, financial contribution, business model, or any viewpoint regarding industry practices. That binds the Foundation, and it bound this decision. Nothing about your politics, your beliefs, who you work for, or what you think of the industry played any part in it.

The policy is published in full, and you are welcome to read the rules this was decided under:
https://openarcollective.org/policies/community-programs-and-standards/

What membership is for
----------------------

It is worth being plain about this, because membership is easy to mistake for something larger than it is. Membership is recognition that you are part of the Foundation's community, and it opens the members-only areas of the Discord server where practitioners talk to each other. That is all it is. It is not a license, a certification, a qualification, or a credential, and it carries no vote and no governance role. The Foundation does not publish a list of its members.

Your access to the work is unchanged
------------------------------------

None of this touches what the Foundation actually produces. Everything it publishes is free at https://openarcollective.org with no account, no membership and no sign-in, and its software is developed in the open at https://github.com/OpenAR-Collective under permissive open-source licenses. You are as welcome to use all of it today as you were yesterday, and you always will be.

If this is wrong
----------------

The decision is about one application, it is not a judgment of you or your work, and it is not permanent. If we have misread something, or if your circumstances change, write and tell us and we will look again.

You can also ask the Board to review it. Write to {$appealInbox}, say why you think it was wrong, and it will be put to the Board, whose decision is final. Section 7.6 of the Community Programs and Standards Policy gives you thirty days from this message to do that, and one appeal. It also says that nothing stops you applying again at any time, which you are welcome to do.
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>Thank you for applying, and for the time it took you. I am sorry to tell you that the Foundation has not approved your application for the reason stated below.</p>

<p style="padding:12px 16px;border-left:3px solid #b8b0a4;background:#f6f4f0;">{$reason}</p>

<h3 style="margin:26px 0 6px;">What this decision is not</h3>

<p>The Community Programs and Standards Policy says admission will not be conditioned on agency size, organization type, trade association affiliation, employer, financial contribution, business model, or any viewpoint regarding industry practices. That binds the Foundation, and it bound this decision. Nothing about your politics, your beliefs, who you work for, or what you think of the industry played any part in it.</p>

<p>The policy is published in full, and you are welcome to read the rules this was decided under: <a href="https://openarcollective.org/policies/community-programs-and-standards/">Community Programs and Standards Policy</a>.</p>

<h3 style="margin:26px 0 6px;">What membership is for</h3>

<p>It is worth being plain about this, because membership is easy to mistake for something larger than it is. Membership is recognition that you are part of the Foundation's community, and it opens the members-only areas of the Discord server where practitioners talk to each other. That is all it is. It is not a license, a certification, a qualification, or a credential, and it carries no vote and no governance role. The Foundation does not publish a list of its members.</p>

<h3 style="margin:26px 0 6px;">Your access to the work is unchanged</h3>

<p>None of this touches what the Foundation actually produces. Everything it publishes is free at <a href="https://openarcollective.org">openarcollective.org</a> with no account, no membership and no sign-in, and its software is developed in the open at <a href="https://github.com/OpenAR-Collective">github.com/OpenAR-Collective</a> under permissive open-source licenses. You are as welcome to use all of it today as you were yesterday, and you always will be.</p>

<h3 style="margin:26px 0 6px;">If this is wrong</h3>

<p>The decision is about one application, it is not a judgment of you or your work, and it is not permanent. If we have misread something, or if your circumstances change, write and tell us and we will look again.</p>

<p>You can also ask the Board to review it. Write to <a href="mailto:{$appealInbox}">{$appealInbox}</a>, say why you think it was wrong, and it will be put to the Board, whose decision is final. Section 7.6 of the Community Programs and Standards Policy gives you thirty days from this message to do that, and one appeal. It also says that nothing stops you applying again at any time, which you are welcome to do.</p>
HTML,
];

/* ------------------------------------------------ nothing sent, needs work -- */

$templates[] = [
  'msg_title' => 'OpenAR - Decline recorded without a reason',
  'msg_subject' => 'Decline not sent, no reason recorded: {$displayName}',
  'msg_text' => <<<'TEXT'
{$displayName} (contact {$contactId}) was added to the declined group, but the "Reason given to the applicant" field is empty, so nothing has been sent to them.

A decline with no reason is worse than no decline at all, so the email is held until a reason is written.

To finish it, open the contact, fill in "Reason given to the applicant", and save. The decline goes out on save; there is nothing else to do.

Write it as something you are willing for them to read. Reviewer working notes belong in "Review notes", which is never sent to anyone.

Nothing else about the application has changed. They remain declined and out of the review queue.

The OpenAR Collective
TEXT,
  'msg_html' => <<<'HTML'
<p><strong>{$displayName}</strong> (contact {$contactId}) was added to the declined group, but the "Reason given to the applicant" field is empty, so <strong>nothing has been sent to them</strong>.</p>

<p>A decline with no reason is worse than no decline at all, so the email is held until a reason is written.</p>

<p>To finish it, open the contact, fill in <strong>Reason given to the applicant</strong>, and save. The decline goes out on save; there is nothing else to do.</p>

<p>Write it as something you are willing for them to read. Reviewer working notes belong in <strong>Review notes</strong>, which is never sent to anyone.</p>

<p>Nothing else about the application has changed. They remain declined and out of the review queue.</p>

<p>The OpenAR Collective</p>
HTML,
];

foreach ($templates as $t) {
  // Signed only where a person is being written to. The held-decline notice
  // goes to whoever is doing reviews, and a colleague signing off a work queue
  // item with a full contact card is odd.
  if (!empty($t['closing'])) {
    $t['msg_text'] .= openar_signature_text($t['closing']);
    $t['msg_html'] .= openar_signature_html($t['closing']);
  }
  unset($t['closing']);

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
