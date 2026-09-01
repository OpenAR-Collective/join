<?php
/**
 * The Brainstorm 2026 prospect blast, as a message template the mailing
 * composer loads.
 *
 * A CiviMail bulk mailing, not a transactional send: it goes to the
 * Brainstorm 2026 prospect group, rides the conference-attendee-blast
 * Postmark stream via openar-mail-stream.php, and carries the unsubscribe
 * and postal-address tokens CiviMail requires of bulk mail. Copy approved by
 * Rob on 2026-08-31.
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

require_once __DIR__ . '/openar-signature.php';

use Civi\Api4\MessageTemplate;

$title = 'OpenAR - Brainstorm 2026 prospect blast';

$text = <<<'TEXT'
Hello {contact.first_name},

Next week at Brainstorm, hundreds of people who care about accounts receivable will be in the same building. That is exactly the kind of gathering the OpenAR Collective exists for, and we will have a booth there. I hope you will stop by.

The OpenAR Collective is a nonprofit bringing together accounts receivable professionals to create a community that will collaborate to create and share in ways our industry has never tried. Among our ambitious goals is the creation of an open-source AR and collections platform. Everything we make is free, with no dues, no sponsors, and no paid tiers, because nothing we make is for sale.

The conference name suits us. The platform is in its design phase right now, being planned in the open, and the practitioners in our community are deciding what gets built first. We are at the brainstorming stage ourselves, and the best time to have a voice is now.

Membership is free and takes about two minutes to request at https://openarcollective.org/join. Applications are reviewed by a person, usually within a few days. Join this week, and you can pick up your member ribbon at our booth and show your support right on your conference nametag.

If you would like the whole story before then, our brochure is at https://openarcollective.org/brochure.
TEXT;

$html = <<<'HTML'
<p>Hello {contact.first_name},</p>

<p>Next week at Brainstorm, hundreds of people who care about accounts receivable will be in the same building. That is exactly the kind of gathering the OpenAR Collective exists for, and we will have a booth there. I hope you will stop by.</p>

<p>The OpenAR Collective is a nonprofit bringing together accounts receivable professionals to create a community that will collaborate to create and share in ways our industry has never tried. Among our ambitious goals is the creation of an open-source AR and collections platform. Everything we make is free, with no dues, no sponsors, and no paid tiers, because nothing we make is for sale.</p>

<p>The conference name suits us. The platform is in its design phase right now, being planned in the open, and the practitioners in our community are deciding what gets built first. We are at the brainstorming stage ourselves, and the best time to have a voice is now.</p>

<p>Membership is free and takes about two minutes to request at <a href="https://openarcollective.org/join">openarcollective.org/join</a>. Applications are reviewed by a person, usually within a few days. Join this week, and you can pick up your member ribbon at our booth and show your support right on your conference nametag.</p>

<p style="margin:18px 0;"><img src="https://openarcollective.org/assets/email/member-ribbon.png" alt="The OpenAR Collective Member ribbon" width="360" height="120" style="display:block;border:none;max-width:100%;height:auto;" /></p>

<p><a href="https://openarcollective.org/join" style="display:inline-block;padding:12px 22px;background:#e8a020;color:#161410;font-family:Arial,Helvetica,sans-serif;font-weight:600;text-decoration:none;border-radius:3px;">Join the Collective</a></p>

<p>If you would like the whole story before then, our <a href="https://openarcollective.org/brochure">brochure</a> is on our website.</p>
HTML;

// Rob's standard signature, addressed as rob@ rather than membership@:
// this one is written in the first person and replies belong with him.
$text .= str_replace('membership@openarcollective.org', 'rob@openarcollective.org',
  openar_signature_text('See you at Brainstorm'));
$html .= str_replace('membership@openarcollective.org', 'rob@openarcollective.org',
  openar_signature_html('See you at Brainstorm'));

// Only the line unique to this blast. The unsubscribe links and the postal
// address come from CiviMail's standing mailing footer, which the composer
// attaches to every bulk mailing; repeating them here stacked three footers
// in a row, counting the last-resort link Postmark appends on broadcast
// streams. CiviMail's required-token check passes because the attached
// footer carries the tokens.
$text .= <<<'TEXT'


You are receiving this one-time note because you are registered for Brainstorm 2026.
TEXT;

$html .= <<<'HTML'

<p style="margin-top:28px;font-size:12px;color:#8a8378;">
You are receiving this one-time note because you are registered for Brainstorm 2026.
</p>
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
