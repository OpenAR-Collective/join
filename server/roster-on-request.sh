#!/bin/bash
#
# Run the roster sync when the Tools screen asks for one.
#
# The screen cannot run it directly. Pushing needs the GitHub App private key,
# which is mode 600 and owned by rob, and the web server runs as www-data.
# Making that key readable to the web user to save a two minute wait would be a
# poor trade: it is the credential that can write to the website repository.
#
# So the button leaves a note and this picks it up. The note is a file the web
# user owns and rewrites; this never deletes it, because a file created by
# www-data in /tmp cannot be removed by rob, and instead remembers the
# timestamp it last acted on. Nothing to clean up and no permissions to widen.
#
# Cron, as rob, alongside the hourly sync:
#   * * * * * /home/rob/openar-server/roster-on-request.sh

set -uo pipefail

REQUEST=/tmp/openar-roster-sync.request
SEEN=/home/rob/openar-roster/last-request-handled
SYNC=/home/rob/openar-roster/sync-roster.sh

[ -f "$REQUEST" ] || exit 0

requested=$(stat -c %Y "$REQUEST" 2>/dev/null) || exit 0
handled=$(cat "$SEEN" 2>/dev/null || echo 0)

# Only a request made since the last one this script acted on. Without the
# comparison every run would sync again, which would mean a push attempt every
# minute for as long as the file existed.
[ "$requested" -gt "$handled" ] || exit 0

echo "$requested" > "$SEEN"
"$SYNC"
