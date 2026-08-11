<?php
/**
 * Who started an application and never confirmed their email address.
 *
 * Afform stores every submission before it emails the link, so a submission
 * still sitting at status Pending is exactly someone who filled the form and
 * did not click. Confirmed ones move to Processed, and a resubmission marks the
 * earlier attempt Rejected.
 *
 * List them:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file pending-applications.php
 *
 * Send someone a fresh link:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file pending-applications.php 12
 *
 * Nothing here writes to the contact records. An unconfirmed application has no
 * contact yet, which is the point.
 */

civicrm_initialize();

const LIFETIME_DAYS = 7;

/** Walk the submission tree for a value; shape differs by form. */
function openar_report_find(array $data, string $key): ?string {
  foreach ($data as $k => $value) {
    if ($k === $key && is_scalar($value) && (string) $value !== '') {
      return (string) $value;
    }
    if (is_array($value)) {
      $found = openar_report_find($value, $key);
      if ($found !== NULL) {
        return $found;
      }
    }
  }
  return NULL;
}

function openar_report_data(array $submission): array {
  $data = $submission['data'] ?? [];
  if (is_string($data)) {
    $data = json_decode($data, TRUE) ?: [];
  }
  return is_array($data) ? $data : [];
}

$resendId = isset($args[0]) ? (int) $args[0] : NULL;

if ($resendId) {
  if (!function_exists('openar_send_verification_link')) {
    echo "ERROR: the onboarding mu-plugin is not loaded, so no link can be sent.\n";
    return;
  }
  echo openar_send_verification_link($resendId)
    ? "sent a fresh 7 day link for submission {$resendId}\n"
    : "could not send a link for submission {$resendId}; see the list below\n";
  echo "\n";
}

$pending = civicrm_api4('AfformSubmission', 'get', [
  'select' => ['id', 'afform_name', 'submission_date', 'data'],
  'where' => [['status_id:name', '=', 'Pending']],
  'orderBy' => ['submission_date' => 'DESC'],
  'checkPermissions' => FALSE,
]);

if (!count($pending)) {
  echo "No unconfirmed applications.\n";
  return;
}

printf("%-5s %-26s %-34s %-12s %s\n", 'ID', 'Name', 'Email', 'Submitted', 'Link');
echo str_repeat('-', 92) . "\n";

$now = new DateTimeImmutable();

foreach ($pending as $s) {
  $data = openar_report_data($s);

  $name = trim(sprintf(
    '%s %s',
    openar_report_find($data, 'first_name') ?? '',
    openar_report_find($data, 'last_name') ?? ''
  ));
  if ($name === '') {
    $name = openar_report_find($data, 'organization_name') ?? '(no name given)';
  }

  $submitted = new DateTimeImmutable($s['submission_date']);
  $age = (int) $submitted->diff($now)->days;
  $live = $age < LIFETIME_DAYS;

  printf(
    "%-5d %-26s %-34s %-12s %s\n",
    $s['id'],
    mb_strimwidth($name, 0, 26, ''),
    mb_strimwidth(openar_report_find($data, 'email') ?? '(none)', 0, 34, ''),
    $submitted->format('Y-m-d'),
    $live ? sprintf('live, %dd left', LIFETIME_DAYS - $age) : sprintf('lapsed %dd ago', $age - LIFETIME_DAYS)
  );
}

echo "\n" . count($pending) . " unconfirmed. Resend with: eval-file pending-applications.php <ID>\n";
