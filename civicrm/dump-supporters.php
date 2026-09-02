<?php
/**
 * Write the published Mission Supporters to a JSON file.
 *
 * The roster sync runs on this server rather than calling in over the internet,
 * so there is no API key, no site key, and no Cloudflare in the path. This dump
 * produces exactly the shape the API would have returned, which is the same
 * shape sync-supporters.py already accepts through CIVI_FIXTURE.
 *
 *   sudo -u www-data wp --path=/var/www/openarcollective.org \
 *     eval-file dump-supporters.php /home/rob/openar-roster/supporters.json
 *
 * Prints a summary only. The file is the output.
 */

civicrm_initialize();

const GROUP_NAME = 'supporters_published';

$target = $args[0] ?? '';
if ($target === '') {
  echo "usage: eval-file dump-supporters.php <output-path>\n";
  return;
}

$group = civicrm_api4('Group', 'get', [
  'select' => ['id', 'title'],
  'where' => [['name', '=', GROUP_NAME]],
  'checkPermissions' => FALSE,
])->first();

if (!$group) {
  fwrite(STDERR, "ERROR: no group named " . GROUP_NAME . "\n");
  exit(1);
}

// Filtering by group takes an id. Passing the name or title here is a DB syntax
// error, which is what made the original version of the sync unusable.
$rows = civicrm_api4('Contact', 'get', [
  'select' => [
    'organization_name',
    'display_name',
    'MissionSupporter.trade_name',
    'MissionSupporter.website_url',
  ],
  'where' => [
    ['groups', 'IN', [(int) $group['id']]],
    ['is_deleted', '=', FALSE],
    ['contact_type', '=', 'Organization'],
  ],
  'orderBy' => ['organization_name' => 'ASC'],
  'limit' => 0,
  'checkPermissions' => FALSE,
]);

$out = [];
foreach ($rows as $r) {
  $out[] = [
    'organization_name' => $r['organization_name'] ?? '',
    'display_name' => $r['display_name'] ?? '',
    'MissionSupporter.trade_name' => $r['MissionSupporter.trade_name'] ?? '',
    'MissionSupporter.website_url' => $r['MissionSupporter.website_url'] ?? '',
  ];
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

// Written through a temporary file so a crash mid-write cannot leave the sync
// reading a truncated roster and deleting everyone from the site.
$tmp = $target . '.tmp';
if (file_put_contents($tmp, $json) === FALSE || !rename($tmp, $target)) {
  fwrite(STDERR, "ERROR: could not write {$target}\n");
  exit(1);
}
@chmod($target, 0644);

echo count($out) . " published supporter(s) written to {$target}\n";
foreach ($out as $o) {
  echo "  " . ($o['MissionSupporter.trade_name'] ?: $o['organization_name']) . "\n";
}
