<?php
/**
 * Emails for someone who applies with an address already on file.
 *
 * These go to the address that was typed and nowhere else. The application page
 * shows the same message whatever we find, because the form is public and
 * anonymous: a page that said "you are already a member" would let anyone test
 * an address and learn whether that person is a member, and their number. The
 * Foundation does not publish a list of members, and this form must not become
 * one.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file repeat-applicant-templates.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

$templates = [];

/* ---------------------------------------------------------------- member -- */

$templates[] = [
  'msg_title' => 'OpenAR - You are already a member',
  'msg_subject' => 'You are already a member of the OpenAR Collective',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

A membership application was submitted using this email address. You are already a member, so there is nothing further for you to do and no new record has been created.
{if $memberNumber}
Your member number is {$memberNumber}.
{/if}{if $discordUrl}
To join the Foundation's Discord server, or to reconnect an account you have lost access to, open this link:

{$discordUrl}
{else}
For an invitation to the Foundation's Discord server, write to membership@openarcollective.org.
{/if}
You are also on the members-only email list, and you can leave it at any time without affecting your membership.

If you did not submit that application, you can ignore this message. Your membership is unchanged.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>A membership application was submitted using this email address. You are already a member, so there is nothing further for you to do and no new record has been created.</p>
{if $memberNumber}
<p>Your member number is <strong>{$memberNumber}</strong>.</p>
{/if}{if $discordUrl}
<p>To join the Foundation's Discord server, or to reconnect an account you have lost access to, use this link:</p>

<p><a href="{$discordUrl}" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Connect my Discord account</a></p>
{else}
<p>For an invitation to the Foundation's Discord server, write to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>.</p>
{/if}
<p>You are also on the members-only email list, and you can leave it at any time without affecting your membership.</p>

<p>If you did not submit that application, you can ignore this message. Your membership is unchanged.</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
HTML,
];

/* ------------------------------------------------------- already on file -- */

// Deliberately the same wording whether the earlier application is awaiting
// review or was declined. Nothing here re-states or re-litigates a decision;
// a declined applicant is picked up by a director instead.
$templates[] = [
  'msg_title' => 'OpenAR - Your application is already with us',
  'msg_subject' => 'We already have your membership application',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

A membership application was submitted using this email address, and we already have an application on file from it. There is nothing more for you to do right now, and someone from the membership team will be in touch.

If you did not submit that application, you can ignore this message.

Questions go to membership@openarcollective.org.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>A membership application was submitted using this email address, and we already have an application on file from it. There is nothing more for you to do right now, and someone from the membership team will be in touch.</p>

<p>If you did not submit that application, you can ignore this message.</p>

<p>Questions go to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>.</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
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
