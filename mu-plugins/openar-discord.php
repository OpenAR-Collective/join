<?php
/**
 * Plugin Name: OpenAR Discord connect
 * Description: Joins an admitted member to the Foundation's Discord server through OAuth2, with their role and name already set.
 * Version:     1.0.0
 * License:     Apache-2.0
 *
 * Two routes:
 *   /connect            the link in the welcome email, authenticated by CiviCRM checksum
 *   /connect/callback   where Discord returns
 *
 * Discord has no endpoint that resolves a username to a user, so a typed handle
 * can never be validated. OAuth removes the problem: Discord authenticates the
 * person and hands back their real identifier, and PUT guilds/{g}/members/{u}
 * accepts roles and nick in the same call, so a member arrives already roled and
 * named. No invite links are minted, so there is no invite list to clean up.
 *
 * Credentials live in wp-config.php, never in this repository. Everything here
 * is inert until they are defined, so the plugin can ship before the Discord
 * application exists.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const OPENAR_DISCORD_API = 'https://discord.com/api/v10';
const OPENAR_DISCORD_SCOPES = 'identify guilds.join';
const OPENAR_DISCORD_STATE_SCOPE = 'openarDiscordState';
const OPENAR_DISCORD_STATE_TTL = 900;
const OPENAR_DISCORD_NICK_LIMIT = 32;
const OPENAR_DISCORD_HELP = 'membership@openarcollective.org';

/** wp-config.php constants this needs. All five, or the plugin stays dormant. */
const OPENAR_DISCORD_SETTINGS = [
  'OPENAR_DISCORD_CLIENT_ID',
  'OPENAR_DISCORD_CLIENT_SECRET',
  'OPENAR_DISCORD_BOT_TOKEN',
  'OPENAR_DISCORD_GUILD_ID',
  'OPENAR_DISCORD_MEMBER_ROLE_ID',
];

function openar_discord_configured(): bool {
  foreach (OPENAR_DISCORD_SETTINGS as $name) {
    if (!defined($name) || constant($name) === '') {
      return FALSE;
    }
  }
  return TRUE;
}

function openar_discord_setting(string $name): string {
  return defined($name) ? (string) constant($name) : '';
}

function openar_discord_connect_url(): string {
  return home_url('/connect');
}

function openar_discord_redirect_uri(): string {
  return home_url('/connect/callback');
}

/* ------------------------------------------------------------------ routes -- */

add_action('init', function (): void {
  add_rewrite_rule('^connect/callback/?$', 'index.php?openar_discord=callback', 'top');
  add_rewrite_rule('^connect/?$', 'index.php?openar_discord=start', 'top');
});

add_filter('query_vars', function (array $vars): array {
  $vars[] = 'openar_discord';
  return $vars;
});

/**
 * The redirect URI is whitelisted in the Discord application character for
 * character, so WordPress must not helpfully append a trailing slash to it on
 * the way back.
 */
add_filter('redirect_canonical', function ($redirect) {
  return get_query_var('openar_discord') ? FALSE : $redirect;
});

add_action('init', function (): void {
  $signature = md5('openar-discord-routes-v1');
  if (get_option('openar_discord_routes') !== $signature) {
    flush_rewrite_rules(FALSE);
    update_option('openar_discord_routes', $signature, FALSE);
  }
}, 99);

add_action('template_redirect', function (): void {
  $action = get_query_var('openar_discord');
  if (!$action) {
    return;
  }

  nocache_headers();

  try {
    if ($action === 'start') {
      openar_discord_start();
    }
    elseif ($action === 'callback') {
      openar_discord_callback();
    }
  }
  catch (\Throwable $e) {
    \Civi::log()->error('OpenAR Discord: {msg}', ['msg' => $e->getMessage()]);
    openar_discord_page(
      'Something went wrong',
      '<p>The Foundation could not complete the connection. Nothing about your membership has changed.</p>'
      . '<p>Write to ' . openar_discord_help_link() . ' and someone will add you by hand.</p>'
    );
  }
  exit;
});

/* ------------------------------------------------------------------- start -- */

/**
 * The member arrived from the welcome email. Check who they are, then hand them
 * to Discord.
 */
function openar_discord_start(): void {
  if (!openar_discord_configured()) {
    openar_discord_page(
      'Discord is not connected yet',
      '<p>The Foundation has not finished setting up its Discord connection. Your membership is unaffected.</p>'
      . '<p>Write to ' . openar_discord_help_link() . ' for an invitation.</p>'
    );
    return;
  }

  civi_wp()->initialize();

  $contactId = (int) ($_GET['cid'] ?? 0);
  $checksum = (string) ($_GET['cs'] ?? '');

  if (!openar_discord_valid_member($contactId, $checksum)) {
    return;
  }

  $state = \Civi::service('crypto.jwt')->encode([
    'exp' => \CRM_Utils_Time::time() + OPENAR_DISCORD_STATE_TTL,
    'scope' => OPENAR_DISCORD_STATE_SCOPE,
    'cid' => $contactId,
    'cs' => $checksum,
  ]);

  $authorize = 'https://discord.com/oauth2/authorize?' . http_build_query([
    'client_id' => openar_discord_setting('OPENAR_DISCORD_CLIENT_ID'),
    'redirect_uri' => openar_discord_redirect_uri(),
    'response_type' => 'code',
    'scope' => OPENAR_DISCORD_SCOPES,
    'state' => $state,
    'prompt' => 'consent',
  ]);

  wp_redirect($authorize, 302, 'OpenAR');
}

/**
 * Validate the link and confirm they are actually a member. Renders the failure
 * page itself and returns false when anything is wrong.
 */
function openar_discord_valid_member(int $contactId, string $checksum): bool {
  if (!$contactId || !$checksum) {
    openar_discord_page(
      'That link is not complete',
      '<p>Open the link in your welcome email again, in full. If you copied it by hand, part of it may have been left behind.</p>'
      . '<p>If it still does not work, write to ' . openar_discord_help_link() . '.</p>'
    );
    return FALSE;
  }

  if (!\CRM_Contact_BAO_Contact_Utils::validChecksum($contactId, $checksum)) {
    openar_discord_page(
      'That link has expired',
      '<p>Connection links are good for a limited time, and this one has passed it. Your membership is unaffected.</p>'
      . '<p>Write to ' . openar_discord_help_link() . ' and a fresh link will be sent.</p>'
    );
    return FALSE;
  }

  // A valid checksum proves who they are, not that they were admitted. Only
  // members belong on the server.
  if (!function_exists('openar_in_group') || !openar_in_group($contactId, 'members')) {
    openar_discord_page(
      'Your membership is not active',
      '<p>This link belongs to a record that is not currently an active membership, so it cannot be used to join the server.</p>'
      . '<p>If you believe that is wrong, write to ' . openar_discord_help_link() . '.</p>'
    );
    return FALSE;
  }

  return TRUE;
}

/* ---------------------------------------------------------------- callback -- */

function openar_discord_callback(): void {
  if (!openar_discord_configured()) {
    openar_discord_page('Discord is not connected yet',
      '<p>Write to ' . openar_discord_help_link() . ' for an invitation.</p>');
    return;
  }

  civi_wp()->initialize();

  if (!empty($_GET['error'])) {
    // They declined the consent screen, which is a legitimate choice.
    openar_discord_page(
      'Nothing was connected',
      '<p>You chose not to give the Foundation permission to add you to its Discord server, so nothing has happened. Your membership is unaffected.</p>'
      . '<p>If you would rather be added by hand, or you changed your mind, write to ' . openar_discord_help_link() . '.</p>'
    );
    return;
  }

  $code = (string) ($_GET['code'] ?? '');
  $state = (string) ($_GET['state'] ?? '');

  if (!$code || !$state) {
    openar_discord_page('That did not come back correctly',
      '<p>Start again from the link in your welcome email. If it keeps failing, write to ' . openar_discord_help_link() . '.</p>');
    return;
  }

  try {
    $claims = \Civi::service('crypto.jwt')->decode($state);
  }
  catch (\Throwable $e) {
    openar_discord_page('That sign-in took too long',
      '<p>Open the link in your welcome email and try again. If it keeps failing, write to ' . openar_discord_help_link() . '.</p>');
    return;
  }

  if (($claims['scope'] ?? '') !== OPENAR_DISCORD_STATE_SCOPE) {
    openar_discord_page('That request was not recognized',
      '<p>Start again from the link in your welcome email.</p>');
    return;
  }

  $contactId = (int) ($claims['cid'] ?? 0);
  if (!openar_discord_valid_member($contactId, (string) ($claims['cs'] ?? ''))) {
    return;
  }

  $token = openar_discord_exchange_code($code);
  if (!$token) {
    openar_discord_page('Discord did not confirm the sign-in',
      '<p>Nothing has changed. Try the link again, or write to ' . openar_discord_help_link() . '.</p>');
    return;
  }

  $discordUser = openar_discord_identify($token);
  if (!$discordUser) {
    openar_discord_page('Discord did not say who you are',
      '<p>Nothing has changed. Try the link again, or write to ' . openar_discord_help_link() . '.</p>');
    return;
  }

  $joined = openar_discord_add_to_guild($contactId, $discordUser, $token);
  if (!$joined) {
    openar_discord_page('The Foundation could not add you to the server',
      '<p>Your membership is unaffected. Write to ' . openar_discord_help_link() . ' and someone will add you by hand.</p>');
    return;
  }

  openar_discord_record($contactId, $discordUser);

  // Someone who outranks the bot keeps the roles and nickname they already had.
  // Say so rather than dropping them into the server wondering whether it
  // worked, since for them nothing visibly changed.
  if (openar_discord_outranked()) {
    openar_discord_page('You are already in the server',
      '<p>Your Discord account is now linked to your membership record.</p>'
      . '<p>Your existing roles and nickname were left exactly as they were. You already hold '
      . 'permissions above the Foundation&rsquo;s bot, so it is not allowed to change them, and it '
      . 'has not tried. Nothing about your access has altered.</p>'
      . '<p><a href="https://discord.com/channels/'
      . rawurlencode(openar_discord_setting('OPENAR_DISCORD_GUILD_ID'))
      . '">Open the Discord server</a></p>');
    return;
  }

  wp_redirect('https://discord.com/channels/' . openar_discord_setting('OPENAR_DISCORD_GUILD_ID'), 302, 'OpenAR');
}

/* ------------------------------------------------------------ discord calls -- */

function openar_discord_exchange_code(string $code): ?string {
  $response = openar_discord_http('POST', OPENAR_DISCORD_API . '/oauth2/token', [
    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    'body' => [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => openar_discord_redirect_uri(),
      'client_id' => openar_discord_setting('OPENAR_DISCORD_CLIENT_ID'),
      'client_secret' => openar_discord_setting('OPENAR_DISCORD_CLIENT_SECRET'),
    ],
  ]);

  if ($response['code'] !== 200 || empty($response['body']['access_token'])) {
    \Civi::log()->error('OpenAR Discord: token exchange returned {code}', ['code' => $response['code']]);
    return NULL;
  }

  return (string) $response['body']['access_token'];
}

function openar_discord_identify(string $accessToken): ?array {
  $response = openar_discord_http('GET', OPENAR_DISCORD_API . '/users/@me', [
    'headers' => ['Authorization' => 'Bearer ' . $accessToken],
  ]);

  if ($response['code'] !== 200 || empty($response['body']['id'])) {
    \Civi::log()->error('OpenAR Discord: /users/@me returned {code}', ['code' => $response['code']]);
    return NULL;
  }

  return $response['body'];
}

/**
 * Add them to the guild with their role and name already set.
 *
 * PUT returns 201 when it actually adds someone, and 204 when they were already
 * in the guild. On 204 it silently ignores the roles and nick that were sent, so
 * anyone who wandered in through a public invite would otherwise finish this
 * flow roleless while the code reported success. Hence the follow-up.
 */
function openar_discord_add_to_guild(int $contactId, array $discordUser, string $accessToken): bool {
  $guildId = openar_discord_setting('OPENAR_DISCORD_GUILD_ID');
  $roleId = openar_discord_setting('OPENAR_DISCORD_MEMBER_ROLE_ID');
  $userId = (string) $discordUser['id'];
  $nick = openar_discord_nickname($contactId);

  $put = openar_discord_http('PUT', OPENAR_DISCORD_API . "/guilds/{$guildId}/members/{$userId}", [
    'headers' => [
      'Authorization' => 'Bot ' . openar_discord_setting('OPENAR_DISCORD_BOT_TOKEN'),
      'Content-Type' => 'application/json',
    ],
    'body' => wp_json_encode(array_filter([
      'access_token' => $accessToken,
      'roles' => [$roleId],
      'nick' => $nick,
    ])),
  ]);

  if ($put['code'] === 201) {
    return TRUE;
  }

  if ($put['code'] !== 204) {
    \Civi::log()->error('OpenAR Discord: guild add returned {code} {body}', [
      'code' => $put['code'],
      'body' => wp_json_encode($put['body']),
    ]);
    return FALSE;
  }

  // Already in the guild. Merge the Member role into whatever they hold rather
  // than replacing, so an existing moderator does not lose their roles here.
  $existing = openar_discord_http('GET', OPENAR_DISCORD_API . "/guilds/{$guildId}/members/{$userId}", [
    'headers' => ['Authorization' => 'Bot ' . openar_discord_setting('OPENAR_DISCORD_BOT_TOKEN')],
  ]);

  $roles = [$roleId];
  if ($existing['code'] === 200 && !empty($existing['body']['roles'])) {
    $roles = array_values(array_unique(array_merge((array) $existing['body']['roles'], [$roleId])));
  }

  $patch = openar_discord_http('PATCH', OPENAR_DISCORD_API . "/guilds/{$guildId}/members/{$userId}", [
    'headers' => [
      'Authorization' => 'Bot ' . openar_discord_setting('OPENAR_DISCORD_BOT_TOKEN'),
      'Content-Type' => 'application/json',
    ],
    'body' => wp_json_encode(array_filter([
      'roles' => $roles,
      'nick' => $nick,
    ])),
  ]);

  if ($patch['code'] === 200) {
    return TRUE;
  }

  // Discord refuses to let a bot change the roles or nickname of anyone whose
  // highest role sits above the bot's, and returns 50013 for it. No bot
  // permission overrides that; hierarchy always wins. It is the normal outcome
  // for the Foundation's own admins and moderators, who already outrank the
  // bot by design.
  //
  // They are in the server, which was the point. Reporting "could not add you"
  // to someone who is already sitting in the guild with more access than the
  // bot has is simply wrong, so this counts as success and the page says what
  // was and was not done.
  $discordCode = (int) ($patch['body']['code'] ?? 0);
  if ($patch['code'] === 403 && $discordCode === 50013) {
    \Civi::log()->info('OpenAR Discord: contact {cid} is already in the guild and outranks the bot, so roles and nickname were left alone', [
      'cid' => $contactId,
    ]);
    openar_discord_outranked(TRUE);
    return TRUE;
  }

  \Civi::log()->error('OpenAR Discord: role and nickname patch returned {code} {body}', [
    'code' => $patch['code'],
    'body' => wp_json_encode($patch['body']),
  ]);
  return FALSE;
}

/**
 * Whether this request hit the role hierarchy, carried from the guild call to
 * the page that reports the outcome.
 */
function openar_discord_outranked(?bool $set = NULL): bool {
  static $outranked = FALSE;
  if ($set !== NULL) {
    $outranked = $set;
  }
  return $outranked;
}

/** Their real name, since Foundation spaces run under real names. Discord caps this at 32. */
function openar_discord_nickname(int $contactId): string {
  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('display_name')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  $name = trim((string) ($contact['display_name'] ?? ''));

  return $name === '' ? '' : mb_substr($name, 0, OPENAR_DISCORD_NICK_LIMIT);
}

/** Keep the Discord user id, which is the only durable handle once a display name changes. */
function openar_discord_record(int $contactId, array $discordUser): void {
  $userId = (string) $discordUser['id'];

  $previous = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('Membership.discord_user_id')
    ->addWhere('id', '=', $contactId)
    ->execute()->first()['Membership.discord_user_id'] ?? '';

  \Civi\Api4\Contact::update(FALSE)
    ->addWhere('id', '=', $contactId)
    ->addValue('Membership.discord_user_id', $userId)
    ->execute();

  $note = ($previous !== '' && $previous !== $userId)
    ? sprintf('Connected Discord account %s, replacing %s.', $userId, $previous)
    : sprintf('Connected Discord account %s.', $userId);

  \Civi\Api4\Activity::create(FALSE)
    ->addValue('activity_type_id:name', 'Email')
    ->addValue('subject', 'Discord account connected')
    ->addValue('details', $note)
    ->addValue('status_id:name', 'Completed')
    ->addValue('source_contact_id', (int) (\CRM_Core_BAO_Domain::getDomain()->contact_id ?? $contactId))
    ->addValue('target_contact_id', [$contactId])
    ->execute();
}

/* ------------------------------------------------------------------- plumbing -- */

/**
 * One place every Discord request goes through, so tests can stand in for it.
 *
 * Returning a non-null array from the openar_discord_http filter short-circuits
 * the real call.
 */
function openar_discord_http(string $method, string $url, array $args = []): array {
  $stub = apply_filters('openar_discord_http', NULL, $method, $url, $args);
  if (is_array($stub)) {
    return $stub;
  }

  $args['method'] = $method;
  $args['timeout'] = 15;

  $response = wp_remote_request($url, $args);

  if (is_wp_error($response)) {
    \Civi::log()->error('OpenAR Discord: {method} {url} failed: {msg}', [
      'method' => $method,
      'url' => $url,
      'msg' => $response->get_error_message(),
    ]);
    return ['code' => 0, 'body' => []];
  }

  $body = json_decode((string) wp_remote_retrieve_body($response), TRUE);

  return [
    'code' => (int) wp_remote_retrieve_response_code($response),
    'body' => is_array($body) ? $body : [],
  ];
}

function openar_discord_help_link(): string {
  return '<a href="mailto:' . OPENAR_DISCORD_HELP . '">' . OPENAR_DISCORD_HELP . '</a>';
}

/** A plain, self-contained page. Every failure route ends somewhere a person can read. */
function openar_discord_page(string $heading, string $body): void {
  status_header(200);
  header('Content-Type: text/html; charset=utf-8');

  $safeHeading = esc_html($heading);

  echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex" />
<title>{$safeHeading} - OpenAR Collective</title>
<style>
  :root { color-scheme: dark; }
  body { margin: 0; background: #161410; color: #efe9df;
         font-family: system-ui, -apple-system, "Segoe UI", Arial, sans-serif; line-height: 1.6; }
  main { max-width: 34rem; margin: 0 auto; padding: 4rem 1.5rem; }
  h1 { font-size: 1.5rem; font-weight: 600; margin: 0 0 1rem; }
  p { color: #cfc7ba; margin: 0 0 1rem; }
  a { color: #e8a020; }
  .rule { height: 3px; width: 3rem; background: #e8a020; margin-bottom: 2rem; }
  footer { margin-top: 2.5rem; font-size: .9rem; color: #8d857a; }
</style>
</head>
<body>
<main>
  <div class="rule"></div>
  <h1>{$safeHeading}</h1>
  {$body}
  <footer>The Open Accounts Receivable Collective Foundation</footer>
</main>
</body>
</html>
HTML;
}
