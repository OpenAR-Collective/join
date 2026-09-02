<?php
/**
 * Switch outbound mail between live and captured, safely.
 *
 * `mailing_backend` is one array holding the delivery mode AND the SMTP server,
 * port, auth flag and credentials. Writing ['outBound_option' => N] over it does
 * not set the mode: it replaces the array and discards the Postmark host and
 * credentials with it. That has happened twice, and the second time outbound
 * mail was dead for hours while real applications were being approved.
 *
 * Two rules, both enforced here rather than remembered:
 *
 *   1. Merge one key. Never write a bare array.
 *   2. Restore in a finally. A test that dies between capture and restore
 *      leaves production capturing mail, and the person who would notice is
 *      not looking at the terminal it died in.
 *
 * Usage from a test script:
 *
 *   require_once __DIR__ . '/openar-mailmode.php';
 *   openar_with_captured_mail(function () {
 *     ... submit forms, assert on civicrm_mailing_spool ...
 *   });
 *
 * The mode is restored even if the callback throws.
 */

// define() rather than const: these sit inside a guard, and const is not
// permitted in a conditional block.
defined('OPENAR_MAIL_SMTP') || define('OPENAR_MAIL_SMTP', 0);
defined('OPENAR_MAIL_CAPTURE') || define('OPENAR_MAIL_CAPTURE', 5);

if (!function_exists('openar_mail_mode')) {

/**
 * Set the delivery mode, leaving every other key alone.
 *
 * @return array The full settings as they were, to hand back to this function.
 */
function openar_mail_mode(int $option): array {
  $before = (array) Civi::settings()->get('mailing_backend');

  $after = $before;
  $after['outBound_option'] = $option;
  Civi::settings()->set('mailing_backend', $after);

  return $before;
}

/** Put the settings back exactly as they were. */
function openar_mail_restore(array $previous): void {
  if (!$previous) {
    return;
  }
  Civi::settings()->set('mailing_backend', $previous);
}

/**
 * Run something with mail captured to the database, then restore the mode.
 *
 * The finally is the point of this function. Without it a fatal inside the
 * callback leaves the site capturing mail indefinitely.
 */
function openar_with_captured_mail(callable $fn) {
  $previous = openar_mail_mode(OPENAR_MAIL_CAPTURE);
  try {
    return $fn();
  }
  finally {
    openar_mail_restore($previous);
  }
}

/** True when mail is configured in a way that can actually deliver. */
function openar_mail_deliverable(): bool {
  $m = (array) Civi::settings()->get('mailing_backend');
  if ((int) ($m['outBound_option'] ?? -1) !== OPENAR_MAIL_SMTP) {
    return FALSE;
  }
  if (empty($m['smtpServer'])) {
    return FALSE;
  }
  return empty($m['smtpAuth']) || !empty($m['smtpPassword']);
}

}
