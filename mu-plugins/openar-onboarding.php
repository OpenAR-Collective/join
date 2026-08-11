<?php
/**
 * Plugin Name: OpenAR Collective onboarding
 * Description: Routes verified membership applications into the review queue and notifies the reviewers.
 * Version:     1.0.0
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

add_action('civicrm_postCommit', 'openar_onboarding_post_commit', 10, 4);

/**
 * A membership application only reaches this point after the applicant has
 * clicked the verification link, because the form runs in manual_processing
 * mode and writes nothing before then. Anything landing here is confirmed.
 */
function openar_onboarding_post_commit(string $op, string $objectName, $objectId, &$objectRef): void {
  if ($op !== 'create' || $objectName !== 'Individual' || empty($objectId)) {
    return;
  }

  // Never let onboarding bookkeeping break contact creation.
  try {
    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('id', 'source', 'display_name')
      ->addWhere('id', '=', (int) $objectId)
      ->execute()->first();

    if (!$contact || ($contact['source'] ?? '') !== OPENAR_APPLICATION_SOURCE) {
      return;
    }

    openar_add_to_review_queue((int) $contact['id']);
    openar_notify_reviewers((int) $contact['id']);
  }
  catch (\Throwable $e) {
    \Civi::log()->error('OpenAR onboarding failed for contact {cid}: {msg}', [
      'cid' => $objectId,
      'msg' => $e->getMessage(),
    ]);
  }
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
