<?php
/**
 * Put outbound mail back to live SMTP, without touching anything else.
 *
 * `mailing_backend` is one array holding the delivery mode AND the SMTP server,
 * port, auth flag and credentials. Writing ['outBound_option' => 0] over it
 * therefore does not "set the mode": it replaces the whole array and discards
 * the Postmark host and credentials with it, leaving CiviCRM told to use SMTP
 * with no SMTP to use. That happened, and outbound mail was dead until the
 * settings were recovered from a database backup. Every write here merges.
 *
 * Run after any test session that captured mail:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file mail-live.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot('mail-live');

const OUTBOUND_SMTP = 0;

$before = (array) Civi::settings()->get('mailing_backend');
echo 'was: outBound_option = ' . ($before['outBound_option'] ?? '?') . "\n";

// Merge, never replace.
$after = ['outBound_option' => OUTBOUND_SMTP] + $before;
$after['outBound_option'] = OUTBOUND_SMTP;
Civi::settings()->set('mailing_backend', $after);

$now = (array) Civi::settings()->get('mailing_backend');
echo 'now: outBound_option = ' . ($now['outBound_option'] ?? '?') . " (0 = live SMTP)\n";

// Being set to SMTP with no server configured is the failure this file exists
// to prevent, so it is checked rather than assumed.
$problems = [];
if (empty($now['smtpServer'])) {
  $problems[] = 'smtpServer is empty';
}
if (!empty($now['smtpAuth']) && empty($now['smtpPassword'])) {
  $problems[] = 'smtpAuth is on but there is no password';
}

if ($problems) {
  echo "\nWARNING: outbound mail will fail.\n";
  foreach ($problems as $p) {
    echo "  {$p}\n";
  }
  echo "  Fix under Administer > System Settings > Outbound Email.\n";
}
else {
  echo 'server: ' . $now['smtpServer'] . ':' . ($now['smtpPort'] ?? '?')
    . ', credentials ' . (!empty($now['smtpPassword']) ? 'present' : 'absent') . "\n";
}

$spooled = (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_spool');
echo "\nmessages sitting in the spool: {$spooled}\n";
if ($spooled) {
  $dao = CRM_Core_DAO::executeQuery('SELECT recipient_email, headers FROM civicrm_mailing_spool ORDER BY id');
  while ($dao->fetch()) {
    preg_match('/^Subject:\s*(.+)$/mi', (string) $dao->headers, $m);
    echo '  -> ' . str_pad((string) $dao->recipient_email, 36) . trim($m[1] ?? '') . "\n";
  }
  echo "\nThese were composed correctly and delivered nowhere. They are NOT deleted\n";
  echo "here: throwing them away silently is how a new member and a new Mission\n";
  echo "Supporter once went a morning hearing nothing. Send them with:\n";
  echo "  eval-file deliver-spool.php send\n";
}

echo "\ncontacts: " . civicrm_api4('Contact', 'get', ['select' => ['row_count'], 'checkPermissions' => FALSE])->count() . "\n";
foreach (civicrm_api4('Contact', 'get', ['select' => ['id', 'display_name'], 'checkPermissions' => FALSE]) as $c) {
  echo "  #{$c['id']} {$c['display_name']}\n";
}
echo 'form submissions: ' . civicrm_api4('AfformSubmission', 'get', ['select' => ['row_count'], 'checkPermissions' => FALSE])->count() . "\n";
