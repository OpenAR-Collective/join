<?php
/**
 * Send the Foundation's mail from membership@, not from a person.
 *
 * Every message this site sends, confirmations, review notifications, declines
 * and welcomes, takes its sender from CRM_Core_BAO_Domain::getNameAndEmail(),
 * which returns whichever from_email_address option is marked default. That was
 * "Rob Grafrath" <rob@openarcollective.org>, so a stranger confirming their
 * email address got a message from an individual, and any reply landed in one
 * person's mailbox rather than the one the Foundation actually monitors.
 *
 * The display name is the Foundation's full legal name on purpose. It is what
 * the templates sign off with, and more to the point it is what /application-sent
 * tells people to look for when the message has gone to spam.
 *
 * The old address is kept, just not as the default. Nothing here is worth making
 * irreversible, and rob@ is still a real mailbox someone may want to send from.
 *
 * IMPORTANT: Postmark will refuse a From address that is not a confirmed Sender
 * Signature or on a verified domain. Check openarcollective.org is verified
 * before trusting this, because the failure is a rejected send, which means no
 * confirmation email and an applicant who never hears back.
 *
 * Idempotent. Run as the web user:
 *   sudo -u www-data wp --path=/var/www/openarcollective.org eval-file from-address.php
 */

civicrm_initialize();

define('OPENAR_SNAPSHOT_INCLUDED', TRUE);
if (is_readable(__DIR__ . '/openar-snapshot.php')) {
  require_once __DIR__ . '/openar-snapshot.php';
  openar_snapshot('from-address');
}

use Civi\Api4\OptionValue;

const OPENAR_FROM_NAME = 'The Open Accounts Receivable Collective Foundation';
const OPENAR_FROM_EMAIL = 'membership@openarcollective.org';

// The option stores the whole sender in label and name; value is just an
// ordinal. getNameAndEmail() parses this string, so the quoting matters.
$sender = sprintf('"%s" <%s>', OPENAR_FROM_NAME, OPENAR_FROM_EMAIL);

echo "before: " . json_encode(CRM_Core_BAO_Domain::getNameAndEmail(), JSON_UNESCAPED_SLASHES) . "\n\n";

$existing = OptionValue::get(FALSE)
  ->addSelect('id', 'label', 'is_default')
  ->addWhere('option_group_id:name', '=', 'from_email_address')
  ->execute();

$mine = NULL;
$others = [];
foreach ($existing as $v) {
  if (str_contains(strtolower((string) $v['label']), strtolower(OPENAR_FROM_EMAIL))) {
    $mine = $v;
  }
  else {
    $others[] = $v;
  }
}

if ($mine) {
  OptionValue::update(FALSE)
    ->addWhere('id', '=', $mine['id'])
    ->addValue('label', $sender)
    ->addValue('name', $sender)
    ->addValue('is_default', TRUE)
    ->addValue('is_active', TRUE)
    ->execute();
  echo "updated  {$sender} (id {$mine['id']})\n";
}
else {
  $next = 1 + (int) CRM_Core_DAO::singleValueQuery(
    "SELECT COALESCE(MAX(CAST(v.value AS UNSIGNED)), 0)
     FROM civicrm_option_value v
     JOIN civicrm_option_group g ON g.id = v.option_group_id
     WHERE g.name = 'from_email_address'");

  $id = OptionValue::create(FALSE)
    ->addValue('option_group_id.name', 'from_email_address')
    ->addValue('label', $sender)
    ->addValue('name', $sender)
    ->addValue('value', (string) $next)
    ->addValue('is_default', TRUE)
    ->addValue('is_active', TRUE)
    ->addValue('weight', 1)
    ->execute()->first()['id'];
  echo "created  {$sender} (id {$id})\n";
}

// Only one can be the default, and CiviCRM does not enforce that for us.
foreach ($others as $v) {
  if ($v['is_default']) {
    OptionValue::update(FALSE)
      ->addWhere('id', '=', $v['id'])
      ->addValue('is_default', FALSE)
      ->execute();
    echo "no longer the default: {$v['label']} (id {$v['id']})\n";
  }
}

[$name, $email] = CRM_Core_BAO_Domain::getNameAndEmail();
echo "\nafter:  " . json_encode([$name, $email], JSON_UNESCAPED_SLASHES) . "\n";

if ($email !== OPENAR_FROM_EMAIL) {
  echo "\nWARNING: the sender did not change. Mail is still going out as {$email}.\n";
  return;
}

echo "\nEvery confirmation, review notice, decline and welcome now comes from\n";
echo "{$sender}, and replies go there rather than to a person.\n";
echo "\nThis only works if Postmark will accept that From address. Confirm\n";
echo "openarcollective.org is a verified domain, or membership@ a confirmed\n";
echo "Sender Signature, before relying on it.\n";
