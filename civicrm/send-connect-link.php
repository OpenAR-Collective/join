<?php
/**
 * Send a member their personal Discord connect link.
 *
 * For anyone admitted before the Discord application existed. Their welcome
 * email correctly omitted the button, so they are members with no way in.
 *
 *   eval-file send-connect-link.php            list who has not connected
 *   eval-file send-connect-link.php 78         send to one contact
 *
 * One at a time, deliberately. There is no bulk mode: everyone on this list
 * already received a welcome email carrying a working link and chose not to use
 * it, and Discord is optional. Mailing them all again would be pestering people
 * for declining something the Foundation says it does not need from them.
 *
 * Anyone who has already connected is skipped, so running it twice does not
 * pester people who are already in the server.
 */

civicrm_initialize();

// connect-template.php renames the old 'Connect your Discord account'
// template to this, so looking for the old title finds nothing and the
// script tells the operator to run connect-template.php, which does not
// help because that is what renamed it.
const CONNECT_TEMPLATE = 'OpenAR - Your Discord link, again';

if (!function_exists('openar_discord_configured') || !openar_discord_configured()) {
  echo "Discord is not configured, so no link can be built. Check the five constants in wp-config.php.\n";
  return;
}

// Guarded so including this file twice in one process is not fatal.
if (!function_exists('openar_unconnected')):

/** Members in good standing who have no Discord account on file. */
function openar_unconnected(): array {
  $gid = civicrm_api4('Group', 'get', [
    'select' => ['id'], 'where' => [['name', '=', 'members']], 'checkPermissions' => FALSE,
  ])->first()['id'] ?? NULL;
  if (!$gid) {
    return [];
  }

  $ids = [];
  foreach (civicrm_api4('GroupContact', 'get', [
    'select' => ['contact_id'],
    'where' => [['group_id', '=', $gid], ['status', '=', 'Added']],
    'checkPermissions' => FALSE,
  ]) as $g) { $ids[] = (int) $g['contact_id']; }

  if (!$ids) {
    return [];
  }

  $out = [];
  foreach (civicrm_api4('Contact', 'get', [
    'select' => ['id', 'first_name', 'display_name', 'Membership.member_number', 'Membership.discord_user_id'],
    'where' => [['id', 'IN', $ids]],
    'orderBy' => ['Membership.member_number' => 'ASC'],
    'checkPermissions' => FALSE,
  ]) as $c) {
    if (!empty($c['Membership.discord_user_id'])) {
      continue;
    }
    $out[(int) $c['id']] = $c;
  }
  return $out;
}

function openar_send_connect(array $contact): bool {
  $contactId = (int) $contact['id'];

  $email = civicrm_api4('Email', 'get', [
    'select' => ['email', 'on_hold'],
    'where' => [['contact_id', '=', $contactId]],
    'orderBy' => ['is_primary' => 'DESC'],
    'checkPermissions' => FALSE,
  ])->first();

  if (empty($email['email'])) {
    printf("  SKIP  #%-4d %-26s no email address on file\n", $contactId, $contact['display_name']);
    return FALSE;
  }
  if (!empty($email['on_hold'])) {
    printf("  SKIP  #%-4d %-26s address is on hold (bouncing)\n", $contactId, $contact['display_name']);
    return FALSE;
  }

  $link = openar_discord_link_for($contactId);
  if ($link === '') {
    printf("  SKIP  #%-4d %-26s no link could be built\n", $contactId, $contact['display_name']);
    return FALSE;
  }

  $template = civicrm_api4('MessageTemplate', 'get', [
    'select' => ['id'],
    'where' => [['msg_title', '=', CONNECT_TEMPLATE], ['is_active', '=', TRUE]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$template) {
    echo "  ERROR: template '" . CONNECT_TEMPLATE . "' not found. Run connect-template.php first.\n";
    return FALSE;
  }

  [$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail();

  CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email['email'],
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'firstName' => $contact['first_name'] ?? '',
      'memberNumber' => $contact['Membership.member_number'] ?? '',
      'discordUrl' => $link,
      'expiryDays' => (int) (Civi::settings()->get('checksum_timeout') ?: 7),
    ],
  ]);

  printf("  sent  #%-4d %-26s member %-4s -> %s\n",
    $contactId, $contact['display_name'], $contact['Membership.member_number'] ?? '-', $email['email']);
  return TRUE;
}

endif;

$arg = isset($args[0]) ? trim((string) $args[0]) : '';
$pending = openar_unconnected();

if ($arg === '') {
  echo "Members who have not connected Discord:\n\n";
  printf("  %-5s %-5s %-28s %s\n", 'id', 'num', 'name', 'email');
  echo '  ' . str_repeat('-', 82) . "\n";
  foreach ($pending as $c) {
    $e = civicrm_api4('Email', 'get', [
      'select' => ['email'], 'where' => [['contact_id', '=', $c['id']]],
      'orderBy' => ['is_primary' => 'DESC'], 'checkPermissions' => FALSE,
    ])->first()['email'] ?? '*** none ***';
    printf("  %-5d %-5s %-28s %s\n", $c['id'], $c['Membership.member_number'] ?? '-',
      mb_strimwidth((string) $c['display_name'], 0, 28, ''), $e);
  }
  printf("\n  %d waiting. Send to one with: eval-file send-connect-link.php <id>\n", count($pending));
  return;
}

if (strtolower($arg) === 'all') {
  echo "There is no bulk mode. Everyone on this list already had a working link and\n";
  echo "did not use it, and Discord is optional. Send to one person at a time, when\n";
  echo "they ask: eval-file send-connect-link.php <id>\n";
  return;
}

$contactId = (int) $arg;
if (!isset($pending[$contactId])) {
  $c = civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'Membership.discord_user_id'],
    'where' => [['id', '=', $contactId]], 'checkPermissions' => FALSE,
  ])->first();
  if (!$c) {
    echo "No contact {$contactId}.\n";
  }
  elseif (!empty($c['Membership.discord_user_id'])) {
    echo "{$c['display_name']} has already connected Discord. Nothing sent.\n";
  }
  else {
    echo "{$c['display_name']} is not currently in the members group. Nothing sent.\n";
  }
  return;
}

openar_send_connect($pending[$contactId]);
