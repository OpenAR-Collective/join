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
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

const OPENAR_ADMIN_SLUG = 'openar-onboarding';
const OPENAR_ADMIN_CAP = 'manage_options';

add_action('admin_menu', 'openar_admin_menu');

function openar_admin_menu(): void {
  add_management_page(
    'OpenAR onboarding',
    'OpenAR onboarding',
    OPENAR_ADMIN_CAP,
    OPENAR_ADMIN_SLUG,
    'openar_admin_page'
  );
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

    $rows[] = [
      'id' => (int) $s['id'],
      'form' => $s['afform_name'] === 'afformSupporterStatement' ? 'Statement of Support' : 'Membership',
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
