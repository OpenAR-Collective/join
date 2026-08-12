<?php
/**
 * Plugin Name: OpenAR onboarding admin
 * Description: A screen for the parts of onboarding that CiviCRM cannot show, chiefly unconfirmed applications.
 * Version:     1.0.0
 * License:     Apache-2.0
 *
 * CiviCRM's own Submissions screen lists form submissions, but for an
 * unconfirmed application it cannot say who is waiting: the "Submitted by"
 * column is the logged-in user, which for a public applicant is nobody, and
 * the applicant's name and address live inside the submission's data blob
 * where no SearchKit column reaches.
 *
 * That leaves the one question worth asking, who is waiting and has their link
 * run out, answerable only from a terminal. This is that question as a screen.
 *
 * Lives under Tools so it sits with the site rather than inside CiviCRM's
 * administration tree, and it is reachable only through wp-admin, which is
 * already behind Cloudflare Access.
 *
 * The same information also appears as a Dashboard widget, because the thing
 * most likely to be missed is an application nobody knew was waiting, and a
 * screen you have to remember to visit does not fix that.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

const OPENAR_ADMIN_SLUG = 'openar-onboarding';
const OPENAR_ADMIN_CAP = 'manage_options';

add_action('admin_menu', 'openar_admin_menu');
add_action('wp_dashboard_setup', 'openar_admin_dashboard_widget');

function openar_admin_menu(): void {
  add_management_page(
    'OpenAR onboarding',
    'OpenAR onboarding',
    OPENAR_ADMIN_CAP,
    OPENAR_ADMIN_SLUG,
    'openar_admin_page'
  );
}

/**
 * Whether outbound mail is actually going out, and what is wrong if not.
 *
 * Testing switches CiviCRM to "Redirect to Database" so nothing reaches a real
 * inbox, and leaving it there silently stops every confirmation, review
 * notification and welcome. It has happened, and it was noticed by a person
 * rather than by the system. A note nobody has to remember to look for is
 * worth more than a script somebody has to remember to run.
 *
 * @return string Empty when mail is fine, otherwise the problem in one line.
 */
function openar_admin_mail_problem(): string {
  if (!function_exists('civi_wp')) {
    return '';
  }
  civi_wp()->initialize();

  $mb = (array) \Civi::settings()->get('mailing_backend');
  $mode = (int) ($mb['outBound_option'] ?? 0);

  if ($mode === 5) {
    return 'Outbound email is set to Redirect to Database, so nothing is being delivered. '
      . 'Every confirmation link, review notification and welcome email is being written to a table instead of sent.';
  }
  if ($mode === 2) {
    return 'Outbound email is disabled, so nothing is being delivered.';
  }
  if ($mode === 0 && empty($mb['smtpServer'])) {
    return 'Outbound email is set to SMTP but no server is configured, so sending will fail.';
  }
  if ($mode === 0 && !empty($mb['smtpAuth']) && empty($mb['smtpPassword'])) {
    return 'Outbound email is set to SMTP with authentication on, but no password is stored.';
  }

  return '';
}

/** Pending submissions, with the applicant dug out of the data blob. */
function openar_admin_pending(): array {
  if (!function_exists('civi_wp')) {
    return [];
  }
  civi_wp()->initialize();

  $rows = [];
  $lifetime = defined('OPENAR_VERIFY_LIFETIME_DAYS') ? OPENAR_VERIFY_LIFETIME_DAYS : 7;

  foreach (civicrm_api4('AfformSubmission', 'get', [
    'select' => ['id', 'afform_name', 'submission_date', 'data'],
    'where' => [['status_id:name', '=', 'Pending']],
    'orderBy' => ['submission_date' => 'DESC'],
    'checkPermissions' => FALSE,
  ]) as $s) {
    $data = is_string($s['data']) ? json_decode($s['data'], TRUE) : $s['data'];
    $data = is_array($data) ? $data : [];

    $find = function (array $tree, string $key) use (&$find) {
      foreach ($tree as $k => $v) {
        if ($k === $key && is_scalar($v) && trim((string) $v) !== '') {
          return trim((string) $v);
        }
        if (is_array($v)) {
          $hit = $find($v, $key);
          if ($hit !== NULL) {
            return $hit;
          }
        }
      }
      return NULL;
    };

    // The membership form keeps the address as an Email join; the Statement of
    // Support keeps the signer's on the organization, so both are looked for.
    $email = $find($data, 'email') ?? $find($data, 'MissionSupporter.signer_email');
    $name = trim(($find($data, 'first_name') ?? '') . ' ' . ($find($data, 'last_name') ?? ''));
    if ($name === '') {
      $name = $find($data, 'MissionSupporter.signer_name')
        ?? $find($data, 'organization_name')
        ?? '(no name given)';
    }

    $submitted = strtotime((string) $s['submission_date']);
    $expires = $submitted + ($lifetime * 86400);
    $daysLeft = (int) floor(($expires - time()) / 86400);

    $supporter = ($s['afform_name'] === 'afformSupporterStatement');

    $rows[] = [
      'id' => (int) $s['id'],
      // Which path this belongs to, kept separate from the label. The two are
      // counted apart on the Dashboard, and matching on display text to decide
      // that is the kind of thing that breaks the day someone rewords a label.
      'kind' => $supporter ? 'supporter' : 'membership',
      'form' => $supporter ? 'Statement of Support' : 'Membership',
      'name' => $name,
      'email' => $email ?? '(none)',
      'submitted' => (string) $s['submission_date'],
      'days_left' => $daysLeft,
      'live' => $daysLeft >= 0,
    ];
  }

  return $rows;
}

function openar_admin_page(): void {
  if (!current_user_can(OPENAR_ADMIN_CAP)) {
    wp_die('You do not have permission to view this page.');
  }

  $notice = '';
  $error = '';

  if (!empty($_POST['openar_resend'])) {
    $id = (int) $_POST['openar_resend'];
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'openar_resend_' . $id)) {
      $error = 'That request could not be verified. Please try again.';
    }
    elseif (!function_exists('openar_send_verification_link')) {
      $error = 'The onboarding plugin is not loaded, so no link could be sent.';
    }
    else {
      civi_wp()->initialize();
      $notice = openar_send_verification_link($id)
        ? "A fresh confirmation link has been sent for application #{$id}."
        : "Could not send a link for application #{$id}. See the CiviCRM log.";
      if (str_starts_with($notice, 'Could not')) {
        $error = $notice;
        $notice = '';
      }
    }
  }

  $rows = openar_admin_pending();
  ?>
  <div class="wrap">
    <h1>OpenAR onboarding</h1>

    <?php $mailProblem = openar_admin_mail_problem(); ?>
    <?php if ($mailProblem) : ?>
      <div class="notice notice-error">
        <p><strong>Email is not going out.</strong> <?php echo esc_html($mailProblem); ?></p>
        <p>Fix it under
          <a href="<?php echo esc_url(openar_admin_civi_url('civicrm/admin/setting/smtp', 'reset=1')); ?>">Outbound Email</a>.</p>
      </div>
    <?php endif; ?>

    <?php if ($notice) : ?>
      <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>
    <?php if ($error) : ?>
      <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <h2>Waiting on confirmation</h2>
    <p class="description">
      People who filled in a form and have not yet clicked the link in their email.
      Nothing is written to the contact records until they do, which is why these
      do not appear under Find Contacts. Links last
      <?php echo (int) (defined('OPENAR_VERIFY_LIFETIME_DAYS') ? OPENAR_VERIFY_LIFETIME_DAYS : 7); ?> days.
    </p>

    <?php if (!$rows) : ?>
      <p><strong>Nobody is waiting.</strong> Every application has either been confirmed or none has been made.</p>
    <?php else : ?>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th scope="col" style="width:4em">ID</th>
            <th scope="col">Name</th>
            <th scope="col">Email</th>
            <th scope="col">Form</th>
            <th scope="col">Submitted</th>
            <th scope="col">Link</th>
            <th scope="col" style="width:12em">Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r) : ?>
          <tr>
            <td><?php echo (int) $r['id']; ?></td>
            <td><strong><?php echo esc_html($r['name']); ?></strong></td>
            <td><a href="mailto:<?php echo esc_attr($r['email']); ?>"><?php echo esc_html($r['email']); ?></a></td>
            <td><?php echo esc_html($r['form']); ?></td>
            <td><?php echo esc_html(substr($r['submitted'], 0, 16)); ?></td>
            <td>
              <?php if ($r['live']) : ?>
                <span style="color:#186a3b">live, <?php echo (int) $r['days_left']; ?> day<?php echo $r['days_left'] === 1 ? '' : 's'; ?> left</span>
              <?php else : ?>
                <span style="color:#a13b1e">lapsed <?php echo abs((int) $r['days_left']); ?> day<?php echo abs($r['days_left']) === 1 ? '' : 's'; ?> ago</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="margin:0">
                <?php wp_nonce_field('openar_resend_' . $r['id']); ?>
                <input type="hidden" name="openar_resend" value="<?php echo (int) $r['id']; ?>" />
                <button type="submit" class="button">Resend link</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h2 style="margin-top:2em">Where everything else lives</h2>
    <table class="widefat striped" style="max-width:60em">
      <tbody>
        <tr>
          <td style="width:16em"><strong>Applications to review</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/group/search', 'reset=1&force=1&context=smog&gid=' . (int) openar_admin_group_id('applicants_pending_review'))); ?>">Applicants pending review</a></td>
        </tr>
        <tr>
          <td><strong>Members</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/group/search', 'reset=1&force=1&context=smog&gid=' . (int) openar_admin_group_id('members'))); ?>">Members group</a></td>
        </tr>
        <tr>
          <td><strong>Supporters awaiting review</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/group/search', 'reset=1&force=1&context=smog&gid=' . (int) openar_admin_group_id('supporters_pending'))); ?>">Mission Supporters pending</a></td>
        </tr>
        <tr>
          <td><strong>Published supporters</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/group/search', 'reset=1&force=1&context=smog&gid=' . (int) openar_admin_group_id('supporters_published'))); ?>">Mission Supporters published</a>
            &mdash; the roster syncs to the website hourly</td>
        </tr>
        <tr>
          <td><strong>All form submissions</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/admin/afform/submissions', 'reset=1')); ?>">CiviCRM Submissions</a></td>
        </tr>
        <tr>
          <td><strong>Email templates</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/admin/messageTemplates', 'reset=1')); ?>">Message Templates</a></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php
}

/**
 * A wp-admin link to a CiviCRM path.
 *
 * CiviCRM admin paths are refused on the front end regardless of who is asking,
 * so they have to be addressed through wp-admin or they render a message about
 * permissions that has nothing to do with permissions.
 */
function openar_admin_civi_url(string $path, string $query = 'reset=1'): string {
  return admin_url('admin.php?page=CiviCRM&q=' . rawurlencode($path) . '&' . $query);
}

/* ------------------------------------- keep back office out of the theme -- */

/**
 * CiviCRM back office paths that have no business rendering on the public site.
 *
 * A denylist rather than an allowlist, deliberately. Everything public that
 * CiviCRM serves here (both forms, and the confirmation link people open from
 * their email) has to keep working for a stranger with no account, and the way
 * to guarantee that is for the default to be "leave it alone".
 *
 * Matched as whole path segments, so 'civicrm/a' catches the Angular back
 * office at civicrm/a/#/... and never civicrm/afform/submission/verify.
 */
const OPENAR_BACK_OFFICE = [
  'civicrm/contact', 'civicrm/group', 'civicrm/admin', 'civicrm/a',
  'civicrm/activity', 'civicrm/case', 'civicrm/custom', 'civicrm/dashboard',
  'civicrm/mailing', 'civicrm/report', 'civicrm/tag', 'civicrm/import',
  'civicrm/export',
];

add_action('template_redirect', 'openar_admin_back_office_redirect', 1);

/**
 * Send staff from a front-end CiviCRM back office page to the same page in
 * wp-admin.
 *
 * CiviCRM will render a contact record on the public base page, inside the site
 * theme. It looks broken there, because it is: the theme's typography and the
 * brand stylesheet are built for the public forms, and CiviCRM's own admin CSS
 * expects wp-admin. The result is unreadable button text and select boxes
 * clipped to half their height.
 *
 * The reason it kept happening is that one bad entry point was enough. Once you
 * were on the base page CiviCRM generated base page links for everything after
 * it, so the whole session stayed there. Correcting the links we send only
 * fixed the links we send; old email, a bookmark, or anything CiviCRM linked to
 * itself still landed here. Moving people at the door fixes all of them.
 *
 * Public pages are untouched, as is anyone not signed in.
 */
function openar_admin_back_office_redirect(): void {
  if (is_admin() || wp_doing_ajax() || !is_user_logged_in()) {
    return;
  }
  // A redirect turns a POST into a GET and drops the body, and a snippet is a
  // fragment fetched into a page that is already open. Neither can be moved.
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || isset($_GET['snippet'])) {
    return;
  }
  if (!current_user_can(OPENAR_ADMIN_CAP) && !current_user_can('access_civicrm')) {
    return;
  }

  $route = openar_admin_civi_route();
  if ($route === '') {
    return;
  }

  $back_office = FALSE;
  foreach (OPENAR_BACK_OFFICE as $prefix) {
    if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
      $back_office = TRUE;
      break;
    }
  }
  if (!$back_office) {
    return;
  }

  // Everything the page was given, less the three parameters that only exist to
  // get WordPress to hand the request to CiviCRM in the first place.
  $args = $_GET;
  unset($args['q'], $args['civiwp'], $args['page_id']);

  $url = admin_url('admin.php?page=CiviCRM&q=' . rawurlencode($route));
  if ($args) {
    $url .= '&' . http_build_query($args);
  }

  // A 302 with no fragment of its own leaves the original one alone, which is
  // what carries the route for civicrm/a/#/...
  wp_safe_redirect($url, 302);
  exit;
}

/**
 * The CiviCRM path this front-end request is asking for, or '' if it is not a
 * CiviCRM request at all.
 */
function openar_admin_civi_route(): string {
  $q = isset($_GET['q']) ? trim((string) $_GET['q'], '/') : '';
  if ($q !== '') {
    return ($q === 'civicrm' || str_starts_with($q, 'civicrm/')) ? $q : '';
  }

  // No ?q=, so the path after the base page slug is the route. The slug is
  // configurable and is not necessarily "civicrm", so ask rather than assume.
  if (!function_exists('civi_wp') || !civi_wp()->initialize()) {
    return '';
  }
  $base = trim((string) CRM_Core_Config::singleton()->wpBasePage, '/');
  if ($base === '') {
    return '';
  }
  $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
  if ($path !== $base && !str_starts_with($path, $base . '/')) {
    return '';
  }
  $rest = trim(substr($path, strlen($base)), '/');
  return $rest === '' ? 'civicrm' : 'civicrm/' . $rest;
}

function openar_admin_group_id(string $name): int {
  if (!function_exists('civi_wp')) {
    return 0;
  }
  civi_wp()->initialize();
  $g = civicrm_api4('Group', 'get', [
    'select' => ['id'],
    'where' => [['name', '=', $name]],
    'checkPermissions' => FALSE,
  ])->first();
  return $g ? (int) $g['id'] : 0;
}


/* ---------------------------------------------------------- dashboard -- */

function openar_admin_dashboard_widget(): void {
  if (!current_user_can(OPENAR_ADMIN_CAP)) {
    return;
  }
  wp_add_dashboard_widget(
    'openar_onboarding_status',
    'OpenAR onboarding',
    'openar_admin_dashboard_render'
  );
}

/** How many contacts sit in a group, or null when the group is missing. */
function openar_admin_group_count(string $name): ?int {
  $id = openar_admin_group_id($name);
  if (!$id) {
    return NULL;
  }
  return (int) civicrm_api4('GroupContact', 'get', [
    'select' => ['row_count'],
    'where' => [['group_id', '=', $id], ['status', '=', 'Added']],
    'checkPermissions' => FALSE,
  ])->count();
}

/** How many contacts have been issued a member number, whatever group they are in. */
function openar_admin_numbered_members(): int {
  return (int) civicrm_api4('Contact', 'get', [
    'select' => ['row_count'],
    'where' => [['Membership.member_number', 'IS NOT EMPTY'], ['is_deleted', '=', FALSE]],
    'checkPermissions' => FALSE,
  ])->count();
}

/**
 * One row of the Dashboard widget: what it counts, what that means, where the
 * work is done.
 *
 * The note under each label is the whole point of the widget. A bare number
 * tells a new reviewer nothing about whether it is theirs to act on, and the
 * two paths run far enough apart that "3 waiting" means different work
 * depending on which one it belongs to.
 */
function openar_admin_dashboard_row(array $row, bool $last = FALSE): void {
  $border = $last ? '' : 'border-bottom:1px solid #f0f0f1;';
  $count = $row['count'];
  $needed = ($count !== NULL && $count > 0);

  // ACTION NEEDED only shouts when there is something to act on. A warning that
  // is present every single day is read as decoration within a week.
  $note = (string) $row['note'];
  $flag = '';
  if (str_starts_with($note, 'ACTION NEEDED:')) {
    $note = trim(substr($note, strlen('ACTION NEEDED:')));
    $flag = 'ACTION NEEDED:';
  }
  ?>
  <li style="display:flex;justify-content:space-between;align-items:baseline;gap:10px;padding:6px 0;<?php echo $border; ?>">
    <span>
      <a href="<?php echo esc_url($row['url']); ?>"><?php echo esc_html($row['label']); ?></a>
      <?php if (!empty($row['lapsed'])) : ?>
        <span style="color:#a13b1e">&nbsp;&mdash; <?php echo (int) $row['lapsed']; ?> lapsed</span>
      <?php endif; ?>
      <br />
      <span style="font-size:11px;line-height:1.5;color:#646970">
        <?php if ($flag && $needed) : ?>
          <strong style="color:#a13b1e"><?php echo esc_html($flag); ?></strong>
        <?php elseif ($flag) : ?>
          <?php echo esc_html($flag); ?>
        <?php endif; ?>
        <?php echo esc_html($note); ?>
      </span>
    </span>
    <strong style="font-size:15px;<?php echo $needed && $flag ? 'color:#a13b1e' : ''; ?>">
      <?php echo $count === NULL ? '&mdash;' : (int) $count; ?>
    </strong>
  </li>
  <?php
}

function openar_admin_dashboard_render(): void {
  if (!function_exists('civi_wp')) {
    echo '<p>CiviCRM is not available.</p>';
    return;
  }

  $pending = openar_admin_pending();
  $memberPending = array_filter($pending, fn($r) => $r['kind'] === 'membership');
  $supporterPending = array_filter($pending, fn($r) => $r['kind'] === 'supporter');

  $lapsed = fn(array $rows) => count(array_filter($rows, fn($r) => !$r['live']));

  $screen = admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG);
  $group = fn(string $name) => openar_admin_civi_url('civicrm/group/search',
    'reset=1&force=1&context=smog&gid=' . openar_admin_group_id($name));

  $members = openar_admin_group_count('members');
  $numbered = openar_admin_numbered_members();

  $rows = [
    [
      'label' => 'Members awaiting confirmation',
      'note' => 'email confirmation link not clicked',
      'count' => count($memberPending),
      'lapsed' => $lapsed($memberPending),
      'url' => $screen,
    ],
    [
      'label' => 'Member applications to review',
      'note' => 'ACTION NEEDED: verify AR professional credentials',
      'count' => openar_admin_group_count('applicants_pending_review'),
      'url' => $group('applicants_pending_review'),
    ],
    [
      'label' => 'Members',
      'note' => 'individuals issued a member ID',
      'count' => $members,
      'url' => $group('members'),
    ],
    [
      'label' => 'Mission Supporters awaiting confirmation',
      'note' => 'email confirmation link not clicked',
      'count' => count($supporterPending),
      'lapsed' => $lapsed($supporterPending),
      'url' => $screen,
    ],
    [
      'label' => 'Mission Supporters to review',
      'note' => 'ACTION NEEDED: verify company legitimacy',
      'count' => openar_admin_group_count('supporters_pending'),
      'url' => $group('supporters_pending'),
    ],
    [
      'label' => 'Mission Supporters',
      'note' => 'companies publicly listed as Mission Supporters',
      'count' => openar_admin_group_count('supporters_published'),
      'url' => $group('supporters_published'),
    ],
  ];

  $mailProblem = openar_admin_mail_problem();
  ?>
  <?php if ($mailProblem) : ?>
    <p style="margin:0 0 10px;padding:8px 10px;background:#fcf0f1;border-left:4px solid #d63638">
      <strong>Email is not going out.</strong><br />
      <?php echo esc_html($mailProblem); ?>
    </p>
  <?php endif; ?>
  <ul style="margin:0">
    <?php foreach ($rows as $i => $row) : ?>
      <?php openar_admin_dashboard_row($row, $i === count($rows) - 1); ?>
    <?php endforeach; ?>
  </ul>

  <?php if ($members !== NULL && $numbered > $members) : ?>
    <p style="margin:10px 0 0;padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;font-size:12px">
      <?php printf(
        /* Both halves can be 1, and "only 1 are in the group" reads like a bug
           in the thing that is reporting a bug. */
        esc_html($numbered === 1 ? '%d contact holds a member ID' : '%d contacts hold a member ID'),
        (int) $numbered
      ); ?>
      <?php printf(
        esc_html($members === 1 ? ' but only %d is in the members group.' : ' but only %d are in the members group.'),
        (int) $members
      ); ?>
      Usually that means two records for the same person, which is fixed by
      merging them.
    </p>
  <?php endif; ?>

  <?php if ($pending) : ?>
    <p style="margin:12px 0 4px"><strong>Waiting on their confirmation email</strong></p>
    <table style="width:100%;border-collapse:collapse">
      <?php foreach (array_slice($pending, 0, 5) as $r) : ?>
        <tr>
          <td style="padding:2px 0"><?php echo esc_html($r['name']); ?></td>
          <td style="padding:2px 0;color:#646970"><?php echo esc_html($r['email']); ?></td>
          <td style="padding:2px 0;text-align:right;white-space:nowrap;<?php echo $r['live'] ? '' : 'color:#a13b1e'; ?>">
            <?php echo $r['live'] ? (int) $r['days_left'] . 'd left' : 'lapsed'; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($pending) > 5) : ?>
      <p style="margin:6px 0 0"><a href="<?php echo esc_url($screen); ?>">and <?php echo count($pending) - 5; ?> more</a></p>
    <?php endif; ?>
  <?php endif; ?>

  <p style="margin:12px 0 0"><a href="<?php echo esc_url($screen); ?>" class="button button-small">Open onboarding</a></p>
  <?php
}
