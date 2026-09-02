<?php
/**
 * Fill in the confirmation date and Terms version on applicants who confirmed
 * before either was being recorded.
 *
 * Both custom fields existed from the start and nothing ever wrote them, so
 * every applicant admitted so far has a blank "Email confirmed" and a blank
 * "Terms version signed". The plugin records both from now on; this is for the
 * records made before it did.
 *
 * The confirmation date is taken from the contact's created_date. That is not a
 * guess. Under manual processing an application is held out of the contact
 * records entirely until the confirmation link is followed, so the moment the
 * contact was created is the moment the address was confirmed.
 *
 * The Terms version is stamped as the current one, which is sound only because
 * the Terms have not changed since these applications were made. It will not be
 * sound the next time, so this script refuses to touch anything created before
 * the cutoff below rather than quietly asserting something it cannot know.
 *
 * Idempotent, and it never overwrites a value that is already there. Run as the
 * web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file backfill-confirmation.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
if (is_readable(__DIR__ . '/openar-snapshot.php')) {
  require_once __DIR__ . '/openar-snapshot.php';
  openar_snapshot('backfill-confirmation');
}

// The Terms have been unchanged since the membership form went up. Anything
// older than this predates what this script can honestly claim.
const OPENAR_TERMS_UNCHANGED_SINCE = '2026-08-01 00:00:00';

$version = defined('OPENAR_TERMS_VERSION') ? OPENAR_TERMS_VERSION : '';
if ($version === '') {
  echo "ERROR: the onboarding plugin is not loaded, so the Terms version is unknown.\n";
  return;
}

echo "Terms version to stamp: {$version}\n";
echo "Refusing anything created before " . OPENAR_TERMS_UNCHANGED_SINCE . "\n\n";

$contacts = civicrm_api4('Contact', 'get', [
  'select' => ['id', 'display_name', 'created_date', 'source',
               'Membership.terms_version', 'Membership.email_confirmed_date',
               'Membership.member_number'],
  'where' => [
    ['contact_type', '=', 'Individual'],
    ['is_deleted', '=', FALSE],
    ['OR', [
      ['Membership.terms_version', 'IS EMPTY'],
      ['Membership.email_confirmed_date', 'IS EMPTY'],
    ]],
    // Only people who came through the form. A director typed in by hand never
    // confirmed anything and never agreed to the Terms on a form.
    ['Membership.member_number', 'IS NOT EMPTY'],
  ],
  'orderBy' => ['id' => 'ASC'],
  'checkPermissions' => FALSE,
]);

if (!count($contacts)) {
  echo "Nothing to fill in. Every applicant already carries both.\n";
  return;
}

$filled = 0;
$skipped = 0;

foreach ($contacts as $c) {
  $created = (string) ($c['created_date'] ?? '');
  $label = sprintf("#%-4s %-24s", $c['id'], $c['display_name']);

  if ($created === '' || $created < OPENAR_TERMS_UNCHANGED_SINCE) {
    echo "{$label} SKIPPED, created {$created}, too old to stamp a Terms version\n";
    $skipped++;
    continue;
  }

  $values = [];
  if (empty($c['Membership.terms_version'])) {
    $values['Membership.terms_version'] = $version;
  }
  if (empty($c['Membership.email_confirmed_date'])) {
    $values['Membership.email_confirmed_date'] = $created;
  }
  if (!$values) {
    continue;
  }

  civicrm_api4('Contact', 'update', [
    'where' => [['id', '=', $c['id']]],
    'values' => $values,
    'checkPermissions' => FALSE,
  ]);

  echo "{$label} " . implode(', ', array_map(
    fn($k, $v) => substr($k, strlen('Membership.')) . " = {$v}",
    array_keys($values), $values)) . "\n";
  $filled++;
}

echo "\n{$filled} filled in";
echo $skipped ? ", {$skipped} left alone as too old to be sure about.\n" : ".\n";
