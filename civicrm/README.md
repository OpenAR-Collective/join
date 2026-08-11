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
