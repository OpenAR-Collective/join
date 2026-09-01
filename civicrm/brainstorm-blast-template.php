<?php
/**
 * The Brainstorm 2026 prospect blast, as a message template the mailing
 * composer loads.
 *
 * A CiviMail bulk mailing, not a transactional send: it goes to the
 * Brainstorm 2026 prospect group and rides the conference-attendee-blast
 * Postmark stream via openar-mail-stream.php. The unsubscribe links and
 * postal address come from CiviMail's standing mailing footer.
 *
 * The wording below is Rob's, verbatim, pulled from draft mailing 103 on
 * 2026-09-01 after he edited the draft directly. The draft is the canonical
 * copy; this script records it and puts the template back in step with it,
 * so loading the template can no longer undo his edits.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file brainstorm-blast-template.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

$title = 'OpenAR - Brainstorm 2026 prospect blast';

$text = <<<'TEXT'
{contact.first_name},

Next week at Brainstorm, hundreds of people who care about accounts receivable will be in the same building. That is exactly the kind of gathering the OpenAR Collective exists for, and we will have a booth there. We hope you'll stop by to hear about our mission.

The OpenAR Collective is a nonprofit bringing together accounts receivable professionals to pool talents in ways our industry has never tried. Among our ambitious goals is creating an open-source AR and collections platform. Everything we make is free, now and forever.

The conference name suits us. The platform is in its design phase right now, being planned in the open (https://github.com/OpenAR-Collective/platform-design), and the practitioners in our community are deciding what gets built first. We are at the brainstorming stage ourselves, so now is the best time to make your voice heard!

Membership is free and takes about two minutes to request at openarcollective.org/join. All applications are reviewed by a person, usually within a day or two. Join this week, and you can pick up your member ribbon at our booth and show your support right on your conference nametag.

If you would like the whole story before the conference, our brochure is at https://openarcollective.org/brochure.

See you at Brainstorm,

Rob Grafrath
Founder and Chair, The Open Accounts Receivable Collective Foundation
rob@openarcollective.org
(903) 436-3547
https://openarcollective.org
Schedule a meeting: https://meetings.hubspot.com/rob-grafrath


You are receiving this one-time note because you are registered for Brainstorm 2026.
TEXT;

$html = <<<'HTML'
<p>{contact.first_name},</p>

<p>Next week at Brainstorm, hundreds of people who care about accounts receivable will be in the same building. That is exactly the kind of gathering the OpenAR Collective exists for, and we will have a booth there. We hope you'll stop by to hear about our mission.</p>

<p>The OpenAR Collective is a nonprofit bringing together accounts receivable professionals to pool talents in ways our industry has never tried. Among our ambitious goals is creating an <strong>open-source AR and collections platform</strong>. Everything we make is free, now and forever.</p>

<p>The conference name suits us. The platform is in its design phase right now, being <a href="https://github.com/OpenAR-Collective/platform-design">planned in the open</a>, and the practitioners in our community are deciding what gets built first. We are at the brainstorming stage ourselves, so now is the best time to make your voice heard!</p>

<p>Membership is free and takes about two minutes to request at <a href="https://openarcollective.org/join">openarcollective.org/join</a>. All applications are reviewed by a person, usually within a day or two.</p>

<p><a href="https://openarcollective.org/join" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Join the Collective</a></p>

<p>Join this week, and you can pick up your member ribbon at our booth and show your support right on your conference nametag.<img alt="The OpenAR Collective Member ribbon" height="120" src="https://openarcollective.org/assets/email/member-ribbon.png" style="display:block;border:none;max-width:100%;height:auto;" width="360" />If you would like the whole story before the conference, our <a href="https://openarcollective.org/brochure">brochure</a> is on our website.</p>

<p style="margin:26px 0 14px;">See you at Brainstorm!</p>

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

<p style="margin-top:28px;font-size:12px;color:#8a8378;">You are receiving this one-time note because you are registered for Brainstorm 2026.</p>
HTML;

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'The OpenAR Collective is Brainstorming',
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
