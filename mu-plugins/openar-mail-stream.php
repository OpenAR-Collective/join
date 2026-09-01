<?php
/**
 * Plugin Name: OpenAR mail streams
 * Description: Routes CiviMail bulk mailings onto the Postmark broadcast stream matching their audience, keeping transactional mail on its own.
 * Version:     1.1.0
 * License:     Apache-2.0
 *
 * Postmark separates transactional mail from bulk, and its terms require
 * marketing blasts to ride a Broadcast message stream. Everything sent over
 * SMTP lands on the default transactional stream unless a header says
 * otherwise, and CiviMail has no setting for that header. Without this hook a
 * bulk mailing would go out as a few hundred identical "transactional"
 * messages, which is exactly what gets a Postmark server flagged, and the
 * onboarding email this site depends on rides that server.
 *
 * Streams also carry separate reputations and suppression lists, so bulk
 * mail is split by audience rather than pooled: mail to prospects, the
 * riskiest thing the Foundation sends, must not taint the stream that
 * member announcements ride. A mailing whose recipients include a group
 * named below goes to that group's stream; every other bulk mailing goes to
 * the default. Transactional sends through sendTemplate arrive here with
 * context 'messageTemplate' or 'singleEmail' and are left alone.
 *
 * A named stream must exist on the Postmark server or delivery fails
 * outright, loudly rather than quietly. The test send of any new mailing
 * doubles as the check that header and stream agree; Postmark's Activity
 * view shows the stream per message.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Recipient group (matched on CiviCRM group name or title) to Postmark
 * message stream ID. First match wins, in this order.
 */
const OPENAR_MAIL_STREAMS = [
  'prospects' => 'conference-attendee-blast',
  'Brainstorm 2026' => 'conference-attendee-blast',
];

/**
 * The stream for bulk mail no rule above claims: "Organization
 * Announcements" in the Postmark dashboard, whose stream ID is "broadcast".
 */
const OPENAR_STREAM_DEFAULT = 'broadcast';

add_action('civicrm_alterMailParams', 'openar_mail_stream_route', 10, 2);

/**
 * Stamp bulk CiviMail deliveries with the stream their audience calls for.
 *
 * Context 'civimail' is the classic CiviMail delivery path and 'flexmailer'
 * the extension that newer CiviCRM versions deliver bulk mail through; which
 * one fires depends on the install, so both are treated as bulk.
 */
function openar_mail_stream_route(&$params, $context = NULL): void {
  if ($context !== 'civimail' && $context !== 'flexmailer') {
    return;
  }
  if (!is_array($params['headers'] ?? NULL)) {
    $params['headers'] = [];
  }
  $params['headers']['X-PM-Message-Stream'] =
    openar_mail_stream_for_job((int) ($params['job_id'] ?? 0));
}

/**
 * The stream a delivery job's mailing should ride, from its recipient groups.
 *
 * Looked up once per job rather than once per recipient, and any failure to
 * answer falls back to the default stream: a mailing on the wrong broadcast
 * stream is a bookkeeping error, while a mailing that dies mid-send is an
 * incident.
 */
function openar_mail_stream_for_job(int $jobId): string {
  static $cache = [];
  if ($jobId && isset($cache[$jobId])) {
    return $cache[$jobId];
  }

  $stream = OPENAR_STREAM_DEFAULT;
  try {
    if ($jobId) {
      $mailingId = \Civi\Api4\MailingJob::get(FALSE)
        ->addSelect('mailing_id')
        ->addWhere('id', '=', $jobId)
        ->execute()->first()['mailing_id'] ?? NULL;
      if ($mailingId) {
        foreach (\Civi\Api4\MailingGroup::get(FALSE)
          ->addSelect('entity_id')
          ->addWhere('mailing_id', '=', $mailingId)
          ->addWhere('entity_table', '=', 'civicrm_group')
          ->addWhere('group_type', '=', 'Include')
          ->execute() as $mg) {
          $g = \Civi\Api4\Group::get(FALSE)
            ->addSelect('name', 'title')
            ->addWhere('id', '=', (int) $mg['entity_id'])
            ->execute()->first();
          foreach ([(string) ($g['name'] ?? ''), (string) ($g['title'] ?? '')] as $key) {
            if ($key !== '' && isset(OPENAR_MAIL_STREAMS[$key])) {
              $stream = OPENAR_MAIL_STREAMS[$key];
              break 2;
            }
          }
        }
      }
    }
  }
  catch (\Throwable $e) {
    // The default stands.
  }

  if ($jobId) {
    $cache[$jobId] = $stream;
  }
  return $stream;
}
