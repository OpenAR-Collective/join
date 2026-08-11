<?php
/**
 * Plugin Name: OpenAR Collective onboarding
 * Description: Confirmation links, review queues, and publication for membership and Mission Supporter signups.
 * Version:     1.2.0
 * License:     Apache-2.0
 *
 * Deployed as a must-use plugin at wp-content/mu-plugins/openar-onboarding.php,
 * so it loads without activation and cannot be switched off by accident.
 *
 * CiviCRM on WordPress dispatches hooks through do_action_ref_array, so the
 * hooks below are ordinary WordPress actions.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
  exit;
}

const OPENAR_APPLICATION_SOURCE = 'Membership application';
const OPENAR_REVIEW_GROUP = 'applicants_pending_review';
const OPENAR_REVIEW_INBOX = 'membership@openarcollective.org';
const OPENAR_REVIEW_TEMPLATE = 'OpenAR - New membership application for review';

const OPENAR_VERIFY_TEMPLATE = 'OpenAR - Confirm your email address';
const OPENAR_VERIFY_LIFETIME_DAYS = 7;

const OPENAR_MEMBERS_GROUP = 'members';
const OPENAR_DECLINED_GROUP = 'applicants_declined';
const OPENAR_ALREADY_MEMBER_TEMPLATE = 'OpenAR - You are already a member';
const OPENAR_ALREADY_APPLIED_TEMPLATE = 'OpenAR - Your application is already with us';

/**
 * A member's personal Discord connect link, or an empty string.
 *
 * Empty until the Discord credentials are present in wp-config.php, in which
 * case the emails tell the member to write to membership@ rather than carrying
 * a link that goes nowhere. Adding those constants is all it takes to switch
 * the link on; nothing here needs editing.
 *
 * The link carries a CiviCRM checksum rather than the member id, which is
 * sequential and would let anyone walk the range and admit themselves.
 */
function openar_discord_link_for(int $contactId): string {
  if (!function_exists('openar_discord_configured') || !openar_discord_configured()) {
    return '';
  }

  return openar_discord_connect_url()
    . '?cid=' . $contactId
    . '&cs=' . \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);
}

/** One courtesy email per address per this many hours, so the form cannot be used to flood a member's inbox. */
const OPENAR_REPEAT_NOTICE_HOURS = 24;
const OPENAR_REPEAT_ACTIVITY = 'Membership application received from an address already on file';

const OPENAR_WELCOME_TEMPLATE = 'OpenAR - Welcome to the Collective';
const OPENAR_DECLINE_TEMPLATE = 'OpenAR - Membership application declined';
const OPENAR_DECLINE_INCOMPLETE_TEMPLATE = 'OpenAR - Decline recorded without a reason';
const OPENAR_DECLINE_ACTIVITY = 'Membership application declined';

/**
 * Where an appeal goes.
 *
 * Deliberately membership@ rather than the board@ list, which reaches all five
 * directors at once and would have them answering an appellant separately and
 * inconsistently. One person takes the appeal and puts it to the Board as a
 * matter of procedure, which is what Term 13 describes. Do not "fix" this to a
 * board-wide alias.
 */
const OPENAR_APPEAL_INBOX = 'membership@openarcollective.org';

/**
 * Forms whose confirmation email this plugin sends. Each must have
 * manual_processing on and allow_verification_by_email off, or the person gets
 * two emails: Afform's ten-minute one and ours.
 *
 * The address is not in the same place on both forms. The membership form
 * collects it as an Email join on the contact; the Statement of Support keeps
 * the signer's address as a custom field on the organization, because the
 * signer is recorded rather than created as a contact of their own. Hence a
 * list of keys per form rather than one shared guess.
 */
const OPENAR_FORMS = [
  'afformMembershipApplication' => [
    'kind' => 'membership',
    'email_keys' => ['email'],
    'name_keys' => ['first_name'],
    'confirm_template' => 'OpenAR - Confirm your email address',
  ],
  'afformSupporterStatement' => [
    'kind' => 'supporter',
    'email_keys' => ['MissionSupporter.signer_email'],
    'name_keys' => ['MissionSupporter.signer_name'],
    'confirm_template' => 'OpenAR - Confirm your Statement of Support',
  ],
];

const OPENAR_SUPPORTER_SOURCE = 'Mission Supporter statement';
const OPENAR_SUPPORTERS_PENDING_GROUP = 'supporters_pending';
const OPENAR_SUPPORTERS_PUBLISHED_GROUP = 'supporters_published';
const OPENAR_SUPPORTER_REVIEW_TEMPLATE = 'OpenAR - New Statement of Support for review';
const OPENAR_SUPPORTER_LISTED_TEMPLATE = 'OpenAR - Your organization is now listed';
const OPENAR_SUPPORTER_LISTED_ACTIVITY = 'Mission Supporter listing confirmed';

/** The Statement version a signature is bound to. Bump when the Statement changes. */
const OPENAR_STATEMENT_VERSION = '1.2';

function openar_form_config(string $formName): ?array {
  return OPENAR_FORMS[$formName] ?? NULL;
}

add_action('civicrm_postCommit', 'openar_onboarding_post_commit', 10, 4);

function openar_onboarding_post_commit(string $op, string $objectName, $objectId, &$objectRef): void {
  if ($objectName !== 'AfformSubmission' || empty($objectId)) {
    return;
  }

  // Onboarding bookkeeping must never break the write that triggered it.
  try {
    if ($op === 'create') {
      openar_handle_new_submission((int) $objectId);
    }
    elseif ($op === 'edit') {
      openar_handle_processed_submission((int) $objectId);
    }
  }
  catch (\Throwable $e) {
    \Civi::log()->error('OpenAR onboarding failed on {entity} {id}: {msg}', [
      'entity' => $objectName,
      'id' => $objectId,
      'msg' => $e->getMessage(),
    ]);
  }
}

/* -------------------------------------------------------------------------
 * Stage 1: a form was submitted, so send the confirmation link.
 *
 * Afform stores the whole submission before emailing, and CRM_Afform_Page_Verify
 * only needs a validly signed JWT carrying a submissionId. It does not check the
 * scope claim, and it re-checks the submission status itself. So minting the
 * token here rather than letting Afform do it costs nothing and lets us choose
 * the lifetime; Afform hardcodes ten minutes in Civi\Afform\Tokens.
 * ---------------------------------------------------------------------- */

function openar_handle_new_submission(int $submissionId): void {
  $submission = \Civi\Api4\AfformSubmission::get(FALSE)
    ->addSelect('id', 'afform_name', 'status_id:name', 'data')
    ->addWhere('id', '=', $submissionId)
    ->execute()->first();

  if (!$submission || $submission['status_id:name'] !== 'Pending') {
    return;
  }

  $config = openar_form_config($submission['afform_name']);
  if (!$config) {
    return;
  }

  $data = openar_submission_data($submission);
  $email = openar_find_value($data, $config['email_keys']);
  if (!$email) {
    \Civi::log()->warning('OpenAR onboarding: submission {id} has no email address, no link sent', ['id' => $submissionId]);
    return;
  }

  // Only the membership form can collide with an existing contact this way. A
  // signer is recorded on the organization rather than created as a contact, so
  // there is no member record for their address to match.
  if ($config['kind'] === 'membership') {
    $existing = openar_lookup_by_email($email);
    if ($existing && $existing['state'] !== 'known') {
      openar_answer_repeat_applicant($existing, openar_find_value($data, $config['name_keys']) ?? '');
      \Civi\Api4\AfformSubmission::update(FALSE)
        ->addWhere('id', '=', $submissionId)
        ->addValue('status_id:name', 'Rejected')
        ->execute();
      return;
    }
  }

  // A resubmission supersedes the earlier attempt, so only the newest link is
  // live and there is one path to one contact.
  openar_supersede_earlier_submissions($submission['afform_name'], $submissionId, $email);

  openar_send_verification_link($submissionId);
}

/**
 * What we already know about an email address.
 *
 * Returns NULL when the address is new. Otherwise a state:
 *   member          already admitted
 *   pending_review  an application is with the reviewers
 *   declined        an application was declined, so a director picks it up
 *   known           on file for some other reason, such as a supporter's signer
 *                   or a director. They may apply; the reviewer is warned about
 *                   the duplicate rather than the record being merged for them.
 */
function openar_lookup_by_email(string $email): ?array {
  $match = \Civi\Api4\Email::get(FALSE)
    ->addSelect('contact_id')
    ->addWhere('email', '=', $email)
    ->addWhere('contact_id.is_deleted', '=', FALSE)
    ->addOrderBy('is_primary', 'DESC')
    ->execute()->first();

  if (!$match) {
    return NULL;
  }

  $contactId = (int) $match['contact_id'];

  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('id', 'first_name', 'display_name', 'Membership.member_number')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  if (!$contact) {
    return NULL;
  }

  $groups = [];
  foreach (\Civi\Api4\GroupContact::get(FALSE)
    ->addSelect('group_id:name')
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('status', '=', 'Added')
    ->execute() as $g) {
    $groups[] = $g['group_id:name'];
  }

  if (in_array(OPENAR_MEMBERS_GROUP, $groups, TRUE)) {
    $state = 'member';
  }
  elseif (in_array(OPENAR_REVIEW_GROUP, $groups, TRUE)) {
    $state = 'pending_review';
  }
  elseif (in_array(OPENAR_DECLINED_GROUP, $groups, TRUE)) {
    $state = 'declined';
  }
  else {
    $state = 'known';
  }

  return [
    'contact_id' => $contactId,
    'state' => $state,
    'first_name' => $contact['first_name'] ?? '',
    'display_name' => $contact['display_name'] ?? '',
    'member_number' => $contact['Membership.member_number'] ?? '',
  ];
}

/**
 * Tell a repeat applicant what they need to know, by email only.
 *
 * Nothing is written back to the page. The form is public and anonymous, so a
 * page that reported membership would let anyone test an address and learn
 * whether that person is a member, and their number.
 */
function openar_answer_repeat_applicant(array $existing, string $typedFirstName): void {
  $contactId = $existing['contact_id'];

  if (openar_notified_recently($contactId)) {
    \Civi::log()->info('OpenAR onboarding: repeat application for contact {cid} within the quiet period, no email sent', ['cid' => $contactId]);
    return;
  }

  $title = $existing['state'] === 'member'
    ? OPENAR_ALREADY_MEMBER_TEMPLATE
    : OPENAR_ALREADY_APPLIED_TEMPLATE;

  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', $title)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->error('OpenAR onboarding: template "' . $title . '" not found');
    return;
  }

  $email = \Civi\Api4\Email::get(FALSE)
    ->addSelect('email')
    ->addWhere('contact_id', '=', $contactId)
    ->addOrderBy('is_primary', 'DESC')
    ->execute()->first()['email'] ?? NULL;

  if (!$email) {
    return;
  }

  $discordUrl = openar_discord_link_for($contactId);

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email,
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'firstName' => $existing['first_name'] ?: $typedFirstName,
      'memberNumber' => $existing['member_number'],
      'discordUrl' => $discordUrl,
    ],
  ]);

  openar_record_repeat_notice($contactId, $existing['state']);

  // A declined applicant trying again is a judgment call for a director, so a
  // person is told rather than the attempt being silently absorbed.
  if ($existing['state'] === 'declined') {
    \CRM_Core_BAO_MessageTemplate::sendTemplate([
      'messageTemplateID' => $template['id'],
      'from' => sprintf('%s <%s>', $fromName, $fromEmail),
      'toEmail' => OPENAR_REVIEW_INBOX,
      'subject' => sprintf('Declined applicant reapplied: %s (contact %d)', $existing['display_name'], $contactId),
      'contactId' => $contactId,
      'tokenContext' => ['contactId' => $contactId],
      'tplParams' => ['firstName' => $existing['first_name'], 'memberNumber' => '', 'discordUrl' => ''],
    ]);
  }
}

/** Has this contact already had a courtesy email inside the quiet period? */
function openar_notified_recently(int $contactId): bool {
  $cutoff = date('Y-m-d H:i:s', \CRM_Utils_Time::time() - (OPENAR_REPEAT_NOTICE_HOURS * 3600));

  return (bool) \Civi\Api4\ActivityContact::get(FALSE)
    ->addSelect('id')
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('record_type_id:name', '=', 'Activity Targets')
    ->addWhere('activity_id.subject', '=', OPENAR_REPEAT_ACTIVITY)
    ->addWhere('activity_id.activity_date_time', '>', $cutoff)
    ->execute()->count();
}

/** Leave a trail so a reviewer can see that someone tried to apply again. */
function openar_record_repeat_notice(int $contactId, string $state): void {
  $domainContactId = (int) (\CRM_Core_BAO_Domain::getDomain()->contact_id ?? $contactId);

  \Civi\Api4\Activity::create(FALSE)
    ->addValue('activity_type_id:name', 'Email')
    ->addValue('subject', OPENAR_REPEAT_ACTIVITY)
    ->addValue('details', sprintf('State at the time: %s. Answered by email; nothing was shown on the application page.', $state))
    ->addValue('status_id:name', 'Completed')
    ->addValue('source_contact_id', $domainContactId)
    ->addValue('target_contact_id', [$contactId])
    ->execute();
}

/**
 * Mint a confirmation link for a pending submission and email it.
 * Also the resend path when someone lets a link lapse.
 */
function openar_send_verification_link(int $submissionId): bool {
  $submission = \Civi\Api4\AfformSubmission::get(FALSE)
    ->addSelect('id', 'afform_name', 'status_id:name', 'data')
    ->addWhere('id', '=', $submissionId)
    ->execute()->first();

  if (!$submission || $submission['status_id:name'] !== 'Pending') {
    \Civi::log()->warning('OpenAR onboarding: submission {id} is not pending, no link sent', ['id' => $submissionId]);
    return FALSE;
  }

  $config = openar_form_config($submission['afform_name']);
  if (!$config) {
    return FALSE;
  }

  $data = openar_submission_data($submission);
  $email = openar_find_value($data, $config['email_keys']);
  if (!$email) {
    return FALSE;
  }

  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', $config['confirm_template'])
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->error('OpenAR onboarding: template "' . $config['confirm_template'] . '" not found, no link sent');
    return FALSE;
  }

  $token = \Civi::service('crypto.jwt')->encode([
    'exp' => \CRM_Utils_Time::time() + (OPENAR_VERIFY_LIFETIME_DAYS * 24 * 60 * 60),
    'scope' => 'afformVerifyEmail',
    'submissionId' => $submissionId,
  ]);

  $url = \CRM_Utils_System::getNotifyUrl(
    'civicrm/afform/submission/verify',
    ['token' => $token],
    TRUE, NULL, NULL, TRUE
  );

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email,
    'tplParams' => [
      'verifyUrl' => $url,
      'firstName' => openar_first_name(openar_find_value($data, $config['name_keys'])),
      'expiryDays' => OPENAR_VERIFY_LIFETIME_DAYS,
    ],
  ]);

  return TRUE;
}

/** Mark this applicant's earlier unconfirmed attempts on the same form as superseded. */
function openar_supersede_earlier_submissions(string $formName, int $keepId, string $email): void {
  $earlier = \Civi\Api4\AfformSubmission::get(FALSE)
    ->addSelect('id', 'data')
    ->addWhere('afform_name', '=', $formName)
    ->addWhere('status_id:name', '=', 'Pending')
    ->addWhere('id', '<', $keepId)
    ->execute();

  $config = openar_form_config($formName);
  foreach ($earlier as $old) {
    $oldEmail = openar_find_value(openar_submission_data($old), $config['email_keys'] ?? ['email']);
    if ($oldEmail && strcasecmp($oldEmail, $email) === 0) {
      \Civi\Api4\AfformSubmission::update(FALSE)
        ->addWhere('id', '=', $old['id'])
        ->addValue('status_id:name', 'Rejected')
        ->execute();
    }
  }
}

/* -------------------------------------------------------------------------
 * Stage 2: the link was clicked, so the contact now exists.
 * ---------------------------------------------------------------------- */

/**
 * The applicant clicked the confirmation link and everything has been written.
 *
 * This deliberately hangs off the submission flipping to Processed rather than
 * off contact creation. Afform\Process writes the contact first and its joins
 * and custom fields after, so a hook on contact creation runs too early: the
 * email address does not exist yet, and the reviewer notification renders with
 * an empty employer, which is the one field a reviewer is there to check.
 */
function openar_handle_processed_submission(int $submissionId): void {
  $submission = \Civi\Api4\AfformSubmission::get(FALSE)
    ->addSelect('afform_name', 'status_id:name', 'data')
    ->addWhere('id', '=', $submissionId)
    ->execute()->first();

  if (!$submission || $submission['status_id:name'] !== 'Processed') {
    return;
  }

  $config = openar_form_config($submission['afform_name']);
  if (!$config) {
    return;
  }

  foreach (openar_submission_entity_ids(openar_submission_data($submission)) as $contactId) {
    if ($config['kind'] === 'supporter') {
      openar_handle_new_supporter($contactId);
    }
    else {
      openar_handle_new_contact($contactId);
    }
  }
}

/**
 * The ids Afform wrote, taken from the top level of each entity.
 * Join records carry ids too, but they are nested under 'joins'.
 */
function openar_submission_entity_ids(array $data): array {
  $ids = [];
  foreach ($data as $items) {
    if (!is_array($items)) {
      continue;
    }
    foreach ($items as $item) {
      if (is_array($item) && !empty($item['id'])) {
        $ids[] = (int) $item['id'];
      }
    }
  }
  return array_values(array_unique($ids));
}

/* -------------------------------------------------------------------------
 * Mission Supporter path.
 *
 * The shape mirrors membership: confirm the address, queue for a person,
 * publish on approval. The differences are that the signer is recorded on the
 * organization rather than created as a contact, and that publishing is what
 * puts the organization on a public web page, so the review step carries more
 * weight than it does for an individual.
 * ---------------------------------------------------------------------- */

/** A Statement of Support was confirmed. Queue the organization for review. */
function openar_handle_new_supporter(int $contactId): void {
  $org = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('id', 'display_name', 'organization_name', 'source',
      'MissionSupporter.signer_email', 'MissionSupporter.statement_version')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  if (!$org || ($org['source'] ?? '') !== OPENAR_SUPPORTER_SOURCE) {
    return;
  }

  // Which Statement they signed has to be stored per record, because a later
  // version cannot be inferred backwards onto an existing signature.
  if (empty($org['MissionSupporter.statement_version'])) {
    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('MissionSupporter.statement_version', OPENAR_STATEMENT_VERSION)
      ->addValue('MissionSupporter.signed_date', date('Y-m-d H:i:s'))
      ->execute();
  }

  openar_add_to_group($contactId, OPENAR_SUPPORTERS_PENDING_GROUP);
  openar_notify_supporter_reviewers($contactId, openar_find_supporter_duplicates($contactId));
}

/**
 * Other organizations already signed up under a comparable name.
 *
 * Matched on a squashed name because "Acme Inc" and "Acme, Inc." are the same
 * organization signing twice. Advisory only: it warns a reviewer rather than
 * blocking, since two genuinely different organizations can have similar names.
 */
function openar_find_supporter_duplicates(int $contactId): array {
  $me = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('organization_name')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  $squash = static fn(string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
  $mine = $squash((string) ($me['organization_name'] ?? ''));
  if ($mine === '') {
    return [];
  }

  $found = [];
  foreach (\Civi\Api4\Contact::get(FALSE)
    ->addSelect('id', 'organization_name', 'display_name')
    ->addWhere('contact_type', '=', 'Organization')
    ->addWhere('id', '!=', $contactId)
    ->addWhere('is_deleted', '=', FALSE)
    ->execute() as $other) {
    if ($squash((string) ($other['organization_name'] ?? '')) === $mine) {
      $found[(int) $other['id']] = $other['display_name'];
    }
  }

  return $found;
}

function openar_notify_supporter_reviewers(int $contactId, array $duplicates = []): void {
  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', OPENAR_SUPPORTER_REVIEW_TEMPLATE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->warning('OpenAR onboarding: supporter review template not found, no notification sent');
    return;
  }

  $warningText = '';
  $warningHtml = '';
  if ($duplicates) {
    $lines = [];
    foreach ($duplicates as $id => $name) {
      $lines[] = sprintf('contact %d (%s)', $id, $name);
    }
    $joined = implode(', ', $lines);
    $warningText = "\nAlready on file: an organization with the same name is recorded as " . $joined . ".\n";
    $warningHtml = '<p style="padding:10px 14px;border-left:3px solid #e8a020;background:#fdf6e7;">'
      . '<strong>Already on file.</strong> An organization with the same name is recorded as '
      . htmlspecialchars($joined) . '.</p>';
  }

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => OPENAR_REVIEW_INBOX,
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'duplicateWarningText' => $warningText,
      'duplicateWarningHtml' => $warningHtml,
    ],
  ]);
}

/**
 * Approved. The organization goes on the public roster.
 *
 * Publishing is the only step here that reaches openarcollective.org, so it is
 * the point at which the signer is told, and the point the roster sync reads.
 */
function openar_publish_supporter(int $contactId): void {
  $org = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('id', 'display_name', 'organization_name', 'contact_type',
      'MissionSupporter.signer_email', 'MissionSupporter.signer_name', 'MissionSupporter.trade_name')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  if (!$org || $org['contact_type'] !== 'Organization') {
    return;
  }

  openar_remove_from_group($contactId, OPENAR_SUPPORTERS_PENDING_GROUP);

  if (openar_supporter_already_told($contactId)) {
    return;
  }

  $email = trim((string) ($org['MissionSupporter.signer_email'] ?? ''));
  if ($email === '') {
    \Civi::log()->warning('OpenAR onboarding: supporter {cid} published with no signer address', ['cid' => $contactId]);
    return;
  }

  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', OPENAR_SUPPORTER_LISTED_TEMPLATE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->error('OpenAR onboarding: supporter listed template not found');
    return;
  }

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email,
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'firstName' => openar_first_name($org['MissionSupporter.signer_name'] ?? ''),
      'organizationName' => trim((string) ($org['MissionSupporter.trade_name'] ?? ''))
        ?: (string) $org['organization_name'],
    ],
  ]);

  \Civi\Api4\Activity::create(FALSE)
    ->addValue('activity_type_id:name', 'Email')
    ->addValue('subject', OPENAR_SUPPORTER_LISTED_ACTIVITY)
    ->addValue('status_id:name', 'Completed')
    ->addValue('source_contact_id', (int) (\CRM_Core_BAO_Domain::getDomain()->contact_id ?? $contactId))
    ->addValue('target_contact_id', [$contactId])
    ->execute();
}

function openar_supporter_already_told(int $contactId): bool {
  return (bool) \Civi\Api4\ActivityContact::get(FALSE)
    ->addSelect('id')
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('record_type_id:name', '=', 'Activity Targets')
    ->addWhere('activity_id.subject', '=', OPENAR_SUPPORTER_LISTED_ACTIVITY)
    ->execute()->count();
}

/** Queue one confirmed applicant for review. */
function openar_handle_new_contact(int $contactId): void {
  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('id', 'source', 'display_name')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  if (!$contact || ($contact['source'] ?? '') !== OPENAR_APPLICATION_SOURCE) {
    return;
  }

  openar_add_to_review_queue((int) $contact['id']);

  // Applicants already on file for another reason, a supporter's signer or a
  // director, reach this point legitimately. Their new record is a duplicate,
  // but merging contacts is destructive and needs a person, so the reviewer is
  // told and CiviCRM's own merge screen does the work.
  $duplicates = openar_find_duplicates((int) $contact['id']);
  if ($duplicates) {
    openar_note_duplicates((int) $contact['id'], $duplicates);
  }

  openar_notify_reviewers((int) $contact['id'], $duplicates);
}

/** Other contacts sharing this one's email address. */
function openar_find_duplicates(int $contactId): array {
  $email = \Civi\Api4\Email::get(FALSE)
    ->addSelect('email')
    ->addWhere('contact_id', '=', $contactId)
    ->addOrderBy('is_primary', 'DESC')
    ->execute()->first()['email'] ?? NULL;

  if (!$email) {
    return [];
  }

  $found = [];
  foreach (\Civi\Api4\Email::get(FALSE)
    ->addSelect('contact_id', 'contact_id.display_name')
    ->addWhere('email', '=', $email)
    ->addWhere('contact_id', '!=', $contactId)
    ->addWhere('contact_id.is_deleted', '=', FALSE)
    ->execute() as $row) {
    $found[(int) $row['contact_id']] = $row['contact_id.display_name'];
  }

  return $found;
}

/** Record the duplicate on the contact so it is visible in CiviCRM, not only in the email. */
function openar_note_duplicates(int $contactId, array $duplicates): void {
  $lines = [];
  foreach ($duplicates as $id => $name) {
    $lines[] = sprintf('contact %d (%s)', $id, $name);
  }

  \Civi\Api4\Note::create(FALSE)
    ->addValue('entity_table', 'civicrm_contact')
    ->addValue('entity_id', $contactId)
    ->addValue('subject', 'Possible duplicate')
    ->addValue('note', sprintf(
      "This email address was already on file before this application, on %s.\n\n"
      . 'Check before admitting, and merge the records if they are the same person. '
      . 'Nothing has been merged automatically.',
      implode(', ', $lines)
    ))
    ->execute();
}

/** Add the applicant to the review queue. Safe to call more than once. */
function openar_add_to_review_queue(int $contactId): void {
  openar_add_to_group($contactId, OPENAR_REVIEW_GROUP);
}

/** Tell the reviewers an application is waiting. */
function openar_notify_reviewers(int $contactId, array $duplicates = []): void {
  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', OPENAR_REVIEW_TEMPLATE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->warning('OpenAR onboarding: review template not found, no notification sent');
    return;
  }

  $warningText = '';
  $warningHtml = '';
  if ($duplicates) {
    $lines = [];
    foreach ($duplicates as $id => $name) {
      $lines[] = sprintf('contact %d (%s)', $id, $name);
    }
    $joined = implode(', ', $lines);
    $warningText = "\nAlready on file: this email address belongs to " . $joined
      . ".\nCheck before admitting, and merge the records if they are the same person.\n";
    $warningHtml = '<p style="padding:10px 14px;border-left:3px solid #e8a020;background:#fdf6e7;">'
      . '<strong>Already on file.</strong> This email address belongs to ' . htmlspecialchars($joined)
      . '. Check before admitting, and merge the records if they are the same person.</p>';
  }

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => OPENAR_REVIEW_INBOX,
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'duplicateWarningText' => $warningText,
      'duplicateWarningHtml' => $warningHtml,
    ],
  ]);
}

/* -------------------------------------------------------------------------
 * Stage 3: admitted. Adding someone to the members group is the approval.
 * ---------------------------------------------------------------------- */

add_action('civicrm_postCommit', 'openar_onboarding_group_commit', 10, 4);

/**
 * CRM_Contact_BAO_GroupContact passes the *group* id as $objectId and a list of
 * contact ids as $objectRef, which is not the usual shape. API4 writes go
 * through the DAO instead and pass the GroupContact row. Both happen in
 * practice, the first from the CiviCRM screens and the second from our own code,
 * so both are handled here.
 */
function openar_onboarding_group_commit(string $op, string $objectName, $objectId, &$objectRef): void {
  if ($objectName !== 'GroupContact' || !in_array($op, ['create', 'edit'], TRUE)) {
    return;
  }

  try {
    $watched = [
      OPENAR_MEMBERS_GROUP => 'openar_admit_member',
      OPENAR_DECLINED_GROUP => 'openar_decline_applicant',
      OPENAR_SUPPORTERS_PUBLISHED_GROUP => 'openar_publish_supporter',
    ];

    $groupId = NULL;
    $contactIds = [];

    if (is_array($objectRef) && $objectRef && is_numeric(reset($objectRef))) {
      // BAO shape: $objectId is the group, $objectRef the contacts.
      $groupId = (int) $objectId;
      $contactIds = array_map('intval', array_values($objectRef));
    }
    else {
      // DAO shape: $objectId is the GroupContact row.
      $row = \Civi\Api4\GroupContact::get(FALSE)
        ->addSelect('contact_id', 'group_id', 'status')
        ->addWhere('id', '=', (int) $objectId)
        ->execute()->first();
      if (!$row || $row['status'] !== 'Added') {
        return;
      }
      $groupId = (int) $row['group_id'];
      $contactIds = [(int) $row['contact_id']];
    }

    foreach ($watched as $groupName => $handler) {
      if ($groupId === openar_group_id($groupName)) {
        foreach ($contactIds as $contactId) {
          $handler($contactId);
        }
        return;
      }
    }
  }
  catch (\Throwable $e) {
    \Civi::log()->error('OpenAR onboarding: admitting from group {gid} failed: {msg}', [
      'gid' => $objectId,
      'msg' => $e->getMessage(),
    ]);
  }
}

/**
 * Give a new member their number, take them out of the review queue, welcome them.
 *
 * Safe to run again on someone already admitted: the number is only assigned
 * once and the welcome only goes out with it.
 */
function openar_admit_member(int $contactId): void {
  $current = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('id', 'Membership.member_number')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  if (!$current) {
    return;
  }

  if (!empty($current['Membership.member_number'])) {
    return;
  }

  $number = openar_assign_member_number($contactId);
  openar_remove_from_group($contactId, OPENAR_REVIEW_GROUP);
  openar_send_welcome($contactId, $number);

  \Civi::log()->info('OpenAR onboarding: contact {cid} admitted as member {n}', [
    'cid' => $contactId,
    'n' => $number,
  ]);
}

/**
 * Decline an application: stamp the date, clear the review queue, tell them why.
 *
 * The email is held back unless a reason has been written, because a decline
 * that gives no reason is worse than one that has not been sent yet. In that
 * case the reviewers are told what to do instead. Safe to run more than once;
 * only one decline email ever goes out.
 */
function openar_decline_applicant(int $contactId): void {
  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('id', 'first_name', 'display_name', 'Membership.decline_reason', 'Membership.declined_date')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  if (!$contact) {
    return;
  }

  openar_remove_from_group($contactId, OPENAR_REVIEW_GROUP);

  if (!openar_already_declined($contactId)) {
    $reason = trim((string) ($contact['Membership.decline_reason'] ?? ''));
    if ($reason === '') {
      openar_report_missing_decline_reason($contact);
    }
    else {
      openar_send_decline($contactId, $reason);
    }
  }

  // Stamped last on purpose. Writing to the contact fires the edit hook below,
  // which by this point finds the decline already sent and does nothing.
  if (empty($contact['Membership.declined_date'])) {
    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('Membership.declined_date', date('Y-m-d H:i:s'))
      ->execute();
  }
}

add_action('civicrm_postCommit', 'openar_onboarding_contact_commit', 10, 4);

/**
 * Someone filled in the decline reason and saved. Send the decline.
 *
 * This is what keeps the ordinary path inside CiviCRM's own screens. A decline
 * recorded before its reason was written used to wait for a command to be run by
 * hand, which is not a reasonable thing to ask of whoever is doing reviews.
 * Either order now works: add to the group and then write the reason, or write
 * the reason and then add to the group.
 */
function openar_onboarding_contact_commit(string $op, string $objectName, $objectId, &$objectRef): void {
  if ($op !== 'edit' || $objectName !== 'Individual' || empty($objectId)) {
    return;
  }

  try {
    $contactId = (int) $objectId;

    $reason = trim((string) (\Civi\Api4\Contact::get(FALSE)
      ->addSelect('Membership.decline_reason')
      ->addWhere('id', '=', $contactId)
      ->execute()->first()['Membership.decline_reason'] ?? ''));

    if ($reason === '') {
      return;
    }
    if (openar_already_declined($contactId)) {
      return;
    }
    if (!openar_in_group($contactId, OPENAR_DECLINED_GROUP)) {
      return;
    }

    openar_send_decline($contactId, $reason);
  }
  catch (\Throwable $e) {
    \Civi::log()->error('OpenAR onboarding: pending decline for {cid} failed: {msg}', [
      'cid' => $objectId,
      'msg' => $e->getMessage(),
    ]);
  }
}

function openar_in_group(int $contactId, string $groupName): bool {
  $groupId = openar_group_id($groupName);
  if (!$groupId) {
    return FALSE;
  }

  return (bool) \Civi\Api4\GroupContact::get(FALSE)
    ->addSelect('id')
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('group_id', '=', $groupId)
    ->addWhere('status', '=', 'Added')
    ->execute()->count();
}

/** Send the decline. Returns false when there is nothing to send it to. */
function openar_send_decline(int $contactId, string $reason): bool {
  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('first_name')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  $email = \Civi\Api4\Email::get(FALSE)
    ->addSelect('email')
    ->addWhere('contact_id', '=', $contactId)
    ->addOrderBy('is_primary', 'DESC')
    ->execute()->first()['email'] ?? NULL;

  if (!$email) {
    \Civi::log()->warning('OpenAR onboarding: contact {cid} declined but has no email address', ['cid' => $contactId]);
    return FALSE;
  }

  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', OPENAR_DECLINE_TEMPLATE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->error('OpenAR onboarding: decline template not found, nothing sent');
    return FALSE;
  }

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email,
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'firstName' => $contact['first_name'] ?? '',
      'reason' => $reason,
      'appealInbox' => OPENAR_APPEAL_INBOX,
    ],
  ]);

  \Civi\Api4\Activity::create(FALSE)
    ->addValue('activity_type_id:name', 'Email')
    ->addValue('subject', OPENAR_DECLINE_ACTIVITY)
    ->addValue('details', 'Reason sent to the applicant: ' . $reason)
    ->addValue('status_id:name', 'Completed')
    ->addValue('source_contact_id', (int) (\CRM_Core_BAO_Domain::getDomain()->contact_id ?? $contactId))
    ->addValue('target_contact_id', [$contactId])
    ->execute();

  return TRUE;
}

/** Has a decline email already gone out to this contact? */
function openar_already_declined(int $contactId): bool {
  return (bool) \Civi\Api4\ActivityContact::get(FALSE)
    ->addSelect('id')
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('record_type_id:name', '=', 'Activity Targets')
    ->addWhere('activity_id.subject', '=', OPENAR_DECLINE_ACTIVITY)
    ->execute()->count();
}

/** Tell the reviewers the decline is waiting on them, not on the applicant. */
function openar_report_missing_decline_reason(array $contact): void {
  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', OPENAR_DECLINE_INCOMPLETE_TEMPLATE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->error('OpenAR onboarding: contact {cid} declined with no reason, and no alert template exists', [
      'cid' => $contact['id'],
    ]);
    return;
  }

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => OPENAR_REVIEW_INBOX,
    'tplParams' => [
      'displayName' => $contact['display_name'] ?? '',
      'contactId' => $contact['id'],
    ],
  ]);

  \Civi::log()->info('OpenAR onboarding: contact {cid} declined without a reason, applicant not written to', [
    'cid' => $contact['id'],
  ]);
}

/**
 * The next member number.
 *
 * Deliberately derived from the numbers actually on record rather than from a
 * counter that only climbs. Purging a contact frees its number again, which is
 * what makes it possible to walk through onboarding repeatedly during testing
 * without burning a number on every run. Merely trashing a contact does not
 * free the number, because CiviCRM keeps the custom values of a trashed contact,
 * so an ordinary withdrawal never lets a number be handed out twice.
 */
function openar_next_member_number(): int {
  [$table, $column] = openar_member_number_column();

  // Table and column names come from CiviCRM's own metadata, not from input.
  $max = (int) \CRM_Core_DAO::singleValueQuery(
    "SELECT MAX(CAST(`{$column}` AS UNSIGNED)) FROM `{$table}`"
  );

  return max($max, 0) + 1;
}

/** Assign a member number, once. Returns the existing one if there is one. */
function openar_assign_member_number(int $contactId): int {
  $existing = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('Membership.member_number')
    ->addWhere('id', '=', $contactId)
    ->execute()->first()['Membership.member_number'] ?? NULL;

  if (!empty($existing)) {
    return (int) $existing;
  }

  // Two approvals at the same moment would otherwise be able to read the same
  // maximum and issue the same number.
  $lock = \Civi::lockManager()->acquire('data.openar.membernumber');
  try {
    $existing = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('Membership.member_number')
      ->addWhere('id', '=', $contactId)
      ->execute()->first()['Membership.member_number'] ?? NULL;

    if (!empty($existing)) {
      return (int) $existing;
    }

    $number = openar_next_member_number();

    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('Membership.member_number', $number)
      ->execute();

    return $number;
  }
  finally {
    if ($lock && $lock->isAcquired()) {
      $lock->release();
    }
  }
}

/** Where the member number physically lives, looked up rather than hardcoded. */
function openar_member_number_column(): array {
  $field = \Civi\Api4\CustomField::get(FALSE)
    ->addSelect('column_name', 'custom_group_id.table_name')
    ->addWhere('custom_group_id.name', '=', 'Membership')
    ->addWhere('name', '=', 'member_number')
    ->execute()->first();

  if (!$field) {
    throw new \RuntimeException('Membership.member_number custom field not found');
  }

  return [$field['custom_group_id.table_name'], $field['column_name']];
}

function openar_send_welcome(int $contactId, int $number): void {
  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', OPENAR_WELCOME_TEMPLATE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->error('OpenAR onboarding: welcome template not found, member {n} not written to', ['n' => $number]);
    return;
  }

  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('first_name')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  $email = \Civi\Api4\Email::get(FALSE)
    ->addSelect('email')
    ->addWhere('contact_id', '=', $contactId)
    ->addOrderBy('is_primary', 'DESC')
    ->execute()->first()['email'] ?? NULL;

  if (!$email) {
    \Civi::log()->warning('OpenAR onboarding: member {n} has no email address', ['n' => $number]);
    return;
  }

  $discordUrl = openar_discord_link_for($contactId);

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => $email,
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
    'tplParams' => [
      'firstName' => $contact['first_name'] ?? '',
      'memberNumber' => $number,
      'discordUrl' => $discordUrl,
    ],
  ]);
}

function openar_group_id(string $name): ?int {
  $group = \Civi\Api4\Group::get(FALSE)
    ->addSelect('id')
    ->addWhere('name', '=', $name)
    ->execute()->first();

  return $group ? (int) $group['id'] : NULL;
}

function openar_add_to_group(int $contactId, string $groupName): void {
  $groupId = openar_group_id($groupName);
  if (!$groupId) {
    \Civi::log()->warning('OpenAR onboarding: group ' . $groupName . ' not found');
    return;
  }

  $already = \Civi\Api4\GroupContact::get(FALSE)
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('group_id', '=', $groupId)
    ->addWhere('status', '=', 'Added')
    ->execute()->count();

  if ($already) {
    return;
  }

  \Civi\Api4\GroupContact::create(FALSE)
    ->addValue('contact_id', $contactId)
    ->addValue('group_id', $groupId)
    ->addValue('status', 'Added')
    ->execute();
}

function openar_remove_from_group(int $contactId, string $groupName): void {
  $groupId = openar_group_id($groupName);
  if (!$groupId) {
    return;
  }

  \Civi\Api4\GroupContact::update(FALSE)
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('group_id', '=', $groupId)
    ->addValue('status', 'Removed')
    ->execute();
}

/* ---------------------------------------------------------------------- */

/** Submission data is JSON in the database; API4 usually decodes it, but not always. */
function openar_submission_data(array $submission): array {
  $data = $submission['data'] ?? [];
  if (is_string($data)) {
    $data = json_decode($data, TRUE) ?: [];
  }
  return is_array($data) ? $data : [];
}

/**
 * Find the first non-empty value for a key anywhere in a submission.
 *
 * The shape differs by form: contact fields sit under the entity name, while
 * email arrives as a join. Walking the tree keeps this working for the Mission
 * Supporter form without teaching it that form's layout.
 */
function openar_find_value(array $data, $keys): ?string {
  foreach ((array) $keys as $key) {
    $found = openar_search_tree($data, $key);
    if ($found !== NULL) {
      return $found;
    }
  }
  return NULL;
}

function openar_search_tree(array $data, string $key): ?string {
  foreach ($data as $k => $value) {
    if ($k === $key && is_scalar($value) && trim((string) $value) !== '') {
      return trim((string) $value);
    }
    if (is_array($value)) {
      $found = openar_search_tree($value, $key);
      if ($found !== NULL) {
        return $found;
      }
    }
  }
  return NULL;
}

/** "Jane Smith" greets as "Jane". A membership form already gives a first name. */
function openar_first_name(?string $name): string {
  $name = trim((string) $name);
  if ($name === '') {
    return '';
  }
  return strtok($name, ' ') ?: $name;
}
