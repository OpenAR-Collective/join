<?php
/**
 * Plugin Name: OpenAR mail streams
 * Description: Routes CiviMail bulk mailings onto the Postmark broadcast stream matching their audience, delivers them to Postmark's broadcast SMTP host, and tells Postmark never to track.
 * Version:     1.2.0
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
 *
 * Two further Postmark facts this file carries the consequences of:
 *
 * Broadcast streams are served by a DIFFERENT SMTP HOST,
 * smtp-broadcasts.postmarkapp.com, with the same credentials. A message
 * carrying a broadcast stream header but delivered to the transactional host
 * is rejected. CiviCRM has one SMTP configuration, so the mailer is wrapped:
 * each message is delivered to the host its stream lives on.
 *
 * And the Foundation's privacy notice promises "we do not track our email",
 * so every message carries headers telling Postmark not to inject open or
 * click tracking, whatever any dashboard default may say now or later.
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

/**
 * Where Postmark serves broadcast streams. Broadcast mail delivered to the
 * transactional host is rejected, and vice versa.
 */
const OPENAR_BROADCAST_SMTP_HOST = 'smtp-broadcasts.postmarkapp.com';

add_action('civicrm_alterMailParams', 'openar_mail_stream_route', 10, 2);
add_action('civicrm_alterMailer', 'openar_mail_stream_wrap_mailer', 10, 3);

/**
 * Stamp bulk CiviMail deliveries with the stream their audience calls for.
 *
 * Context 'civimail' is the classic CiviMail delivery path and 'flexmailer'
 * the extension that newer CiviCRM versions deliver bulk mail through; which
 * one fires depends on the install, so both are treated as bulk.
 */
function openar_mail_stream_route(&$params, $context = NULL): void {
  if (!is_array($params['headers'] ?? NULL)) {
    $params['headers'] = [];
  }

  // Every message, bulk and transactional alike: the privacy notice says
  // "we do not track our email", so Postmark is told so explicitly rather
  // than trusted to default that way forever.
  $params['headers']['X-PM-TrackOpens'] = 'false';
  $params['headers']['X-PM-TrackLinks'] = 'None';

  if ($context !== 'civimail' && $context !== 'flexmailer') {
    return;
  }
  $params['headers']['X-PM-Message-Stream'] =
    openar_mail_stream_for_job((int) ($params['job_id'] ?? 0));
}

/**
 * Wrap the SMTP mailer so each message is delivered to the host its stream
 * lives on: broadcast-stream messages to Postmark's broadcasts host, and
 * everything else to the configured transactional host.
 */
function openar_mail_stream_wrap_mailer(&$mailer, $driver = NULL, $params = NULL): void {
  if ($driver !== 'smtp' || !is_array($params)) {
    return;
  }
  $mailer = new OpenAR_Stream_Splitting_Mailer($mailer, $params);
}

/**
 * A PEAR-Mail-shaped mailer that picks the SMTP host per message.
 *
 * The broadcast connection is created only when a broadcast message actually
 * arrives, with the same credentials as the configured mailer, because
 * Postmark authenticates both hosts with the same server token.
 */
class OpenAR_Stream_Splitting_Mailer {

  private $default;
  private $broadcast = NULL;
  private array $params;

  public function __construct($default, array $params) {
    $this->default = $default;
    $this->params = $params;
  }

  private function broadcastMailer() {
    if ($this->broadcast === NULL) {
      $params = $this->params;
      $params['host'] = OPENAR_BROADCAST_SMTP_HOST;
      require_once 'Mail.php';
      $this->broadcast = Mail::factory('smtp', $params);
    }
    return $this->broadcast;
  }

  public function send($recipients, $headers, $body) {
    $stream = is_array($headers) ? (string) ($headers['X-PM-Message-Stream'] ?? '') : '';
    $broadcastStreams = array_unique(array_merge(
      array_values(OPENAR_MAIL_STREAMS), [OPENAR_STREAM_DEFAULT]
    ));
    $mailer = in_array($stream, $broadcastStreams, TRUE)
      ? $this->broadcastMailer()
      : $this->default;
    return $mailer->send($recipients, $headers, $body);
  }

  /** CiviMail calls this between batches when the mailer offers it. */
  public function disconnect() {
    $ok = TRUE;
    foreach ([$this->default, $this->broadcast] as $mailer) {
      if ($mailer && is_callable([$mailer, 'disconnect'])) {
        $ok = $mailer->disconnect() && $ok;
      }
    }
    return $ok;
  }

  /** Anything else is the default mailer's business. */
  public function __call($name, $args) {
    return call_user_func_array([$this->default, $name], $args);
  }

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
