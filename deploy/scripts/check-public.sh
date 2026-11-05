#!/usr/bin/env bash
# Is every public entry point actually reaching its application?
#
# The vhost lives outside this repository (/etc/daily-work-web/Caddyfile), so a site
# block can disappear — or a container can fall over — with nothing in CI noticing. This
# runs from a timer and fails loudly instead of waiting for somebody to open a bookmark.
#
#   deploy/scripts/check-public.sh
#   WRP_PUBLIC_REPORT=https://report.521985.xyz WRP_PUBLIC_SSO=https://sso.521985.xyz
#   WRP_PUBLIC_IP=172.22.0.29      (the NIC the public address is NATed to)
set -euo pipefail
source "$(dirname "$0")/lib.sh"

report=${WRP_PUBLIC_REPORT:-https://report.521985.xyz}
sso=${WRP_PUBLIC_SSO:-https://sso.521985.xyz}
legacy=${WRP_PUBLIC_LEGACY:-https://521985.xyz}

# This host cannot reach its own public address: the NAT has no hairpin, the same fact
# that made the SSO's token exchange fail until it was pointed at the loopback. So the
# HTTP checks are sent to the NIC address with --resolve, which still exercises TLS,
# Caddy, the site block, nginx and the application — everything except the NAT itself —
# and the DNS side is checked separately below.
nic=${WRP_PUBLIC_IP:-$(ip -4 -o addr show scope global | awk '{print $4}' | cut -d/ -f1 | grep -v '^100\.' | head -1)}
resolve=()
for host in report.521985.xyz sso.521985.xyz 521985.xyz; do
    resolve+=(--resolve "$host:443:$nic")
done

failed=0

check() { # <label> <url> <expected-status> [body-fragment]
    local label=$1 url=$2 want=$3 body=${4:-}
    local out status
    out=$(curl -fsSk -o /tmp/wr-check.$$ -w '%{http_code}' --max-time 10 "${resolve[@]}" "$url" 2>/dev/null) || true
    status=${out:-000}

    if [ "$status" != "$want" ]; then
        log "FAIL $label: $url answered $status, wanted $want"
        failed=1
        return
    fi

    if [ -n "$body" ] && ! grep -q "$body" /tmp/wr-check.$$; then
        log "FAIL $label: $url answered $status but not the application's body"
        failed=1
        return
    fi

    log "ok   $label ($status)"
}

# DNS is the one part the --resolve trick skips, so ask it directly: a record pointing
# somewhere else takes every application off the internet as effectively as a missing
# site block.
for host in report.521985.xyz sso.521985.xyz; do
    if getent hosts "$host" >/dev/null; then
        log "ok   dns $host -> $(getent hosts "$host" | awk '{print $1}' | head -1)"
    else
        log "FAIL dns $host does not resolve"
        failed=1
    fi
done

# The application's own health endpoint: proves TLS, Caddy, nginx and PHP all agree.
check "report healthz" "$report/healthz" 200 "ok"
check "sso healthz" "$sso/healthz" 200

# The old spellings must keep redirecting; if someone deletes those blocks, links in
# chat and in member picture URLs stop working silently.
check "legacy /report" "$legacy/report" 308
check "legacy /sso" "$legacy/sso" 308

rm -f /tmp/wr-check.$$
[ "$failed" -eq 0 ] || die "a public entry point is not reaching its application"
