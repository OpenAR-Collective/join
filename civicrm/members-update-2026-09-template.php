<?php
/**
 * The September 2026 members update, as a message template the mailing
 * composer loads.
 *
 * Goes to the members group, so the audience routing in
 * openar-mail-stream.php puts it on the Organization Announcements broadcast
 * stream. The unsubscribe links and postal address come from CiviMail's
 * standing mailing footer. Copy approved by Rob on 2026-09-02; the draft
 * mailing is the canonical copy once he edits it, and this script records
 * the starting point.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file members-update-2026-09-template.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

$title = 'OpenAR - September 2026 members update';

$text = <<<'TEXT'
{contact.first_name},

A short update on what the Foundation has been doing, and what September holds.

Brainstorm 2026. The conference runs September 9 through 12, and for the first time the Collective will be running a booth. If you are attending, come find us.

Podcasts. Last month I recorded an episode of the Receivables Podcast with Adam Parks, due out late this month or early October. Next up is a recording with John Erickson, proud member and Mission Supporter.

Our 501(c)(3). We are filing IRS Form 1023, the application for the Foundation's federal tax exemption. A determination usually takes about three to four months from filing.

First members-only event. We will hold our first members-only event on the Foundation's Discord server; the date is still being set. If you have not connected your Discord account yet, now is a good time. Create a Discord account if you need one, and if your join link has lapsed, reply to this note and I will send you a fresh one.

And now we are ready for your ideas. The platform's design is being written down decision by decision, in the open, and what it needs most is your experience: how your shop actually handles disputes, payments, recalls, and the rest of daily AR reality. The design record is deep enough that trying to take it in all at once is intimidating, so do not start there. Start with a conversation: copy the prompt below into your AI assistant of choice, and it will interview you one question at a time and turn your answers into a contribution you can submit in one paste.

I would like to contribute to the design of an open-source accounts receivable platform. Fetch this page: https://raw.githubusercontent.com/OpenAR-Collective/platform-design/main/START-HERE.md and follow the section titled The Procedure exactly as written, one question at a time, starting at Step 1. Do not summarize the page back to me, and do not skip steps. If you cannot open links, tell me and I will paste the page in instead.

Give it a try this week. The questions we gather now decide what gets built first. The prompt also lives at the end of our FAQ at https://openarcollective.org/faq/#the-platform, along with a second one built for developers.

See you in the community,

Rob Grafrath
Founder and Chair, The Open Accounts Receivable Collective Foundation
rob@openarcollective.org
(903) 436-3547
https://openarcollective.org
Schedule a meeting: https://meetings.hubspot.com/rob-grafrath


You are receiving this because you are a member of the OpenAR Collective.
TEXT;

$html = <<<'HTML'
<p>{contact.first_name},</p>

<p>A short update on what the Foundation has been doing, and what September holds.</p>

<p><strong>Brainstorm 2026.</strong> The conference runs September 9 through 12, and for the first time the Collective will be running a booth. If you are attending, come find us.</p>

<p><strong>Podcasts.</strong> Last month I recorded an episode of the Receivables Podcast with Adam Parks, due out late this month or early October. Next up is a recording with John Erickson, proud member and Mission Supporter.</p>

<p><strong>Our 501(c)(3).</strong> We are filing IRS Form 1023, the application for the Foundation's federal tax exemption. A determination usually takes about three to four months from filing.</p>

<p><strong>First members-only event.</strong> We will hold our first members-only event on the Foundation's Discord server; the date is still being set. If you have not connected your Discord account yet, now is a good time. Create a Discord account if you need one, and if your join link has lapsed, reply to this note and I will send you a fresh one.</p>

<p><strong>And now we are ready for your ideas.</strong> The platform's design is being written down decision by decision, in the open, and what it needs most is your experience: how your shop actually handles disputes, payments, recalls, and the rest of daily AR reality. The design record is deep enough that trying to take it in all at once is intimidating, so do not start there. Start with a conversation: copy the prompt below into your AI assistant of choice, and it will interview you one question at a time and turn your answers into a contribution you can submit in one paste.</p>

<p style="font-family:'Courier New',Courier,monospace;font-size:13px;line-height:1.5;background:#f6f4f0;border-left:3px solid #e8a020;padding:12px 16px;">I would like to contribute to the design of an open-source accounts receivable platform. Fetch this page: https://raw.githubusercontent.com/OpenAR-Collective/platform-design/main/START-HERE.md and follow the section titled The Procedure exactly as written, one question at a time, starting at Step 1. Do not summarize the page back to me, and do not skip steps. If you cannot open links, tell me and I will paste the page in instead.</p>

<p>Give it a try this week. The questions we gather now decide what gets built first. The prompt also lives at the end of <a href="https://openarcollective.org/faq/#the-platform">our FAQ</a>, along with a second one built for developers.</p>

<p style="margin:26px 0 14px;">See you in the community,</p>

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

<p style="margin-top:28px;font-size:12px;color:#8a8378;">You are receiving this because you are a member of the OpenAR Collective.</p>
HTML;

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'September at the OpenAR Collective',
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
