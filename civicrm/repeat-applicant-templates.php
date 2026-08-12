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

require_once __DIR__ . '/openar-signature.php';

/* ---------------------------------------------------------------- member -- */

$templates[] = [
  'msg_title' => 'OpenAR - You are already a member',
  'msg_subject' => 'Good news, you are already a member!',
  'closing' => 'Good to have you with us',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

Thank you for applying, and for the enthusiasm. You are already a member, so there is nothing further for you to do. Nothing on your record has changed and no second one has been created.
{if $memberNumber}

Your member number is {$memberNumber}, in case that is what you were looking for.
{/if}{if $discordUrl}

If you came back because you cannot get into the Foundation's Discord server, or you have lost access to the account you joined with, this link sorts it out:

{$discordUrl}
{else}

If you came back because you cannot get into the Foundation's Discord server, write to membership@openarcollective.org and we will send you a fresh invitation.
{/if}

You are also on the members-only email list, and you can leave it at any time without affecting your membership.

If you did not submit that application, you can ignore this message. Your membership is unchanged.
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>Thank you for applying, and for the enthusiasm. You are already a member, so there is nothing further for you to do. Nothing on your record has changed and no second one has been created.</p>
{if $memberNumber}
<p>Your member number is <strong>{$memberNumber}</strong>, in case that is what you were looking for.</p>
{/if}{if $discordUrl}
<p>If you came back because you cannot get into the Foundation's Discord server, or you have lost access to the account you joined with, this button sorts it out:</p>

<p><a href="{$discordUrl}" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Connect my Discord account</a></p>
{else}
<p>If you came back because you cannot get into the Foundation's Discord server, write to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a> and we will send you a fresh invitation.</p>
{/if}
<p>You are also on the members-only email list, and you can leave it at any time without affecting your membership.</p>

<p>If you did not submit that application, you can ignore this message. Your membership is unchanged.</p>
HTML,
];

/* ------------------------------------------------------- already on file -- */

// Deliberately the same wording whether the earlier application is awaiting
// review or was declined. Nothing here re-states or re-litigates a decision;
// a declined applicant is picked up by a director instead.
$templates[] = [
  'msg_title' => 'OpenAR - Your application is already with us',
  'msg_subject' => 'Your application is already with us',
  'closing' => 'Thank you for your patience',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

Thank you for applying. We already have an application from this address, so there is nothing more for you to do and nothing has been lost. A person is looking at it, and that usually takes a few days rather than weeks.

If you have thought of something you would like us to know while it is being read, reply to this message and it will reach the same people.

If you did not submit that application, you can ignore this message.
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>Thank you for applying. We already have an application from this address, so there is nothing more for you to do and nothing has been lost. A person is looking at it, and that usually takes a few days rather than weeks.</p>

<p>If you have thought of something you would like us to know while it is being read, reply to this message and it will reach the same people.</p>

<p>If you did not submit that application, you can ignore this message.</p>
HTML,
];

foreach ($templates as $t) {
  // The sign-off is appended rather than written into each template, so the
  // closing line is the only part that varies with the moment.
  $t['msg_text'] .= openar_signature_text($t['closing']);
  $t['msg_html'] .= openar_signature_html($t['closing']);
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
