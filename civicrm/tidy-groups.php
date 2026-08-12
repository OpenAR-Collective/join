<?php
/**
 * Retire the leftover "Applicants" group and turn "Prospects" into a real one.
 *
 * Both were made by hand in the CiviCRM UI before any of these scripts existed,
 * which is why they carry generated machine names like Applic_3 rather than
 * the readable ones everything else uses.
 *
 * "Applicants" has been superseded three times over: an application now moves
 * through applicants_unconfirmed, applicants_pending_review and either members
 * or applicants_declined. Leaving a fourth group with the same name sitting in
 * the recipient picker is how somebody eventually mails the wrong list.
 *
 * "Prospects" stays. It is where practitioners gathered from conference lists
 * go, so it gets a name that can be typed, and a description that says what it
 * is, because the next person to open the recipient picker will not have been
 * in this conversation.
 *
 * Refuses to delete a group with anybody in it or anything pointing at it, so
 * running this after the group has been filled does nothing.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file tidy-groups.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
if (is_readable(__DIR__ . '/openar-snapshot.php')) {
  require_once __DIR__ . '/openar-snapshot.php';
  openar_snapshot('tidy-groups');
}

use Civi\Api4\Group;

/* -------------------------------------------------- retire the old group -- */

$old = Group::get(FALSE)
  ->addSelect('id', 'name', 'title')
  ->addWhere('name', '=', 'Applic_3')
  ->execute()->first();

if (!$old) {
  echo "Applicants: already gone\n";
}
else {
  $count = (int) civicrm_api4('GroupContact', 'get', [
    'select' => ['row_count'],
    'where' => [['group_id', '=', $old['id']]],
    'checkPermissions' => FALSE,
  ])->count();

  $mailings = (int) CRM_Core_DAO::singleValueQuery(
    'SELECT COUNT(*) FROM civicrm_mailing_group WHERE entity_table = %1 AND entity_id = %2',
    [1 => ['civicrm_group', 'String'], 2 => [$old['id'], 'Integer']]);

  if ($count || $mailings) {
    echo "Applicants: NOT deleted. It holds {$count} contact(s) and is referenced by "
      . "{$mailings} mailing(s). Deleting it would take a group membership away from a\n"
      . "            real person, so this leaves it alone.\n";
  }
  else {
    Group::delete(FALSE)->addWhere('id', '=', $old['id'])->execute();
    echo "Applicants: deleted (was id {$old['id']}, empty and unreferenced)\n";
  }
}

/* ------------------------------------------------ make Prospects a real one -- */

$prospects = Group::get(FALSE)
  ->addSelect('id', 'name', 'title')
  ->addWhere('name', 'IN', ['Prosp_4', 'prospects'])
  ->execute()->first();

if (!$prospects) {
  echo "Prospects: not found, so nothing to rename\n";
}
else {
  Group::update(FALSE)
    ->addWhere('id', '=', $prospects['id'])
    ->addValue('name', 'prospects')
    ->addValue('title', 'Prospects')
    ->addValue('description',
      'Accounts receivable practitioners who are not members, gathered from '
      . 'conference attendee lists and similar sources. Mailing this list uses '
      . 'the default footer, which is worded to be true of anyone. The warmer '
      . '"Mailing Footer - members only" is for the Members group and says '
      . 'things that are not true of the people here.')
    // Already a mailing list, but stated rather than assumed, since it is what
    // makes the group selectable as a mailing recipient at all.
    ->addValue('group_type:name', ['Mailing List'])
    ->addValue('is_active', TRUE)
    ->execute();

  echo "Prospects: id {$prospects['id']}, now named \"prospects\"\n";
}

/* ------------------------------------------------------------- what is left -- */

echo "\nMailing lists a mailing can be addressed to:\n";
foreach (Group::get(FALSE)
  ->addSelect('id', 'name', 'title')
  ->addWhere('group_type:name', 'CONTAINS', 'Mailing List')
  ->addWhere('is_active', '=', TRUE)
  ->addOrderBy('id')
  ->execute() as $g) {
  $n = (int) civicrm_api4('GroupContact', 'get', [
    'select' => ['row_count'],
    'where' => [['group_id', '=', $g['id']], ['status', '=', 'Added'],
                ['contact_id.is_deleted', '=', FALSE]],
    'checkPermissions' => FALSE,
  ])->count();
  printf("  %-14s %-28s %d contact(s)\n", $g['name'], $g['title'], $n);
}
