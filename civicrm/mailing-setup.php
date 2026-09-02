<?php
/**
 * Prepare CiviMail so the first members-only mailing can be written and sent
 * without anyone having to remember the compliance parts.
 *
 * No mailing has been sent yet, so nothing here changes what members receive
 * today. It changes what happens the first time somebody writes one.
 *
 * Three things were wrong or missing:
 *
 * 1. The header and footer were still CiviCRM's sample text. "Sample Footer for
 *    HTML formatted content" would have gone out on the first mailing.
 *
 * 2. The footer is where the unsubscribe lives, and the welcome email now tells
 *    every new member they can unsubscribe. Until a mailing carries a working
 *    link, that promise is only as good as somebody reading membership@.
 *
 * 3. Bulk email to a list has to carry a physical postal address. CiviCRM emits
 *    it from the domain record with {domain.address}, and the Foundation's is
 *    on file, so this only has to use it.
 *
 * Two links, and they are not the same thing. {action.unsubscribeUrl} leaves
 * this one list. {action.optOutUrl} stops all bulk email from the Foundation.
 * Somebody who wants out of a newsletter should not have to also cut off every
 * other message to get there, so both are offered.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file mailing-setup.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
if (is_readable(__DIR__ . '/openar-snapshot.php')) {
  require_once __DIR__ . '/openar-snapshot.php';
  openar_snapshot('mailing-setup');
}

use Civi\Api4\Job;
use Civi\Api4\MailingComponent;

/* ------------------------------------------------------------- the footer -- */

/**
 * Two footers, and which is the default matters.
 *
 * The members-only list is not the only list. Prospects, gathered from
 * conference attendee lists, will be mailed from here too, and the first
 * version of this footer told every recipient "you are receiving this because
 * you are a member", which would have been a false statement to every one of
 * them.
 *
 * So the default is the one that is true of anybody, and the warmer
 * members-only wording is the deliberate upgrade. That way forgetting to
 * choose costs a little warmth rather than telling a stranger they are a
 * member of something they have not joined.
 */
$footerText = <<<'TEXT'
--
You are receiving this because your address is on a mailing list kept by The Open Accounts Receivable Collective Foundation.

Leave this mailing list: {action.unsubscribeUrl}
Stop all email from the Foundation: {action.optOutUrl}

Either one is honored immediately and needs no reason.

{domain.address}
TEXT;

$footerHtml = <<<'HTML'
<div style="margin-top:28px;padding-top:14px;border-top:1px solid #e3ded3;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#5c564c;">
<p style="margin:0 0 8px;">You are receiving this because your address is on a mailing list kept by The Open Accounts Receivable Collective Foundation.</p>
<p style="margin:0 0 8px;">
<a href="{action.unsubscribeUrl}" style="color:#8f5a0d;">Leave this mailing list</a>
&nbsp;&middot;&nbsp;
<a href="{action.optOutUrl}" style="color:#8f5a0d;">Stop all email from the Foundation</a>
</p>
<p style="margin:0 0 8px;">Either one is honored immediately and needs no reason.</p>
<p style="margin:0;">{domain.address}</p>
</div>
HTML;

$memberFooterText = <<<'TEXT'
--
You are receiving this because you are a member of The Open Accounts Receivable Collective Foundation.

Leave this mailing list: {action.unsubscribeUrl}
Stop all email from the Foundation: {action.optOutUrl}

Leaving the list does not affect your membership, and nobody is told that you left.

{domain.address}
TEXT;

$memberFooterHtml = <<<'HTML'
<div style="margin-top:28px;padding-top:14px;border-top:1px solid #e3ded3;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#5c564c;">
<p style="margin:0 0 8px;">You are receiving this because you are a member of The Open Accounts Receivable Collective Foundation.</p>
<p style="margin:0 0 8px;">
<a href="{action.unsubscribeUrl}" style="color:#8f5a0d;">Leave this mailing list</a>
&nbsp;&middot;&nbsp;
<a href="{action.optOutUrl}" style="color:#8f5a0d;">Stop all email from the Foundation</a>
</p>
<p style="margin:0 0 8px;">Leaving the list does not affect your membership, and nobody is told that you left.</p>
<p style="margin:0;">{domain.address}</p>
</div>
HTML;

/* ------------------------------------------------------------- the header -- */

// Emptied rather than styled. A mailing's own opening line is a better greeting
// than a band of chrome above it, and the sample text had to go regardless.
$headerText = '';
$headerHtml = '';

$components = [
  ['type' => 'Header', 'text' => $headerText, 'html' => $headerHtml],
  ['type' => 'Footer', 'text' => $footerText, 'html' => $footerHtml],
];

foreach ($components as $c) {
  $existing = MailingComponent::get(FALSE)
    ->addSelect('id', 'name', 'body_text', 'body_html')
    ->addWhere('component_type', '=', $c['type'])
    ->addWhere('is_default', '=', TRUE)
    ->execute()->first();

  if (!$existing) {
    echo "WARNING: no default {$c['type']} component to update\n";
    continue;
  }

  $wasSample = stripos((string) $existing['body_text'] . $existing['body_html'], 'sample') !== FALSE;

  MailingComponent::update(FALSE)
    ->addWhere('id', '=', $existing['id'])
    ->addValue('body_text', $c['text'])
    ->addValue('body_html', $c['html'])
    ->addValue('is_active', TRUE)
    ->execute();

  printf("%-8s %-22s %s\n", $c['type'], $existing['name'],
    $wasSample ? 'replaced CiviCRM sample text' : 'updated');
}

/* ------------------------------------------- the warmer members-only one -- */

const OPENAR_MEMBER_FOOTER = 'Mailing Footer - members only';

$memberFooter = MailingComponent::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', OPENAR_MEMBER_FOOTER)
  ->execute()->first();

$values = [
  'name' => OPENAR_MEMBER_FOOTER,
  'component_type' => 'Footer',
  'subject' => OPENAR_MEMBER_FOOTER,
  'body_text' => $memberFooterText,
  'body_html' => $memberFooterHtml,
  'is_active' => TRUE,
  // Never the default. The default has to be the one that is true of anybody
  // on any list, so that forgetting to choose is survivable.
  'is_default' => FALSE,
];

if ($memberFooter) {
  MailingComponent::update(FALSE)->addWhere('id', '=', $memberFooter['id'])->setValues($values)->execute();
  echo "Footer   " . OPENAR_MEMBER_FOOTER . "  updated\n";
}
else {
  MailingComponent::create(FALSE)->setValues($values)->execute();
  echo "Footer   " . OPENAR_MEMBER_FOOTER . "  created\n";
}

/* --------------------------------------------------------- the sending job -- */

// Without this nothing sends, however well written. It needs a CiviCRM cron to
// call it, which is a separate step and documented in server/README.md.
$job = Job::get(FALSE)
  ->addSelect('id', 'is_active')
  ->addWhere('api_action', '=', 'process_mailing')
  ->execute()->first();

if ($job) {
  if (!$job['is_active']) {
    Job::update(FALSE)->addWhere('id', '=', $job['id'])->addValue('is_active', TRUE)->execute();
    echo "Job     Send Scheduled Mailings enabled\n";
  }
  else {
    echo "Job     Send Scheduled Mailings already enabled\n";
  }
}

// Deliberately left alone: Fetch Bounces. It reads an IMAP mailbox that does not
// exist here, so enabling it would only produce an hourly error. Postmark
// reports bounces over a webhook, which is the path worth building later.

/* ------------------------------------------------------------- what is left -- */

echo "\nStill to do before a first mailing:\n";

// Reported, not judged. This runs as the web user, and a CiviCRM cron lives in
// a person's crontab that the web user cannot read. Two earlier versions of
// this check tried to infer it anyway: one read the wrong crontab and announced
// a cron that did not exist, the other called a job last run twenty-one hours
// ago "running". Both were confidently wrong about a step that decides whether
// mail leaves the building, so this now states the fact and the way to check.
$latest = civicrm_api4('Job', 'get', [
  'select' => ['name', 'last_run'],
  'where' => [['is_active', '=', TRUE], ['last_run', 'IS NOT EMPTY']],
  'orderBy' => ['last_run' => 'DESC'],
  'limit' => 1,
  'checkPermissions' => FALSE,
])->first();

echo $latest
  ? "  Last scheduled job run: {$latest['last_run']} ({$latest['name']}).\n"
  : "  No scheduled job has ever run.\n";
echo "  Scheduled mailings need a CiviCRM cron every 15 minutes, which cannot be\n"
  . "  confirmed from here. Check with: crontab -l | grep job.execute\n";

// Both of these are deliberate. Anything else appearing here is a group somebody
// marked as a mailing list without saying so, which is worth noticing before it
// turns up in a recipient picker next to the real ones.
$expected = ['members', 'prospects'];

foreach (civicrm_api4('Group', 'get', [
  'select' => ['name', 'title'],
  'where' => [['group_type:name', 'CONTAINS', 'Mailing List'], ['is_active', '=', TRUE]],
  'checkPermissions' => FALSE,
]) as $g) {
  if (!in_array($g['name'], $expected, TRUE)) {
    echo "  Group \"{$g['title']}\" ({$g['name']}) is marked as a mailing list and is\n"
      . "  not one this build knows about. Check it belongs there before sending.\n";
  }
}

echo "\nNo mailing has been created, and nothing has been sent.\n";
