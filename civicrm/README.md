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
| `confirm-email.php` | Confirmation email template, enables verification |
| `review-template.php` | Reviewer notification template |
| `fix-lang.php` | Populates `languageLimit` (see note) |

## Two things that are easy to get wrong

**Email must be a join, not an entity.** Afform's verification routine looks for
the address in the contact entity's `joins` and intersects them against the
entity's declared joins. An `<af-entity type="Email">` yields nothing and the
confirmation email is silently skipped. Use `<div af-join="Email">` inside the
contact fieldset.

**The confirmation email only fires when `manual_processing` is on.** In that
mode nothing is written to CiviCRM until the applicant clicks the link, which is
the behaviour we want: an unconfirmed address never creates a contact. The
verification link is hardcoded to expire after ten minutes.

## languageLimit

This install runs in multilingual mode (localized tables such as
`civicrm_group_en_US`) while using only `en_US`, but `languageLimit` was never
populated. Locale resolution calls `array_keys()` on it, so every contact-scoped
templated email threw a `TypeError` in `Civi\Core\Locale`. `fix-lang.php` sets it
to `['en_US' => 1]`.
