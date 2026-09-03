<?php
/**
 * Tell somebody the nightly backup failed.
 *
 * Called by /opt/backup/nightly.sh when a step fails, through the one command
 * root can hand to the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org \
 *     eval-file backup-failure-mail.php "what failed"
 *
 * The box runs no local mail daemon, so the note goes out the same way every
 * other alert does, through CiviCRM's Postmark connection. A backup that
 * stops silently is the failure that hides best: the log stays quiet, the
 * dated directories just stop appearing, and nobody looks until the day a
 * restore is needed.
 */

civicrm_initialize();

$reason = trim((string) ($args[0] ?? 'no reason given'));

[$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail();
$mail = [
  'from' => sprintf('%s <%s>', $fromName, $fromEmail),
  'toEmail' => 'bots@openarcollective.org',
  'subject' => 'Nightly backup FAILED on openar-web-1',
  'text' => "The nightly backup on openar-web-1 failed:\n\n{$reason}\n\n"
    . "Until it succeeds again, the newest offsite backup is a day older than it\n"
    . "should be for every day this stands. The log is /var/log/openar-backup.log,\n"
    . "and the script is /opt/backup/nightly.sh (root).\n",
];
echo CRM_Utils_Mail::send($mail) ? "failure mail sent\n" : "could not send the failure mail\n";
