<?php
/**
 * Create the custom field group and fields backing membership onboarding.
 * Idempotent: re-running matches on name and updates rather than duplicating.
 */
civicrm_initialize();

use Civi\Api4\CustomGroup;
use Civi\Api4\CustomField;

function upsertGroup(array $vals): int {
  $existing = CustomGroup::get(FALSE)
    ->addWhere('name', '=', $vals['name'])->execute()->first();
  if ($existing) {
    echo "  group {$vals['name']} exists (id {$existing['id']})\n";
    return $existing['id'];
  }
  $id = CustomGroup::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "  created group {$vals['name']} (id $id)\n";
  return $id;
}

function upsertField(int $groupId, array $vals): int {
  $existing = CustomField::get(FALSE)
    ->addWhere('custom_group_id', '=', $groupId)
    ->addWhere('name', '=', $vals['name'])->execute()->first();
  if ($existing) {
    echo "  field {$vals['name']} exists (id {$existing['id']})\n";
    return $existing['id'];
  }
  $vals['custom_group_id'] = $groupId;
  $id = CustomField::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "  created field {$vals['name']} (id $id)\n";
  return $id;
}

echo "Membership custom group:\n";
$gid = upsertGroup([
  'name' => 'Membership',
  'title' => 'Membership',
  'extends' => 'Individual',
  'style' => 'Inline',
  'collapse_display' => FALSE,
  'is_active' => TRUE,
  'help_pre' => 'Recognition membership program record.',
]);

$fields = [
  // Captured on the public application form.
  ['name' => 'mission_affirmation', 'label' => 'I have read the mission statement, and I support the Foundation\'s charitable mission',
   'data_type' => 'Boolean', 'html_type' => 'Radio', 'is_required' => FALSE, 'weight' => 10],
  ['name' => 'terms_agreement', 'label' => 'I have read and agree to the Community Participation Terms',
   'data_type' => 'Boolean', 'html_type' => 'Radio', 'is_required' => FALSE, 'weight' => 20],
  ['name' => 'info_truthful', 'label' => 'The information I have provided is truthful and current',
   'data_type' => 'Boolean', 'html_type' => 'Radio', 'is_required' => FALSE, 'weight' => 30],

  // Recorded by the system, never shown on the public form.
  ['name' => 'terms_version', 'label' => 'Terms version signed',
   'data_type' => 'String', 'html_type' => 'Text', 'text_length' => 32, 'weight' => 40],
  ['name' => 'member_number', 'label' => 'Member number',
   'data_type' => 'Int', 'html_type' => 'Text', 'weight' => 50, 'is_searchable' => TRUE],
  ['name' => 'email_confirmed_date', 'label' => 'Email confirmed',
   'data_type' => 'Date', 'html_type' => 'Select Date', 'date_format' => 'yy-mm-dd', 'time_format' => 2, 'weight' => 60],
  ['name' => 'discord_user_id', 'label' => 'Discord user ID',
   'data_type' => 'String', 'html_type' => 'Text', 'text_length' => 32, 'weight' => 70],
  ['name' => 'application_notes', 'label' => 'Review notes',
   'data_type' => 'Memo', 'html_type' => 'TextArea', 'weight' => 80],
];

echo "Fields:\n";
foreach ($fields as $f) {
  upsertField($gid, $f);
}

echo "\nDone.\n";
