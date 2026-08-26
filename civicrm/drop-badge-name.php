<?php
/**
 * Remove the short-lived MissionSupporter.badge_name field.
 *
 * Created 2026-08-25 and superseded the same day: the badge is now drawn with
 * the name the roster shows, so nothing reads this field, and an editable
 * field nothing reads invites somebody to fill it in and expect an effect.
 *
 * Refuses to act while any record holds a value, so it can never destroy a
 * decision somebody recorded. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file drop-badge-name.php
 */

civicrm_initialize();

$field = \Civi\Api4\CustomField::get(FALSE)
  ->addSelect('id')
  ->addWhere('custom_group_id.name', '=', 'MissionSupporter')
  ->addWhere('name', '=', 'badge_name')
  ->execute()->first();

if (!$field) {
  echo "badge_name is already gone\n";
  return;
}

$holding = (int) civicrm_api4('Contact', 'get', [
  'select' => ['row_count'],
  'where' => [['MissionSupporter.badge_name', 'IS NOT EMPTY']],
  'checkPermissions' => FALSE,
])->count();

if ($holding > 0) {
  echo "REFUSED: {$holding} contact(s) hold a badge name. Look at what is there before deleting it.\n";
  return;
}

\Civi\Api4\CustomField::delete(FALSE)
  ->addWhere('id', '=', $field['id'])
  ->execute();

echo "deleted MissionSupporter.badge_name (id {$field['id']}), which held no data\n";
