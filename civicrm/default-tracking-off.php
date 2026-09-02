<?php
/**
 * Default new mailings to no open or click tracking.
 *
 * The privacy notice says "we do not track our email", and until now keeping
 * that promise depended on remembering to uncheck two boxes on every compose.
 * These settings flip the composer's defaults, so the promise is the path of
 * least resistance. The boxes remain visible and checkable; checking one is
 * now a deliberate act that contradicts the published notice, not a default
 * that silently does.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file default-tracking-off.php
 */

civicrm_initialize();

foreach (['url_tracking_default', 'open_tracking_default'] as $name) {
  $was = \Civi::settings()->get($name);
  \Civi::settings()->set($name, 0);
  echo "{$name}: " . var_export($was, TRUE) . ' -> ' . var_export(\Civi::settings()->get($name), TRUE) . "\n";
}
