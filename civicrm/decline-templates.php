<?php
/**
 * The decline email, and the alert sent when a decline is recorded without a reason.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file decline-templates.php
 */

civicrm_initialize();

use Civi\Api4\MessageTemplate;

$templates = [];

/* --------------------------------------------------------------- decline -- */

$templates[] = [
  'msg_title' => 'OpenAR - Membership application declined',
  'msg_subject' => 'Your membership application to the OpenAR Collective',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

The Foundation has reviewed your membership application and declined it.

{$reason}

You can ask the Board to review that decision. Write to {$appealInbox}, say why you think it was wrong, and it will be put to the Board. Under the Membership Application you have one appeal.

If we have misread something, or if your circumstances have changed, tell us and we will look again.

None of this affects your access to the Foundation's work. Everything the Foundation publishes is free on openarcollective.org, with no account, no membership, and no sign-in, and that does not change.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>The Foundation has reviewed your membership application and declined it.</p>

<p style="padding:12px 16px;border-left:3px solid #b8b0a4;background:#f6f4f0;">{$reason}</p>

<p>You can ask the Board to review that decision. Write to <a href="mailto:{$appealInbox}">{$appealInbox}</a>, say why you think it was wrong, and it will be put to the Board. Under the Membership Application you have one appeal.</p>

<p>If we have misread something, or if your circumstances have changed, tell us and we will look again.</p>

<p>None of this affects your access to the Foundation's work. Everything the Foundation publishes is free on <a href="https://openarcollective.org">openarcollective.org</a>, with no account, no membership, and no sign-in, and that does not change.</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
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
