<?php
/**
 * The install runs in multilingual mode (localized tables such as
 * civicrm_group_en_US) but languageLimit was never populated. Locale resolution
 * does array_keys() on it, so any contact-scoped templated email threw a
 * TypeError. Populate it with the one language actually in use.
 */
civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot('fix-lang');

echo "before: ", var_export(Civi::settings()->get('languageLimit'), TRUE), "\n";
Civi::settings()->set('languageLimit', ['en_US' => 1]);
echo "after:  ", var_export(Civi::settings()->get('languageLimit'), TRUE), "\n";

// Prove locale resolution no longer throws.
try {
  $loc = \Civi\Core\Locale::negotiate('en_US');
  echo "locale negotiate: nominal={$loc->nominal} ts={$loc->ts} db=", var_export($loc->db, TRUE), "\n";
} catch (\Throwable $e) {
  echo "STILL FAILING: ", $e->getMessage(), "\n";
}
