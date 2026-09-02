<?php
/** Workflow groups for membership and Mission Supporter onboarding. Idempotent. */
civicrm_initialize();

use Civi\Api4\Group;

function upsertGroup(array $vals): int {
  $existing = Group::get(FALSE)->addWhere('name', '=', $vals['name'])->execute()->first();
  if ($existing) {
    echo "  exists: {$vals['name']} (id {$existing['id']})\n";
    return $existing['id'];
  }
  $id = Group::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "  created: {$vals['name']} (id $id)\n";
  return $id;
}

$groups = [
  ['name' => 'applicants_unconfirmed', 'title' => 'Applicants - unconfirmed email',
   'description' => 'Applied but has not yet clicked the email confirmation link. Not reviewed.',
   'group_type:name' => ['Access Control']],

  ['name' => 'applicants_pending_review', 'title' => 'Applicants - pending review',
   'description' => 'Email confirmed. Awaiting review by membership@openarcollective.org.',
   'group_type:name' => ['Access Control']],

  ['name' => 'applicants_declined', 'title' => 'Applicants - declined',
   'description' => 'Application declined by a director. Reason recorded in Review notes.',
   'group_type:name' => ['Access Control']],

  ['name' => 'members', 'title' => 'Members',
   'description' => 'Admitted members. This group is the members-only email list.',
   'group_type:name' => ['Mailing List']],

  ['name' => 'supporters_pending', 'title' => 'Mission Supporters - pending',
   'description' => 'Statement signed. Awaiting verification of signer authority.',
   'group_type:name' => ['Access Control']],

  ['name' => 'supporters_published', 'title' => 'Mission Supporters - published',
   'description' => 'Verified. Synced to the public roster on openarcollective.org by scripts/sync-supporters.py.',
   'group_type:name' => ['Access Control']],
];

echo "Groups:\n";
foreach ($groups as $g) { upsertGroup($g); }
echo "\nDone.\n";
