#!/bin/sh
# Stop after the first sleep request and inspect its delay without running real backups.
set -eu
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/var/cache/prod"
printf 'test\n' > "$work/VERSION"
cp "$work/VERSION" "$work/var/cache/prod/.patrimeter-ready"
export PATRIMETER_DIR="$work" PATH="$work/bin:$PATH" PATRIMETER_BACKUP_INTERVAL=12345
printf '%s\n' '#!/bin/sh' 'exit "$BACKUP_STATUS"' > "$work/bin/patrimeter-backup"
printf '%s\n' '#!/bin/sh' 'exit "$CHECK_STATUS"' > "$work/bin/patrimeter-restore"
printf '%s\n' '#!/bin/sh' 'echo "$1" >> "$PATRIMETER_DIR/delays"' 'exit 77' > "$work/bin/sleep"
chmod +x "$work/bin/"*
check() {
    : > "$work/delays"
    status=0
    BACKUP_STATUS="$1" CHECK_STATUS="$2" bash docker/entrypoint.sh backup || status=$?
    test "$status" = 77 && test "$(head -n 1 "$work/delays")" = "$3"
}
check 1 0 3600
check 0 1 3600
check 0 0 12345
