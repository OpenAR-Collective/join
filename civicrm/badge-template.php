<?php
/**
 * The "here is your member badge" email, sent from the admin screen.
 *
 * Distinct from the welcome, which carries the badge the first time. This one
 * is for somebody who has already had it and wants a fresh copy, so it does
 * not re-announce their membership. It hands over the badge, says what may be
 * done with it, and gets out of the way.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file badge-template.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

$title = 'OpenAR - Your member badge';

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'Your OpenAR Collective member badge',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

Here is your member badge, attached, stamped with your number: you are member #{$memberNumber}.

You are welcome to display it in a signature, a profile, or a slide to show your membership. It signifies membership, not any certification or endorsement by the Foundation.

If you need anything else, write to membership@openarcollective.org. All emails to that address are read and responded to by a real, live human.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>Here is your member badge, attached, stamped with your number: you are member <strong>#{$memberNumber}</strong>.</p>

<p>You are welcome to display it in a signature, a profile, or a slide to show your membership. It signifies membership, not any certification or endorsement by the Foundation.</p>

<p>If you need anything else, write to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>. All emails to that address are read and responded to by a real, live human.</p>

<p>The Open Accounts Receivable Collective Foundation<br />
<a href="https://openarcollective.org">openarcollective.org</a></p>
HTML,
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
