<?php
/**
 * The reason an application was declined, in the words sent to the applicant.
 *
 * Deliberately separate from `application_notes`. Those are the reviewer's
 * working notes and will sooner or later contain something speculative or blunt
 * that must never be mailed to the person it is about. This field holds only
 * what the Foundation is willing to say to them, and it is the only thing the
 * decline email quotes.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file decline-fields.php
 */

civicrm_initialize();

use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;

$group = CustomGroup::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', 'Membership')
  ->execute()->first();

if (!$group) {
  echo "ERROR: the Membership custom group is missing. Run civi-fields.php first.\n";
  return;
}

$fields = [
  [
    'name' => 'decline_reason',
    'label' => 'Reason given to the applicant',
    'data_type' => 'Memo',
    'html_type' => 'TextArea',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'help_post' => 'This text is sent to the applicant verbatim in the decline email. '
      . 'Write it as something you are willing for them to read. '
      . 'Reviewer working notes belong in Review notes instead, which is never sent.',
    'weight' => 60,
  ],
  [
    'name' => 'declined_date',
    'label' => 'Declined on',
    'data_type' => 'Date',
    'html_type' => 'Select Date',
    'is_required' => FALSE,
    'is_searchable' => TRUE,
    'weight' => 61,
  ],
];

foreach ($fields as $f) {
  $existing = CustomField::get(FALSE)
    ->addSelect('id')
    ->addWhere('custom_group_id', '=', $group['id'])
    ->addWhere('name', '=', $f['name'])
    ->execute()->first();

  if ($existing) {
    echo "already present: {$f['name']} (id {$existing['id']})\n";
    continue;
  }

  $f['custom_group_id'] = $group['id'];
  $id = CustomField::create(FALSE)->setValues($f)->execute()->first()['id'];
  echo "created: {$f['name']} (id {$id})\n";
}

echo "\nMembership group fields now:\n";
foreach (CustomField::get(FALSE)
  ->addSelect('id', 'name', 'data_type')
  ->addWhere('custom_group_id', '=', $group['id'])
  ->addOrderBy('weight', 'ASC')
  ->execute() as $f) {
  echo "  #{$f['id']} {$f['name']} ({$f['data_type']})\n";
}
