<?php
/**
 * Pull Postmark's broadcast suppressions into CiviCRM, so an unsubscribe
 * clicked on Postmark's own link is recorded where the records live.
 *
 * Postmark appends its own unsubscribe link to broadcast messages and keeps
 * the resulting suppressions in its own per-stream ledger, which CiviCRM
 * never hears about. Left alone, our records would keep believing those
 * people are mailable. This closes the loop: a recipient suppression or a
 * spam complaint becomes a CiviCRM opt-out, and a hard bounce puts the
 * address on hold.
 *
 * One direction only, and never the reverse: CiviCRM opt-outs are already
 * honored by CiviMail itself and need no help from Postmark.
 *
 * Idempotent; run it as often as you like. Run by cron as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file sync-postmark-suppressions.php
 *
 * A failure emails bots@, because a suppression sync that stops silently
 * means mailing somebody who told us to stop, which is the failure worth
 * spending an email on.
 */

civicrm_initialize();

// The streams whose suppressions carry meaning for us: the broadcast
// streams. Transactional suppressions are Postmark's own bookkeeping.
const OPENAR_SYNC_STREAMS = ['conference-attendee-blast', 'broadcast'];

echo gmdate('c') . " postmark suppression sync starting\n";

try {
  // The server token is the SMTP credential CiviCRM already holds. It is
  // stored encrypted, so it goes through CiviCRM's own decryption; a value
  // stored in the clear passes through decrypt() unchanged. Used here and
  // never printed.
  $stored = (string) ((\Civi::settings()->get('mailing_backend')['smtpPassword']) ?? '');
  if ($stored === '') {
    throw new RuntimeException('no SMTP credential in CiviCRM settings');
  }
  $token = (string) \Civi::service('crypto.token')->decrypt($stored);

  $optedOut = 0;
  $held = 0;
  $unknown = 0;
  $already = 0;

  foreach (OPENAR_SYNC_STREAMS as $stream) {
    $response = wp_remote_get(
      "https://api.postmarkapp.com/message-streams/{$stream}/suppressions/dump",
      [
        'headers' => ['Accept' => 'application/json', 'X-Postmark-Server-Token' => $token],
        'timeout' => 30,
      ]
    );
    if (is_wp_error($response)) {
      throw new RuntimeException("{$stream}: " . $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
      throw new RuntimeException("{$stream}: Postmark answered {$code}");
    }

    $rows = json_decode(wp_remote_retrieve_body($response), TRUE)['Suppressions'] ?? [];
    foreach ($rows as $row) {
      $address = strtolower(trim((string) ($row['EmailAddress'] ?? '')));
      $reason = (string) ($row['SuppressionReason'] ?? '');
      if ($address === '') {
        continue;
      }

      $emails = civicrm_api4('Email', 'get', [
        'select' => ['id', 'contact_id', 'on_hold', 'contact_id.is_opt_out', 'contact_id.display_name'],
        'where' => [['email', '=', $address], ['contact_id.is_deleted', '=', FALSE]],
        'checkPermissions' => FALSE,
      ]);
      if (!$emails->count()) {
        echo "  unknown to CiviCRM: {$address} ({$stream}, {$reason})\n";
        $unknown++;
        continue;
      }

      foreach ($emails as $e) {
        if ($reason === 'HardBounce') {
          if (!empty($e['on_hold'])) {
            $already++;
            continue;
          }
          civicrm_api4('Email', 'update', [
            'where' => [['id', '=', $e['id']]],
            'values' => ['on_hold' => 1],
            'checkPermissions' => FALSE,
          ]);
          echo "  on hold: {$address} ({$e['contact_id.display_name']}, {$stream})\n";
          $held++;
        }
        else {
          // ManualSuppression and SpamComplaint alike: they said stop.
          if (!empty($e['contact_id.is_opt_out'])) {
            $already++;
            continue;
          }
          civicrm_api4('Contact', 'update', [
            'where' => [['id', '=', $e['contact_id']]],
            'values' => ['is_opt_out' => TRUE],
            'checkPermissions' => FALSE,
          ]);
          echo "  opted out: {$address} ({$e['contact_id.display_name']}, {$stream}, {$reason})\n";
          $optedOut++;
        }
      }
    }
  }

  echo gmdate('c') . " done: {$optedOut} opted out, {$held} put on hold, "
    . "{$already} already recorded, {$unknown} unknown\n";
}
catch (Throwable $e) {
  echo gmdate('c') . ' FAILED: ' . $e->getMessage() . "\n";
  try {
    [$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail();
    $mail = [
      'from' => sprintf('%s <%s>', $fromName, $fromEmail),
      'toEmail' => 'bots@openarcollective.org',
      'subject' => 'Postmark suppression sync failed',
      'text' => "The suppression sync on join.openarcollective.org failed:\n\n"
        . $e->getMessage() . "\n\nUntil it runs again, an unsubscribe clicked on "
        . "Postmark's own link is not being recorded in CiviCRM.\n",
    ];
    CRM_Utils_Mail::send($mail);
  }
  catch (Throwable $mailFailure) {
    echo 'could not send the failure email either: ' . $mailFailure->getMessage() . "\n";
  }
}
