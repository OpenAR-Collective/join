<?php
/**
 * Rename the message templates onto their grouping prefixes.
 *
 * The titles all began "OpenAR - ", which distinguishes nothing inside our
 * own CiviCRM, so the picker showed twenty templates blended together. The
 * prefix now names the pipeline: Automated Membership and Automated
 * Supporter for the mail the plugins send, Member News and Marketing for the
 * mail a person composes. Titles are internal; no recipient sees them.
 *
 * The plugins look templates up by exact title, so this runs in the same
 * breath as installing the plugin versions that expect the new names.
 * CiviCRM's three sample newsletter templates are deactivated while we are
 * here, because a picker should not offer what will never be sent.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file rename-template-prefixes.php
 */

civicrm_initialize();

// Keep a copy of whatever is live before replacing it.
define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
require_once __DIR__ . '/openar-snapshot.php';
openar_snapshot(basename(__FILE__, '.php'));

$groups = [
  'Automated Membership' => [
    'Confirm your email address',
    'New membership application for review',
    'You are already a member',
    'Your application is already with us',
    'Welcome to the Collective',
    'Membership application declined',
    'Decline recorded without a reason',
    'Membership revoked',
    'Revocation recorded without a reason',
    'Your Discord link, again',
    'Your member badge',
  ],
  'Automated Supporter' => [
    'Confirm your Statement of Support',
    'New Statement of Support for review',
    'Your organization is now listed',
    'Statement of Support declined',
    'Mission Supporter participation revoked',
    'Your Mission Supporter badge',
  ],
  'Member News' => [
    'Brainstorm 2026 members note',
    'September 2026 members update',
  ],
  'Marketing' => [
    'Brainstorm 2026 prospect blast',
  ],
];

$renamed = 0;
$already = 0;
$missing = 0;

foreach ($groups as $prefix => $names) {
  foreach ($names as $name) {
    $old = "OpenAR - {$name}";
    $new = "{$prefix} - {$name}";

    if (civicrm_api4('MessageTemplate', 'get', [
      'select' => ['id'],
      'where' => [['msg_title', '=', $new]],
      'checkPermissions' => FALSE,
    ])->count()) {
      $already++;
      continue;
    }

    $row = civicrm_api4('MessageTemplate', 'get', [
      'select' => ['id'],
      'where' => [['msg_title', '=', $old]],
      'checkPermissions' => FALSE,
    ])->first();
    if (!$row) {
      echo "MISSING under either name: {$name}\n";
      $missing++;
      continue;
    }

    civicrm_api4('MessageTemplate', 'update', [
      'where' => [['id', '=', $row['id']]],
      'values' => ['msg_title' => $new],
      'checkPermissions' => FALSE,
    ]);
    echo "renamed {$row['id']}: {$new}\n";
    $renamed++;
  }
}

$samples = civicrm_api4('MessageTemplate', 'update', [
  'where' => [
    ['msg_title', 'LIKE', 'Sample %'],
    ['workflow_name', 'IS EMPTY'],
    ['is_active', '=', TRUE],
  ],
  'values' => ['is_active' => FALSE],
  'checkPermissions' => FALSE,
])->count();

echo "done: {$renamed} renamed, {$already} already renamed, {$missing} missing, "
  . "{$samples} sample template(s) deactivated\n";
