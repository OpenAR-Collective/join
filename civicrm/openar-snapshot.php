<?php
/**
 * Snapshot every piece of configuration a provisioning script can overwrite.
 *
 * Included by the scripts that replace live configuration, and run before they
 * write anything. It exists because a script here destroyed the live membership
 * application form: the mission statement, the section headings and the
 * Community Participation Terms all vanished because the script carried only
 * the field list, and Afform::update replaces the whole layout.
 *
 * The guards in those scripts check that the script's own copy looks complete.
 * They cannot tell that the live form has something the script does not, which
 * is the case that actually hurt. This can: it keeps the old copy so the damage
 * is a restore rather than an archaeology exercise.
 *
 * Snapshots land in /home/rob/openar-snapshots/<timestamp>-<reason>/ and are
 * never pruned automatically. They are small and the machine has room.
 *
 * That directory is created once by rob, mode 1777, because these scripts run
 * as www-data and www-data cannot write into rob's home. Sticky and
 * world-writable is the same arrangement as /tmp, and costs nothing here: a
 * snapshot only ever holds a form layout, an email template, a stylesheet or a
 * field definition, every one of which is already in the public repository. No
 * credential and no member record is written to one.
 *
 *   mkdir -p /home/rob/openar-snapshots && chmod 1777 /home/rob/openar-snapshots
 *
 * On its own:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file openar-snapshot.php
 */

if (!function_exists('openar_snapshot')) {

  /**
   * Write the current state of everything overwritable, and report what it saw.
   *
   * @return string The directory written to, or '' if it could not write.
   */
  function openar_snapshot(string $reason = 'manual'): string {
    $base = '/home/rob/openar-snapshots';
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $reason) ?: 'run';
    $dir = $base . '/' . date('Ymd-His') . '-' . strtolower(trim($slug, '-'));

    if (!is_dir($base)) {
      echo "WARNING: {$base} does not exist, so nothing was snapshotted.\n";
      echo "         Create it once:  mkdir -p {$base} && chmod 1777 {$base}\n";
      return '';
    }

    if (!is_dir($dir) && !@mkdir($dir, 0755, TRUE)) {
      echo "WARNING: could not create {$dir}; continuing without a snapshot\n";
      return '';
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
          file_put_contents("{$dir}/{$name}.aff.html", $layout);
          $wrote[] = "{$name}.aff.html (" . strlen($layout) . ')';
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
        'checkPermissions' => FALSE,
      ]);
      if (count($templates)) {
        file_put_contents("{$dir}/message-templates.json",
          json_encode((array) $templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $wrote[] = 'message-templates.json (' . count($templates) . ' templates)';
      }
    }
    catch (\Throwable $e) {
      echo "WARNING: could not snapshot message templates: " . $e->getMessage() . "\n";
    }

    // The brand stylesheet, which lives only in a WordPress post.
    if (function_exists('wp_get_custom_css')) {
      $css = (string) wp_get_custom_css();
      if ($css !== '') {
        file_put_contents("{$dir}/brand.css", $css);
        $wrote[] = 'brand.css (' . strlen($css) . ')';
      }
    }

    // Custom field definitions, so a wrong edit to a field is recoverable too.
    try {
      $fields = civicrm_api4('CustomField', 'get', [
        'select' => ['custom_group_id.name', 'name', 'label', 'data_type', 'html_type',
                     'is_required', 'is_active', 'help_pre', 'help_post', 'weight'],
        'where' => [['custom_group_id.name', 'IN', ['Membership', 'MissionSupporter']]],
        'checkPermissions' => FALSE,
      ]);
      file_put_contents("{$dir}/custom-fields.json",
        json_encode((array) $fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $wrote[] = 'custom-fields.json (' . count($fields) . ' fields)';
    }
    catch (\Throwable $e) {
      echo "WARNING: could not snapshot custom fields: " . $e->getMessage() . "\n";
    }

    @chmod($dir, 0755);
    foreach (glob("{$dir}/*") as $f) {
      @chmod($f, 0644);
    }

    echo "snapshot: {$dir}\n";
    foreach ($wrote as $w) {
      echo "  {$w}\n";
    }

    return $dir;
  }
}

// Run directly rather than included: take a snapshot and say so.
if (!defined('OPENAR_SNAPSHOT_INCLUDED')) {
  civicrm_initialize();
  openar_snapshot('manual');
}
