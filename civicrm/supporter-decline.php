<?php
/**
 * The declining half of the Mission Supporter path, which did not exist.
 *
 * Approving a Statement of Support published the organization and told the
 * signer. Declining one did nothing at all: there was no group to put them in,
 * nowhere to write the reason, and no message. In practice that meant a
 * reviewer who was not going to publish an organization simply left it in the
 * pending queue forever, and the signer never heard anything.
 *
 * A signer put their organization's name to a public statement. Silence is a
 * worse answer than no.
 *
 * Creates the declined group, the two fields the decline needs, and the email.
 * The plugin watches the group, exactly as it does for a declined applicant.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file supporter-decline.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
if (is_readable(__DIR__ . '/openar-snapshot.php')) {
  require_once __DIR__ . '/openar-snapshot.php';
  openar_snapshot('supporter-decline');
}

require_once __DIR__ . '/openar-signature.php';

use Civi\Api4\CustomField;
use Civi\Api4\Group;
use Civi\Api4\MessageTemplate;

/* ------------------------------------------------------------- the group -- */

$group = Group::get(FALSE)->addSelect('id')->addWhere('name', '=', 'supporters_declined')->execute()->first();
if ($group) {
  echo "group   supporters_declined exists (id {$group['id']})\n";
}
else {
  $id = Group::create(FALSE)->setValues([
    'name' => 'supporters_declined',
    'title' => 'Mission Supporters - declined',
    'description' => 'Statements of Support that were reviewed and not published. '
      . 'Adding an organization here sends the signer the reason written in '
      . '"Reason given to the signer". Nothing is published from this group.',
    'is_active' => TRUE,
    'group_type:name' => ['Access Control'],
  ])->execute()->first()['id'];
  echo "group   supporters_declined created (id {$id})\n";
}

/* ------------------------------------------------------------ the fields -- */

$fields = [
  [
    'name' => 'decline_reason',
    'label' => 'Reason given to the signer',
    'data_type' => 'Memo',
    'html_type' => 'TextArea',
    'help_post' => 'Sent to the signer word for word. Write it as something you '
      . 'are content for them to read. Reviewer working notes belong in Supporter notes.',
    'weight' => 200,
  ],
  [
    'name' => 'declined_date',
    'label' => 'Declined on',
    'data_type' => 'Date',
    'html_type' => 'Select Date',
    'date_format' => 'yy-mm-dd',
    'time_format' => 2,
    'weight' => 210,
  ],
];

foreach ($fields as $f) {
  $existing = CustomField::get(FALSE)
    ->addSelect('id')
    ->addWhere('custom_group_id.name', '=', 'MissionSupporter')
    ->addWhere('name', '=', $f['name'])
    ->execute()->first();

  if ($existing) {
    echo "field   MissionSupporter.{$f['name']} exists (id {$existing['id']})\n";
    continue;
  }

  $id = CustomField::create(FALSE)
    ->setValues($f + ['custom_group_id.name' => 'MissionSupporter', 'is_active' => TRUE, 'is_required' => FALSE])
    ->execute()->first()['id'];
  echo "field   MissionSupporter.{$f['name']} created (id {$id})\n";
}

/* ---------------------------------------------------------- the email -- */

$title = 'OpenAR - Statement of Support declined';

$text = <<<'TEXT'
Hello {$firstName},

Thank you for signing the Mission Supporter Statement of Support on behalf of {$organizationName}, and for the time it took you. I am sorry to tell you that the Foundation has not added the organization to the public roster, for the reason stated below.

{$reason}

That is a decision about one statement, and it is not permanent. If we have misread something, or if circumstances change, write and tell us and we will look again.

The review is a check that the information given is valid, to the best of our ability. It is not a judgment of your organization's politics, its stance towards the accounts receivable industry, or anything of the sort. Nothing about this decision is recorded publicly, and the organization appears nowhere on the Foundation's website.

The rules this was decided under are published in full, and you are welcome to read them:
https://openarcollective.org/policies/community-programs-and-standards/

If you think the decision is wrong, you can ask the Board to review it. Write to {$appealInbox}, say why, and it will be put to the Board, whose decision is final. That is one appeal, as the policy provides.

Nothing else changes. Everything the Foundation publishes is free on openarcollective.org, with no account and no sign-in, and your organization is as welcome to use it today as it was yesterday.
TEXT;

$html = <<<'HTML'
<p>Hello {$firstName},</p>

<p>Thank you for signing the Mission Supporter Statement of Support on behalf of <strong>{$organizationName}</strong>, and for the time it took you. I am sorry to tell you that the Foundation has not added the organization to the public roster, for the reason stated below.</p>

<p style="padding:12px 16px;border-left:3px solid #b8b0a4;background:#f6f4f0;">{$reason}</p>

<p>That is a decision about one statement, and it is not permanent. If we have misread something, or if circumstances change, write and tell us and we will look again.</p>

<p>The review is a check that the information given is valid, to the best of our ability. It is not a judgment of your organization's politics, its stance towards the accounts receivable industry, or anything of the sort. Nothing about this decision is recorded publicly, and the organization appears nowhere on the Foundation's website.</p>

<p>The rules this was decided under are published in full, and you are welcome to read them: <a href="https://openarcollective.org/policies/community-programs-and-standards/">Community Programs and Standards Policy</a>.</p>

<p>If you think the decision is wrong, you can ask the Board to review it. Write to <a href="mailto:{$appealInbox}">{$appealInbox}</a>, say why, and it will be put to the Board, whose decision is final. That is one appeal, as the policy provides.</p>

<p>Nothing else changes. Everything the Foundation publishes is free on <a href="https://openarcollective.org">openarcollective.org</a>, with no account and no sign-in, and your organization is as welcome to use it today as it was yesterday.</p>
HTML;

$vals = [
  'msg_title' => $title,
  'msg_subject' => 'Your organization\'s Statement of Support',
  'msg_text' => $text . openar_signature_text('With thanks for your interest'),
  'msg_html' => $html . openar_signature_html('With thanks for your interest'),
  'is_active' => TRUE,
  'is_reserved' => FALSE,
];

$existing = MessageTemplate::get(FALSE)->addWhere('msg_title', '=', $title)->execute()->first();
if ($existing) {
  MessageTemplate::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($vals)->execute();
  echo "email   {$title} updated (id {$existing['id']})\n";
}
else {
  $id = MessageTemplate::create(FALSE)->setValues($vals)->execute()->first()['id'];
  echo "email   {$title} created (id {$id})\n";
}

echo "\nDeclining a Statement of Support now has somewhere to go and something to say.\n";
