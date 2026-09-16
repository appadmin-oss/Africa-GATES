#!/usr/bin/env bash
#
# THE MySQL PARITY RUN, SET UP FROM NOTHING.
#
# ══════════════════════════════════════════════════════════════════════════════
# WHY THIS SCRIPT EXISTS
# ══════════════════════════════════════════════════════════════════════════════
#
# `CLAUDE.md` has a whole section on faults that are INVISIBLE under SQLite: real
# ENUMs, integer widths, strict mode, ONLY_FULL_GROUP_BY, the DATETIME/TIMESTAMP
# timezone shift. Every one of them shipped green. The parity run is the only thing
# that sees them, and it was documented as a command with no way to get a server —
# so on a fresh container it was one apt install and four setup steps away from
# being run, which in practice means it was not run.
#
#   scripts/mysql-parity.sh            # set up if needed, then run the whole suite
#   scripts/mysql-parity.sh --filter X # ...or pass anything through to phpunit
#   scripts/mysql-parity.sh --setup    # set the server up and stop
#
# ── IT REFUSES MariaDB, AND THAT IS THE POINT ────────────────────────────────
#
# MariaDB is the easy one to reach for: same client, same connection string,
# `mysqld` on the path. It has supported `CREATE INDEX IF NOT EXISTS` since 10.1.4,
# so a full green parity run on MariaDB says NOTHING about the three migrations
# that were throwing a 1064 on production — it creates the indexes and reports
# success. A parity run that silently is not one is worse than none, so this
# checks and stops rather than letting the report be wrong.
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-africa_gates_test}"
DB_USER="${DB_USER:-gates}"
DB_PASS="${DB_PASS:-gates_test_pw}"

say() { printf '\033[1m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m!!\033[0m %s\n' "$*" >&2; exit 1; }

setup_only=0
[ "${1:-}" = "--setup" ] && { setup_only=1; shift; }

# ── 1. a server ──────────────────────────────────────────────────────────────
if ! command -v mysqld >/dev/null 2>&1 && ! [ -x /usr/sbin/mysqld ]; then
    say "installing mysql-server"
    [ "$(id -u)" -eq 0 ] || die "not root, and mysqld is not installed — install mysql-server first"
    # policy-rc.d stops the postinst trying to start a service through systemd,
    # which a container does not have; the daemon is started by hand below.
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d && chmod +x /usr/sbin/policy-rc.d
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq mysql-server
fi

MYSQLD="$(command -v mysqld || echo /usr/sbin/mysqld)"

# THE CHECK THAT MAKES THIS A PARITY RUN. See the header.
if "$MYSQLD" --version 2>&1 | grep -qi mariadb; then
    die "this is MariaDB, not MySQL. It accepts things MySQL rejects — a green run here
    would prove nothing about production. See CLAUDE.md, 'The MySQL parity run'."
fi
say "$("$MYSQLD" --version)"

# ── 2. running ───────────────────────────────────────────────────────────────
if ! pgrep -x mysqld >/dev/null 2>&1; then
    say "starting mysqld on ${DB_HOST}:${DB_PORT}"
    mkdir -p /var/run/mysqld && chown mysql:mysql /var/run/mysqld
    nohup setpriv --reuid=mysql --regid=mysql --init-groups \
        "$MYSQLD" --bind-address="$DB_HOST" --port="$DB_PORT" \
        >/var/log/mysql/manual-start.log 2>&1 &
    for _ in $(seq 1 60); do
        mysqladmin --protocol=socket -u root ping >/dev/null 2>&1 && break
        sleep 1
    done
fi
mysqladmin --protocol=socket -u root ping >/dev/null 2>&1 \
    || die "mysqld did not come up — see /var/log/mysql/manual-start.log"

# ── 3. a database and a user ─────────────────────────────────────────────────
#
# Not root: the harness connects over TCP, and Ubuntu's root uses auth_socket,
# which always refuses a TCP connection. That failure reads as a wrong password.
say "ensuring ${DB_NAME} and user ${DB_USER}"
mysql --protocol=socket -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# The two modes the parity run exists to exercise. Reported, not assumed: a server
# started from someone's my.cnf with them off is a run that proves less than it says.
say "sql_mode: $(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -N -s -e 'SELECT @@sql_mode' 2>/dev/null)"

[ "$setup_only" -eq 1 ] && { say "set up. Run it with: scripts/mysql-parity.sh"; exit 0; }

# ── 4. the run ───────────────────────────────────────────────────────────────
#
# Both of these name code you did not touch when they bite. See CLAUDE.md.
[ -f .env ] && say "WARNING: a local .env is present — it leaks into the suite and breaks ~14 AI tests"
rm -f var/data/.gates-maintenance.lock var/data/.maintenance_tick

out="$(mktemp)"
say "running the parity suite (this takes a few minutes)"
set +e
TEST_DB_DRIVER=mysql DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_NAME="$DB_NAME" \
DB_USER="$DB_USER" DB_PASS="$DB_PASS" \
    ./vendor/bin/phpunit --no-coverage "$@" 2>&1 | tee "$out"
set -e

# READ THE COUNT, NOT THE EXIT CODE. Piping to tee gives the PIPE's status, and a
# run with two hundred errors exits 0 through a pipe — CLAUDE.md names this.
if grep -qE '^(OK|OK, but)' "$out"; then
    say "PARITY RUN GREEN — $(grep -oE 'Tests: [0-9]+.*' "$out" | tail -1)"
    exit 0
fi
die "PARITY RUN NOT GREEN — $(grep -oE 'Tests: [0-9]+.*' "$out" | tail -1)"
