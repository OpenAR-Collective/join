<?php
/**
 * Install the join site's brand stylesheet into WordPress Additional CSS.
 *
 * The whole visual identity of join.openarcollective.org lived in one
 * custom-css post in the database, with no copy anywhere. Losing the machine
 * meant rebuilding it from a screenshot.
 *
 * The stylesheet itself is ../wordpress/brand.css, kept as a plain file so it
 * can be read, diffed and reviewed. This only pushes it into WordPress.
 *
 *   sudo -u www-data wp --path=/var/www/openarcollective.org \
 *     eval-file /home/rob/openar-scripts/install-brand-css.php
 *
 * If someone edits Additional CSS through the WordPress admin, mirror the
 * change back into brand.css or the next run of this reverts them.
 */

if (!function_exists('wp_update_custom_css_post')) {
  echo "ERROR: WordPress is not loaded.\n";
  return;
}

// This replaces the live stylesheet outright, so keep the current one first.
// An edit made through the WordPress admin is otherwise lost without trace.
if (function_exists('civicrm_initialize')) {
  civicrm_initialize();
  define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
  // Deployed flat alongside the other scripts, but sits in wordpress/ in the
  // repository, so both layouts are tried. A missing helper is a warning rather
  // than a fatal: failing to snapshot should not stop the stylesheet going out.
  $snapshot = '';
  foreach ([__DIR__ . '/openar-snapshot.php', __DIR__ . '/../civicrm/openar-snapshot.php'] as $candidate) {
    if (is_readable($candidate)) {
      $snapshot = $candidate;
      break;
    }
  }
  if ($snapshot !== '') {
    require_once $snapshot;
    openar_snapshot('install-brand-css');
  }
  else {
    echo "WARNING: openar-snapshot.php not found, so nothing was saved first.
";
  }
}

$css = __DIR__ . '/brand.css';
if (!is_readable($css)) {
  echo "ERROR: cannot read {$css}\n";
  return;
}

$new = (string) file_get_contents($css);

// Refusing an empty write matters more than it looks: this replaces the live
// stylesheet outright, so a truncated file would silently unstyle the site.
if (trim($new) === '') {
  echo "ERROR: brand.css is empty. Refusing to blank the live stylesheet.\n";
  return;
}

$current = (string) wp_get_custom_css();

if ($current === $new) {
  echo 'already current, ' . strlen($new) . " chars\n";
  return;
}

$result = wp_update_custom_css_post($new);

if (is_wp_error($result)) {
  echo 'ERROR: ' . $result->get_error_message() . "\n";
  return;
}

printf("installed %d chars (was %d)\n", strlen($new), strlen($current));
