#!/bin/bash
#
# Publish the Mission Supporter roster to the website.
#
# Runs on the CiviCRM host as rob, hourly from cron. Reads the published group
# straight out of CiviCRM through wp, so there is no API key, no site key, and
# no Cloudflare in the path. Commits and pushes only when something changed;
# Cloudflare Pages deploys the push.
#
# Approving a supporter is a CiviCRM action. This turns that into a change on
# openarcollective.org, and a withdrawal off it, without anyone running anything.
#
# Failures are mailed to bots@ through CiviCRM's own mailer, because a sync that
# quietly stops is worse than one that breaks loudly: the roster would simply
# freeze and nobody would know.
#
# Pushing uses a GitHub App installation token, minted fresh each run and good
# for an hour. The org disables deploy keys by policy, and rightly: a deploy key
# cannot be rotated, attributed, or revoked without touching this box.

set -uo pipefail

WP_PATH=/var/www/openarcollective.org
SCRIPTS=/home/rob/openar-scripts
WORK=/home/rob/openar-roster
SERVER_SCRIPTS=/home/rob/openar-server
REPO_SLUG=OpenAR-Collective/website
REPO="$WORK/website"
# Written by www-data, which has no business writing into rob's home. The
# contents are the public roster, so a world-readable path gives nothing away.
DUMP=/tmp/openar-supporters.json
LOG="$WORK/last-run.log"
# The branch the roster publishes to. full-site while the site is being
# rehearsed: Cloudflare builds it to full-site.website-8wa.pages.dev, so a test
# supporter appears there and never on openarcollective.org.
# Switch to main when full-site merges.
BRANCH=full-site

mkdir -p "$WORK"

# Everything from here is captured, so the failure mail can quote it.
exec >"$LOG" 2>&1

echo "roster sync starting: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"

fail() {
  echo
  echo "FAILED: $1"
  # Never let the notifier's own failure hide the original one.
  sudo -n -u www-data wp --path="$WP_PATH" eval-file "$SCRIPTS/notify-sync-failure.php" "$LOG" \
    || echo "(could not send the failure notification either)"
  exit 1
}

# 1. Read the roster out of CiviCRM.
sudo -n -u www-data wp --path="$WP_PATH" eval-file "$SCRIPTS/dump-supporters.php" "$DUMP" \
  || fail "could not read the published group from CiviCRM"
[ -s "$DUMP" ] || fail "the dump is empty or missing: $DUMP"

# 2. Start from exactly what is on the branch. This clone is written by nothing
#    else, so discarding local state is the right way to recover from a run that
#    died half way through.
cd "$REPO" || fail "no clone at $REPO"
git fetch --quiet origin "$BRANCH" || fail "could not fetch origin/$BRANCH"
git checkout --quiet "$BRANCH" || fail "could not check out $BRANCH"
git reset --quiet --hard "origin/$BRANCH" || fail "could not reset to origin/$BRANCH"

# 3. Until full-site merges, main is still the holding page and carries neither
#    the sync script nor the content collection. That is a normal state, not a
#    failure, so say so and stop rather than mailing about it every hour.
if [ ! -f scripts/sync-supporters.py ]; then
  echo
  echo "branch $BRANCH has no scripts/sync-supporters.py, so it is still the holding page."
  echo "The roster starts publishing by itself once full-site merges to $BRANCH."
  exit 0
fi

# 4. Turn the dump into content files.
CIVI_FIXTURE="$DUMP" python3 scripts/sync-supporters.py || fail "the sync script errored"

# 5. Commit and push only if that changed something.
if [ -z "$(git status --porcelain src/content/supporters)" ]; then
  echo
  echo "roster unchanged, nothing to push"
  exit 0
fi

echo
echo "changes to publish:"
git status --porcelain src/content/supporters

git -c user.name="openar-roster-sync" \
    -c user.email="bots@openarcollective.org" \
    commit --quiet -m "Update the Mission Supporter roster

Written by the hourly roster sync from the CiviCRM published group.
Approving or withdrawing a supporter happens in CiviCRM; this commit is
the result of that decision, not the decision itself." -- src/content/supporters \
  || fail "could not commit"

# The token is short lived and never written to disk. Interpolating it into the
# remote URL keeps it out of the process list, which a command-line argument
# would not.
TOKEN=$(php "$SERVER_SCRIPTS/github-token.php") || fail "could not mint a GitHub App token"
[ -n "$TOKEN" ] || fail "the GitHub App token came back empty"

git push --quiet "https://x-access-token:${TOKEN}@github.com/${REPO_SLUG}.git" "$BRANCH"   || fail "could not push to $BRANCH"
unset TOKEN

echo
echo "pushed. Cloudflare Pages will deploy it."
