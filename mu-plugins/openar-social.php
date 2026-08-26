<?php
/**
 * Plugin Name: OpenAR link previews
 * Description: Gives the public join pages the Open Graph tags a chat client
 *              or social network reads when somebody pastes a link, and stops
 *              WordPress advertising an oEmbed endpoint that describes the
 *              wrong page.
 * Version:     1.0.1
 * Author:      The OpenAR Collective
 *
 * Pasting https://join.openarcollective.org/sign/ produced a preview reading
 * "CiviCRM". The page title was already correct, so the cause was elsewhere:
 * the short URLs are internal rewrites onto the CiviCRM base page, WordPress
 * still believes the post being viewed is that base page, and the base page is
 * titled "CiviCRM". With no Open Graph tags to say otherwise, a preview
 * generator falls back to whatever it can find, and WordPress hands it an
 * oEmbed endpoint reporting the base page's title and the author's name.
 *
 * Two fixes, because different clients read different things. The tags below
 * cover anything reading Open Graph, which is most of them, and the oEmbed
 * discovery links are removed so nothing is left pointing at "CiviCRM".
 */

defined('ABSPATH') || exit;

/**
 * What each public route should say when it is shared.
 *
 * Keyed by the short path from openar-short-urls.php. The description is the
 * sentence a reader sees under the title in a chat client, so it says what the
 * page asks of them rather than describing the Foundation in general.
 */
const OPENAR_SOCIAL = [
  'apply' => [
    'title' => 'Apply for membership | The OpenAR Collective',
    'description' => 'Membership is free for anyone working in accounts receivable. There are no dues and no purchase requirement, and nothing the Foundation publishes requires membership to read.',
  ],
  'sign' => [
    'title' => 'Become a Mission Supporter | The OpenAR Collective',
    'description' => "Organizations can state publicly that they support the Foundation's mission. Signing costs nothing.",
  ],
];

const OPENAR_SOCIAL_FALLBACK = 'The Open Accounts Receivable Collective Foundation builds free, openly licensed software and publishes open compliance resources for the accounts receivable industry.';
// 1200x627, the ratio the networks crop to. The favicon that was here before
// is square, and LinkedIn cropped it to a band across the middle of the
// hexagon with the wordmark nowhere in the frame.
const OPENAR_SOCIAL_IMAGE = 'https://openarcollective.org/assets/social-card.png';

/**
 * The short path being served, or '' when this is not one of them.
 *
 * Read from the request rather than from the query, because the rewrite has
 * already turned /sign/ into the base page by the time WordPress answers, and
 * asking WordPress what is being viewed gives the wrong answer.
 */
function openar_social_route(): string {
  $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
  return isset(OPENAR_SOCIAL[$path]) ? $path : '';
}

/**
 * WordPress advertises an oEmbed endpoint for the post it thinks is being
 * viewed. On a short URL that is the CiviCRM base page, so the endpoint reports
 * the title "CiviCRM" and the author's personal name. Neither belongs in a
 * preview of a public form, and nothing needs to embed these pages.
 */
add_action('init', function () {
  remove_action('wp_head', 'wp_oembed_add_discovery_links');
});

add_action('wp_head', function () {
  $route = openar_social_route();

  if ($route !== '') {
    $title = OPENAR_SOCIAL[$route]['title'];
    $description = OPENAR_SOCIAL[$route]['description'];
    $url = home_url('/' . $route . '/');
  }
  else {
    // Any other public page: the document title is already right, so the tags
    // only have to stop a generator from going looking for something worse.
    $title = wp_get_document_title();
    $description = OPENAR_SOCIAL_FALLBACK;
    $url = home_url(add_query_arg([]));
  }

  $tags = [
    'og:type' => 'website',
    'og:site_name' => 'The OpenAR Collective',
    'og:title' => $title,
    'og:description' => $description,
    'og:url' => $url,
    'og:image' => OPENAR_SOCIAL_IMAGE,
  ];

  foreach ($tags as $property => $content) {
    printf("<meta property=\"%s\" content=\"%s\" />\n",
      esc_attr($property), esc_attr($content));
  }

  // Twitter reads its own names and falls back to Open Graph for the rest.
  // summary_large_image rather than summary: the card is 1200x627, and
  // summary would shrink it back to a small square thumbnail.
  printf("<meta name=\"twitter:card\" content=\"summary_large_image\" />\n");
  printf("<meta name=\"twitter:title\" content=\"%s\" />\n", esc_attr($title));
  printf("<meta name=\"twitter:description\" content=\"%s\" />\n", esc_attr($description));
  printf("<meta name=\"description\" content=\"%s\" />\n", esc_attr($description));
}, 2);
