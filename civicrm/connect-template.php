<?php
/**
 * The "here is your Discord link again" email.
 *
 * Distinct from the welcome, which carries the link the first time. This one is
 * for somebody who has already had it and cannot find it, so it does not
 * re-announce their membership or explain what Discord is at length. It says
 * where the link is and gets out of the way.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file connect-template.php
 */

civicrm_initialize();

use Civi\Api4\MessageTemplate;

$title = 'Automated Membership - Your Discord link, again';

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'Your Discord link for the OpenAR Collective',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

Here is your link to the Foundation's Discord server:

{$discordUrl}

You are member number {$memberNumber}. Signing in through Discord adds you to the server with your name and access already set. If you do not have a Discord account, you can create one along the way.

The link is yours alone and works for {$expiryDays} days. Please do not forward it. If it lapses, just ask and another will be sent.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>Here is your link to the Foundation's Discord server:</p>

<p><a href="{$discordUrl}" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Join the Discord server</a></p>

<p>You are member number <strong>{$memberNumber}</strong>. Signing in through Discord adds you to the server with your name and access already set. If you do not have a Discord account, you can create one along the way.</p>

<p>The link is yours alone and works for {$expiryDays} days. Please do not forward it. If it lapses, just ask and another will be sent.</p>

<p>If the button does not work, copy this address into your browser:</p>

<p style="font-family:monospace;font-size:13px;word-break:break-all;">{$discordUrl}</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
HTML,
  'is_active' => TRUE,
  'is_reserved' => FALSE,
];

// The earlier template was written on the mistaken belief that these members
// never got a link. They did, so it is renamed and rewritten in place rather
// than left behind as a near-duplicate somebody might pick by accident.
$existing = MessageTemplate::get(FALSE)
  ->addClause('OR', ['msg_title', '=', $title], ['msg_title', '=', 'OpenAR - Connect your Discord account'])
  ->execute()->first();

if ($existing) {
  MessageTemplate::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($vals)->execute();
  echo "updated template id {$existing['id']} ({$title})\n";
}
else {
  $id = MessageTemplate::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "created template id {$id} ({$title})\n";
}
