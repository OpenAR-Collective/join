# Server-side roster publishing

Approving a Mission Supporter is a CiviCRM action: a reviewer adds the
organization to **Mission Supporters - published**. These scripts turn that into
a change on openarcollective.org, hourly, with nobody running anything.

| File | Where it lives | What it does |
|---|---|---|
| `sync-roster.sh` | `/home/rob/openar-roster/` | The cron job. Dumps, syncs, commits, pushes. |
| `github-token.php` | `/home/rob/openar-server/` | Mints a GitHub App installation token. |
| `dump-supporters.php` | `/home/rob/openar-scripts/` | Writes the published group to JSON. |
| `notify-sync-failure.php` | `/home/rob/openar-scripts/` | Mails a failure to bots@. |

Cron: `17 * * * *`, as `rob`. `MAILTO=""` because the script mails its own
failures with far better context than cron would.

## Why not GitHub Actions

Running the sync in Actions would mean giving GitHub a CiviCRM key that can read
every contact, including the whole member roster. GitHub has no other reason to
hold member data. Running it here means CiviCRM is read locally through `wp`,
with no API key, no site key, and no Cloudflare in the path.

## Why not a deploy key

The org disables deploy keys by policy, which is the right call: a deploy key
cannot be rotated, attributed, or revoked without logging into the box that holds
it. A GitHub App installation token is scoped to one repository, carries only
`contents: write`, expires in an hour, and is revocable from the org.

## Configuration

`/home/rob/.config/openar/github-app.env`, mode 600:

```
OPENAR_GH_APP_ID=123456
OPENAR_GH_KEY_FILE=/home/rob/.config/openar/openar-roster-sync.pem
```

The `.pem` is the App's private key, also mode 600. Neither belongs in this
repository, which is public.

## Two things that will look like bugs and are not

**It exits 0 saying `main` is still the holding page.** Until `full-site` merges,
`main` carries neither the sync script nor the content collection. That is a
normal state, so it reports and stops rather than mailing an alarm every hour.

**It resets the clone hard before every run.** `/home/rob/openar-roster/website`
is written by nothing else, so discarding local state is how a run that died half
way through recovers on its own.

## Running it by hand

```bash
/home/rob/openar-roster/sync-roster.sh; cat /home/rob/openar-roster/last-run.log
```

All output goes to the log; the script itself prints nothing.
