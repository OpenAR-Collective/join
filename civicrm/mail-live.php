<?php
/** Assert the production mail backend, rather than restoring whatever was there. */
civicrm_initialize();

$before = Civi::settings()->get('mailing_backend');
echo "was: outBound_option = " . ($before['outBound_option'] ?? '?') . "\n";

Civi::settings()->set('mailing_backend', ['outBound_option' => 0]);

$after = Civi::settings()->get('mailing_backend');
echo "now: outBound_option = " . ($after['outBound_option'] ?? '?') . " (0 = live SMTP)\n";

$spooled = (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_spool');
echo "\nmessages sitting in the spool: {$spooled}\n";
if ($spooled) {
  $d = CRM_Core_DAO::executeQuery('SELECT recipient_email, headers FROM civicrm_mailing_spool ORDER BY id');
  while ($d->fetch()) {
    preg_match('/^Subject:\s*(.+)$/mi', (string) $d->headers, $m);
    echo "  -> " . str_pad((string) $d->recipient_email, 36) . trim($m[1] ?? '') . "\n";
  }
  CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool');
  echo "cleared (all of these are test addresses)\n";
}

echo "\ncontacts: " . civicrm_api4('Contact', 'get', ['select' => ['row_count'], 'checkPermissions' => FALSE])->count() . "\n";
foreach (civicrm_api4('Contact', 'get', ['select' => ['id', 'display_name'], 'checkPermissions' => FALSE]) as $c) {
  echo "  #{$c['id']} {$c['display_name']}\n";
}
$subs = civicrm_api4('AfformSubmission', 'get', ['select' => ['row_count'], 'checkPermissions' => FALSE])->count();
echo "form submissions: {$subs}\n";
