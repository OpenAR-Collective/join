<?php
/**
 * Snapshot every piece of configuration a provisioning script can overwrite,
 * into a git repository, so what changed is a diff rather than a guess.
 *
 * Included by the scripts that replace live configuration, and run before they
 * write anything. It exists because a script here destroyed the live membership
 * application form: the mission statement, the section headings and the
 * Community Participation Terms all vanished, because the script carried only
 * the field list and Afform::update replaces the whole layout.
 *
 * The guards in those scripts check that the script's own copy looks complete.
 * They cannot tell that the live form has something the script does not, which
 * is the case that actually hurt. This can. Every run overwrites the same
 * filenames and commits, so `git log -p` shows exactly what each script changed
 * and any earlier state can be restored with `git show`.
 *
 * What is kept: both Afform layouts, all OpenAR message templates, the brand
 * stylesheet, the custom field definitions, and a few settings.
 *
 * What is never kept: credentials, and any member or applicant record.
 *
 * The repository is local and deliberately has no remote. Everything in it is
 * either already in the public join repository or is drift away from it, and
 * the failure it defends against is a bad write on this machine.
 *
 * Created once, as root:
 *   mkdir -p /var/www/openar-snapshots
 *   chown www-data:www-data /var/www/openar-snapshots
 *   chmod 750 /var/www/openar-snapshots
 *
 * Record the current state:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file openar-snapshot.php
 *
 * Read the history as an ordinary user, no sudo needed:
 *   git -C /var/www/openar-snapshots log --oneline
 *   git -C /var/www/openar-snapshots log -p -- brand.css
 *   git -C /var/www/openar-snapshots show <commit>:message-templates.json
 *
 * Git may refuse a repository owned by another user. Once, as rob:
 *   git config --global --add safe.directory /var/www/openar-snapshots
 */

if (!function_exists('openar_snapshot')) {

  /** Run a git command in the snapshot repository. Returns [exitCode, output]. */
  function openar_snapshot_git(string $dir, array $args): array {
    // The committer identity is passed per command so git never needs a
    // writable HOME, which www-data does not reliably have.
    $cmd = 'git -c user.name=openar-snapshot -c user.email=bots@openarcollective.org'
      . ' -C ' . escapeshellarg($dir);
    foreach ($args as $a) {
      $cmd .= ' ' . escapeshellarg($a);
    }
    $out = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
  }

  /**
   * Record the current state of everything overwritable.
   *
   * @return string The repository written to, or '' if nothing was recorded.
   */
  function openar_snapshot(string $reason = 'manual'): string {
    $base = '';
    foreach (['/var/www/openar-snapshots', '/home/rob/openar-snapshots'] as $candidate) {
      if (is_dir($candidate) && is_writable($candidate)) {
        $base = $candidate;
        break;
      }
    }

    if ($base === '') {
      echo "WARNING: no snapshot directory, so nothing was saved before this write.\n";
      echo "         Create one once, as root:\n";
      echo "           mkdir -p /var/www/openar-snapshots\n";
      echo "           chown www-data:www-data /var/www/openar-snapshots\n";
      echo "           chmod 750 /var/www/openar-snapshots\n";
      return '';
    }

    $isRepo = is_dir("{$base}/.git");
    if (!$isRepo) {
      [$code, $out] = openar_snapshot_git($base, ['init', '-q', '-b', 'main']);
      $isRepo = ($code === 0 && is_dir("{$base}/.git"));
      if (!$isRepo) {
        echo "WARNING: could not create a git repository in {$base}: {$out}\n";
        echo "         Files are still written, but without history.\n";
      }
    }

    $wrote = [];

    // Form layouts. The thing that was lost.
    foreach (['afformMembershipApplication', 'afformSupporterStatement'] as $name) {
      try {
        $a = civicrm_api4('Afform', 'get', [
          'select' => ['name', 'layout'],
          'where' => [['name', '=', $name]],
          'layoutFormat' => 'html',
          'checkPermissions' => FALSE,
        ])->first();
        if (!empty($a['layout'])) {
          $layout = is_string($a['layout']) ? $a['layout'] : json_encode($a['layout']);
          file_put_contents("{$base}/{$name}.aff.html", $layout);
          $wrote[] = $name;
        }
      }
      catch (\Throwable $e) {
        echo "WARNING: could not snapshot {$name}: " . $e->getMessage() . "\n";
      }
    }

    // Every message template we own, including any hand edit made in the UI.
    try {
      $templates = civicrm_api4('MessageTemplate', 'get', [
        'select' => ['id', 'msg_title', 'msg_subject', 'msg_text', 'msg_html'],
        'where' => [['msg_title', 'LIKE', 'OpenAR%']],
        'orderBy' => ['msg_title' => 'ASC'],
        'checkPermissions' => FALSE,
      ]);
      file_put_contents("{$base}/message-templates.json",
        json_encode((array) $templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $wrote[] = 'message templates';
    }
    catch (\Throwable $e) {
      echo "WARNING: could not snapshot message templates: " . $e->getMessage() . "\n";
    }

    // The brand stylesheet, which lives only in a WordPress post.
    if (function_exists('wp_get_custom_css')) {
      $css = (string) wp_get_custom_css();
      if ($css !== '') {
        file_put_contents("{$base}/brand.css", $css);
        $wrote[] = 'brand.css';
      }
    }

    // Settings, with credentials stripped. mailing_backend holds the delivery
    // mode and the SMTP credentials in one array, so a careless write to it
    // destroys the mail configuration. That happened. The shape is worth
    // keeping even though the secrets deliberately are not.
    try {
      $settings = [];
      foreach (['mailing_backend', 'languageLimit', 'checksum_timeout'] as $name) {
        $value = Civi::settings()->get($name);
        if (is_array($value)) {
          foreach (['smtpPassword', 'smtpUsername'] as $secret) {
            if (isset($value[$secret]) && $value[$secret] !== '') {
              $value[$secret] = '(redacted, ' . strlen((string) $value[$secret]) . ' chars)';
            }
          }
        }
        $settings[$name] = $value;
      }
      file_put_contents("{$base}/settings.json",
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      $wrote[] = 'settings';
    }
    catch (\Throwable $e) {
      echo "WARNING: could not snapshot settings: " . $e->getMessage() . "\n";
    }

    // Custom field definitions, so a wrong edit to a field is recoverable too.
    try {
      $fields = civicrm_api4('CustomField', 'get', [
        'select' => ['custom_group_id.name', 'name', 'label', 'data_type', 'html_type',
                     'is_required', 'is_active', 'help_pre', 'help_post', 'weight'],
        'where' => [['custom_group_id.name', 'IN', ['Membership', 'MissionSupporter']]],
        'orderBy' => ['weight' => 'ASC'],
        'checkPermissions' => FALSE,
      ]);
      file_put_contents("{$base}/custom-fields.json",
        json_encode((array) $fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $wrote[] = 'custom fields';
    }
    catch (\Throwable $e) {
      echo "WARNING: could not snapshot custom fields: " . $e->getMessage() . "\n";
    }

    // Readable by rob, so the history can be read without sudo. Nothing here is
    // secret: it is all either in the public join repository already or is drift
    // away from it, and credentials are redacted before they are written.
    @chmod($base, 0755);
    foreach (glob("{$base}/*") as $f) {
      @chmod($f, 0644);
    }

    if (!$isRepo) {
      echo 'snapshot written to ' . $base . ', no history (' . implode(', ', $wrote) . ")\n";
      return $base;
    }

    // What changed, established before it is committed.
    [, $status] = openar_snapshot_git($base, ['status', '--porcelain']);

    if (trim($status) === '') {
      echo "snapshot: nothing has changed since the last one\n";
      return $base;
    }

    openar_snapshot_git($base, ['add', '-A']);
    [$code, $out] = openar_snapshot_git($base, ['commit', '-q', '-m', "before {$reason}"]);

    if ($code !== 0) {
      echo "WARNING: snapshot files written but not committed: {$out}\n";
      return $base;
    }

    [, $hash] = openar_snapshot_git($base, ['rev-parse', '--short', 'HEAD']);
    echo 'snapshot: ' . trim($hash) . " committed before {$reason}\n";
    foreach (explode("\n", trim($status)) as $line) {
      echo '  ' . trim($line) . "\n";
    }

    return $base;
  }
}

// Run directly rather than included: record the current state and say so.
if (!defined('OPENAR_SNAPSHOT_INCLUDED')) {
  civicrm_initialize();
  openar_snapshot('manual');
}
