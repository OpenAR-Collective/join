<?php
/**
 * Wipe one person's onboarding state so the whole process can be walked again.
 *
 * Handles both paths. A member is found by their Email record; a supporter's
 * signer is found by the custom field on the organization, since the signer is
 * recorded rather than created as a contact of their own.
 *
 * For testing with a real address. It purges rather than trashes, which is what
 * frees the member number again: CiviCRM keeps the custom values of a trashed
 * contact, so only a purge lets the next admission reuse the number.
 *
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file reset-applicant.php rob@example.org
 *
 * With no argument it reports the state and changes nothing.
 *
 * This destroys a contact and everything hanging off it. It is meant for
 * addresses you are testing with, not for real members.
 */

civicrm_initialize();

$email = isset($args[0]) ? trim((string) $args[0]) : NULL;

function openar_reset_next_number(): int {
  $field = civicrm_api4('CustomField', 'get', [
    'select' => ['column_name', 'custom_group_id.table_name'],
    'where' => [['custom_group_id.name', '=', 'Membership'], ['name', '=', 'member_number']],
    'checkPermissions' => FALSE,
  ])->first();
  $max = (int) CRM_Core_DAO::singleValueQuery(
    "SELECT MAX(CAST(`{$field['column_name']}` AS UNSIGNED)) FROM `{$field['custom_group_id.table_name']}`"
  );
  return max($max, 0) + 1;
}

function openar_reset_report(): void {
  echo "\nMission Supporters:\n";
  $found = FALSE;
  foreach (civicrm_api4('Contact', 'get', [
    'select' => ['id', 'organization_name', 'MissionSupporter.signer_email'],
    'where' => [['contact_type', '=', 'Organization'], ['MissionSupporter.signer_email', 'IS NOT EMPTY']],
    'orderBy' => ['organization_name' => 'ASC'],
    'checkPermissions' => FALSE,
  ]) as $o) {
    $groups = [];
    foreach (civicrm_api4('GroupContact', 'get', [
      'select' => ['group_id:name'],
      'where' => [['contact_id', '=', $o['id']], ['status', '=', 'Added']],
      'checkPermissions' => FALSE,
    ]) as $g) {
      $groups[] = $g['group_id:name'];
    }
    printf("  #%-4d %-34s %-34s %s\n",
      $o['id'],
      mb_strimwidth((string) $o['organization_name'], 0, 34, ''),
      mb_strimwidth((string) $o['MissionSupporter.signer_email'], 0, 34, ''),
      implode(',', $groups) ?: '(no group)');
    $found = TRUE;
  }
  if (!$found) {
    echo "  (none)\n";
  }

  echo "\nMembers on record:\n";
  $found = FALSE;
  foreach (civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'Membership.member_number'],
    'where' => [['Membership.member_number', 'IS NOT EMPTY']],
    'orderBy' => ['Membership.member_number' => 'ASC'],
    'checkPermissions' => FALSE,
  ]) as $c) {
    printf("  %-6s #%-4d %s\n",
      $c['Membership.member_number'], $c['id'], $c['display_name']);
    $found = TRUE;
  }
  if (!$found) {
    echo "  (none)\n";
  }

  echo "\nNext member number to be issued: " . openar_reset_next_number() . "\n";
}

if (!$email) {
  echo "No address given, so nothing has been changed.\n";
  echo "Pass an address to purge it: eval-file reset-applicant.php someone@example.org\n";
  openar_reset_report();
  return;
}

$contactIds = [];

// Members: the address is an Email record on the contact.
foreach (civicrm_api4('Email', 'get', [
  'select' => ['contact_id'],
  'where' => [['email', '=', $email]],
  'checkPermissions' => FALSE,
]) as $row) {
  $contactIds[(int) $row['contact_id']] = TRUE;
}

// Mission Supporters: the signer is recorded on the organization rather than
// created as a contact, so their address is a custom field and the lookup above
// will never find it.
foreach (civicrm_api4('Contact', 'get', [
  'select' => ['id'],
  'where' => [['MissionSupporter.signer_email', '=', $email]],
  'checkPermissions' => FALSE,
]) as $row) {
  $contactIds[(int) $row['id']] = TRUE;
}

$contactIds = array_keys($contactIds);

if (!$contactIds) {
  echo "No contact has the address {$email}.\n";
}

foreach ($contactIds as $contactId) {
  $c = civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'Membership.member_number'],
    'where' => [['id', '=', $contactId]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$c) {
    continue;
  }

  echo "purging contact #{$c['id']} {$c['display_name']}"
    . (empty($c['Membership.member_number']) ? '' : " (was member {$c['Membership.member_number']})") . "\n";

  civicrm_api4('Contact', 'delete', [
    'where' => [['id', '=', $contactId]],
    'useTrash' => FALSE,
    'checkPermissions' => FALSE,
  ]);
}

// Unconfirmed and superseded submissions carry the typed data, so they go too.
$removed = 0;
foreach (civicrm_api4('AfformSubmission', 'get', ['select' => ['id', 'data'], 'checkPermissions' => FALSE]) as $s) {
  $data = is_string($s['data']) ? json_decode($s['data'], TRUE) : $s['data'];
  if (stripos(json_encode($data), $email) !== FALSE) {
    civicrm_api4('AfformSubmission', 'delete', ['where' => [['id', '=', $s['id']]], 'checkPermissions' => FALSE]);
    $removed++;
  }
}
echo "removed {$removed} form submission(s) for that address\n";

openar_reset_report();
