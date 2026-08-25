<?php
/**
 * Plugin Name: OpenAR badges
 * Description: Draws the numbered member badge and locates the badge art the onboarding emails attach.
 * Version:     1.0.0
 * License:     Apache-2.0
 *
 * The member badge is the blank 1024px badge with the member number drawn over
 * the center hexagon, downscaled to 512px for email. It is a pure function of
 * the number, so it is drawn fresh for every send and never stored: there is
 * nothing to go stale, nothing to leak, and regenerating one on demand is the
 * same code path as the welcome email.
 *
 * The art and the font live in wp-content/mu-plugins/openar-assets, installed
 * by server/install-asset.php. The blank badge is pre-rendered rather than
 * drawn here because the badge itself is a designed object: arcs of outlined
 * type, a gradient, a hex-grid texture. Only the number varies per member, so
 * only the number is drawn at runtime.
 *
 * Geometry comes from the badge generator (openar-member-badge-generator.py in
 * the website project's working files): at 1024px the hexagon is centered at
 * (512, 365.5) and measures 340 across the vertices, flat top and bottom. The
 * number auto-sizes to fit inside the hexagon's slanted sides with a fixed
 * inset, and its height is capped so a single digit stays a number rather than
 * becoming a monument.
 *
 * Nothing here touches CiviCRM. Callers decide what a missing badge means;
 * here a problem is a NULL return and openar_badge_problem() says why the
 * prerequisites are not met.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

/** A file in the badge asset directory. */
function openar_badge_asset(string $file): string {
  return WPMU_PLUGIN_DIR . '/openar-assets/' . $file;
}

/**
 * Whether a member badge can be drawn here, and what is wrong if not.
 *
 * @return string Empty when everything needed is present, otherwise the
 *   problem in one line, written for the admin screen.
 */
function openar_badge_problem(): string {
  if (!function_exists('imagettftext') || !function_exists('imagecreatefrompng')) {
    return 'PHP GD with FreeType support is not available, so no badge can be drawn.';
  }
  if (!is_readable(openar_badge_asset('openar-member-badge-1024.png'))) {
    return 'The blank member badge (openar-assets/openar-member-badge-1024.png) is missing.';
  }
  if (!is_readable(openar_badge_asset('Barlow-Bold.ttf'))) {
    return 'The badge font (openar-assets/Barlow-Bold.ttf) is missing.';
  }
  return '';
}

/**
 * Draw one member's badge and write it to a temporary file.
 *
 * @return string|null The path to a PNG the caller owns and should unlink
 *   after use, or NULL when it could not be drawn.
 */
function openar_member_badge_create(int $number, int $size = 512): ?string {
  if ($number < 1 || openar_badge_problem() !== '') {
    return NULL;
  }

  $base = imagecreatefrompng(openar_badge_asset('openar-member-badge-1024.png'));
  if (!$base) {
    return NULL;
  }
  imagealphablending($base, TRUE);

  // The hexagon on the 1024px base: center, center-to-vertex, and
  // center-to-edge for the flat top and bottom.
  $cx = 512.0;
  $cy = 365.5;
  $r = 170.0;
  $h = $r * sqrt(3) / 2.0;
  $inset = 26.0;
  $maxHeight = 0.44 * 2.0 * $h;

  $font = openar_badge_asset('Barlow-Bold.ttf');
  $text = '#' . $number;

  // The largest size whose ink box fits. Measured with the box the glyphs
  // actually ink rather than the em box, so the loop is immune to whatever
  // unit imagettftext believes its size argument is in: the same measurement
  // that accepts a size is the one that rendering will honor.
  $fit = NULL;
  for ($pts = 240; $pts >= 16; $pts -= 2) {
    $box = imagettfbbox((float) $pts, 0.0, $font, $text);
    if ($box === FALSE) {
      break;
    }
    $left = min($box[0], $box[6]);
    $right = max($box[2], $box[4]);
    $top = min($box[5], $box[7]);
    $bottom = max($box[1], $box[3]);
    $w = $right - $left;
    $ht = $bottom - $top;
    if ($ht > $maxHeight || $ht / 2.0 > $h - $inset) {
      continue;
    }
    // Half-width of a flat-top hexagon at the ink box's top and bottom rows.
    // The sides slope inward, so the widest number must clear them there, not
    // at the center line.
    $avail = $r * (1.0 - ($ht / 2.0) / (2.0 * $h)) - $inset;
    if ($w / 2.0 <= $avail) {
      $fit = ['pts' => $pts, 'left' => $left, 'right' => $right, 'top' => $top, 'bottom' => $bottom];
      break;
    }
  }
  if (!$fit) {
    imagedestroy($base);
    return NULL;
  }

  $ink = imagecolorallocate($base, 14, 13, 11);
  if ($ink === FALSE) {
    imagedestroy($base);
    return NULL;
  }

  // Center the ink box on the hexagon's center. imagettftext places the
  // baseline origin, and the box was measured from that same origin.
  $x = (int) round($cx - ($fit['left'] + $fit['right']) / 2.0);
  $y = (int) round($cy - ($fit['top'] + $fit['bottom']) / 2.0);
  imagettftext($base, (float) $fit['pts'], 0.0, $x, $y, $ink, $font, $text);

  // Downscale with the alpha channel copied rather than blended, or the
  // transparent corners come out black.
  $out = imagecreatetruecolor($size, $size);
  if (!$out) {
    imagedestroy($base);
    return NULL;
  }
  imagealphablending($out, FALSE);
  imagesavealpha($out, TRUE);
  imagefill($out, 0, 0, (int) imagecolorallocatealpha($out, 0, 0, 0, 127));
  imagecopyresampled($out, $base, 0, 0, 0, 0, $size, $size, imagesx($base), imagesy($base));
  imagedestroy($base);

  $path = tempnam(get_temp_dir(), 'openar-badge-');
  if ($path === FALSE || !imagepng($out, $path)) {
    imagedestroy($out);
    if ($path !== FALSE) {
      @unlink($path);
    }
    return NULL;
  }
  imagedestroy($out);
  return $path;
}

/**
 * One member's badge as a sendTemplate attachment, or NULL.
 *
 * The caller unlinks fullPath once the send is done. CiviCRM reads the file
 * into the message and does not delete it.
 */
function openar_member_badge_attachment(int $number): ?array {
  $path = openar_member_badge_create($number);
  if ($path === NULL) {
    return NULL;
  }
  return [
    'fullPath' => $path,
    'mime_type' => 'image/png',
    'cleanName' => 'openar-member-badge-' . $number . '.png',
  ];
}

/**
 * The Mission Supporter badge as a sendTemplate attachment, or NULL.
 *
 * A static file straight from the asset directory: every organization gets the
 * same badge, so there is nothing to draw and nothing to clean up afterward.
 */
function openar_supporter_badge_attachment(): ?array {
  $path = openar_badge_asset('openar-mission-supporter-badge-512.png');
  if (!is_readable($path)) {
    return NULL;
  }
  return [
    'fullPath' => $path,
    'mime_type' => 'image/png',
    'cleanName' => 'openar-mission-supporter-badge.png',
  ];
}
