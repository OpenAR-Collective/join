<?php
/**
 * Plugin Name: OpenAR onboarding admin
 * Description: A screen for the parts of onboarding that CiviCRM cannot show, chiefly unconfirmed applications.
 * Version:     1.3.0
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
add_action('admin_init', 'openar_admin_badge_download');
add_action('admin_init', 'openar_admin_supporter_badge_download');

function openar_admin_menu(): void {
  add_management_page(
    'OpenAR Members & Supporters',
    'OpenAR Members & Supporters',
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
      'id', 'display_name', 'created_date', 'job_title',
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
    'select' => ['display_name', 'is_deleted', 'contact_type'],
    'where' => [['id', '=', $contactId]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$who || $who['is_deleted']) {
    return "Contact #{$contactId} no longer exists.";
  }
  $name = $who['display_name'];

  // Organizations travel the supporter path, individuals the membership one.
  // The contact type decides, so a reviewer never picks the wrong one and the
  // two queues cannot be crossed by a stray form post.
  $supporter = ($who['contact_type'] === 'Organization');

  if ($action === 'approve') {
    $target = $supporter ? OPENAR_SUPPORTERS_PUBLISHED_GROUP : OPENAR_MEMBERS_GROUP;
    if (openar_in_group($contactId, $target)) {
      return $supporter
        ? "{$name} was already published, so nothing changed."
        : "{$name} was already a member, so nothing changed.";
    }
    openar_add_to_group($contactId, $target);
    return $supporter
      ? "{$name} is published. The signer has been told, and the roster on "
        . 'openarcollective.org picks it up on the next hourly sync.'
      : "{$name} is now a member. Their welcome email, member number and "
        . 'Discord link have gone out.';
  }

  if ($action === 'revoke') {
    $reason = trim($reason);
    if ($reason === '') {
      return 'A revocation needs a reason, because the policy requires the person '
        . 'be given the basis of the decision. Nothing was changed.';
    }

    $current = $supporter ? OPENAR_SUPPORTERS_PUBLISHED_GROUP : OPENAR_MEMBERS_GROUP;
    if (!openar_in_group($contactId, $current)) {
      return "{$name} is not currently " . ($supporter ? 'published' : 'a member')
        . ', so there is nothing to revoke. Nothing was changed.';
    }

    // Written before the group add, exactly as the decline does it, so the
    // notice goes out complete on the first attempt rather than firing the
    // "recorded without a reason" alert and waiting for a second edit.
    civicrm_api4('Contact', 'update', [
      'where' => [['id', '=', $contactId]],
      'values' => [($supporter ? 'MissionSupporter' : 'Membership') . '.revocation_reason' => $reason],
      'checkPermissions' => FALSE,
    ]);
    openar_add_to_group($contactId,
      $supporter ? OPENAR_SUPPORTERS_REVOKED_GROUP : OPENAR_MEMBERS_REVOKED_GROUP);

    return $supporter
      ? "{$name} has been revoked and removed from the published roster. The signer "
        . 'has been sent the reason, and the next hourly sync takes the organization '
        . 'off openarcollective.org.'
      : "{$name}'s membership has been revoked. Their access has ended and they have "
        . 'been sent the reason.';
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
      'values' => [($supporter ? 'MissionSupporter' : 'Membership') . '.decline_reason' => $reason],
      'checkPermissions' => FALSE,
    ]);
    openar_add_to_group($contactId,
      $supporter ? OPENAR_SUPPORTERS_DECLINED_GROUP : OPENAR_DECLINED_GROUP);
    return "{$name} has been declined, and the reason you wrote has been emailed to them.";
  }

  return '';
}

/**
 * Organizations waiting on a person, with what the reviewer needs to decide.
 *
 * Approving one puts a company's name on a public web page, which is a heavier
 * action than admitting an individual, so the screen shows the evidence a
 * reviewer would otherwise go looking for: the website, the registered
 * jurisdiction, and whether the signer's address is on the organization's own
 * domain.
 */
function openar_admin_supporter_queue(): array {
  if (!function_exists('civi_wp')) {
    return [];
  }
  civi_wp()->initialize();

  $gid = openar_admin_group_id(defined('OPENAR_SUPPORTERS_PENDING_GROUP')
    ? OPENAR_SUPPORTERS_PENDING_GROUP : 'supporters_pending');
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
      'id', 'display_name', 'organization_name', 'created_date',
      'MissionSupporter.trade_name', 'MissionSupporter.website_url',
      'MissionSupporter.registered_in', 'MissionSupporter.signer_name',
      'MissionSupporter.signer_title', 'MissionSupporter.signer_email',
      'MissionSupporter.statement_version', 'MissionSupporter.supporter_notes',
    ],
    'where' => [['id', 'IN', $ids]],
    'orderBy' => ['created_date' => 'ASC'],
    'checkPermissions' => FALSE,
  ]) as $c) {
    // The cheapest signal a reviewer has, worked out here rather than left for
    // them to eyeball: does the signer's address sit on the organization's own
    // domain? It is not proof, but its absence is worth a second look.
    $site = strtolower((string) ($c['MissionSupporter.website_url'] ?? ''));
    $host = (string) parse_url($site && !str_contains($site, '//') ? "https://{$site}" : $site, PHP_URL_HOST);
    $host = preg_replace('/^www\./', '', $host);
    $mailDomain = strtolower(substr(strrchr((string) ($c['MissionSupporter.signer_email'] ?? ''), '@') ?: '', 1));
    $c['domain_matches'] = ($host !== '' && $mailDomain !== '' && $host === $mailDomain);
    $c['mail_domain'] = $mailDomain;
    $rows[] = $c;
  }

  return $rows;
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

  // Resending a Discord link. Harmless by design: it re-sends something the
  // member already had, to the address already on file, and cannot be used to
  // reach anybody who is not currently a member.
  if (!empty($_POST['openar_send_discord']) && !empty($_POST['openar_discord_contact'])) {
    $cid = (int) $_POST['openar_discord_contact'];

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'openar_send_discord')) {
      $error = 'That request could not be verified. Please try again.';
    }
    else {
      $result = openar_admin_send_discord_link($cid);
      if (str_starts_with($result, 'Sent')) {
        $notice = $result;
      }
      else {
        $error = $result;
      }
    }
  }

  // Emailing a member their badge. As harmless as the Discord resend and for
  // the same reasons: it re-sends something the member already had, to the
  // address already on file. The download variant of the same form never
  // reaches this page; openar_admin_badge_download() streams it and exits
  // before any admin HTML is out the door.
  if (($_POST['openar_badge_action'] ?? '') === 'email' && !empty($_POST['openar_badge_contact'])) {
    $cid = (int) $_POST['openar_badge_contact'];

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'openar_send_badge')) {
      $error = 'That request could not be verified. Please try again.';
    }
    else {
      $result = openar_admin_send_badge($cid);
      if (str_starts_with($result, 'Sent')) {
        $notice = $result;
      }
      else {
        $error = $result;
      }
    }
  }

  // The supporter twin of the member badge email above, with the same shape
  // and the same reasoning. Its download variant is likewise intercepted at
  // admin_init before any HTML is out the door.
  if (($_POST['openar_supporter_badge_action'] ?? '') === 'email' && !empty($_POST['openar_supporter_badge_contact'])) {
    $cid = (int) $_POST['openar_supporter_badge_contact'];

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'openar_send_supporter_badge')) {
      $error = 'That request could not be verified. Please try again.';
    }
    else {
      $result = openar_admin_send_supporter_badge($cid);
      if (str_starts_with($result, 'Sent')) {
        $notice = $result;
      }
      else {
        $error = $result;
      }
    }
  }

  // Revoking is shown back before it happens. Approving and declining act on
  // somebody who applied and is waiting to hear; revoking acts on somebody in
  // good standing who is not expecting anything, ends their access, and sends
  // an email that cannot be recalled. One confirmation is worth the click.
  $confirm = NULL;
  if (!empty($_POST['openar_revoke_review']) && !empty($_POST['openar_contact'])) {
    $cid = (int) $_POST['openar_contact'];
    $reason = trim(wp_unslash((string) ($_POST['openar_reason'] ?? '')));

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'openar_revoke_review')) {
      $error = 'That request could not be verified. Please try again.';
    }
    elseif ($reason === '') {
      $error = 'A revocation needs a reason, because the policy requires the person be '
        . 'given the basis of the decision. Nothing was changed.';
    }
    else {
      civi_wp()->initialize();
      $who = civicrm_api4('Contact', 'get', [
        'select' => ['display_name', 'contact_type', 'Membership.discord_user_id'],
        'where' => [['id', '=', $cid]],
        'checkPermissions' => FALSE,
      ])->first();
      if (!$who) {
        $error = "Contact #{$cid} no longer exists.";
      }
      else {
        $confirm = [
          'id' => $cid,
          'name' => $who['display_name'],
          'supporter' => ($who['contact_type'] === 'Organization'),
          'reason' => $reason,
          // Carried through so the confirmation can name the one thing this
          // does not do. The Discord plugin only ever adds people; nothing in
          // this build removes anybody from the server.
          'discord' => (string) ($who['Membership.discord_user_id'] ?? ''),
        ];
      }
    }
  }

  if (!empty($_POST['openar_sync_roster'])) {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'openar_sync_roster')) {
      $error = 'That request could not be verified. Please try again.';
    }
    else {
      $notice = openar_admin_request_sync()
        ?: 'Could not write the sync request file, so nothing was asked for.';
      if (str_starts_with($notice, 'Could not')) {
        $error = $notice;
        $notice = '';
      }
    }
  }

  $pending = openar_admin_pending();
  $queue = openar_admin_review_queue();
  $supporters = openar_admin_supporter_queue();
  $revocable = openar_admin_revocable();
  ?>
  <div class="wrap">
    <h1>OpenAR Members &amp; Supporters</h1>

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

          <?php
          // Same three-column shape as the supporter card: what it is, what it
          // says, and any remark about it. Keeping remarks out of the value
          // cell is what makes a column of values scannable.
          $rows = [
            [
              'label' => 'Email',
              'html' => $a['email']
                ? '<a href="mailto:' . esc_attr($a['email']) . '">' . esc_html($a['email']) . '</a>'
                : '<span style="color:#646970">(none)</span>',
              'note' => '',
            ],
            [
              'label' => 'Job title',
              'html' => $a['job_title']
                ? esc_html($a['job_title'])
                : '<span style="color:#646970">(not given)</span>',
              'note' => '',
            ],
            [
              'label' => 'Employer or affiliation',
              'html' => $a['Membership.employer_affiliation']
                ? esc_html($a['Membership.employer_affiliation'])
                : '<span style="color:#646970">(not given)</span>',
              'note' => 'The one thing this review is meant to confirm',
            ],
            [
              'label' => 'LinkedIn',
              'html' => $linkedin
                ? '<a href="' . esc_url($linkedin) . '" target="_blank" rel="noopener noreferrer">' . esc_html($linkedin) . '</a>'
                : '<span style="color:#646970">(not supplied)</span>',
              'note' => '',
            ],
            [
              'label' => 'Confirmed their email',
              'html' => $a['Membership.email_confirmed_date']
                ? esc_html($a['Membership.email_confirmed_date'])
                : '<span style="color:#646970">(not recorded)</span>',
              'note' => !empty($a['Membership.terms_version'])
                ? 'Agreed to Terms v' . $a['Membership.terms_version']
                : '',
            ],
            [
              'label' => 'Review notes',
              'value' => (string) ($a['Membership.application_notes'] ?? ''),
              'note' => '',
              'wrap' => TRUE,
              'skip' => empty($a['Membership.application_notes']),
            ],
          ];
          ?>

          <table class="widefat striped" style="margin:10px 0;">
            <tbody>
              <?php foreach ($rows as $r) : ?>
                <?php if (!empty($r['skip'])) { continue; } ?>
                <tr>
                  <td style="width:12em"><strong><?php echo esc_html($r['label']); ?></strong></td>
                  <td style="width:24em;<?php echo !empty($r['wrap']) ? 'white-space:pre-wrap' : ''; ?>">
                    <?php echo $r['html'] ?? esc_html($r['value']); ?>
                  </td>
                  <td style="color:#646970"><?php echo esc_html($r['note']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">
            <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>#applicant-<?php echo $cid; ?>" style="margin:0">
              <?php wp_nonce_field("openar_decide_{$cid}"); ?>
              <input type="hidden" name="openar_contact" value="<?php echo $cid; ?>" />
              <input type="hidden" name="openar_decide" value="approve" />
              <button type="submit" class="button button-primary">Approve and welcome them</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>#applicant-<?php echo $cid; ?>" style="margin:0;flex:1;min-width:22em">
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

    <?php if ($supporters) : ?>
      <h2 style="margin-top:2em">Statements of Support to review</h2>
      <p class="description" style="max-width:60em">
        Approving one of these puts the organization's name on a public page at
        openarcollective.org/supporters, which is a heavier action than
        admitting an individual. Declining sends the reason you write to the
        signer.
      </p>

      <?php foreach ($supporters as $s) : ?>
        <?php
        $cid = (int) $s['id'];
        $focus = ((int) ($_GET['review'] ?? 0) === $cid);
        $site = (string) ($s['MissionSupporter.website_url'] ?? '');
        $siteUrl = $site && !preg_match('#^https?://#i', $site) ? "https://{$site}" : $site;
        ?>
        <div id="applicant-<?php echo $cid; ?>" class="card" style="max-width:60em;padding:16px 20px;margin:14px 0;<?php echo $focus ? 'border-left:4px solid #e8a020;' : ''; ?>">
          <h3 style="margin:0 0 4px"><?php echo esc_html($s['organization_name'] ?: $s['display_name']); ?></h3>

          <?php
          // A third column for the notes. They were appended to the values,
          // which put a fact and a remark about that fact in one cell and made
          // both harder to read, particularly the email one, where the remark
          // is the thing a reviewer is looking for.
          $emailNote = '';
          $emailNoteColor = '';
          if ($s['domain_matches']) {
            $emailNote = "on the organization's own domain";
            $emailNoteColor = '#186a3b';
          }
          elseif (!empty($s['mail_domain'])) {
            $emailNote = "not the website's domain";
            $emailNoteColor = '#a13b1e';
          }

          $rows = [
            [
              'label' => 'Trade name',
              'value' => $s['MissionSupporter.trade_name'] ?: '',
              'note' => $s['MissionSupporter.trade_name'] ? 'This is what the roster will show' : '',
              'skip' => empty($s['MissionSupporter.trade_name']),
            ],
            [
              'label' => 'Website',
              'html' => $site
                ? '<a href="' . esc_url($siteUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($site) . '</a>'
                : '<span style="color:#646970">(not supplied)</span>',
              'note' => '',
            ],
            [
              'label' => 'Registered in',
              'html' => $s['MissionSupporter.registered_in']
                ? esc_html($s['MissionSupporter.registered_in'])
                : '<span style="color:#646970">(not supplied)</span>',
              'note' => $s['MissionSupporter.registered_in']
                ? "Searchable in that jurisdiction's public register"
                : 'Without this, a name alone is hard to verify',
            ],
            [
              'label' => 'Signed by',
              'value' => trim($s['MissionSupporter.signer_name'] . ', ' . $s['MissionSupporter.signer_title'], ', '),
              'note' => '',
            ],
            [
              'label' => "Signer's email",
              'html' => '<a href="mailto:' . esc_attr($s['MissionSupporter.signer_email']) . '">'
                . esc_html($s['MissionSupporter.signer_email']) . '</a>',
              'note' => $emailNote,
              'noteColor' => $emailNoteColor,
            ],
            [
              'label' => 'Supporter notes',
              'value' => (string) ($s['MissionSupporter.supporter_notes'] ?? ''),
              'note' => '',
              'wrap' => TRUE,
              'skip' => empty($s['MissionSupporter.supporter_notes']),
            ],
          ];
          ?>

          <table class="widefat striped" style="margin:10px 0;">
            <tbody>
              <?php foreach ($rows as $r) : ?>
                <?php if (!empty($r['skip'])) { continue; } ?>
                <tr>
                  <td style="width:12em"><strong><?php echo esc_html($r['label']); ?></strong></td>
                  <td style="width:24em;<?php echo !empty($r['wrap']) ? 'white-space:pre-wrap' : ''; ?>">
                    <?php echo $r['html'] ?? esc_html($r['value']); ?>
                  </td>
                  <td style="color:<?php echo esc_attr($r['noteColor'] ?? '#646970'); ?>">
                    <?php echo esc_html($r['note']); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">
            <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>#applicant-<?php echo $cid; ?>" style="margin:0">
              <?php wp_nonce_field("openar_decide_{$cid}"); ?>
              <input type="hidden" name="openar_contact" value="<?php echo $cid; ?>" />
              <input type="hidden" name="openar_decide" value="approve" />
              <button type="submit" class="button button-primary">Approve and publish</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>#applicant-<?php echo $cid; ?>" style="margin:0;flex:1;min-width:22em">
              <?php wp_nonce_field("openar_decide_{$cid}"); ?>
              <input type="hidden" name="openar_contact" value="<?php echo $cid; ?>" />
              <input type="hidden" name="openar_decide" value="decline" />
              <textarea name="openar_reason" rows="2" style="width:100%"
                placeholder="Reason, in words you are content for the signer to read. Required to decline."></textarea>
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

    <?php $sync = openar_admin_sync_status(); ?>
    <h2 style="margin-top:2em">The public roster</h2>
    <table class="widefat striped" style="max-width:60em">
      <tbody>
        <tr>
          <td style="width:12em"><strong>Last sync</strong></td>
          <td style="width:24em">
            <?php if ($sync['when']) : ?>
              <?php
              // Rendered in UTC and rewritten to the reader's own zone by the
              // script below. The server runs on UTC and the log records UTC,
              // but nobody reasons about "01:19 UTC" without doing arithmetic
              // first, and the question this row answers is "was that recent".
              $utc = gmdate('c', strtotime($sync['when'] . ' UTC'));
              ?>
              <span class="openar-localtime" data-utc="<?php echo esc_attr($utc); ?>"><?php
                echo esc_html($sync['when']);
              ?></span>
            <?php else : ?>
              never, as far as this screen can see
            <?php endif; ?>
          </td>
          <td style="color:<?php echo $sync['failed'] ? '#a13b1e' : '#646970'; ?>">
            <?php echo esc_html($sync['summary']); ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <form method="post" style="margin:0">
              <?php wp_nonce_field('openar_sync_roster'); ?>
              <input type="hidden" name="openar_sync_roster" value="1" />
              <button type="submit" class="button">Sync the roster now</button>
            </form>
          </td>
        </tr>
      </tbody>
    </table>

    <p style="margin:12px 0 0">
      <span class="description" style="max-width:40em">
        It runs on its own every hour at seventeen minutes past. This asks for one
        straight away, which takes about a minute to start.
        <?php if (!empty($sync['waiting'])) : ?>
          <br /><strong style="color:#a13b1e">A request is waiting and has not run yet.</strong>
        <?php endif; ?>
      </span>
    </p>

    <script>
      // Progressive: the UTC string is already in the page, so a reader without
      // scripting still sees a correct time, just not their own.
      document.querySelectorAll('.openar-localtime').forEach(function (el) {
        var when = new Date(el.dataset.utc);
        if (isNaN(when)) { return; }
        el.textContent = when.toLocaleString(undefined, {
          dateStyle: 'medium', timeStyle: 'short'
        });
        el.title = el.dataset.utc;
      });
    </script>

    <h2 style="margin-top:2em">Send a Discord link</h2>
    <p class="description" style="max-width:60em">
      For a member who has mislaid the link in their welcome email. This sends
      the same personal link again, to the address already on file. It cannot
      reach anybody who is not a current member, and it tells them nothing they
      were not already entitled to know, so it is safe to use whenever somebody
      asks. Joining Discord is optional, so send this when it is wanted rather
      than to prompt people who have not asked.
    </p>

    <?php $discordPeople = openar_admin_discord_candidates(); ?>
    <?php if (!$discordPeople) : ?>
      <p class="description">There are no current members to send to.</p>
    <?php elseif (!function_exists('openar_discord_configured') || !openar_discord_configured()) : ?>
      <p class="description"><strong>Discord is not configured</strong>, so there is no link to send.
        The five constants belong in <code>wp-config.php</code>.</p>
    <?php else : ?>
      <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>" class="card" style="max-width:60em;padding:16px 20px;margin:14px 0">
        <?php wp_nonce_field('openar_send_discord'); ?>
        <input type="hidden" name="openar_send_discord" value="1" />

        <label for="openar_discord_contact"><strong>Who</strong></label>
        <p style="margin:6px 0 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <select name="openar_discord_contact" id="openar_discord_contact" style="min-width:28em" required>
            <option value="">Choose a member</option>
            <?php foreach ($discordPeople as $person) : ?>
              <option value="<?php echo (int) $person['id']; ?>"><?php echo esc_html($person['label']); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="button">Send the Discord link</button>
        </p>
      </form>
    <?php endif; ?>

    <h2 style="margin-top:2em">Send a member badge</h2>
    <p class="description" style="max-width:60em">
      The welcome email already carries the badge, so this is for a member who
      has lost theirs or asks for a fresh copy. The badge is drawn fresh from
      the member number every time, so there is no stored image to go stale.
      Email sends it to the address on file with a short note; Download saves
      the same image here, for the times the file itself is wanted.
    </p>

    <?php $badgeProblem = function_exists('openar_badge_problem') ? openar_badge_problem() : 'The badges plugin is not loaded.'; ?>
    <?php $badgePeople = $badgeProblem === '' ? openar_admin_badge_candidates() : []; ?>
    <?php if ($badgeProblem !== '') : ?>
      <p class="description"><strong>Badges cannot be drawn.</strong> <?php echo esc_html($badgeProblem); ?></p>
    <?php elseif (!$badgePeople) : ?>
      <p class="description">There are no current members with a member number.</p>
    <?php else : ?>
      <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>" class="card" style="max-width:60em;padding:16px 20px;margin:14px 0">
        <?php wp_nonce_field('openar_send_badge'); ?>

        <label for="openar_badge_contact"><strong>Who</strong></label>
        <p style="margin:6px 0 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <select name="openar_badge_contact" id="openar_badge_contact" style="min-width:28em" required>
            <option value="">Choose a member</option>
            <?php foreach ($badgePeople as $person) : ?>
              <option value="<?php echo (int) $person['id']; ?>"><?php echo esc_html($person['label']); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" name="openar_badge_action" value="email" class="button">Email their badge</button>
          <button type="submit" name="openar_badge_action" value="download" class="button">Download it</button>
        </p>
      </form>
    <?php endif; ?>

    <h2 style="margin-top:2em">Send a supporter badge</h2>
    <p class="description" style="max-width:60em">
      The listing email already carries the badge, so this is for an
      organization that has lost theirs or asks for a fresh copy. The badge is
      drawn with the name the roster shows, the trade name if one is set and
      the legal name otherwise; a name too long to draw legibly gets the plain
      badge instead, and the list below says when that is the case. Email goes
      to the signer's address on file; Download saves the same image here.
    </p>

    <?php $sBadgeProblem = function_exists('openar_supporter_badge_problem') ? openar_supporter_badge_problem() : 'The badges plugin is not loaded.'; ?>
    <?php $sBadgeOrgs = $sBadgeProblem === '' ? openar_admin_supporter_badge_candidates() : []; ?>
    <?php if ($sBadgeProblem !== '') : ?>
      <p class="description"><strong>Badges cannot be drawn.</strong> <?php echo esc_html($sBadgeProblem); ?></p>
    <?php elseif (!$sBadgeOrgs) : ?>
      <p class="description">There are no published Mission Supporters to send to.</p>
    <?php else : ?>
      <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>" class="card" style="max-width:60em;padding:16px 20px;margin:14px 0">
        <?php wp_nonce_field('openar_send_supporter_badge'); ?>

        <label for="openar_supporter_badge_contact"><strong>Which organization</strong></label>
        <p style="margin:6px 0 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <select name="openar_supporter_badge_contact" id="openar_supporter_badge_contact" style="min-width:28em" required>
            <option value="">Choose an organization</option>
            <?php foreach ($sBadgeOrgs as $orgRow) : ?>
              <option value="<?php echo (int) $orgRow['id']; ?>"><?php echo esc_html($orgRow['label']); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" name="openar_supporter_badge_action" value="email" class="button">Email their badge</button>
          <button type="submit" name="openar_supporter_badge_action" value="download" class="button">Download it</button>
        </p>
      </form>
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

    <h2 id="openar-danger" style="margin-top:2.5em;padding-top:1.25em;border-top:2px solid #d63638;color:#a13b1e">
      Danger zone: ending someone's participation
    </h2>

    <div style="max-width:60em;padding:14px 18px;margin:0 0 16px;background:#fcf0f1;border-left:4px solid #d63638">
      <p style="margin:0 0 10px"><strong>Read this before using anything below it.</strong>
        Revocation is available only on the grounds the Community Programs and
        Standards Policy states, and for nothing else. In practice almost every
        legitimate use is somebody asking to be removed.</p>

      <p style="margin:0 0 6px"><strong>A membership may be revoked only for</strong>
        (Section 7.2): violation of the community standards in Article V; material
        misrepresentation in the application or professional identification; conduct
        materially inconsistent with the Foundation's charitable purposes; or
        unlawful conduct in or directed at the Foundation's spaces or participants.</p>

      <p style="margin:0 0 10px"><strong>An organization's participation may be revoked only for</strong>
        (Section 7.3): material misrepresentation in the Statement of Support,
        including the signatory's authority to bind it; use of the designation
        inconsistent with the Statement that is not corrected after notice; unlawful
        conduct directed at the Foundation, its community, or its participants; or a
        determination that continued participation would be unlawful.</p>

      <p style="margin:0 0 10px;padding:10px 14px;background:#fff;border-left:3px solid #a13b1e">
        <strong>Criticism of the Foundation is never a ground, and that is deliberate.</strong>
        The policy says a member "may question, criticize, or publicly disagree with
        the Foundation's board, officers, software, technical decisions, educational
        materials, published positions, governance, or this policy, without any risk
        to that member's standing." A change in someone's views is not a ground
        either, and an organization's products, pricing, business model, customer
        base, or opinions about industry practices are never grounds.</p>

      <p style="margin:0 0 10px;padding:10px 14px;background:#fff;border-left:3px solid #5c564c">
        <strong>Where the line falls is the policy's own.</strong> The community
        standards "govern how a member treats other participants, not what a member
        thinks of the Foundation." So disagreement, however blunt or public, is not
        a ground. How somebody treats people is a separate question, and harassment,
        personal attacks and intimidation are Article V(a), which is a real ground
        under Section 7.2(i). Judging which of the two you are looking at is the
        job. If it is close, it is a Board conversation rather than a decision to
        make on this screen.</p>

      <p style="margin:0">If what you have in front of you is not on the two lists
        above, the answer is not revocation. Moderation, a correction request under
        Section 4.6, or a conversation is. When in doubt, ask the Board first: this
        cannot be undone, and the person may not reapply for a year.</p>
    </div>


    <?php if ($confirm) : ?>
      <div class="card" style="max-width:60em;padding:16px 20px;border-left:4px solid #d63638">
        <h3 style="margin:0 0 8px">Revoke <?php echo esc_html($confirm['name']); ?>?</h3>
        <p style="margin:0 0 10px">
          <?php if ($confirm['supporter']) : ?>
            This removes the organization from the public roster, so the next hourly
            sync takes it off openarcollective.org, and emails the signer the reason
            below. Under Section 7.7 the organization may not sign again for a year.
          <?php else : ?>
            This ends their access to the members-only spaces, retires their member
            number, and emails them the reason below. Under Section 7.7 they may not
            apply again for a year.
          <?php endif; ?>
        </p>
        <p style="margin:0 0 4px"><strong>They will be sent this, word for word:</strong></p>
        <p style="padding:12px 16px;border-left:3px solid #b8b0a4;background:#f6f4f0;white-space:pre-wrap;margin:0 0 14px"><?php echo esc_html($confirm['reason']); ?></p>

        <?php if (!$confirm['supporter']) : ?>
          <p style="margin:0 0 14px;padding:10px 14px;background:#fcf9e8;border-left:4px solid #dba617">
            <strong>This does not remove them from Discord.</strong>
            Nothing here touches the server, so they keep their access and their
            roles until somebody removes them by hand. Do that in Discord after
            this, or they will still be in the members-only channels.
            <?php if ($confirm['discord']) : ?>
              <br />Their Discord user ID is
              <code><?php echo esc_html($confirm['discord']); ?></code>,
              which you can search in the member list to find them.
            <?php else : ?>
              <br />No Discord ID is recorded against them, so they may never have
              connected an account. Worth checking before you go looking.
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <div style="display:flex;gap:12px;align-items:center">
          <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>#openar-danger" style="margin:0">
            <?php wp_nonce_field("openar_decide_{$confirm['id']}"); ?>
            <input type="hidden" name="openar_contact" value="<?php echo (int) $confirm['id']; ?>" />
            <input type="hidden" name="openar_decide" value="revoke" />
            <input type="hidden" name="openar_reason" value="<?php echo esc_attr($confirm['reason']); ?>" />
            <button type="submit" class="button button-primary" style="background:#d63638;border-color:#d63638">
              Yes, revoke and send this
            </button>
          </form>
          <a href="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>">Cancel</a>
        </div>
      </div>

    <?php elseif (!$revocable) : ?>
      <p class="description" style="max-width:60em">
        Nobody is currently a member or on the published roster, so there is
        nothing to revoke.
      </p>

    <?php else : ?>
      <p class="description" style="max-width:60em">
        Revoking takes participation away from someone in good standing. It is not
        the same as declining an application, which refuses someone who was never
        admitted: a declined applicant may apply again at once, while Section 7.7
        makes a revoked one wait a year. You will be shown what you have written
        before anything is sent.
      </p>

      <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=' . OPENAR_ADMIN_SLUG)); ?>#openar-danger" class="card" style="max-width:60em;padding:16px 20px;margin:14px 0">
        <?php wp_nonce_field('openar_revoke_review'); ?>
        <input type="hidden" name="openar_revoke_review" value="1" />

        <p style="margin:0 0 10px">
          <label for="openar_contact"><strong>Who</strong></label><br />
          <select name="openar_contact" id="openar_contact" style="min-width:28em" required>
            <option value="">Choose a member or a listed organization</option>
            <?php foreach (['member' => 'Members', 'supporter' => 'Mission Supporters'] as $kind => $heading) : ?>
              <?php $group = array_filter($revocable, fn($r) => $r['kind'] === $kind); ?>
              <?php if ($group) : ?>
                <optgroup label="<?php echo esc_attr($heading); ?>">
                  <?php foreach ($group as $r) : ?>
                    <option value="<?php echo (int) $r['id']; ?>"><?php echo esc_html($r['label']); ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </p>

        <p style="margin:0 0 10px">
          <label for="openar_reason"><strong>Reason</strong></label><br />
          <span class="description">Sent to them word for word. The policy requires that
            they be told the basis, so this cannot be left empty.</span><br />
          <textarea name="openar_reason" id="openar_reason" rows="3" style="width:100%" required></textarea>
        </p>

        <button type="submit" class="button">Review this revocation</button>
      </form>
    <?php endif; ?>
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
    'OpenAR Members & Supporters',
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

/* ------------------------------------------------ the roster sync, on demand -- */

// Written by the web user, read by rob's cron. Not a lock or a queue: the file
// carries nothing but its own modification time, and the responder acts when
// that time is newer than the last one it handled.
const OPENAR_SYNC_REQUEST = '/tmp/openar-roster-sync.request';
const OPENAR_SYNC_LOG = '/home/rob/openar-roster/last-run.log';

/**
 * Ask for a roster sync.
 *
 * The screen cannot run it. Pushing to the website repository needs the GitHub
 * App private key, which is mode 600 and owned by rob, and the web server is
 * www-data. Widening that key to the web user to save a wait would be a bad
 * trade, so the button leaves a note and a one minute cron picks it up.
 */
function openar_admin_request_sync(): string {
  $ok = @file_put_contents(OPENAR_SYNC_REQUEST, gmdate('c') . "\n");
  if ($ok === FALSE) {
    return '';
  }
  @chmod(OPENAR_SYNC_REQUEST, 0644);
  return 'Asked for a roster sync. It starts within a minute and usually takes a few '
    . 'seconds. Reload this page to see the result.';
}

/** The last sync, as the sync itself recorded it. */
function openar_admin_sync_status(): array {
  $log = @file_get_contents(OPENAR_SYNC_LOG);
  if ($log === FALSE) {
    return ['when' => NULL, 'summary' => 'No sync log is readable yet.', 'failed' => FALSE];
  }

  $when = NULL;
  if (preg_match('/roster sync starting: (.+)$/m', $log, $m)) {
    $when = trim($m[1]);
  }

  $failed = str_contains($log, 'FAILED:');
  $summary = 'finished without saying what it did';

  if ($failed && preg_match('/FAILED: (.+)$/m', $log, $m)) {
    $summary = 'failed: ' . trim($m[1]);
  }
  elseif (str_contains($log, 'roster unchanged')) {
    $summary = 'no change to publish';
  }
  elseif (str_contains($log, 'pushed.')) {
    preg_match_all('/^\s{2}(add|update|remove):\s+(.+)$/m', $log, $m, PREG_SET_ORDER);
    $bits = [];
    foreach ($m as $line) {
      if (trim($line[2]) !== 'none') {
        $bits[] = $line[1] . ' ' . trim($line[2]);
      }
    }
    $summary = 'published' . ($bits ? ': ' . implode('; ', $bits) : '');
  }
  elseif (str_contains($log, 'still the holding page')) {
    $summary = 'stopped, because the branch it publishes to has no roster yet';
  }

  // A pending request the responder has not reached yet.
  $requested = @filemtime(OPENAR_SYNC_REQUEST);
  $waiting = ($requested && $when && strtotime($when . ' UTC') < $requested);

  return ['when' => $when, 'summary' => $summary, 'failed' => $failed, 'waiting' => $waiting];
}

/**
 * Everyone whose participation could be revoked: current members, and
 * organizations currently on the public roster.
 *
 * Unlike a review queue there is no natural list here, because revocation acts
 * on somebody who is already in good standing. So the screen has to offer a
 * choice, and the choice has to be exactly the people it is possible to revoke,
 * rather than every contact in the database.
 */
function openar_admin_revocable(): array {
  if (!function_exists('civi_wp')) {
    return [];
  }
  civi_wp()->initialize();

  $out = [];

  foreach ([
    [OPENAR_MEMBERS_GROUP, 'member', 'Membership.member_number'],
    [OPENAR_SUPPORTERS_PUBLISHED_GROUP, 'supporter', NULL],
  ] as [$group, $kind, $numberField]) {
    $gid = openar_admin_group_id($group);
    if (!$gid) {
      continue;
    }
    $ids = [];
    foreach (civicrm_api4('GroupContact', 'get', [
      'select' => ['contact_id'],
      'where' => [['group_id', '=', $gid], ['status', '=', 'Added'],
                  ['contact_id.is_deleted', '=', FALSE]],
      'checkPermissions' => FALSE,
    ]) as $r) {
      $ids[] = (int) $r['contact_id'];
    }
    if (!$ids) {
      continue;
    }

    $select = ['id', 'display_name', 'sort_name'];
    if ($numberField) {
      $select[] = $numberField;
    }

    foreach (civicrm_api4('Contact', 'get', [
      'select' => $select,
      'where' => [['id', 'IN', $ids]],
      'orderBy' => ['sort_name' => 'ASC'],
      'checkPermissions' => FALSE,
    ]) as $c) {
      $label = $c['display_name'];
      if ($numberField && !empty($c[$numberField])) {
        $label .= ' (member ' . $c[$numberField] . ')';
      }
      $out[] = ['id' => (int) $c['id'], 'label' => $label, 'kind' => $kind];
    }
  }

  // Sorted on the label, which is what the reader sees. CiviCRM's sort_name is
  // "Last, First" while display_name is "First Last", so ordering by sort_name
  // and showing display_name produces a list that is correctly sorted on a
  // string nobody can see, and looks shuffled to the person using it.
  usort($out, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

  return $out;
}

/**
 * Revocations that have taken effect but told nobody.
 *
 * Adding somebody to a revoked group ends their access immediately, and the
 * notice is held until a reason is written, because the policy requires that
 * they be given the basis. The reviewer gets an email saying so, but an email
 * is easy to miss and the person is meanwhile revoked without knowing it. This
 * is that state, on a screen.
 */
function openar_admin_revocations_awaiting_reason(): array {
  if (!function_exists('civi_wp')) {
    return [];
  }
  civi_wp()->initialize();

  $waiting = [];

  foreach ([
    ['members_revoked', 'Membership.revocation_reason'],
    ['supporters_revoked', 'MissionSupporter.revocation_reason'],
  ] as [$group, $field]) {
    $gid = openar_admin_group_id($group);
    if (!$gid) {
      continue;
    }
    foreach (civicrm_api4('GroupContact', 'get', [
      'select' => ['contact_id'],
      'where' => [
        ['group_id', '=', $gid],
        ['status', '=', 'Added'],
        ['contact_id.is_deleted', '=', FALSE],
      ],
      'checkPermissions' => FALSE,
    ]) as $r) {
      $c = civicrm_api4('Contact', 'get', [
        'select' => ['id', 'display_name', $field],
        'where' => [['id', '=', $r['contact_id']]],
        'checkPermissions' => FALSE,
      ])->first();

      if ($c && trim((string) ($c[$field] ?? '')) === '') {
        $waiting[] = ['id' => (int) $c['id'], 'display_name' => $c['display_name']];
      }
    }
  }

  return $waiting;
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

  // SearchKit addresses a display through a URL fragment, which the query
  // string helper cannot carry, so the display path is appended after it. The
  // two names are the saved search and the display of the same names in
  // CiviCRM; rename either there and this link stops resolving.
  $display = fn(string $search, string $table) => openar_admin_civi_url('civicrm/search')
    . '#/display/' . $search . '/' . $table;

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
      'url' => $display('openar_members', 'openar_members_table'),
      'where' => 'Members table, in CiviCRM',
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

  <?php
  // Anybody sitting in a revoked group with no reason written. Their access has
  // already ended, but nothing has been sent to them, and the only other sign of
  // it is an email that is easy to lose. It belongs where the counts are.
  $stuck = openar_admin_revocations_awaiting_reason();
  ?>
  <?php if ($stuck) : ?>
    <p style="margin:10px 0 0;padding:8px 10px;background:#fcf0f1;border-left:4px solid #d63638;font-size:12px">
      <strong>A revocation is unfinished.</strong>
      Access has ended for
      <?php echo esc_html(implode(', ', array_column($stuck, 'display_name'))); ?>,
      but nothing has been sent, because the reason has not been written.
      Open the record, fill in <strong>Reason for revocation</strong>, and save.
      The notice goes out on save.
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


/**
 * Everyone who could be sent a Discord link: current members, in the order a
 * reader would look for them.
 *
 * Members who have already connected are included rather than filtered out.
 * Somebody who has lost access to their Discord account needs exactly this, and
 * the connect flow handles a second account by replacing the stored id and
 * noting the change on the record.
 */
function openar_admin_discord_candidates(): array {
  if (!function_exists('civi_wp')) {
    return [];
  }
  civi_wp()->initialize();

  $gid = openar_admin_group_id(OPENAR_MEMBERS_GROUP);
  if (!$gid) {
    return [];
  }

  $ids = [];
  foreach (civicrm_api4('GroupContact', 'get', [
    'select' => ['contact_id'],
    'where' => [['group_id', '=', $gid], ['status', '=', 'Added'],
                ['contact_id.is_deleted', '=', FALSE]],
    'checkPermissions' => FALSE,
  ]) as $r) {
    $ids[] = (int) $r['contact_id'];
  }
  if (!$ids) {
    return [];
  }

  $out = [];
  foreach (civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'Membership.member_number', 'Membership.discord_user_id'],
    'where' => [['id', 'IN', $ids]],
    'checkPermissions' => FALSE,
  ]) as $c) {
    $label = (string) $c['display_name'];
    if (!empty($c['Membership.member_number'])) {
      $label .= ' (member ' . $c['Membership.member_number'] . ')';
    }
    if (!empty($c['Membership.discord_user_id'])) {
      $label .= ' - already connected';
    }
    $out[] = ['id' => (int) $c['id'], 'label' => $label];
  }

  // Sorted on the label for the same reason the revocation list is.
  usort($out, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

  return $out;
}

/**
 * Send one member their Discord link.
 *
 * @return string A sentence for the screen. Anything not starting "Sent" is an error.
 */
function openar_admin_send_discord_link(int $contactId): string {
  if (!function_exists('openar_discord_link_for')) {
    return 'The Discord plugin is not loaded, so no link could be built.';
  }
  civi_wp()->initialize();

  $contact = civicrm_api4('Contact', 'get', [
    'select' => ['id', 'first_name', 'display_name', 'Membership.member_number'],
    'where' => [['id', '=', $contactId]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$contact) {
    return "Contact #{$contactId} no longer exists.";
  }
  if (!function_exists('openar_in_group') || !openar_in_group($contactId, OPENAR_MEMBERS_GROUP)) {
    return "{$contact['display_name']} is not a current member, so no link was sent.";
  }

  $link = openar_discord_link_for($contactId);
  if ($link === '') {
    return 'Discord is not configured, so there is no link to send.';
  }

  $email = civicrm_api4('Email', 'get', [
    'select' => ['email', 'on_hold'],
    'where' => [['contact_id', '=', $contactId]],
    'orderBy' => ['is_primary' => 'DESC'],
    'checkPermissions' => FALSE,
  ])->first();

  if (empty($email['email'])) {
    return "{$contact['display_name']} has no email address on file.";
  }
  if (!empty($email['on_hold'])) {
    return "{$contact['display_name']}'s address is on hold because mail to it bounced. Nothing was sent.";
  }

  $template = civicrm_api4('MessageTemplate', 'get', [
    'select' => ['id'],
    'where' => [['msg_title', '=', 'OpenAR - Your Discord link, again'], ['is_active', '=', TRUE]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$template) {
    return 'The Discord link template is missing. Run connect-template.php.';
  }

  [$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail();

  CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email['email'],
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'firstName' => $contact['first_name'] ?? '',
      'memberNumber' => $contact['Membership.member_number'] ?? '',
      'discordUrl' => $link,
      'expiryDays' => (int) (Civi::settings()->get('checksum_timeout') ?: 7),
    ],
  ]);

  return sprintf('Sent a fresh Discord link to %s at %s.', $contact['display_name'], $email['email']);
}

/**
 * Everyone who has a badge to send: current members with a member number.
 *
 * The number is what the badge is drawn from, so a member without one has no
 * badge yet and is left off the list rather than offered and refused. The
 * Dashboard already warns when the numbered count and the members group
 * disagree, so the gap is visible where the counts are.
 */
function openar_admin_badge_candidates(): array {
  if (!function_exists('civi_wp')) {
    return [];
  }
  civi_wp()->initialize();

  $gid = openar_admin_group_id(OPENAR_MEMBERS_GROUP);
  if (!$gid) {
    return [];
  }

  $ids = [];
  foreach (civicrm_api4('GroupContact', 'get', [
    'select' => ['contact_id'],
    'where' => [['group_id', '=', $gid], ['status', '=', 'Added'],
                ['contact_id.is_deleted', '=', FALSE]],
    'checkPermissions' => FALSE,
  ]) as $r) {
    $ids[] = (int) $r['contact_id'];
  }
  if (!$ids) {
    return [];
  }

  $out = [];
  foreach (civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'Membership.member_number'],
    'where' => [['id', 'IN', $ids], ['Membership.member_number', 'IS NOT EMPTY']],
    'checkPermissions' => FALSE,
  ]) as $c) {
    $out[] = [
      'id' => (int) $c['id'],
      'number' => (int) $c['Membership.member_number'],
      'label' => $c['display_name'] . ' (member ' . $c['Membership.member_number'] . ')',
    ];
  }

  // Sorted on the label for the same reason the revocation list is.
  usort($out, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

  return $out;
}

/**
 * Email one member their badge.
 *
 * @return string A sentence for the screen. Anything not starting "Sent" is an error.
 */
function openar_admin_send_badge(int $contactId): string {
  if (!function_exists('openar_member_badge_attachment')) {
    return 'The badges plugin is not loaded, so no badge could be drawn.';
  }
  $problem = openar_badge_problem();
  if ($problem !== '') {
    return $problem;
  }
  civi_wp()->initialize();

  $contact = civicrm_api4('Contact', 'get', [
    'select' => ['id', 'first_name', 'display_name', 'Membership.member_number'],
    'where' => [['id', '=', $contactId]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$contact) {
    return "Contact #{$contactId} no longer exists.";
  }
  if (!function_exists('openar_in_group') || !openar_in_group($contactId, OPENAR_MEMBERS_GROUP)) {
    return "{$contact['display_name']} is not a current member, so no badge was sent.";
  }

  $number = (int) ($contact['Membership.member_number'] ?? 0);
  if ($number < 1) {
    return "{$contact['display_name']} has no member number, so there is no badge to draw.";
  }

  $email = civicrm_api4('Email', 'get', [
    'select' => ['email', 'on_hold'],
    'where' => [['contact_id', '=', $contactId]],
    'orderBy' => ['is_primary' => 'DESC'],
    'checkPermissions' => FALSE,
  ])->first();

  if (empty($email['email'])) {
    return "{$contact['display_name']} has no email address on file.";
  }
  if (!empty($email['on_hold'])) {
    return "{$contact['display_name']}'s address is on hold because mail to it bounced. Nothing was sent.";
  }

  $template = civicrm_api4('MessageTemplate', 'get', [
    'select' => ['id'],
    'where' => [['msg_title', '=', 'OpenAR - Your member badge'], ['is_active', '=', TRUE]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$template) {
    return 'The badge template is missing. Run badge-template.php.';
  }

  $badge = openar_member_badge_attachment($number);
  if (!$badge) {
    return 'The badge image could not be drawn. Check the PHP error log.';
  }

  [$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail();

  CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email['email'],
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'firstName' => $contact['first_name'] ?? '',
      'memberNumber' => $number,
    ],
    'attachments' => [$badge],
  ]);

  @unlink($badge['fullPath']);

  return sprintf('Sent member badge #%d to %s at %s.', $number, $contact['display_name'], $email['email']);
}

/**
 * Stream one member's badge to the browser as a download.
 *
 * Runs on admin_init because it has to answer with a PNG instead of a page,
 * and by the time the Tools screen callback runs the admin header is already
 * out the door. It handles only its own form (the Download button beside
 * "Send a member badge") and touches nothing else.
 *
 * A POST with a nonce, like every other action here, even though it changes
 * nothing: the payoff of a GET would be a bookmarkable URL, and a bookmark to
 * a member's badge is not worth having two patterns on one screen.
 */
function openar_admin_badge_download(): void {
  if (($_POST['openar_badge_action'] ?? '') !== 'download' || empty($_POST['openar_badge_contact'])) {
    return;
  }
  if (!current_user_can(OPENAR_ADMIN_CAP)) {
    return;
  }
  check_admin_referer('openar_send_badge');

  if (!function_exists('openar_member_badge_create') || !function_exists('civi_wp')) {
    wp_die('The badges plugin is not loaded, so no badge could be drawn.',
      '', ['back_link' => TRUE]);
  }
  $problem = openar_badge_problem();
  if ($problem !== '') {
    wp_die(esc_html($problem), '', ['back_link' => TRUE]);
  }

  civi_wp()->initialize();
  $contactId = (int) $_POST['openar_badge_contact'];
  $contact = civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'Membership.member_number'],
    'where' => [['id', '=', $contactId]],
    'checkPermissions' => FALSE,
  ])->first();

  $number = (int) ($contact['Membership.member_number'] ?? 0);
  if (!$contact || $number < 1) {
    wp_die('That contact has no member number, so there is no badge to draw.',
      '', ['back_link' => TRUE]);
  }

  $path = openar_member_badge_create($number);
  if ($path === NULL) {
    wp_die('The badge image could not be drawn. Check the PHP error log.',
      '', ['back_link' => TRUE]);
  }

  nocache_headers();
  header('Content-Type: image/png');
  header('Content-Disposition: attachment; filename="openar-member-badge-' . $number . '.png"');
  header('Content-Length: ' . (string) filesize($path));
  readfile($path);
  @unlink($path);
  exit;
}

/**
 * Every organization that has a badge to send: the published supporters.
 *
 * The label carries the badge name when one is set, so the choice shows what
 * will actually be drawn before anything is sent.
 */
function openar_admin_supporter_badge_candidates(): array {
  if (!function_exists('civi_wp')) {
    return [];
  }
  civi_wp()->initialize();

  $gid = openar_admin_group_id(OPENAR_SUPPORTERS_PUBLISHED_GROUP);
  if (!$gid) {
    return [];
  }

  $ids = [];
  foreach (civicrm_api4('GroupContact', 'get', [
    'select' => ['contact_id'],
    'where' => [['group_id', '=', $gid], ['status', '=', 'Added'],
                ['contact_id.is_deleted', '=', FALSE]],
    'checkPermissions' => FALSE,
  ]) as $r) {
    $ids[] = (int) $r['contact_id'];
  }
  if (!$ids) {
    return [];
  }

  $out = [];
  foreach (civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'organization_name', 'MissionSupporter.trade_name'],
    'where' => [['id', 'IN', $ids]],
    'checkPermissions' => FALSE,
  ]) as $c) {
    // The label is the name the badge will carry, so the choice shows what
    // will be drawn. Marked only for the exception: a name too long to draw,
    // which gets the plain badge instead.
    $label = openar_admin_supporter_badge_name($c);
    if (function_exists('openar_supporter_badge_layout')
      && openar_supporter_badge_layout($label) === NULL) {
      $label .= ' - too long to draw; gets the plain badge';
    }
    $out[] = ['id' => (int) $c['id'], 'label' => $label];
  }

  // Sorted on the label for the same reason the revocation list is.
  usort($out, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));

  return $out;
}

/**
 * The name an organization's badge is drawn with: the name the roster shows,
 * the trade name if one is set and the legal name otherwise. Nothing is
 * stored; what the roster says is what the badge says.
 */
function openar_admin_supporter_badge_name(array $org): string {
  return trim((string) ($org['MissionSupporter.trade_name'] ?? ''))
    ?: (string) (($org['organization_name'] ?? '') ?: ($org['display_name'] ?? ''));
}

/**
 * The attachment one organization's badge send should carry, or an error
 * sentence.
 *
 * Shared by the email and download paths so the two can never disagree about
 * which badge an organization gets. The named badge is a temporary file the
 * caller unlinks; the plain badge is the static asset and must be left alone.
 * 'temp' says which kind came back, 'drawn' carries the name when one was
 * drawn, and both are stripped before mailing.
 *
 * @return array|string The attachment array, or the error as a sentence.
 */
function openar_admin_supporter_badge_for(array $org) {
  $name = openar_admin_supporter_badge_name($org);

  // A name too long to draw legibly gets the plain badge, the expected
  // outcome for some legal names rather than an error worth refusing over.
  if ($name !== '' && openar_supporter_badge_problem() === ''
    && openar_supporter_badge_layout($name) !== NULL) {
    $badge = openar_supporter_badge_named_attachment($name);
    if ($badge) {
      return $badge + ['temp' => TRUE, 'drawn' => $name];
    }
  }

  $badge = openar_supporter_badge_attachment();
  if (!$badge) {
    return 'The plain supporter badge (openar-assets/openar-mission-supporter-badge-512.png) is missing.';
  }
  return $badge + ['temp' => FALSE];
}

/**
 * Email one organization its Mission Supporter badge.
 *
 * @return string A sentence for the screen. Anything not starting "Sent" is an error.
 */
function openar_admin_send_supporter_badge(int $contactId): string {
  if (!function_exists('openar_supporter_badge_attachment')) {
    return 'The badges plugin is not loaded, so no badge could be sent.';
  }
  civi_wp()->initialize();

  $org = civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'organization_name', 'contact_type',
      'MissionSupporter.signer_name', 'MissionSupporter.signer_email',
      'MissionSupporter.trade_name'],
    'where' => [['id', '=', $contactId]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$org || $org['contact_type'] !== 'Organization') {
    return "Contact #{$contactId} is not an organization on file.";
  }
  $name = (string) ($org['organization_name'] ?: $org['display_name']);

  if (!function_exists('openar_in_group') || !openar_in_group($contactId, OPENAR_SUPPORTERS_PUBLISHED_GROUP)) {
    return "{$name} is not on the published roster, so no badge was sent.";
  }

  $email = trim((string) ($org['MissionSupporter.signer_email'] ?? ''));
  if ($email === '') {
    return "{$name} has no signer email address on file.";
  }

  $template = civicrm_api4('MessageTemplate', 'get', [
    'select' => ['id'],
    'where' => [['msg_title', '=', 'OpenAR - Your Mission Supporter badge'], ['is_active', '=', TRUE]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$template) {
    return 'The supporter badge template is missing. Run supporter-badge-template.php.';
  }

  $badge = openar_admin_supporter_badge_for($org);
  if (is_string($badge)) {
    return $badge;
  }
  $temp = !empty($badge['temp']);
  $drawn = (string) ($badge['drawn'] ?? '');
  unset($badge['temp'], $badge['drawn']);

  [$fromName, $fromEmail] = CRM_Core_BAO_Domain::getNameAndEmail();

  CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email,
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'firstName' => function_exists('openar_first_name')
        ? openar_first_name($org['MissionSupporter.signer_name'] ?? '')
        : trim((string) strtok((string) ($org['MissionSupporter.signer_name'] ?? ''), ' ')),
      'organizationName' => openar_admin_supporter_badge_name($org),
    ],
    'attachments' => [$badge],
  ]);

  if ($temp) {
    @unlink($badge['fullPath']);
  }

  return $drawn !== ''
    ? sprintf('Sent the Mission Supporter badge, drawn with "%s", to %s.', $drawn, $email)
    : sprintf('Sent the plain Mission Supporter badge to %s; the name is too long to draw legibly.', $email);
}

/**
 * Stream one organization's supporter badge to the browser as a download.
 *
 * The supporter twin of openar_admin_badge_download() above, and the way to
 * check how a badge name fits before emailing anything.
 */
function openar_admin_supporter_badge_download(): void {
  if (($_POST['openar_supporter_badge_action'] ?? '') !== 'download' || empty($_POST['openar_supporter_badge_contact'])) {
    return;
  }
  if (!current_user_can(OPENAR_ADMIN_CAP)) {
    return;
  }
  check_admin_referer('openar_send_supporter_badge');

  if (!function_exists('openar_supporter_badge_attachment') || !function_exists('civi_wp')) {
    wp_die('The badges plugin is not loaded, so no badge could be drawn.',
      '', ['back_link' => TRUE]);
  }

  civi_wp()->initialize();
  $contactId = (int) $_POST['openar_supporter_badge_contact'];
  $org = civicrm_api4('Contact', 'get', [
    'select' => ['id', 'display_name', 'organization_name', 'contact_type', 'MissionSupporter.trade_name'],
    'where' => [['id', '=', $contactId]],
    'checkPermissions' => FALSE,
  ])->first();

  if (!$org || $org['contact_type'] !== 'Organization') {
    wp_die('That contact is not an organization on file.', '', ['back_link' => TRUE]);
  }

  $badge = openar_admin_supporter_badge_for($org);
  if (is_string($badge)) {
    wp_die(esc_html($badge), '', ['back_link' => TRUE]);
  }
  $temp = !empty($badge['temp']);

  nocache_headers();
  header('Content-Type: image/png');
  header('Content-Disposition: attachment; filename="openar-mission-supporter-badge.png"');
  header('Content-Length: ' . (string) filesize($badge['fullPath']));
  readfile($badge['fullPath']);
  if ($temp) {
    @unlink($badge['fullPath']);
  }
  exit;
}
