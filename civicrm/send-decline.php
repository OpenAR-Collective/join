<?php
/**
 * Send the decline email once a reason has been written.
 *
 * Adding someone to the declined group normally sends it automatically. When the
 * reason field was still empty at that moment the email is held back, the
 * reviewers are told, and this finishes the job afterwards.
 *
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file send-decline.php 42
 *
 * With no argument it lists everyone who is declined but has not been told.
 */

civicrm_initialize();

if (!function_exists('openar_send_decline')) {
  echo "ERROR: the onboarding mu-plugin is not loaded.\n";
  return;
}

$contactId = isset($args[0]) ? (int) $args[0] : NULL;

function openar_declined_contacts(): array {
  $groupId = civicrm_api4('Group', 'get', [
    'select' => ['id'], 'where' => [['name', '=', 'applicants_declined']], 'checkPermissions' => FALSE,
  ])->first()['id'] ?? NULL;

  if (!$groupId) {
    return [];
  }

  $ids = [];
  foreach (civicrm_api4('GroupContact', 'get', [
    'select' => ['contact_id'],
    'where' => [['group_id', '=', $groupId], ['status', '=', 'Added']],
    'checkPermissions' => FALSE,
  ]) as $row) {
    $ids[] = (int) $row['contact_id'];
  }

  if (!$ids) {
    return [];
  }

  return (array) civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'Membership.decline_reason', 'Membership.declined_date'],
    'where' => [['id', 'IN', $ids]],
    'checkPermissions' => FALSE,
  ]);
}

if (!$contactId) {
  $rows = openar_declined_contacts();
  if (!$rows) {
    echo "Nobody is in the declined group.\n";
    return;
  }

  printf("%-6s %-28s %-10s %-9s %s\n", 'ID', 'Name', 'Reason?', 'Told?', 'Declined on');
  echo str_repeat('-', 78) . "\n";
  $waiting = 0;
  foreach ($rows as $c) {
    $hasReason = trim((string) ($c['Membership.decline_reason'] ?? '')) !== '';
    $told = openar_already_declined((int) $c['id']);
    if (!$told) {
      $waiting++;
    }
    printf("%-6d %-28s %-10s %-9s %s\n",
      $c['id'],
      mb_strimwidth((string) $c['display_name'], 0, 28, ''),
      $hasReason ? 'yes' : 'MISSING',
      $told ? 'sent' : 'not yet',
      substr((string) ($c['Membership.declined_date'] ?? ''), 0, 10) ?: '-'
    );
  }
  echo "\n{$waiting} not yet told. Send one with: eval-file send-decline.php <ID>\n";
  return;
}

$contact = civicrm_api4('Contact', 'get', [
  'select' => ['id', 'display_name', 'Membership.decline_reason'],
  'where' => [['id', '=', $contactId]],
  'checkPermissions' => FALSE,
])->first();

if (!$contact) {
  echo "No contact {$contactId}.\n";
  return;
}

if (openar_already_declined($contactId)) {
  echo "{$contact['display_name']} has already been sent a decline. Nothing sent again.\n";
  return;
}

$reason = trim((string) ($contact['Membership.decline_reason'] ?? ''));
if ($reason === '') {
  echo "{$contact['display_name']} has no reason recorded, so nothing was sent.\n";
  echo "Fill in \"Reason given to the applicant\" on the contact first.\n";
  return;
}

echo "Sending to {$contact['display_name']} (contact {$contactId}).\n";
echo "Reason they will read:\n\n  " . str_replace("\n", "\n  ", $reason) . "\n\n";

echo openar_send_decline($contactId, $reason)
  ? "sent.\n"
  : "could not send; see the CiviCRM log.\n";
