<?php
/**
 * The Brainstorm 2026 note to members who are on the attendee list, as a
 * message template the mailing composer loads.
 *
 * Goes to the Brainstorm 2026 Members group: current members whose email
 * appears on the conference attendee list. That group is not in the
 * openar-mail-stream.php map, so this mailing rides the Organization
 * Announcements broadcast stream, which is right for member-facing mail.
 * The unsubscribe links and postal address come from CiviMail's standing
 * mailing footer. The draft mailing is the canonical copy once Rob edits it;
 * this script records the starting point.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file brainstorm-members-template.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

$title = 'Member News - Brainstorm 2026 members note';

$text = <<<'TEXT'
{contact.first_name},

I just sent an introduction to the Collective to the Brainstorm attendee list, and you are one of the few people on it who is already a member. Thank you for being early.

The Foundation has a booth this year, and your member ribbon is waiting at it. It wears right on your conference nametag, and only verified members get one.

If you get the chance, bring people by. A member standing at the booth says more than I can say alone. The platform is in its design phase, being planned in the open at https://github.com/OpenAR-Collective/platform-design, and Brainstorm is exactly the room it should be shaped by.

See you there,

Rob Grafrath
Founder and Chair, The Open Accounts Receivable Collective Foundation
rob@openarcollective.org
(903) 436-3547
https://openarcollective.org
Schedule a meeting: https://meetings.hubspot.com/rob-grafrath


You are receiving this note because you are a member of the Collective and registered for Brainstorm 2026.
TEXT;

$html = <<<'HTML'
<p>{contact.first_name},</p>

<p>I just sent an introduction to the Collective to the Brainstorm attendee list, and you are one of the few people on it who is already a member. Thank you for being early.</p>

<p>The Foundation has a booth this year, and your member ribbon is waiting at it. It wears right on your conference nametag, and only verified members get one.</p>

<p><img alt="The OpenAR Collective Member ribbon" height="120" src="https://openarcollective.org/assets/email/member-ribbon.png" style="display:block;border:none;max-width:100%;height:auto;" width="360" /></p>

<p>If you get the chance, bring people by. A member standing at the booth says more than I can say alone. The platform is in its design phase, being <a href="https://github.com/OpenAR-Collective/platform-design">planned in the open</a>, and Brainstorm is exactly the room it should be shaped by.</p>

<p style="margin:26px 0 14px;">See you there,</p>

<table border="0" cellpadding="0" cellspacing="0" style="font-family:Arial,sans-serif;line-height:1.2;color:#1A1714;">
	<tbody>
		<tr>
			<td style="text-align:center;padding-right:10px;vertical-align:top;padding-top:4px;"><img alt="OpenAR Collective" border="0" height="70" src="https://openarcollective.org/assets/email/openar-icon.png" style="display:block;margin:0 auto 4px auto;" width="70" />
			<div style="font-size:14px;font-weight:bold;line-height:1.1;white-space:nowrap;"><span style="color:#2E2B28;">Open</span><span style="color:#B87818;">AR</span></div>

			<div style="font-size:14px;font-weight:bold;color:#2E2B28;white-space:nowrap;">Collective</div>
			</td>
			<td style="border-left:2px solid #B87818;padding:0;vertical-align:middle;">&nbsp;</td>
			<td style="padding-left:10px;vertical-align:middle;">
			<div style="font-size:15px;font-weight:bold;color:#2E2B28;">ROB GRAFRATH</div>

			<div style="font-size:13px;color:#B87818;font-weight:bold;">Founder and Chair</div>

			<div style="margin-top:3px;"><a href="mailto:rob@openarcollective.org" style="text-decoration:none;color:#1A1714;font-size:13px;">rob@openarcollective.org</a></div>

			<div style="margin-top:3px;font-size:13px;color:#1A1714;">(903) 436-3547</div>

			<div style="margin-top:3px;"><a href="https://www.linkedin.com/in/grafrath/" style="text-decoration:none;border:none;display:inline-block;padding-right:4px;vertical-align:middle;"><img alt="LinkedIn" border="0" src="https://openarcollective.org/assets/email/linkedin.png" style="display:inline-block;vertical-align:middle;border:none;" width="21" /></a><a href="https://openarcollective.org" style="text-decoration:none;border:none;display:inline-block;padding-right:4px;vertical-align:middle;"><img alt="Website" border="0" src="https://openarcollective.org/assets/email/website.png" style="display:inline-block;vertical-align:middle;border:none;" width="21" /></a><a href="https://discord.gg/5Z7TEQAek3" style="text-decoration:none;border:none;display:inline-block;padding-right:4px;vertical-align:middle;"><img alt="Discord" border="0" src="https://openarcollective.org/assets/email/discord.jpg" style="display:inline-block;vertical-align:middle;border:none;" width="21" /></a></div>

			<div style="margin-top:3px;"><a href="https://meetings.hubspot.com/rob-grafrath" style="text-decoration:none;color:#B87818;font-size:13px;">Schedule a Meeting</a></div>
			</td>
		</tr>
	</tbody>
</table>

<p style="margin-top:28px;font-size:12px;color:#8a8378;">You are receiving this note because you are a member of the Collective and registered for Brainstorm 2026.</p>
HTML;

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'Your member ribbon is waiting at Brainstorm',
  'msg_text' => $text,
  'msg_html' => $html,
  'is_active' => TRUE,
  'is_reserved' => FALSE,
];

$existing = MessageTemplate::get(FALSE)->addWhere('msg_title', '=', $title)->execute()->first();
if ($existing) {
  MessageTemplate::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($vals)->execute();
  echo "updated template id {$existing['id']} ({$title})\n";
}
else {
  $id = MessageTemplate::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "created template id {$id} ({$title})\n";
}
