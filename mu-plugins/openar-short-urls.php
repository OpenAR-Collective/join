<?php
/**
 * Plugin Name: OpenAR short URLs
 * Description: Maps join.openarcollective.org/apply and /sign to the CiviCRM
 *              membership and Mission Supporter forms as internal rewrites, so
 *              the short path is what visitors see and share.
 * Version:     1.0.0
 * Author:      The OpenAR Collective
 *
 * CiviCRM's own rule claims everything under the base page prefix. These are
 * exact-path rules registered at the same priority, so they coexist with it
 * and with ordinary WordPress pages. Changing a target here does not require
 * touching CiviCRM.
 */

defined('ABSPATH') || exit;

const OPENAR_SHORT_URLS = [
  'apply' => 'civicrm/membership-application',
  'sign'  => 'civicrm/supporter-statement',
];

/**
 * Resolve the CiviCRM base page id once per request. The rewrite target has to
 * name the base page explicitly, exactly as CiviCRM's own rule does.
 */
function openar_basepage_id(): ?int {
  static $id = NULL;
  if ($id !== NULL) {
    return $id ?: NULL;
  }
  $id = 0;
  if (function_exists('civi_wp') && civi_wp()->initialize()) {
    $slug = CRM_Core_Config::singleton()->wpBasePage;
    $page = $slug ? get_page_by_path($slug) : NULL;
    if ($page) {
      $id = (int) $page->ID;
    }
  }
  return $id ?: NULL;
}

add_action('init', function () {
  $basepage = openar_basepage_id();
  if (!$basepage) {
    return;
  }
  foreach (OPENAR_SHORT_URLS as $short => $route) {
    add_rewrite_rule(
      '^' . $short . '/?$',
      'index.php?page_id=' . $basepage . '&civiwp=CiviCRM&q=' . urlencode($route),
      'top'
    );
  }
});

/**
 * Flush rewrites only when the rule set actually changes, so this never costs
 * anything on a normal request.
 */
add_action('init', function () {
  $signature = md5(serialize(OPENAR_SHORT_URLS) . openar_basepage_id());
  if (get_option('openar_short_urls_signature') !== $signature) {
    flush_rewrite_rules(FALSE);
    update_option('openar_short_urls_signature', $signature, FALSE);
  }
}, 99);
