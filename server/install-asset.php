<?php
/**
 * Install a badge asset from a staged copy.
 *
 * The same arrangement as install-mu-plugin.php and for the same reason: the
 * destination belongs to www-data, and the only thing the deploy account may
 * run as www-data is wp itself. This one handles the files that are not PHP,
 * the badge art and its font, which the plugin installer rightly refuses.
 *
 * The destination is always wp-content/mu-plugins/openar-assets and the name
 * always comes from the staged file, so nothing outside that directory can be
 * written by passing a different argument. Only the file types the badges
 * actually use are accepted; this is an art drop, not a general uploader.
 *
 *   scp openar-assets/Barlow-Bold.ttf rob@host:/tmp/
 *   sudo -u www-data wp --path=/var/www/openarcollective.org \
 *     eval-file install-asset.php /tmp/Barlow-Bold.ttf
 */

$src = (string) ($args[0] ?? '');

if ($src === '' || !is_readable($src)) {
  echo "ERROR: cannot read the staged asset: {$src}\n";
  return;
}

$name = basename($src);
if (!preg_match('/^[A-Za-z0-9._-]+\.(png|ttf|txt|md)$/', $name)) {
  echo "ERROR: {$name} is not a badge asset (png, ttf, txt, or md)\n";
  return;
}

// PNG and TTF files must actually be what their names claim. mu-plugins sits
// inside the document root, so anything placed here is served to the world,
// and a check at the door is cheaper than an audit later.
$magic = (string) file_get_contents($src, FALSE, NULL, 0, 8);
if (str_ends_with($name, '.png') && substr($magic, 0, 4) !== "\x89PNG") {
  echo "ERROR: {$name} does not begin like a PNG, so it was not installed\n";
  return;
}
if (str_ends_with($name, '.ttf') && substr($magic, 0, 4) !== "\x00\x01\x00\x00") {
  echo "ERROR: {$name} does not begin like a TrueType font, so it was not installed\n";
  return;
}

$dir = WPMU_PLUGIN_DIR . '/openar-assets';

if (!is_dir($dir) && !mkdir($dir, 0755)) {
  echo "ERROR: could not create {$dir}\n";
  return;
}
if (!is_writable($dir)) {
  echo "ERROR: {$dir} is not writable by " . (get_current_user() ?: 'this user') . "\n";
  return;
}

$dest = "{$dir}/{$name}";

$before = is_readable($dest) ? md5_file($dest) : '';
$after = md5_file($src);

if ($before === $after) {
  echo "unchanged  {$name}\n";
  return;
}

if (!copy($src, $dest)) {
  echo "ERROR: could not write {$dest}\n";
  return;
}
chmod($dest, 0644);

echo ($before === '' ? 'installed  ' : 'updated    ') . $name
  . ' (' . number_format(filesize($dest)) . " bytes)\n";
