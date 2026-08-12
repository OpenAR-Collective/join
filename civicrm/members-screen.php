<?php
/**
 * A Members screen worth opening, and a contact record that leads with the
 * facts rather than with three checkboxes.
 *
 * Two problems, both cosmetic until you are the person doing the work daily.
 *
 * The group listing is CiviCRM's stock contact search, so its columns are
 * Address, City, State, Postal and Country. Every one of those is empty for
 * every member this Foundation will ever have, because the application does not
 * ask for an address. Meanwhile the member number and the employer, which are
 * the two things anyone actually wants, are not shown at all. This adds a
 * SearchKit display with the right columns and puts it in the CiviCRM menu.
 *
 * The contact record shows the Membership block in field order, and that order
 * was the order the fields were created: the three "I have read and agree"
 * checkboxes first, then the employer, with the member number down at position
 * seven. Nobody opens a member record to check that they ticked a box. The
 * weights are reordered so identity comes first, the agreements sit together
 * lower down as the record they are, and the decline fields stay last.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file members-screen.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
if (is_readable(__DIR__ . '/openar-snapshot.php')) {
  require_once __DIR__ . '/openar-snapshot.php';
  openar_snapshot('members-screen');
}

use Civi\Api4\CustomField;
use Civi\Api4\Group;
use Civi\Api4\Navigation;
use Civi\Api4\SavedSearch;
use Civi\Api4\SearchDisplay;

/* ------------------------------------------- the contact record's field order -- */

// Lower weight sorts higher. Left as gaps of ten so a field can be slid between
// two others later without renumbering everything.
$order = [
  'member_number' => 10,
  'employer_affiliation' => 20,
  'linkedin_url' => 30,
  'email_confirmed_date' => 40,
  'discord_user_id' => 50,
  'terms_version' => 60,
  'mission_affirmation' => 70,
  'terms_agreement' => 80,
  'info_truthful' => 90,
  'application_notes' => 100,
  'decline_reason' => 110,
  'declined_date' => 120,
];

foreach ($order as $name => $weight) {
  $f = CustomField::get(FALSE)
    ->addSelect('id', 'weight')
    ->addWhere('custom_group_id.name', '=', 'Membership')
    ->addWhere('name', '=', $name)
    ->execute()->first();

  if (!$f) {
    echo "  WARNING: Membership.{$name} not found, so it keeps its place\n";
    continue;
  }
  if ((int) $f['weight'] !== $weight) {
    CustomField::update(FALSE)->addWhere('id', '=', $f['id'])->addValue('weight', $weight)->execute();
  }
}
echo "Contact record: Membership fields reordered, member number first\n";

/* --------------------------------------------------- the Members search screen -- */

$membersGroupId = (int) (Group::get(FALSE)
  ->addSelect('id')->addWhere('name', '=', 'members')
  ->execute()->first()['id'] ?? 0);

if (!$membersGroupId) {
  echo "ERROR: no members group, so the screen cannot be built\n";
  return;
}

const OPENAR_SEARCH = 'openar_members';
const OPENAR_DISPLAY = 'openar_members_table';

$apiParams = [
  'version' => 4,
  'select' => [
    'Membership.member_number',
    'sort_name',
    'job_title',
    'Membership.employer_affiliation',
    'email_primary.email',
    'Membership.email_confirmed_date',
    'Membership.linkedin_url',
  ],
  'orderBy' => [],
  'where' => [
    ['contact_type', '=', 'Individual'],
    ['is_deleted', '=', FALSE],
    ['groups', 'IN', [$membersGroupId]],
  ],
  'groupBy' => [],
  // Declared rather than implied. Contact.get resolves email_primary.email on
  // its own, but SearchKit does not: it returned the column with no label and a
  // null type, which renders as a blank cell rather than an error. This is the
  // join SearchKit writes for itself when an Email is added in its own UI.
  'join' => [
    [
      'Email AS email_primary',
      'LEFT',
      ['id', '=', 'email_primary.contact_id'],
      ['email_primary.is_primary', '=', TRUE],
    ],
  ],
  'having' => [],
];

$searchValues = [
  'name' => OPENAR_SEARCH,
  'label' => 'Members',
  'api_entity' => 'Contact',
  'api_params' => $apiParams,
  'description' => 'Everyone in the members group, in member number order.',
  'is_current' => TRUE,
];

$search = SavedSearch::get(FALSE)->addSelect('id')->addWhere('name', '=', OPENAR_SEARCH)->execute()->first();
if ($search) {
  SavedSearch::update(FALSE)->addWhere('id', '=', $search['id'])->setValues($searchValues)->execute();
  $searchId = (int) $search['id'];
  echo "Saved search: updated (id {$searchId})\n";
}
else {
  $searchId = (int) SavedSearch::create(FALSE)->setValues($searchValues)->execute()->first()['id'];
  echo "Saved search: created (id {$searchId})\n";
}

$col = fn(string $key, string $label, array $extra = []) => $extra + [
  'type' => 'field',
  'key' => $key,
  'label' => $label,
  'sortable' => TRUE,
];

$displayValues = [
  'name' => OPENAR_DISPLAY,
  'label' => 'Members',
  'saved_search_id' => $searchId,
  'type' => 'table',
  'settings' => [
    'actions' => TRUE,
    'limit' => 50,
    'classes' => ['table', 'table-striped'],
    'pager' => ['show_count' => TRUE, 'expose_limit' => TRUE],
    'sort' => [['Membership.member_number', 'ASC']],
    'columns' => [
      $col('Membership.member_number', 'Member #'),
      // The name is the way into the record, so it carries the link rather
      // than making somebody hunt for a View action at the end of the row.
      $col('sort_name', 'Name', [
        'link' => ['entity' => 'Contact', 'action' => 'view', 'target' => '_blank'],
      ]),
      $col('job_title', 'Title'),
      $col('Membership.employer_affiliation', 'Employer or affiliation'),
      $col('email_primary.email', 'Email'),
      $col('Membership.email_confirmed_date', 'Confirmed'),
      // The whole point of storing a LinkedIn profile is that a reviewer can
      // open it, so the cell is the link rather than a URL to copy. The text
      // is rewritten because a full profile URL is long enough to set the
      // column width for the entire table.
      $col('Membership.linkedin_url', 'LinkedIn', [
        'link' => ['path' => '[Membership.linkedin_url]', 'target' => '_blank'],
        'rewrite' => 'Profile',
        'sortable' => FALSE,
      ]),
    ],
  ],
];

$display = SearchDisplay::get(FALSE)->addSelect('id')->addWhere('name', '=', OPENAR_DISPLAY)->execute()->first();
if ($display) {
  SearchDisplay::update(FALSE)->addWhere('id', '=', $display['id'])->setValues($displayValues)->execute();
  echo "Display:      updated (id {$display['id']})\n";
}
else {
  $id = SearchDisplay::create(FALSE)->setValues($displayValues)->execute()->first()['id'];
  echo "Display:      created (id {$id})\n";
}

/* ----------------------------------------------------------------- the menu -- */

$parent = Navigation::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', 'Contacts')
  ->addWhere('domain_id', '=', 'current_domain')
  ->execute()->first();

$navValues = [
  'label' => 'Members',
  'name' => 'openar_members_menu',
  'url' => 'civicrm/search#/display/' . OPENAR_SEARCH . '/' . OPENAR_DISPLAY,
  'permission' => ['access CiviCRM'],
  'parent_id' => $parent['id'] ?? NULL,
  'is_active' => TRUE,
  'weight' => 5,
];

$nav = Navigation::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', 'openar_members_menu')
  ->addWhere('domain_id', '=', 'current_domain')
  ->execute()->first();

if ($nav) {
  Navigation::update(FALSE)->addWhere('id', '=', $nav['id'])->setValues($navValues)->execute();
  echo "Menu:         updated\n";
}
else {
  Navigation::create(FALSE)->setValues($navValues)->execute();
  echo "Menu:         created under Contacts\n";
}

CRM_Core_BAO_Navigation::resetNavigation();

/* -------------------------------------------------------------- does it run? -- */

// Built through the API rather than by clicking, so it is worth proving the
// configuration is one CiviCRM can actually execute before calling it done.
try {
  $result = civicrm_api4('SearchDisplay', 'run', [
    'savedSearch' => OPENAR_SEARCH,
    'display' => OPENAR_DISPLAY,
    'checkPermissions' => FALSE,
  ]);
  printf("\nThe screen runs. %d member(s) listed:\n", count($result));
  foreach ($result as $r) {
    printf("  #%-4s %-26s %s\n",
      $r['columns'][0]['val'] ?? '?',
      $r['columns'][1]['val'] ?? '?',
      $r['columns'][2]['val'] ?? '');
  }
}
catch (\Throwable $e) {
  echo "\nERROR: the display was saved but will not run: " . $e->getMessage() . "\n";
}

echo "\nContacts > Members, in the CiviCRM menu.\n";
