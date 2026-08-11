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
  if ($op !== 'create' || empty($objectId)) {
    return;
  }

  // Onboarding bookkeeping must never break the write that triggered it.
  try {
    if ($objectName === 'AfformSubmission') {
      openar_handle_new_submission((int) $objectId);
    }
    elseif ($objectName === 'Individual') {
      openar_handle_new_contact((int) $objectId);
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

  // A resubmission supersedes the earlier attempt, so only the newest link is
  // live and there is one path to one contact.
  openar_supersede_earlier_submissions($submission['afform_name'], $submissionId, $email);

  openar_send_verification_link($submissionId);
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
 * A membership application only reaches this point after the applicant has
 * clicked the confirmation link, because the form runs in manual_processing
 * mode and writes nothing before then. Anything landing here is confirmed.
 */
function openar_handle_new_contact(int $contactId): void {
  $contact = \Civi\Api4\Contact::get(FALSE)
    ->addSelect('id', 'source', 'display_name')
    ->addWhere('id', '=', $contactId)
    ->execute()->first();

  if (!$contact || ($contact['source'] ?? '') !== OPENAR_APPLICATION_SOURCE) {
    return;
  }

  openar_add_to_review_queue((int) $contact['id']);
  openar_notify_reviewers((int) $contact['id']);
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
function openar_notify_reviewers(int $contactId): void {
  $template = \Civi\Api4\MessageTemplate::get(FALSE)
    ->addSelect('id')
    ->addWhere('msg_title', '=', OPENAR_REVIEW_TEMPLATE)
    ->addWhere('is_active', '=', TRUE)
    ->execute()->first();

  if (!$template) {
    \Civi::log()->warning('OpenAR onboarding: review template not found, no notification sent');
    return;
  }

  [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail();

  \CRM_Core_BAO_MessageTemplate::sendTemplate([
    'messageTemplateID' => $template['id'],
    'from' => sprintf('%s <%s>', $fromName, $fromEmail),
    'toEmail' => OPENAR_REVIEW_INBOX,
    'contactId' => $contactId,
    'tokenContext' => ['contactId' => $contactId],
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
