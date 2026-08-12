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

/**
 * Applicants waiting on a person, with everything needed to decide.
 *
 * The reviewer email already carries these details. Repeating them here means a
 * decision can be made from one screen instead of the email, a CiviCRM contact
 * record, and a group edit screen.
 */
function openar_admin_review_queue(): array {
  if (!function_exists('civi_wp')) {
    return [];
  }
  civi_wp()->initialize();

  $gid = openar_admin_group_id(defined('OPENAR_REVIEW_GROUP') ? OPENAR_REVIEW_GROUP : 'applicants_pending_review');
  if (!$gid) {
    return [];
  }

  $ids = [];
  foreach (civicrm_api4('GroupContact', 'get', [
    'select' => ['contact_id'],
    'where' => [
      ['group_id', '=', $gid],
      ['status', '=', 'Added'],
      ['contact_id.is_deleted', '=', FALSE],
    ],
    'checkPermissions' => FALSE,
  ]) as $r) {
    $ids[] = (int) $r['contact_id'];
  }
  if (!$ids) {
    return [];
  }

  $rows = [];
  foreach (civicrm_api4('Contact', 'get', [
    'select' => [
      'id', 'display_name', 'created_date',
      'Membership.employer_affiliation', 'Membership.linkedin_url',
      'Membership.email_confirmed_date', 'Membership.terms_version',
      'Membership.application_notes',
    ],
    'where' => [['id', 'IN', $ids]],
    'orderBy' => ['created_date' => 'ASC'],
    'checkPermissions' => FALSE,
  ]) as $c) {
    $c['email'] = civicrm_api4('Email', 'get', [
      'select' => ['email'],
      'where' => [['contact_id', '=', $c['id']]],
      'orderBy' => ['is_primary' => 'DESC'],
      'checkPermissions' => FALSE,
    ])->first()['email'] ?? '';
    $rows[] = $c;
  }

  return $rows;
}

/**
 * Approve or decline, from the screen rather than from CiviCRM.
 *
 * Neither of these does the work itself. Adding the contact to the members or
 * declined group is what the onboarding plugin already watches for, so the
 * member number, the welcome email, the Discord link and the decline email all
 * happen exactly as they do when a reviewer moves someone by hand in CiviCRM.
 * Two ways into the same path, not two paths.
 *
 * @return string A message for the reviewer, or '' when nothing was done.
 */
function openar_admin_decide(int $contactId, string $action, string $reason = ''): string {
  if (!function_exists('openar_add_to_group')) {
    return 'The onboarding plugin is not loaded, so nothing was changed.';
  }
  civi_wp()->initialize();

  $who = civicrm_api4('Contact', 'get', [
    'select' => ['display_name', 'is_deleted'],
    'where' => [['id', '=', $contactId]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$who || $who['is_deleted']) {
    return "Contact #{$contactId} no longer exists.";
  }
  $name = $who['display_name'];

  if ($action === 'approve') {
    if (openar_in_group($contactId, OPENAR_MEMBERS_GROUP)) {
      return "{$name} was already a member, so nothing changed.";
    }
    openar_add_to_group($contactId, OPENAR_MEMBERS_GROUP);
    return "{$name} is now a member. Their welcome email, member number and "
      . 'Discord link have gone out.';
  }

  if ($action === 'decline') {
    $reason = trim($reason);
    if ($reason === '') {
      return 'A decline needs a reason, because the reason is what the applicant '
        . 'is sent. Nothing was changed.';
    }
    // Written before the group add, so the decline email goes out complete on
    // the first attempt rather than firing the "no reason recorded" notice.
    civicrm_api4('Contact', 'update', [
      'where' => [['id', '=', $contactId]],
      'values' => ['Membership.decline_reason' => $reason],
      'checkPermissions' => FALSE,
    ]);
    openar_add_to_group($contactId, OPENAR_DECLINED_GROUP);
    return "{$name} has been declined, and the reason you wrote has been emailed to them.";
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

  // Approve and decline. Both are POST with a nonce, deliberately, and neither
  // is reachable by following a link. Reviewer email lands on this screen, not
  // on the action: a URL that admits a member on GET would be fired by every
  // link scanner, safe-links rewriter and mail gateway between here and the
  // reviewer's inbox, and would admit people nobody had looked at.
  if (!empty($_POST['openar_decide']) && !empty($_POST['openar_contact'])) {
    $cid = (int) $_POST['openar_contact'];
    $action = sanitize_key((string) $_POST['openar_decide']);

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), "openar_decide_{$cid}")) {
      $error = 'That request could not be verified. Please try again.';
    }
    else {
      $result = openar_admin_decide($cid, $action, wp_unslash((string) ($_POST['openar_reason'] ?? '')));
      if (str_contains($result, 'needs a reason') || str_contains($result, 'no longer exists')
        || str_contains($result, 'not loaded')) {
        $error = $result;
      }
      else {
        $notice = $result;
      }
    }
  }

  $pending = openar_admin_pending();
  $queue = openar_admin_review_queue();
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

    <table class="widefat striped" style="max-width:76em">
      <thead>
        <tr>
          <th scope="col" style="width:20em">What</th>
          <th scope="col" style="width:5em;text-align:right">Count</th>
          <th scope="col">What it means</th>
          <th scope="col" style="width:22em">Where the work is done</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (openar_admin_rows($pending) as $i => $r) : ?>
          <?php
          $flag = str_starts_with($r['note'], 'ACTION NEEDED:');
          $note = $flag ? trim(substr($r['note'], strlen('ACTION NEEDED:'))) : $r['note'];
          $needed = ($r['count'] !== NULL && $r['count'] > 0);
          // Same break between the two paths as the Dashboard widget.
          $top = ($i === 3) ? 'border-top:2px solid #c3c4c7;' : '';
          ?>
          <tr>
            <td style="<?php echo $top; ?>">
              <strong><a href="<?php echo esc_url($r['url']); ?>"><?php echo esc_html($r['label']); ?></a></strong>
              <?php if (!empty($r['lapsed'])) : ?>
                <br /><span style="color:#a13b1e"><?php echo (int) $r['lapsed']; ?> lapsed</span>
              <?php endif; ?>
            </td>
            <td style="<?php echo $top; ?>text-align:right;font-size:15px;<?php echo $needed && $flag ? 'color:#a13b1e;font-weight:600' : ''; ?>">
              <?php echo $r['count'] === NULL ? '&mdash;' : (int) $r['count']; ?>
            </td>
            <td style="<?php echo $top; ?>">
              <?php if ($flag) : ?>
                <strong style="<?php echo $needed ? 'color:#a13b1e' : ''; ?>">ACTION NEEDED:</strong>
              <?php endif; ?>
              <?php echo esc_html($note); ?>
            </td>
            <td style="<?php echo $top; ?>"><?php echo esc_html($r['where']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($queue) : ?>
      <h2 style="margin-top:2em">Applications to review</h2>
      <p class="description" style="max-width:60em">
        These people have confirmed their email address and are waiting on a
        decision. Approving sends the welcome email with their member number and
        Discord link. Declining sends the reason you write, so write it as
        something you are content for them to read.
      </p>

      <?php foreach ($queue as $a) : ?>
        <?php
        $cid = (int) $a['id'];
        $focus = ((int) ($_GET['review'] ?? 0) === $cid);
        $linkedin = (string) ($a['Membership.linkedin_url'] ?? '');
        ?>
        <div id="applicant-<?php echo $cid; ?>" class="card" style="max-width:60em;padding:16px 20px;margin:14px 0;<?php echo $focus ? 'border-left:4px solid #e8a020;' : ''; ?>">
          <h3 style="margin:0 0 4px"><?php echo esc_html($a['display_name']); ?></h3>

          <table class="widefat striped" style="margin:10px 0;">
            <tbody>
              <tr>
                <td style="width:14em"><strong>Email</strong></td>
                <td><a href="mailto:<?php echo esc_attr($a['email']); ?>"><?php echo esc_html($a['email'] ?: '(none)'); ?></a></td>
              </tr>
              <tr>
                <td><strong>Employer or affiliation</strong></td>
                <td><?php echo esc_html($a['Membership.employer_affiliation'] ?: '(not given)'); ?></td>
              </tr>
              <tr>
                <td><strong>LinkedIn</strong></td>
                <td>
                  <?php if ($linkedin) : ?>
                    <a href="<?php echo esc_url($linkedin); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($linkedin); ?></a>
                  <?php else : ?>
                    (not supplied)
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <td><strong>Confirmed their email</strong></td>
                <td><?php echo esc_html($a['Membership.email_confirmed_date'] ?: '(not recorded)'); ?>
                  <?php if (!empty($a['Membership.terms_version'])) : ?>
                    &nbsp;&middot;&nbsp; agreed to Terms v<?php echo esc_html($a['Membership.terms_version']); ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php if (!empty($a['Membership.application_notes'])) : ?>
                <tr>
                  <td><strong>Review notes</strong></td>
                  <td style="white-space:pre-wrap"><?php echo esc_html($a['Membership.application_notes']); ?></td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>

          <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">
            <form method="post" style="margin:0">
              <?php wp_nonce_field("openar_decide_{$cid}"); ?>
              <input type="hidden" name="openar_contact" value="<?php echo $cid; ?>" />
              <input type="hidden" name="openar_decide" value="approve" />
              <button type="submit" class="button button-primary">Approve and welcome them</button>
            </form>

            <form method="post" style="margin:0;flex:1;min-width:22em">
              <?php wp_nonce_field("openar_decide_{$cid}"); ?>
              <input type="hidden" name="openar_contact" value="<?php echo $cid; ?>" />
              <input type="hidden" name="openar_decide" value="decline" />
              <textarea name="openar_reason" rows="2" style="width:100%"
                placeholder="Reason, in words you are content for them to read. Required to decline."></textarea>
              <button type="submit" class="button" style="margin-top:6px">Decline</button>
            </form>
          </div>

          <p style="margin:10px 0 0">
            <a href="<?php echo esc_url(openar_admin_civi_url('civicrm/contact/view', 'reset=1&cid=' . $cid)); ?>">Open the full record in CiviCRM</a>
          </p>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($pending) : ?>
      <h2 style="margin-top:2em">Waiting on confirmation</h2>
      <p class="description" style="max-width:60em">
        People who filled in a form and have not yet clicked the link in their
        email. Nothing is written to the contact records until they do, which is
        why these do not appear under Find Contacts. Links last
        <?php echo (int) (defined('OPENAR_VERIFY_LIFETIME_DAYS') ? OPENAR_VERIFY_LIFETIME_DAYS : 7); ?> days.
      </p>

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
        <?php foreach ($pending as $r) : ?>
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

    <h2 style="margin-top:2em">Everything else</h2>
    <table class="widefat striped" style="max-width:60em">
      <tbody>
        <tr>
          <td style="width:22em"><strong>All form submissions</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/admin/afform/submissions', 'reset=1')); ?>">CiviCRM Submissions</a></td>
        </tr>
        <tr>
          <td><strong>Email templates</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/admin/messageTemplates', 'reset=1')); ?>">Message Templates</a></td>
        </tr>
        <tr>
          <td><strong>Outbound email</strong></td>
          <td><a href="<?php echo esc_url(openar_admin_civi_url('civicrm/admin/setting/smtp', 'reset=1')); ?>">Outbound Email settings</a>
            &mdash; leave this on SMTP; anything else stops every confirmation and welcome</td>
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

/**
 * How many contacts sit in a group, or null when the group is missing.
 *
 * Trashed contacts are excluded. Deleting a contact in CiviCRM moves it to the
 * trash and leaves its group membership row untouched, so counting the group
 * alone keeps counting people who have been deleted. That is not a harmless
 * overcount: every one of these numbers links to a CiviCRM group screen, which
 * hides trashed contacts by default, so the figure and the page it points at
 * disagreed with each other.
 */
function openar_admin_group_count(string $name): ?int {
  $id = openar_admin_group_id($name);
  if (!$id) {
    return NULL;
  }
  return (int) civicrm_api4('GroupContact', 'get', [
    'select' => ['row_count'],
    'where' => [
      ['group_id', '=', $id],
      ['status', '=', 'Added'],
      ['contact_id.is_deleted', '=', FALSE],
    ],
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
 *
 * Notes get their own line, so each one starts with a capital.
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

/**
 * The six things worth counting, in the order they happen to a person.
 *
 * Both paths reach the same three states, so they are named and ordered the
 * same way: awaiting confirmation, waiting on a reviewer, done. The symmetry is
 * the point. It draws a clean line between members and Mission Supporters and
 * makes it obvious at a glance which side of the house a number belongs to.
 *
 * Defined once and rendered twice, by the Dashboard widget and by the Tools
 * screen. They said different things for a while, because there were two copies
 * of these labels and only one got updated.
 *
 * @param array|null $pending Pass an already-fetched list to avoid a second
 *   query when the caller has one.
 */
function openar_admin_rows(?array $pending = NULL): array {
  $pending ??= openar_admin_pending();

  $of = fn(string $kind) => array_filter($pending, fn($r) => $r['kind'] === $kind);
  $lapsed = fn(array $rows) => count(array_filter($rows, fn($r) => !$r['live']));

  $screen = admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG);
  $group = fn(string $name) => openar_admin_civi_url('civicrm/group/search',
    'reset=1&force=1&context=smog&gid=' . openar_admin_group_id($name));

  $members = $of('membership');
  $supporters = $of('supporter');

  return [
    [
      'side' => 'member',
      'label' => 'Members awaiting confirmation',
      'note' => 'Email confirmation link not clicked',
      'count' => count($members),
      'lapsed' => $lapsed($members),
      'url' => $screen,
      // Each "where" is a column value, so it names a place and stops. It is
      // also read when the count is zero and the list it refers to is not on
      // the page at all, which rules out "listed below".
      'where' => 'This screen, with a button to send a fresh link',
    ],
    [
      'side' => 'member',
      'label' => 'Member applications to review',
      'note' => 'ACTION NEEDED: verify AR professional credentials',
      'count' => openar_admin_group_count('applicants_pending_review'),
      'url' => $group('applicants_pending_review'),
      'where' => 'Applicants pending review, in CiviCRM',
    ],
    [
      'side' => 'member',
      'label' => 'Members',
      'note' => 'Individuals issued a member ID',
      'count' => openar_admin_group_count('members'),
      'url' => $group('members'),
      'where' => 'Members group, in CiviCRM',
    ],
    [
      'side' => 'supporter',
      'label' => 'Mission Supporters awaiting confirmation',
      'note' => 'Email confirmation link not clicked',
      'count' => count($supporters),
      'lapsed' => $lapsed($supporters),
      'url' => $screen,
      // Each "where" is a column value, so it names a place and stops. It is
      // also read when the count is zero and the list it refers to is not on
      // the page at all, which rules out "listed below".
      'where' => 'This screen, with a button to send a fresh link',
    ],
    [
      'side' => 'supporter',
      'label' => 'Mission Supporters to review',
      'note' => 'ACTION NEEDED: verify company legitimacy',
      'count' => openar_admin_group_count('supporters_pending'),
      'url' => $group('supporters_pending'),
      'where' => 'Mission Supporters pending, in CiviCRM',
    ],
    [
      'side' => 'supporter',
      'label' => 'Mission Supporters',
      'note' => 'Companies publicly listed as Mission Supporters',
      'count' => openar_admin_group_count('supporters_published'),
      'url' => $group('supporters_published'),
      // The one place a "where" earns a second clause: adding a contact to this
      // group puts the organization on a public website, and nothing else on
      // either screen says so.
      'where' => 'Mission Supporters published, in CiviCRM. Adding one here publishes it; the roster syncs hourly',
    ],
  ];
}

function openar_admin_dashboard_render(): void {
  if (!function_exists('civi_wp')) {
    echo '<p>CiviCRM is not available.</p>';
    return;
  }

  $pending = openar_admin_pending();
  $rows = openar_admin_rows($pending);

  $screen = admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG);
  $members = openar_admin_group_count('members');
  $numbered = openar_admin_numbered_members();

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
      <?php
      // A gap where the two paths meet, so the symmetry is visible rather than
      // something you have to read six labels to work out.
      if ($i > 0 && $row['side'] !== $rows[$i - 1]['side']) {
        echo '<li style="height:8px"></li>';
      }
      openar_admin_dashboard_row($row, $i === count($rows) - 1);
      ?>
    <?php endforeach; ?>
  </ul>

  <?php if ($members !== NULL && $numbered > $members) : ?>
    <p style="margin:10px 0 0;padding:8px 10px;background:#fcf9e8;border-left:4px solid #dba617;font-size:12px">
      <?php /* Stated as two figures rather than a sentence, because every
               phrasing of it reads badly at one of the numbers it can hold. */ ?>
      <strong>Member IDs issued: <?php echo (int) $numbered; ?>. Members group: <?php echo (int) $members; ?>.</strong>
      Those should match. It is usually two records for the same person, which
      merging fixes, or a number issued to somebody who never made it into the
      group. Deleted contacts are already excluded from both figures.
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
