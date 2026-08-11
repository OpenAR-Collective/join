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
