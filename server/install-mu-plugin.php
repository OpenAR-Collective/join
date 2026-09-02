<?php
/**
 * Install a must-use plugin from a staged copy.
 *
 * wp-content/mu-plugins belongs to www-data, and the only thing this account may
 * run as www-data is wp itself. So a plugin is staged somewhere writable, then
 * put in place by this, which wp runs as the web user. That is narrower than
 * handing out sudo on cp, and it is the same path every time rather than a
 * remembered one-liner.
 *
 * The destination is always mu-plugins and the name always comes from the
 * staged file, so nothing outside that directory can be written by passing a
 * different argument.
 *
 * Syntax is checked before anything is replaced. A must-use plugin that does not
 * parse takes down the whole site, wp-admin included, and leaves no way in
 * except a shell.
 *
 *   scp openar-admin.php rob@host:/tmp/
 *   sudo -u www-data wp --path=/var/www/openarcollective.org \
 *     eval-file install-mu-plugin.php /tmp/openar-admin.php
 */

$src = (string) ($args[0] ?? '');

if ($src === '' || !is_readable($src)) {
  echo "ERROR: cannot read the staged plugin: {$src}\n";
  return;
}

$name = basename($src);
if (!preg_match('/^[a-z0-9-]+\.php$/', $name)) {
  echo "ERROR: {$name} is not a plausible plugin filename\n";
  return;
}

// -l writes to stdout and returns non-zero on a parse error.
$check = [];
$status = 0;
exec('php -l ' . escapeshellarg($src) . ' 2>&1', $check, $status);
if ($status !== 0) {
  echo "ERROR: {$name} does not parse, so it was not installed:\n";
  echo implode("\n", $check) . "\n";
  return;
}

$dir = WPMU_PLUGIN_DIR;
$dest = "{$dir}/{$name}";

if (!is_dir($dir) || !is_writable($dir)) {
  echo "ERROR: {$dir} is not writable by " . (get_current_user() ?: 'this user') . "\n";
  return;
}

// An earlier version of this script kept its backup beside the original. Clear
// any left behind, wherever this runs: PHP will not execute a .bak, but nginx
// will serve it as text to anyone who guesses the name.
if (file_exists("{$dest}.bak")) {
  echo(@unlink("{$dest}.bak")
    ? "removed  {$name}.bak, which was web readable\n"
    : "WARNING: {$name}.bak is web readable and could not be removed\n");
}

$before = is_readable($dest) ? md5_file($dest) : '';
$after = md5_file($src);

if ($before === $after) {
  echo "unchanged  {$name}\n";
  return;
}

// Keep the copy being replaced, so reverting a bad install is a file move
// rather than a scp from a laptop that may not be to hand. It goes to the
// temp directory and never beside the original: mu-plugins is inside the
// document root, and a .bak there is not run by PHP but is happily served as
// plain text to anyone who asks for it.
$kept = '';
if ($before !== '') {
  $kept = rtrim(get_temp_dir(), '/') . "/{$name}.previous";
  copy($dest, $kept);
}

if (!copy($src, $dest)) {
  echo "ERROR: could not write {$dest}\n";
  return;
}
chmod($dest, 0644);

echo ($before === '' ? 'installed  ' : 'updated    ') . $name
  . ' (' . number_format(filesize($dest)) . " bytes)\n";
if ($kept !== '') {
  echo "previous copy kept at {$kept}\n";
}
