<?php
/**
 * Revocation, for members and for Mission Supporters.
 *
 * Declining and revoking look similar and are not the same thing. A decline
 * refuses someone who was never admitted; a revocation takes participation away
 * from someone who has it. Under Section 7.7 that difference is a year: a
 * declined applicant may apply again at any time, a revoked one may not reapply
 * for twelve months, and readmission is then discretionary.
 *
 * Without this, the only way to revoke somebody would be to drop them into the
 * declined group, which would send them an email inviting them to apply again
 * whenever they liked. That is the opposite of what the policy says, and it is
 * exactly the kind of thing nobody notices until it has been sent.
 *
 * Revoking is done in CiviCRM, deliberately, rather than from a button: it is
 * rare, it is serious, and unlike an approval it has no queue to work through.
 * Write the reason on the contact, then add them to the revoked group. The
 * email goes out on the group add, and is held until a reason exists.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file revocation.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
if (is_readable(__DIR__ . '/openar-snapshot.php')) {
  require_once __DIR__ . '/openar-snapshot.php';
  openar_snapshot('revocation');
}

require_once __DIR__ . '/openar-signature.php';

use Civi\Api4\CustomField;
use Civi\Api4\Group;
use Civi\Api4\MessageTemplate;

const POLICY_URL = 'https://openarcollective.org/policies/community-programs-and-standards/';

/* ------------------------------------------------------------- the groups -- */

$groups = [
  [
    'name' => 'members_revoked',
    'title' => 'Members - revoked',
    'description' => 'Members whose participation has been revoked under Article VII. '
      . 'Adding somebody here sends them the reason written in "Reason for revocation" '
      . 'and removes them from Members. Under Section 7.7 they may not reapply for one '
      . 'year. This is not the group for a declined application, which is a different '
      . 'decision with no waiting period.',
  ],
  [
    'name' => 'supporters_revoked',
    'title' => 'Mission Supporters - revoked',
    'description' => 'Organizations whose participation has been revoked under Article VII. '
      . 'Adding one here removes it from the published roster, so the next hourly sync '
      . 'takes it off openarcollective.org, and sends the signer the reason written in '
      . '"Reason for revocation".',
  ],
];

foreach ($groups as $g) {
  $existing = Group::get(FALSE)->addSelect('id')->addWhere('name', '=', $g['name'])->execute()->first();
  if ($existing) {
    Group::update(FALSE)->addWhere('id', '=', $existing['id'])
      ->addValue('description', $g['description'])->execute();
    echo "group   {$g['name']} exists (id {$existing['id']})\n";
    continue;
  }
  $id = Group::create(FALSE)->setValues($g + [
    'is_active' => TRUE,
    'group_type:name' => ['Access Control'],
  ])->execute()->first()['id'];
  echo "group   {$g['name']} created (id {$id})\n";
}

/* ------------------------------------------------------------- the fields -- */

foreach (['Membership', 'MissionSupporter'] as $customGroup) {
  $fields = [
    [
      'name' => 'revocation_reason',
      'label' => 'Reason for revocation',
      'data_type' => 'Memo',
      'html_type' => 'TextArea',
      'help_post' => 'Sent word for word to the person or organization whose participation '
        . 'is being revoked, and the policy requires that they be told the basis. Write it '
        . 'as something you are content for them to read.',
      'weight' => 300,
    ],
    [
      'name' => 'revoked_date',
      'label' => 'Participation revoked on',
      'data_type' => 'Date',
      'html_type' => 'Select Date',
      'date_format' => 'yy-mm-dd',
      'time_format' => 2,
      'weight' => 310,
    ],
  ];

  foreach ($fields as $f) {
    $existing = CustomField::get(FALSE)
      ->addSelect('id')
      ->addWhere('custom_group_id.name', '=', $customGroup)
      ->addWhere('name', '=', $f['name'])
      ->execute()->first();

    if ($existing) {
      echo "field   {$customGroup}.{$f['name']} exists (id {$existing['id']})\n";
      continue;
    }
    $id = CustomField::create(FALSE)
      ->setValues($f + ['custom_group_id.name' => $customGroup, 'is_active' => TRUE, 'is_required' => FALSE])
      ->execute()->first()['id'];
    echo "field   {$customGroup}.{$f['name']} created (id {$id})\n";
  }
}

/* ------------------------------------------------------------ the emails -- */

$appeal = 'If you think this is wrong, you can ask the Board to review it. Write to '
  . '{$appealInbox} within thirty days of this message, say why, and it will be put to the '
  . 'Board, whose decision is final. Section 7.6 of the Community Programs and Standards '
  . 'Policy gives one appeal.';

$templates = [];

$templates[] = [
  'msg_title' => 'OpenAR - Membership revoked',
  'msg_subject' => 'Your membership of the OpenAR Collective',
  'closing' => 'Sincerely',
  'msg_text' => <<<TEXT
Hello {\$firstName},

I am writing to tell you that your membership of The Open Accounts Receivable Collective Foundation has been revoked, for the reason stated below.

{\$reason}

What this means. Your access to the members-only spaces ends, including the Foundation's Discord server, and you should stop describing yourself as a member of the Foundation or using the member designation. Your member number is retired and will not be reissued.

{$appeal}

Under Section 7.7 you may apply again no earlier than one year after this becomes final, and readmission is then at the discretion of the Program Administrator. That is longer than for someone whose application was simply declined, because this is a different decision.

The rules this was decided under are published in full:
POLICY_URL_HERE

This does not affect your employer's participation in the Mission Supporter program, if it takes part, and it does not affect your access to the Foundation's work. Everything the Foundation publishes is free at https://openarcollective.org with no account and no sign-in, and its software is developed in the open at https://github.com/OpenAR-Collective.
TEXT,
  'msg_html' => <<<HTML
<p>Hello {\$firstName},</p>

<p>I am writing to tell you that your membership of The Open Accounts Receivable Collective Foundation has been revoked, for the reason stated below.</p>

<p style="padding:12px 16px;border-left:3px solid #b8b0a4;background:#f6f4f0;">{\$reason}</p>

<p><strong>What this means.</strong> Your access to the members-only spaces ends, including the Foundation's Discord server, and you should stop describing yourself as a member of the Foundation or using the member designation. Your member number is retired and will not be reissued.</p>

<p>{$appeal}</p>

<p>Under Section 7.7 you may apply again no earlier than one year after this becomes final, and readmission is then at the discretion of the Program Administrator. That is longer than for someone whose application was simply declined, because this is a different decision.</p>

<p>The rules this was decided under are published in full: <a href="POLICY_URL_HERE">Community Programs and Standards Policy</a>.</p>

<p>This does not affect your employer's participation in the Mission Supporter program, if it takes part, and it does not affect your access to the Foundation's work. Everything the Foundation publishes is free at <a href="https://openarcollective.org">openarcollective.org</a> with no account and no sign-in, and its software is developed in the open at <a href="https://github.com/OpenAR-Collective">github.com/OpenAR-Collective</a>.</p>
HTML,
];

$templates[] = [
  'msg_title' => 'OpenAR - Mission Supporter participation revoked',
  'msg_subject' => 'Your organization\'s Mission Supporter participation',
  'closing' => 'Sincerely',
  'msg_text' => <<<TEXT
Hello {\$firstName},

I am writing to tell you that the Mission Supporter participation of {\$organizationName} has been revoked, for the reason stated below.

{\$reason}

What this means. The organization has been removed from the public roster at https://openarcollective.org/supporters and will no longer appear there. It should stop describing itself as an OpenAR Collective Mission Supporter and stop using the designation in its website, marketing, or other materials.

{$appeal}

Under Section 7.7 the organization may sign again no earlier than one year after this becomes final, and readmission is then at the discretion of the Program Administrator.

The rules this was decided under are published in full:
POLICY_URL_HERE

This does not affect the membership of anybody employed by the organization, and it does not affect anyone's access to the Foundation's work, which is free at https://openarcollective.org with no account and no sign-in.
TEXT,
  'msg_html' => <<<HTML
<p>Hello {\$firstName},</p>

<p>I am writing to tell you that the Mission Supporter participation of <strong>{\$organizationName}</strong> has been revoked, for the reason stated below.</p>

<p style="padding:12px 16px;border-left:3px solid #b8b0a4;background:#f6f4f0;">{\$reason}</p>

<p><strong>What this means.</strong> The organization has been removed from the public roster at <a href="https://openarcollective.org/supporters">openarcollective.org/supporters</a> and will no longer appear there. It should stop describing itself as an OpenAR Collective Mission Supporter and stop using the designation in its website, marketing, or other materials.</p>

<p>{$appeal}</p>

<p>Under Section 7.7 the organization may sign again no earlier than one year after this becomes final, and readmission is then at the discretion of the Program Administrator.</p>

<p>The rules this was decided under are published in full: <a href="POLICY_URL_HERE">Community Programs and Standards Policy</a>.</p>

<p>This does not affect the membership of anybody employed by the organization, and it does not affect anyone's access to the Foundation's work, which is free at <a href="https://openarcollective.org">openarcollective.org</a> with no account and no sign-in.</p>
HTML,
];

// The same safety net the decline has. A revocation with no reason recorded
// sends nothing, because the policy requires that the basis be given, and a
// revocation notice with a blank where the reason should be is worse than one
// that arrives an hour late.
$templates[] = [
  'msg_title' => 'OpenAR - Revocation recorded without a reason',
  'msg_subject' => 'Revocation not sent, no reason recorded: {$displayName}',
  'msg_text' => <<<'TEXT'
{$displayName} (contact {$contactId}) was added to a revoked group, but "Reason for revocation" is empty, so nothing has been sent to them.

The policy requires that the person or organization be given the basis of the decision, so the email is held until a reason is written.

To finish it, open the contact, fill in "Reason for revocation", and save. The notice goes out on save.

Their participation has already ended. They have been removed from the members or published group, so only the notice is waiting.
TEXT,
  'msg_html' => <<<'HTML'
<p><strong>{$displayName}</strong> (contact {$contactId}) was added to a revoked group, but <strong>Reason for revocation</strong> is empty, so <strong>nothing has been sent to them</strong>.</p>

<p>The policy requires that the person or organization be given the basis of the decision, so the email is held until a reason is written.</p>

<p>To finish it, open the contact, fill in <strong>Reason for revocation</strong>, and save. The notice goes out on save.</p>

<p>Their participation has already ended. They have been removed from the members or published group, so only the notice is waiting.</p>
HTML,
];

foreach ($templates as $t) {
  if (!empty($t['closing'])) {
    $t['msg_text'] .= openar_signature_text($t['closing']);
    $t['msg_html'] .= openar_signature_html($t['closing']);
  }
  unset($t['closing']);

  $t['msg_text'] = str_replace('POLICY_URL_HERE', POLICY_URL, $t['msg_text']);
  $t['msg_html'] = str_replace('POLICY_URL_HERE', POLICY_URL, $t['msg_html']);
  $t['is_active'] = TRUE;
  $t['is_reserved'] = FALSE;

  $existing = MessageTemplate::get(FALSE)->addWhere('msg_title', '=', $t['msg_title'])->execute()->first();
  if ($existing) {
    MessageTemplate::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($t)->execute();
    echo "email   {$t['msg_title']} updated (id {$existing['id']})\n";
  }
  else {
    $id = MessageTemplate::create(FALSE)->setValues($t)->execute()->first()['id'];
    echo "email   {$t['msg_title']} created (id {$id})\n";
  }
}

echo "\nRevocation exists as its own path, so it can no longer be confused with a decline.\n";
