<?php
/**
 * Finish the workflow groups. CiviCRM's installer already created default
 * "Members", "Applicants", and "Prospects" groups, so the Members group is
 * repurposed in place rather than duplicated under a near-identical title.
 */
civicrm_initialize();

use Civi\Api4\Group;

function byNameOrTitle(string $name, string $title): ?array {
  $g = Group::get(FALSE)->addWhere('name', '=', $name)->execute()->first();
  if ($g) return $g;
  return Group::get(FALSE)->addWhere('title', '=', $title)->execute()->first();
}

// Members: reuse the installer's group so there is only ever one.
$m = byNameOrTitle('members', 'Members');
if ($m) {
  Group::update(FALSE)->addWhere('id', '=', $m['id'])->setValues([
    'name' => 'members',
    'title' => 'Members',
    'description' => 'Admitted members. This group is the members-only email list.',
    'group_type:name' => ['Mailing List'],
    'is_active' => TRUE,
  ])->execute();
  echo "  updated Members in place (id {$m['id']}) -> name=members, type=Mailing List\n";
} else {
  $id = Group::create(FALSE)->setValues([
    'name' => 'members', 'title' => 'Members',
    'description' => 'Admitted members. This group is the members-only email list.',
    'group_type:name' => ['Mailing List'],
  ])->execute()->first()['id'];
  echo "  created Members (id $id)\n";
}

// Mission Supporter groups.
$supporters = [
  ['name' => 'supporters_pending', 'title' => 'Mission Supporters - pending',
   'description' => 'Statement signed. Awaiting verification of signer authority.',
   'group_type:name' => ['Access Control']],
  ['name' => 'supporters_published', 'title' => 'Mission Supporters - published',
   'description' => 'Verified. Synced to the public roster by scripts/sync-supporters.py.',
   'group_type:name' => ['Access Control']],
];
foreach ($supporters as $s) {
  $existing = byNameOrTitle($s['name'], $s['title']);
  if ($existing) { echo "  exists: {$s['name']} (id {$existing['id']})\n"; continue; }
  $id = Group::create(FALSE)->setValues($s)->execute()->first()['id'];
  echo "  created: {$s['name']} (id $id)\n";
}

echo "\nFinal group list:\n";
foreach (Group::get(FALSE)->addSelect('id', 'name', 'title')->addOrderBy('id')->execute() as $g) {
  printf("  %3d | %-28s | %s\n", $g['id'], $g['name'], $g['title']);
}
