<?php
/**
 * Send people somewhere after they submit, instead of leaving them on the form.
 *
 * Afform stays put and shows a confirmation message in place. On the membership
 * form that message still promised the link would expire "in ten minutes",
 * which stopped being true when the confirmation link moved to seven days. On
 * the Statement of Support there was no message at all, so the page simply sat
 * there after "Saving" and gave no sign anything had happened.
 *
 * Both forms now redirect to a page that says the one thing that matters at
 * that moment: go and look in your inbox, because nothing has been recorded
 * yet. The confirmation messages are corrected as well, since they still show
 * if a redirect cannot be followed.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file sent-pages.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
if (is_readable(__DIR__ . '/openar-snapshot.php')) {
  require_once __DIR__ . '/openar-snapshot.php';
  openar_snapshot('sent-pages');
}

const OPENAR_LINK_DAYS = 7;

$pages = [
  'application-sent' => [
    'title' => 'Check your email',
    'body' => <<<'HTML'
<!-- wp:paragraph -->
<p><strong>Your application has not been sent yet.</strong> We have emailed a confirmation link to the address you gave. Open it and your application goes to the Foundation for review.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Nothing has been recorded in the Foundation's records, and nobody has seen your details. That only happens once you follow the link, which is what proves the address is yours.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">If it has not arrived</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Give it a few minutes, then look in your spam or junk folder. The message comes from The Open Accounts Receivable Collective Foundation.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>The link is good for seven days. If it lapses, or if you mistyped your address, simply <a href="/apply">apply again</a> and a fresh link is sent straight away. Applying twice causes no confusion; the newer one replaces the older.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">After you confirm</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>A person reviews the employer or affiliation you gave, which usually takes a few days. On approval you get an email with your member number and a link that connects your Discord account.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Membership is free and always will be, and you do not need it to use the Foundation's work. Questions go to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>.</p>
<!-- /wp:paragraph -->
HTML,
  ],
  'statement-sent' => [
    'title' => 'Check your email',
    'body' => <<<'HTML'
<!-- wp:paragraph -->
<p><strong>The Statement has not been signed yet.</strong> We have emailed a confirmation link to the signer's address. Open it and the signature is recorded.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Nothing has been recorded in the Foundation's records, and your organization is not listed anywhere. That only happens once the link is followed, which is what confirms the address belongs to the person who signed.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">If it has not arrived</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Give it a few minutes, then look in the spam or junk folder. The message comes from The Open Accounts Receivable Collective Foundation.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>The link is good for seven days. If it lapses, or if the address was mistyped, simply <a href="/sign">sign again</a> and a fresh link is sent straight away.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">After you confirm</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Someone at the Foundation checks the signature, chiefly that the person who signed can speak for the organization. Once confirmed, the organization appears on the public roster in alphabetical order, on the same terms as everyone else, and you get an email when it is listed.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Signing costs nothing and carries no financial commitment of any kind, now or ever. Questions go to <a href="mailto:membership@openarcollective.org">membership@openarcollective.org</a>.</p>
<!-- /wp:paragraph -->
HTML,
  ],
];

$urls = [];

foreach ($pages as $slug => $page) {
  $existing = get_page_by_path($slug);

  $data = [
    'post_title' => $page['title'],
    'post_name' => $slug,
    'post_content' => $page['body'],
    'post_status' => 'publish',
    'post_type' => 'page',
    // Kept out of menus and search; people arrive here by being sent, and a
    // page telling a stranger to check an inbox they never used is noise.
    'comment_status' => 'closed',
    'ping_status' => 'closed',
  ];

  if ($existing) {
    $data['ID'] = $existing->ID;
    $id = wp_update_post($data, TRUE);
    $verb = 'updated';
  }
  else {
    $id = wp_insert_post($data, TRUE);
    $verb = 'created';
  }

  if (is_wp_error($id)) {
    echo "ERROR on /{$slug}: " . $id->get_error_message() . "\n";
    continue;
  }

  update_post_meta((int) $id, '_openar_noindex', '1');
  $urls[$slug] = get_permalink((int) $id);
  echo "{$verb} /{$slug} -> {$urls[$slug]}\n";
}

$message = '<p>Check your inbox. We have sent a confirmation link, and nothing is recorded until you open it. '
  . 'The link is good for ' . OPENAR_LINK_DAYS . ' days.</p>';

$forms = [
  'afformMembershipApplication' => $urls['application-sent'] ?? '',
  'afformSupporterStatement' => $urls['statement-sent'] ?? '',
];

foreach ($forms as $name => $url) {
  if ($url === '') {
    echo "skipping {$name}, no destination page\n";
    continue;
  }
  civicrm_api4('Afform', 'update', [
    'where' => [['name', '=', $name]],
    'values' => [
      'redirect' => $url,
      // Shown only if the redirect cannot be followed, but it was wrong, and a
      // wrong fallback is worse than none.
      'confirmation_message' => $message,
    ],
    'checkPermissions' => FALSE,
  ]);
  echo "{$name} now redirects to {$url}\n";
}

echo "\nDone. Submitting either form now lands on a page rather than sitting still.\n";
