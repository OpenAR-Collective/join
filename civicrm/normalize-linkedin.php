<?php
/**
 * Put a scheme on every stored LinkedIn URL.
 *
 * The plugin normalizes what applicants type, but only from the point it
 * started doing so. Anything entered by hand before that, or typed into
 * CiviCRM directly, can still be sitting there as "linkedin.com/in/someone".
 *
 * That is fine as text and broken as a link: a browser resolves a schemeless
 * href against the current page, so it would send a reviewer to
 * join.openarcollective.org/linkedin.com/in/someone. The member list shows
 * these as links, so they have to be real URLs.
 *
 * Uses the plugin's own normalizer, so this cannot disagree with what the form
 * does to new applications.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file normalize-linkedin.php
 */

civicrm_initialize();

if (!function_exists('openar_normalize_linkedin_on')) {
  echo "ERROR: the onboarding plugin is not loaded, so there is nothing to normalize with.\n";
  return;
}

$fixed = 0;
$already = 0;

foreach (civicrm_api4('Contact', 'get', [
  'select' => ['id', 'display_name', 'Membership.linkedin_url'],
  'where' => [['Membership.linkedin_url', 'IS NOT EMPTY'], ['is_deleted', '=', FALSE]],
  'orderBy' => ['id' => 'ASC'],
  'checkPermissions' => FALSE,
]) as $c) {
  $before = (string) $c['Membership.linkedin_url'];

  openar_normalize_linkedin_on((int) $c['id']);

  $after = (string) (civicrm_api4('Contact', 'get', [
    'select' => ['Membership.linkedin_url'],
    'where' => [['id', '=', $c['id']]],
    'checkPermissions' => FALSE,
  ])->first()['Membership.linkedin_url'] ?? '');

  if ($before === $after) {
    $already++;
    continue;
  }
  printf("  #%-4s %-22s %s\n              -> %s\n", $c['id'], $c['display_name'], $before, $after);
  $fixed++;
}

printf("\n%d rewritten, %d already correct.\n", $fixed, $already);
