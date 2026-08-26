<?php
/**
 * The Mission Supporter custom group and its fields.
 *
 * These existed only in the database. Every other part of the onboarding build
 * had a script that could recreate it; this did not, so a lost or rebuilt
 * CiviCRM would have taken the Statement of Support with it.
 *
 * Idempotent: existing fields are left alone, missing ones are created.
 *
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file civi-fields-supporter.php
 */

civicrm_initialize();

use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;

const GROUP = 'MissionSupporter';

$group = CustomGroup::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', GROUP)
  ->execute()->first();

if (!$group) {
  $group = CustomGroup::create(FALSE)
    ->addValue('name', GROUP)
    ->addValue('title', 'Mission Supporter')
    ->addValue('extends', 'Organization')
    ->addValue('is_active', TRUE)
    ->execute()->first();
  echo "created custom group " . GROUP . " (id {$group['id']})
";
}
else {
  echo "custom group " . GROUP . " already present (id {$group['id']})
";
}

$fields = [
  [
    'name' => 'trade_name',
    'label' => 'Trade name, if different',
    'data_type' => 'String',
    'html_type' => 'Text',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'text_length' => 128,
    'weight' => 4,
  ],
  [
    'name' => 'website_url',
    'label' => 'Website',
    'data_type' => 'String',
    'html_type' => 'Text',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'text_length' => 255,
    'weight' => 7,
  ],
  [
    // Optional, and free text rather than a list of US states. Half the
    // plausible answers are Delaware, England and Wales, Ontario or Ireland,
    // and a state dropdown would quietly tell everyone else they are not
    // expected here. A reviewer who has a name and a jurisdiction can settle
    // "is this a real organization" with one public records search; a reviewer
    // with a name alone cannot settle it at all.
    'name' => 'registered_in',
    'label' => 'Where the organization is registered',
    'data_type' => 'String',
    'html_type' => 'Text',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'text_length' => 128,
    'weight' => 8,
  ],
  [
    'name' => 'signer_name',
    'label' => 'Your name',
    'data_type' => 'String',
    'html_type' => 'Text',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'text_length' => 128,
    'weight' => 10,
  ],
  [
    'name' => 'signer_title',
    'label' => 'Your title',
    'data_type' => 'String',
    'html_type' => 'Text',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'text_length' => 128,
    'weight' => 12,
  ],
  [
    'name' => 'signer_email',
    'label' => 'Your business email address',
    'data_type' => 'String',
    'html_type' => 'Text',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'text_length' => 255,
    'weight' => 14,
  ],
  [
    'name' => 'mission_affirmation_org',
    'label' => 'Our organization has read the mission statement above, and supports the Foundation\'s charitable mission',
    'data_type' => 'Boolean',
    'html_type' => 'Radio',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'weight' => 16,
  ],
  [
    'name' => 'terms_agreement_org',
    'label' => 'Our organization has read and agrees to the Community Participation Terms',
    'data_type' => 'Boolean',
    'html_type' => 'Radio',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'weight' => 18,
  ],
  [
    'name' => 'authority_representation',
    'label' => 'I have authority to bind the organization named above, and the information provided is truthful and current',
    'data_type' => 'Boolean',
    'html_type' => 'Radio',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'weight' => 20,
  ],
  [
    'name' => 'statement_version',
    'label' => 'Statement version signed',
    'data_type' => 'String',
    'html_type' => 'Text',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'text_length' => 32,
    'weight' => 22,
  ],
  [
    'name' => 'signed_date',
    'label' => 'Date signed',
    'data_type' => 'Date',
    'html_type' => 'Select Date',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'weight' => 24,
  ],
  [
    'name' => 'supporter_notes',
    'label' => 'Verification notes',
    'data_type' => 'Memo',
    'html_type' => 'TextArea',
    'is_required' => FALSE,
    'is_searchable' => FALSE,
    'weight' => 25,
  ],
];

foreach ($fields as $f) {
  $existing = CustomField::get(FALSE)
    ->addSelect('id')
    ->addWhere('custom_group_id', '=', $group['id'])
    ->addWhere('name', '=', $f['name'])
    ->execute()->first();

  if ($existing) {
    echo "  present: {$f['name']}
";
    continue;
  }

  $f['custom_group_id'] = $group['id'];
  $f['is_active'] = TRUE;
  $id = CustomField::create(FALSE)->setValues($f)->execute()->first()['id'];
  echo "  created: {$f['name']} (id {$id})
";
}

echo "
" . GROUP . " now has " . CustomField::get(FALSE)
  ->addSelect('row_count')
  ->addWhere('custom_group_id', '=', $group['id'])
  ->execute()->count() . " fields
";
