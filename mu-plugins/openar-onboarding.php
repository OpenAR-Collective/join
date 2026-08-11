<?php
/**
 * Plugin Name: OpenAR Collective onboarding
 * Description: Sends long-lived confirmation links, then routes verified applications into the review queue.
 * Version:     1.1.0
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
 * Where a member connects their Discord account. Empty until the Discord
 * application exists, in which case the already-a-member email tells them to
 * write to membership@ instead of carrying a link that goes nowhere.
 */
const OPENAR_DISCORD_CONNECT_URL = '';

/** One courtesy email per address per this many hours, so the form cannot be used to flood a member's inbox. */
const OPENAR_REPEAT_NOTICE_HOURS = 24;
const OPENAR_REPEAT_ACTIVITY = 'Membership application received from an address already on file';

/**
 * Forms whose confirmation email this plugin sends. Each must have
 * manual_processing on and allow_verification_by_email off, or the applicant
 * gets two emails: Afform's ten-minute one and ours.
 */
const OPENAR_VERIFIED_FORMS = [
  'afformMembershipApplication',
];

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
  if (!in_array($submission['afform_name'], OPENAR_VERIFIED_FORMS, TRUE)) {
    return;
  }

  $data = openar_submission_data($submission);
  $email = openar_find_value($data, 'email');
  if (!$email) {
    \Civi::log()->warning('OpenAR onboarding: submission {id} has no email address, no link sent', ['id' => $submissionId]);
    return;
  }

  // Someone we already know does not need a confirmation link, and in the case
  // of an existing member must not get a second contact record.
  $existing = openar_lookup_by_email($email);
  if ($existing && $existing['state'] !== 'known') {
    openar_answer_repeat_applicant($existing, openar_find_value($data, 'first_name') ?? '');
    \Civi\Api4\AfformSubmission::update(FALSE)
      ->addWhere('id', '=', $submissionId)
      ->addValue('status_id:name', 'Rejected')
      ->execute();
    return;
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

  $discordUrl = '';
  if (OPENAR_DISCORD_CONNECT_URL !== '') {
    $discordUrl = OPENAR_DISCORD_CONNECT_URL
      . '?cid=' . $contactId
      . '&cs=' . \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);
  }

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
    ->addSelect('id', 'status_id:name', 'data')
    ->addWhere('id', '=', $submissionId)
    ->execute()->first();

  if (!$submission || $submission['status_id:name'] !== 'Pending') {
    \Civi::log()->warning('OpenAR onboarding: submission {id} is not pending, no link sent', ['id' => $submissionId]);
    return FALSE;
  }

  $data = openar_submission_data($submission);
  $email = openar_find_value($data, 'email');
  if (!$email) {
    return FALSE;
  }

  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', OPENAR_VERIFY_TEMPLATE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->error('OpenAR onboarding: template "' . OPENAR_VERIFY_TEMPLATE . '" not found, no link sent');
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
      'firstName' => openar_find_value($data, 'first_name') ?? '',
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

  foreach ($earlier as $old) {
    $oldEmail = openar_find_value(openar_submission_data($old), 'email');
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
  if (!in_array($submission['afform_name'], OPENAR_VERIFIED_FORMS, TRUE)) {
    return;
  }

  foreach (openar_submission_entity_ids(openar_submission_data($submission)) as $contactId) {
    openar_handle_new_contact($contactId);
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
  $group = \Civi\Api4\Group::get(FALSE)
    ->addSelect('id')
    ->addWhere('name', '=', OPENAR_REVIEW_GROUP)
    ->execute()->first();

  if (!$group) {
    \Civi::log()->warning('OpenAR onboarding: group ' . OPENAR_REVIEW_GROUP . ' not found');
    return;
  }

  $already = \Civi\Api4\GroupContact::get(FALSE)
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('group_id', '=', $group['id'])
    ->addWhere('status', '=', 'Added')
    ->execute()->count();

  if ($already) {
    return;
  }

  \Civi\Api4\GroupContact::create(FALSE)
    ->addValue('contact_id', $contactId)
    ->addValue('group_id', $group['id'])
    ->addValue('status', 'Added')
    ->execute();
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
function openar_find_value(array $data, string $key): ?string {
  foreach ($data as $k => $value) {
    if ($k === $key && is_scalar($value) && (string) $value !== '') {
      return (string) $value;
    }
    if (is_array($value)) {
      $found = openar_find_value($value, $key);
      if ($found !== NULL) {
        return $found;
      }
    }
  }
  return NULL;
}
