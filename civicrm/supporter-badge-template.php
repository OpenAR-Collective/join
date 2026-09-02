<?php
/**
 * The "here is your Mission Supporter badge" email, sent from the admin screen.
 *
 * Distinct from the listing email, which carries the badge when the
 * organization is first published. This one is for a signer who has already
 * had it and wants a fresh copy, including a copy freshly drawn with the
 * organization's name in the hexagon. It hands over the badge, says what may
 * be done with it, and gets out of the way.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file supporter-badge-template.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it. The guards below only
// check that this file looks complete; they cannot know the live copy has
// something this file lacks, which is the case that has actually bitten.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

use Civi\Api4\MessageTemplate;

$title = 'Automated Supporter - Your Mission Supporter badge';

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'Your OpenAR Collective Mission Supporter badge',
  'msg_text' => <<<'TEXT'
Hello {$firstName},

Here is the Mission Supporter badge for {$organizationName}, attached.

You are welcome to display it on your organization's website or in its materials alongside your listing. It signifies your organization's statement of support, and it may not be presented as certification, approval, or endorsement by the Foundation.

If you need anything else, write to membership@openarcollective.org. All emails to that address are read and responded to by a real, live human.

The Open Accounts Receivable Collective Foundation
openarcollective.org
TEXT,
  'msg_html' => <<<'HTML'
<p>Hello {$firstName},</p>

<p>Here is the Mission Supporter badge for <strong>{$organizationName}</strong>, attached.</p>

<p>You are welcome to display it on your organization's website or in its materials alongside your listing. It signifies your organization's statement of support, and it may not be presented as certification, approval, or endorsement by the Foundation.</p>

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
