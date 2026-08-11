<?php
/**
 * Two more application fields. Deliberately custom text rather than CiviCRM's
 * core current_employer (which creates linked Organization records as a side
 * effect) or a Website entity (which complicates the form for one URL).
 */
civicrm_initialize();

use Civi\Api4\CustomGroup;
use Civi\Api4\CustomField;

$gid = CustomGroup::get(FALSE)->addWhere('name', '=', 'Membership')->execute()->first()['id'];

$fields = [
  ['name' => 'employer_affiliation', 'label' => 'Employer or affiliation',
   'data_type' => 'String', 'html_type' => 'Text', 'text_length' => 128,
   'is_required' => FALSE, 'is_searchable' => TRUE, 'weight' => 5],
  ['name' => 'linkedin_url', 'label' => 'LinkedIn profile',
   'data_type' => 'String', 'html_type' => 'Text', 'text_length' => 255,
   'is_required' => FALSE, 'weight' => 6],
];

foreach ($fields as $f) {
  $existing = CustomField::get(FALSE)
    ->addWhere('custom_group_id', '=', $gid)
    ->addWhere('name', '=', $f['name'])->execute()->first();
  if ($existing) { echo "  exists: {$f['name']} (id {$existing['id']})\n"; continue; }
  $f['custom_group_id'] = $gid;
  $id = CustomField::create(FALSE)->setValues($f)->execute()->first()['id'];
  echo "  created: {$f['name']} (id $id)\n";
}

echo "\nAll Membership fields:\n";
foreach (CustomField::get(FALSE)->addWhere('custom_group_id', '=', $gid)
  ->addSelect('id', 'name', 'label', 'data_type')->addOrderBy('weight')->execute() as $f) {
  printf("  custom_%-3d %-24s %-10s %s\n", $f['id'], $f['name'], $f['data_type'], $f['label']);
}
