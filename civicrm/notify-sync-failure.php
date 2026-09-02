<?php
/**
 * Mail a failed roster sync to bots@, through CiviCRM's configured mailer.
 *
 * The server has no configured sendmail, but CiviCRM already talks to Postmark
 * and that path is proven, so the notification rides on it rather than on
 * anything new. A sync that fails silently would freeze the public roster with
 * nobody the wiser, which is the failure mode worth spending an email on.
 *
 *   sudo -u www-data wp --path=/var/www/openarcollective.org \
 *     eval-file notify-sync-failure.php /home/rob/openar-roster/last-run.log
 */

civicrm_initialize();

const NOTIFY = 'bots@openarcollective.org';

$logPath = $args[0] ?? '';
$log = ($logPath !== '' && is_readable($logPath))
  ? (string) file_get_contents($logPath)
  : '(no log available at ' . $logPath . ')';

// Keep the mail small; the tail is where the failure is.
if (strlen($log) > 8000) {
  $log = "... earlier output trimmed ...\n" . substr($log, -8000);
}

$host = gethostname() ?: 'unknown host';
$when = gmdate('Y-m-d H:i:s') . ' UTC';

$text = <<<TEXT
The Mission Supporter roster sync failed on {$host} at {$when}.

The public roster on openarcollective.org is now frozen at whatever it last
published. Approvals and withdrawals made in CiviCRM since then have not
reached the website. Nothing is lost: the next successful run picks them all up.

Run it by hand to see the failure live:

  /home/rob/openar-roster/sync-roster.sh; cat /home/rob/openar-roster/last-run.log

Output from the failed run:

{$log}
TEXT;

$html = '<p>The Mission Supporter roster sync failed on <strong>' . htmlspecialchars($host)
  . '</strong> at ' . htmlspecialchars($when) . '.</p>'
  . '<p>The public roster on openarcollective.org is now frozen at whatever it last published. '
  . 'Approvals and withdrawals made in CiviCRM since then have not reached the website. '
  . 'Nothing is lost: the next successful run picks them all up.</p>'
  . '<p>Run it by hand to see the failure live:</p>'
  . '<pre>/home/rob/openar-roster/sync-roster.sh; cat /home/rob/openar-roster/last-run.log</pre>'
  . '<p>Output from the failed run:</p>'
  . '<pre style="font-size:12px;white-space:pre-wrap;">' . htmlspecialchars($log) . '</pre>';

[$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail();

// Passed by reference, so it has to be a variable rather than a literal.
$params = [
  'from' => sprintf('%s <%s>', $fromName, $fromEmail),
  'toEmail' => NOTIFY,
  'subject' => 'Mission Supporter roster sync failed',
  'text' => $text,
  'html' => $html,
];
$sent = CRM_Utils_Mail::send($params);

echo $sent
  ? "failure notification sent to " . NOTIFY . "\n"
  : "WARNING: could not send the failure notification\n";
