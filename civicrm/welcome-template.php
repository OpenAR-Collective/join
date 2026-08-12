<?php
/**
 * The welcome email, sent when someone is admitted.
 *
 * The Discord section only appears once OPENAR_DISCORD_CONNECT_URL is set in the
 * mu-plugin. Until the Discord application exists the email points at
 * membership@ instead, rather than carrying a link that goes nowhere.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file welcome-template.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

$title = 'OpenAR - Welcome to the Collective';

$text = <<<'TEXT'
Hello {$firstName},

Your membership in The Open Accounts Receivable Collective Foundation has been approved. Welcome.

Your member number is {$memberNumber}.
{if $discordUrl}
The Foundation's Discord server is where members talk to each other. Open this link to join it. You will be asked to sign in to Discord, or to create an account if you do not have one, and you will be added to the server with your name and access already set.

{$discordUrl}
{else}
For an invitation to the Foundation's Discord server, write to membership@openarcollective.org.
{/if}
You are now on the members-only email list. You can leave it at any time without affecting your membership.

A reminder of what membership asks of you. Take part under your own name. Never share a consumer's personal or account information in a Foundation space. Many members compete with one another, so Foundation spaces are never used to discuss prices, fees, rates, terms of service, allocation of markets or customers, or refusal to deal. Vendors are welcome as practitioners, but commercial solicitation is not.

Criticism of the Foundation, its board, its software, or its published positions is never a violation of those standards and will never affect your participation.

The full terms are at https://openarcollective.org/policies/community-programs-and-standards/

Questions go to membership@openarcollective.org.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT;

$html = <<<'HTML'
<p>Hello {$firstName},</p>

<p>Your membership in The Open Accounts Receivable Collective Foundation has been approved. Welcome.</p>

<p>Your member number is <strong>{$memberNumber}</strong>.</p>
{if $discordUrl}
<p>The Foundation's Discord server is where members talk to each other. Use the button below to join it. You will be asked to sign in to Discord, or to create an account if you do not have one, and you will be added to the server with your name and access already set.</p>

<p><a href="{$discordUrl}" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Join the Discord server</a></p>
{else}
<p>For an invitation to the Foundation's Discord server, write to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>.</p>
{/if}
<p>You are now on the members-only email list. You can leave it at any time without affecting your membership.</p>

<p>A reminder of what membership asks of you. Take part under your own name. Never share a consumer's personal or account information in a Foundation space. Many members compete with one another, so Foundation spaces are never used to discuss prices, fees, rates, terms of service, allocation of markets or customers, or refusal to deal. Vendors are welcome as practitioners, but commercial solicitation is not.</p>

<p>Criticism of the Foundation, its board, its software, or its published positions is never a violation of those standards and will never affect your participation.</p>

<p>The full terms are in the <a href="https://openarcollective.org/policies/community-programs-and-standards/">Community Programs and Standards Policy</a>.</p>

<p>Questions go to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>.</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
HTML;

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'Welcome to the OpenAR Collective, member {$memberNumber}',
  'msg_text' => $text,
  'msg_html' => $html,
  'is_active' => TRUE,
  'is_reserved' => FALSE,
];

$existing = MessageTemplate::get(FALSE)->addWhere('msg_title', '=', $title)->execute()->first();
if ($existing) {
  MessageTemplate::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($vals)->execute();
  echo "updated template id {$existing['id']}\n";
}
else {
  $id = MessageTemplate::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "created template id {$id}\n";
}
