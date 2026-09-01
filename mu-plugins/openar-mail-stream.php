<?php
/**
 * Plugin Name: OpenAR mail streams
 * Description: Routes CiviMail bulk mailings onto Postmark's broadcast message stream, keeping transactional mail on its own.
 * Version:     1.0.0
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
 * So: bulk CiviMail deliveries get the stream header, and nothing else does.
 * The confirmations, welcomes, and badges sent through sendTemplate arrive
 * here with context 'messageTemplate' or 'singleEmail' and are left alone.
 *
 * The stream must exist on the Postmark server or delivery fails outright,
 * loudly rather than quietly. It was created by hand in the Postmark
 * dashboard on 2026-08-31; the test send of any new mailing doubles as the
 * check that the header and the stream still agree, visible per message in
 * Postmark's Activity view.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

const OPENAR_BROADCAST_STREAM = 'conference-attendee-blast';

add_action('civicrm_alterMailParams', 'openar_mail_stream_route', 10, 2);

/**
 * Stamp bulk CiviMail deliveries with the broadcast stream header.
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
  $params['headers']['X-PM-Message-Stream'] = OPENAR_BROADCAST_STREAM;
}
