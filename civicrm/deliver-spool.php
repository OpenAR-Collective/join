<?php
/**
 * Deliver messages sitting in the spool.
 *
 * Anything captured while the backend was set to "Redirect to Database" is a
 * message that was composed correctly and sent nowhere. Deleting the spool
 * throws those away silently, which is how a new member and a new Mission
 * Supporter once went a whole morning hearing nothing.
 *
 * These are replayed verbatim rather than re-triggered, because the workflow
 * guards ("has this person already been told?") will refuse: as far as CiviCRM
 * is concerned they were told.
 *
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file deliver-spool.php        list
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file deliver-spool.php send   deliver
 */

civicrm_initialize();

require_once __DIR__ . '/openar-mailmode.php';

$rows = [];
$d = CRM_Core_DAO::executeQuery('SELECT id, recipient_email, headers, body, added_at FROM civicrm_mailing_spool ORDER BY id');
while ($d->fetch()) {
  $rows[] = ['id' => (int) $d->id, 'to' => (string) $d->recipient_email,
             'headers' => (string) $d->headers, 'body' => (string) $d->body,
             'when' => (string) $d->added_at];
}

function openar_spool_subject(string $blob): string {
  return preg_match('/^Subject:\s*(.+)$/mi', $blob, $m) ? trim($m[1]) : '(no subject)';
}

if (!$rows) {
  echo "The spool is empty. Nothing is waiting.\n";
  return;
}

if ((isset($args[0]) ? strtolower(trim((string) $args[0])) : '') !== 'send') {
  echo count($rows) . " message(s) captured and never delivered:\n\n";
  foreach ($rows as $r) {
    printf("  %s  %-36s %s\n", substr($r['when'], 0, 16), $r['to'], openar_spool_subject($r['headers']));
  }
  echo "\nDeliver them with: eval-file deliver-spool.php send\n";
  return;
}

if (!openar_mail_deliverable()) {
  echo "REFUSING: outbound mail is not configured to deliver.\n";
  echo "Run mail-live.php first, and check the SMTP server and credentials.\n";
  return;
}

$mailer = Civi::service('pear_mail');
if (stripos(get_class($mailer), 'Spool') !== FALSE) {
  echo "REFUSING: the active mailer is still the spool, so this would only re-capture them.\n";
  return;
}

/** Headers are stored joined; PEAR wants them keyed. Folded lines continue the one above. */
function openar_spool_headers(string $blob): array {
  $out = [];
  $last = NULL;
  foreach (preg_split('/\r\n|\r|\n/', $blob) as $line) {
    if ($line === '') {
      continue;
    }
    if ($last !== NULL && preg_match('/^[ \t]/', $line)) {
      $out[$last] .= ' ' . trim($line);
      continue;
    }
    $p = strpos($line, ':');
    if ($p === FALSE) {
      continue;
    }
    $name = trim(substr($line, 0, $p));
    $out[$name] = ltrim(substr($line, $p + 1));
    $last = $name;
  }
  return $out;
}

$sent = 0;
$failed = 0;
foreach ($rows as $r) {
  $result = $mailer->send($r['to'], openar_spool_headers($r['headers']), $r['body']);

  if (is_a($result, 'PEAR_Error')) {
    printf("  FAILED  %-36s %s\n", $r['to'], openar_spool_subject($r['headers']));
    printf("          %s\n", $result->getMessage());
    $failed++;
    continue;
  }

  printf("  sent    %-36s %s\n", $r['to'], openar_spool_subject($r['headers']));
  CRM_Core_DAO::executeQuery('DELETE FROM civicrm_mailing_spool WHERE id = %1', [1 => [$r['id'], 'Integer']]);
  $sent++;
}

printf("\n%d sent, %d failed. Spool now holds %d.\n", $sent, $failed,
  CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_mailing_spool'));
