# join.openarcollective.org

Integration code for the membership and Mission Supporter onboarding that runs at
[join.openarcollective.org](https://join.openarcollective.org), a WordPress and
CiviCRM installation operated by The Open Accounts Receivable Collective
Foundation.

This repository is public on purpose. The Foundation asks practitioners to hand
over their name, employer, and email address in order to join a community, and
publishing the code that receives it means anyone can check what actually happens
to that information rather than taking our word for it.

## What lives here

The Discord onboarding callback, the CiviCRM helpers around it, and the scheduled
jobs that support membership and Mission Supporter processing. The public website
is a separate repository, [OpenAR-Collective/website](https://github.com/OpenAR-Collective/website).

## What does not live here, ever

No credentials, no API keys, no bot tokens, no database dumps, no exports, and no
member data of any kind. Configuration comes from the server environment.
Anything sensitive lives in the Foundation's credential store.

If you believe a credential has been committed here, please report it to
security@openarcollective.org rather than opening a public issue, and we will
rotate it.

## Licensing

Code in this repository is licensed under the Apache License, Version 2.0,
consistent with Article III of the Foundation's
[Open Source Policy](https://openarcollective.org/policies/open-source/).

Brand assets are not open licensed. Their use is governed by the Foundation's
[Trademark Policy](https://openarcollective.org/policies/trademark/).

## What is here

Everything needed to rebuild join.openarcollective.org's configuration from
nothing, apart from credentials.

| Directory | What it holds |
|---|---|
| `mu-plugins/` | The must-use plugins: onboarding, Discord connect, short URLs, badges, admin screen |
| `mu-plugins/openar-assets/` | The badge art and font the badges plugin draws with |
| `civicrm/` | Scripts that build the custom fields, groups, forms and email templates |
| `wordpress/` | The join site's brand stylesheet and its installer |
| `server/` | The roster publishing job, its GitHub App token minter, and the plugin and asset installers |

Not here, and deliberately: credentials. The Discord client secret and bot token
live in `wp-config.php`, the GitHub App private key in `~/.config/openar/`, and
neither belongs in a public repository.

## Things that were only ever on the server

Four pieces of this build existed nowhere but the live machine until 2026-08-11:
the Statement of Support form layout, the Mission Supporter custom fields, the
short-URL plugin that provides `/apply` and `/sign`, and the entire brand
stylesheet. Any of them would have been rebuilt from memory or a screenshot if
the machine had been lost. They are all here now.

The general rule this is worth remembering by: **if it took a decision to make,
it belongs in the repository.** A form layout is a decision. A stylesheet is a
decision. A field's help text is a decision.

## Running the process without a terminal

**Tools > OpenAR onboarding**, in wp-admin.

It answers the one question nothing else can: who has filled in a form and not
yet clicked the link in their email. CiviCRM's own Submissions screen lists those
submissions but cannot identify them, because the applicant's name and address
live inside the submission's data blob and its "Submitted by" column is the
logged-in user, which for a public applicant is nobody at all.

The screen shows name, address, which form, how long the link has left, and a
button to send a fresh one. It also links to the review queues, the members
group, and the supporter groups, so the day-to-day work has one starting point.

The same figures appear as a **Dashboard widget**, so they are seen on login
rather than only when someone remembers to look.

### The six states

Both paths reach the same three states, so they are named and ordered the same
way. The symmetry is the point: it draws a clean line between members and
Mission Supporters and makes it obvious which side of the house a number belongs
to. "3 waiting" means different work depending on which one it is.

It is also meant to need no explaining. The table carried a paragraph telling
the reader that members were above the line and supporters below it, which the
table already said.

| | |
|---|---|
| Members awaiting confirmation | Email confirmation link not clicked |
| Member applications to review | Verify AR professional credentials |
| Members | Individuals issued a member ID |
| Mission Supporters awaiting confirmation | Email confirmation link not clicked |
| Mission Supporters to review | Verify company legitimacy |
| Mission Supporters | Companies publicly listed as Mission Supporters |

The two review rows are marked **ACTION NEEDED** in red, but only while the
count is above zero. A warning that is present every day is read as decoration
within a week.

These six are defined once, in `openar_admin_rows()`, and rendered twice. The
widget and the Tools screen said different things for a while because there were
two copies of the labels and only one got updated.

Both screens carry a red warning when outbound email is not actually being
delivered, which is the failure that hides best: everything appears to work and
nothing arrives.

`civicrm/pending-applications.php` still does the same job from a terminal, and
is the fallback if the plugin is ever unloaded.

## Badges

Every member's welcome email carries their badge: the blank member badge with
their number drawn over the center hexagon, rendered fresh at send time by
`openar-badges.php` from the art in `mu-plugins/openar-assets/`. The Mission
Supporter listing email carries the supporter badge the same way, as a static
file. A badge that cannot be drawn downgrades the email rather than blocking
the admission, and the emails only mention an attachment when one is there.

The Tools screen has a **Send a member badge** section for a member who has
lost theirs: pick the member, and Email sends a fresh copy to the address on
file while Download saves the same image locally. The badge is a pure function
of the member number, so there is no stored image to manage or go stale.

A Mission Supporter's badge is drawn with the name the roster shows, the
trade name if one is set and the legal name otherwise. Names wrap onto two
lines at a space when that lets the type render larger, and a name too long
to draw legibly gets the plain badge rather than one shrunk into
illegibility. The **Send a supporter badge** section on the Tools screen
emails a fresh copy to the signer or downloads it locally, and its list says
which organizations fall back to the plain badge.

## CiviCRM belongs in wp-admin

CiviCRM will happily render a contact record on the public base page, inside the
site theme, where it looks broken: the theme's typography and the brand
stylesheet are built for the public forms, and CiviCRM's own admin CSS expects
wp-admin. Unreadable buttons, and select boxes clipped to half their height.

`openar-admin.php` moves signed-in staff from a front-end back office path to the
same page in wp-admin, carrying the query string. That is done at the door rather
than by correcting the links we send, because one bad entry point was enough:
once you were on the base page, CiviCRM generated base page links for everything
after it, so an old email or a bookmark kept a whole session there.

The list of back office paths is a denylist, deliberately. Both forms and the
confirmation link people open from their email have to keep working for a
stranger with no account, so the default is to leave a path alone.

## Installing a must-use plugin

`wp-content/mu-plugins` belongs to `www-data`, and the deploy account may run
only `wp` as that user. So a plugin is staged somewhere writable and put in place
by `server/install-mu-plugin.php`, which `wp` runs as the web user:

```bash
scp mu-plugins/openar-admin.php rob@HOST:/tmp/ && ssh rob@HOST 'sudo -u www-data wp --path=/var/www/openarcollective.org eval-file ~/openar-server/install-mu-plugin.php /tmp/openar-admin.php'
```

It refuses to install anything that does not parse. A must-use plugin with a
syntax error takes down the whole site including wp-admin, leaving no way back in
except a shell.
