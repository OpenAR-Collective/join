# CiviCRM provisioning scripts

Idempotent PHP scripts run with `wp eval-file` as the web user:

```bash
sudo -u www-data wp --path=/var/www/openarcollective.org eval-file <script>.php
```

| Script | Purpose |
|---|---|
| `civi-fields.php` | Membership custom field group and its fields |
| `civi-fields2.php` | Employer and LinkedIn fields |
| `civi-groups.php` | Applicant workflow groups |
| `civi-groups2.php` | Members group (reuses the installer's) and supporter groups |
| `civi-afform3.php` | Membership application form layout |
| `verify-email.php` | Seven-day confirmation email template; disables Afform's ten-minute one |
| `pending-applications.php` | Lists people who never confirmed; resends a link |
| `confirm-email.php` | Superseded by `verify-email.php`, kept for history |
| `review-template.php` | Reviewer notification template |
| `repeat-applicant-templates.php` | Emails for someone applying with an address already on file |
| `welcome-template.php` | Welcome email sent on admission |
| `decline-fields.php` | The decline reason and date fields |
| `decline-templates.php` | Decline email, and the alert when no reason is recorded |
| `send-decline.php` | Lists declines; sends one whose reason arrived late |
| `reset-applicant.php` | Purges one address so onboarding can be walked again |
| `fix-lang.php` | Populates `languageLimit` (see note) |

## Four things that are easy to get wrong

**Email must be a join, not an entity.** Afform's verification routine looks for
the address in the contact entity's `joins` and intersects them against the
entity's declared joins. An `<af-entity type="Email">` yields nothing and the
confirmation email is silently skipped. Use `<div af-join="Email">` inside the
contact fieldset.

**The confirmation email only fires when `manual_processing` is on.** In that
mode nothing is written to CiviCRM until the applicant clicks the link, which is
the behavior we want: an unconfirmed address never creates a contact.

**Afform's own confirmation link expires after ten minutes and that is not
configurable.** It is hardcoded in `Civi\Afform\Tokens`. We send our own instead,
with a seven-day expiry, and switch `allow_verification_by_email` off so the
applicant does not get two emails. This is safe because Afform stores the whole
submission before emailing, and `CRM_Afform_Page_Verify` validates the JWT and
re-checks the submission status without inspecting the token's `scope` claim.
Links stay single use: `Afform::process()` requires status `Pending` and sets
`Processed`.

## languageLimit

This install runs in multilingual mode (localized tables such as
`civicrm_group_en_US`) while using only `en_US`, but `languageLimit` was never
populated. Locale resolution calls `array_keys()` on it, so every contact-scoped
templated email threw a `TypeError` in `Civi\Core\Locale`. `fix-lang.php` sets it
to `['en_US' => 1]`.

## Custom field tokens are `custom_N`, not the API name

The token processor exposes custom fields as `{contact.custom_13}`. The API-style
`{contact.Membership.employer_affiliation}` renders empty and only warns in the
log, so the reviewers were being emailed a blank employer, which is the single
field the review step exists to check. `review-template.php` resolves the number
from the field name at install time and throws if the field is missing, so this
fails loudly rather than silently.

## Act on the submission, not on contact creation

`Afform\Process` writes the contact first and its joins and custom fields after,
then flips the submission to `Processed`. A `civicrm_postCommit` hook on
`Individual` create therefore runs too early: no email address exists yet and the
custom fields are empty. The plugin hooks the submission going to `Processed`
instead, and reads the contact ids out of the submission data, where
`combineValuesAndIds` has put them at `Individual1[0].id`.

## Repeat applicants are answered by email, never on the page

The application form is public and anonymous. A page that said "you are already a
member" would let anyone type an address and learn whether that person is a
member, and their member number. The Foundation does not publish a list of
members, so the page response is identical in every case and the answer goes only
to the address that was typed. One courtesy email per address per 24 hours, so
the form cannot be used to flood a member's inbox.

## Member numbers

Approval is adding someone to the `members` group. The plugin reacts by issuing
a number, taking them out of the review queue, and sending the welcome email.

Two rules decide the number:

1. **A contact that already has one keeps it.** Approving twice never renumbers
   anyone and never sends a second welcome.
2. **The next number is one above the highest on record**, read from the custom
   value table rather than from a counter.

Rule 2 is what makes repeated testing possible. CiviCRM keeps the custom values
of a *trashed* contact, so an ordinary withdrawal never lets a number be handed
out twice. Only a *purge* removes them, and a purge is what
`reset-applicant.php` does. So walking through the whole process with your own
address as many times as you like costs nothing, as long as you reset between
runs:

```bash
sudo -u www-data wp --path=/var/www/openarcollective.org eval-file reset-applicant.php you@example.org
```

Called with no address it reports the members on record and the next number, and
changes nothing.

Assignment is wrapped in a CiviCRM lock, so two approvals in the same moment
cannot read the same maximum and issue the same number.

One caveat: because approval is group membership, a bulk add to `members` admits
everyone in the selection and emails them all. That is the correct reading of the
gesture, but it is not undoable.

## GroupContact hooks come in two shapes

`CRM_Contact_BAO_GroupContact` fires `post($op, 'GroupContact', $groupId,
$contactIds)`, where the object id is the *group* and the ref is a list of
contact ids. API4 writes go through the DAO and pass the GroupContact row
instead. Both occur here, the first from the CiviCRM screens and the second from
this plugin's own code, so the handler detects which it was given.

## Declining

Adding someone to `applicants_declined` sends the decline. The plugin stamps the
date, clears them out of the review queue, and emails them the reason, the appeal
route, and the fact that nothing they can actually use is affected.

**The reason comes from `decline_reason`, never from `application_notes`.** Those
are the reviewer's working notes and will sooner or later hold something
speculative or blunt that must not be mailed to the person it is about. Only
`decline_reason` is ever sent, and its help text says so on the form.

**No reason means no email.** A decline that gives no reason is worse than one
that has not been sent yet, so the applicant hears nothing and the reviewers get
an alert. Filling in the reason and saving sends it. Either order works: add to
the group and then write the reason, or write the reason and then add to the
group. Nothing about declining needs a command line.

`send-decline.php` remains as a report and a fallback. With no id it lists
everyone declined, whether a reason is recorded, and whether they have been
told.

Sending is recorded as an activity and checked before every send, so re-adding
someone to the group never mails them twice.

**The appeal goes to `membership@`, and that is deliberate.** There is a
`board@` list reaching all five directors, but sending an appellant there means
five people answering separately and inconsistently. One person takes the appeal
and puts it to the Board as a matter of procedure, which is what Term 13
describes. Do not "fix" `OPENAR_APPEAL_INBOX` to a board-wide alias.

## Keep operational actions inside CiviCRM

Whoever does reviews should never have to open a terminal. Every step of the
live process is therefore a thing you can do on CiviCRM's own screens:

| Action | How it is done |
|---|---|
| Approve | Add the contact to `members` |
| Decline | Add to `applicants_declined`, and fill in the reason |
| Record why | Type into the contact's custom fields |

The CLI scripts here are provisioning (run once, by a developer) and testing.
The one operational gap left is resending a confirmation link to someone whose
link lapsed, because unconfirmed applications are form submissions rather than
contacts and CiviCRM has no screen for them. That is the first candidate for the
admin toolbox on the roadmap.

## Discord

`openar-discord.php` joins an admitted member to the server through OAuth2. Two
routes, both public:

| Route | Purpose |
|---|---|
| `/connect` | The link in the welcome email, authenticated by CiviCRM checksum |
| `/connect/callback` | Where Discord returns |

**Credentials never live in this repository.** Five constants in `wp-config.php`
switch it on. Until all five are defined the plugin is dormant: `/connect`
renders a page pointing at membership@, and the welcome email omits the button
rather than carrying a dead link. Adding the constants is the whole deployment
step.

```php
define('OPENAR_DISCORD_CLIENT_ID',       '...');
define('OPENAR_DISCORD_CLIENT_SECRET',   '...');
define('OPENAR_DISCORD_BOT_TOKEN',       '...');
define('OPENAR_DISCORD_GUILD_ID',        '...');
define('OPENAR_DISCORD_MEMBER_ROLE_ID',  '...');
```

### Why OAuth rather than an invite link

Discord has no endpoint resolving a username to a user, so a typed handle can
never be validated. OAuth has Discord authenticate the person and hand back
their real snowflake, and `PUT /guilds/{id}/members/{id}` takes `roles` and
`nick` in the same call, so a member arrives already roled and named. No invites
are minted, so there is no invite list to clean up and no ambiguity when several
people join at once.

### Things that bite

**`PUT` returns 204 when the person is already in the guild, and silently
ignores the roles and nick you sent.** Anyone who wandered in through a public
invite would otherwise finish the flow roleless while the code reported success.
On 204 the plugin reads their current roles, merges the Member role in, and
`PATCH`es. Merging rather than replacing matters: a moderator who runs the flow
must not lose their other roles.

**The bot's role must sit above Member**, or role assignment fails with 403.

**Nicknames cap at 32 characters**, so the real name is truncated rather than
rejected.

**The redirect URI is matched character for character.** WordPress's canonical
redirect is disabled on these routes so it cannot append a trailing slash and
break the match.

**The callback must not fall behind Cloudflare Access.** The existing Access
application covers `wp-admin` and `wp-login.php` only, and `/connect/callback`
was checked as publicly reachable.

### Security

The link carries a CiviCRM checksum, never the member id, which is sequential
and would otherwise let anyone walk the range and admit themselves. The checksum
is re-validated on the return leg, and it is echoed through OAuth `state` inside
a short-lived signed JWT, which also covers CSRF on the callback.

A valid checksum proves identity, not entitlement, so the plugin separately
requires current membership in the `members` group before admitting anyone.

### Testing without credentials

Every Discord request goes through `openar_discord_http()`, and the
`openar_discord_http` filter stands in for it. `test-discord.php` exercises the
201 path, the 204-then-PATCH path, role merging, nickname truncation, a 403, and
the checksum guards, all without touching Discord.
