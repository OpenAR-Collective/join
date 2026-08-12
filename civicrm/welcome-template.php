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

require_once __DIR__ . '/openar-signature.php';

use Civi\Api4\MessageTemplate;

$title = 'OpenAR - Welcome to the Collective';

$text = <<<'TEXT'
Hello {$firstName},

Your membership has been reviewed and approved. You're in!

You are member #{$memberNumber}
We won't ask you for it often, and we can always look you up by name or email, but it is uniquely yours to cherish all the same.

{if $discordUrl}
Most of what makes membership worthwhile happens on the Foundation's Discord server, where members discuss, collaborate, and create. Open this link to join. You will be asked to sign in to Discord, or to create an account if you do not have one, and you will arrive with your name and your access already set.

{$discordUrl}
{else}
For an invitation to the Foundation's Discord server, write to membership@openarcollective.org and we will send you one.
{/if}

You have also been added to the members-only email list, which you can unsubscribe from at any time by writing to membership@openarcollective.org, without affecting your membership.

There are a few things the Foundation asks of everyone, and they exist so the space stays worth being in.

* Take part under your own name.

* Never share a consumer's personal or account information in a Foundation space.

* Remain cognizant of antitrust concerns. Many members compete with one another, so Foundation spaces are never used to discuss prices, fees, rates, terms of service, allocation of markets or customers, or refusal to deal.

* Vendors are welcome as practitioners, but commercial solicitation is not.

* Treat all participants with respect. Harassment, discrimination, personal attacks, and intimidation are prohibited.

Criticism of the Foundation, its board, its software, or its published positions is never a violation of those standards and will never affect your participation. Say what you think.

The full terms are at https://openarcollective.org/policies/community-programs-and-standards/

If anything is unclear, or you would just like to introduce yourself, write to membership@openarcollective.org. All emails to that address are read and responded to by a real, live human.
TEXT;

$html = <<<'HTML'
<p>Hello {$firstName},</p>

<p>Your membership has been reviewed and approved. You're in!</p>

<p style="margin:22px 0;padding:14px 18px;background:#f6f4f0;border-left:3px solid #e8a020;">
<strong style="font-size:17px;">You are member #{$memberNumber}</strong><br />
<em style="color:#5c564c;">We won't ask you for it often, and we can always look you up by name or email, but it is uniquely yours to cherish all the same.</em>
</p>
{if $discordUrl}
<p>Most of what makes membership worthwhile happens on the Foundation's Discord server, where members discuss, collaborate, and create. Use the button below to join. You will be asked to sign in to Discord, or to create an account if you do not have one, and you will arrive with your name and your access already set.</p>

<p><a href="{$discordUrl}" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Join the Discord server</a></p>
{else}
<p>For an invitation to the Foundation's Discord server, write to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a> and we will send you one.</p>
{/if}
<p>You have also been added to the members-only email list, which you can unsubscribe from at any time by writing to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>, without affecting your membership.</p>

<p>There are a few things the Foundation asks of everyone, and they exist so the space stays worth being in.</p>

<ul style="padding-left:20px;">
<li style="margin-bottom:10px;">Take part under your own name.</li>
<li style="margin-bottom:10px;">Never share a consumer's personal or account information in a Foundation space.</li>
<li style="margin-bottom:10px;">Remain cognizant of antitrust concerns. Many members compete with one another, so Foundation spaces are never used to discuss prices, fees, rates, terms of service, allocation of markets or customers, or refusal to deal.</li>
<li style="margin-bottom:10px;">Vendors are welcome as practitioners, but commercial solicitation is not.</li>
<li>Treat all participants with respect. Harassment, discrimination, personal attacks, and intimidation are prohibited.</li>
</ul>

<p>Criticism of the Foundation, its board, its software, or its published positions is never a violation of those standards and will never affect your participation. Say what you think.</p>

<p>The full terms are in the <a href="https://openarcollective.org/policies/community-programs-and-standards/">Community Programs and Standards Policy</a>.</p>

<p>If anything is unclear, or you would just like to introduce yourself, write to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>. All emails to that address are read and responded to by a real, live human.</p>
HTML;

$text .= openar_signature_text();
$html .= openar_signature_html();

$vals = [
  'msg_title' => $title,
  // Guarded, because "Welcome to the OpenAR Collective, !" is a worse greeting
  // than no name at all, and a missing first name is one bad import away.
  'msg_subject' => 'Welcome to the OpenAR Collective{if $firstName}, {$firstName}{/if}!',
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
