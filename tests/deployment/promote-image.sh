#!/bin/sh
# Exercise the real promotion script without a registry, credentials or network access.
set -eu
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
cat > "$work/git" <<'SH'
#!/bin/sh
printf 'abc\trefs/tags/v1.0.0\n'
SH
cat > "$work/skopeo" <<'SH'
#!/bin/sh
# Same credentials file as buildah, or Docker Hub and the GitLab registry refuse the requests.
test -n "$REGISTRY_AUTH_FILE"
case "$1" in
    list-tags) [ "$REGISTRY_OK" = yes ] && cat "$TAGS" ;;
    *) exit 1 ;;
esac
SH
cat > "$work/buildah" <<'SH'
#!/bin/sh
test -n "$REGISTRY_AUTH_FILE"
case "$1" in
    login) cat >/dev/null ;;
    push) printf '%s\n' "$3" >> "$PUSHES" ;;
    pull) : ;;
    inspect) echo v1.0.0 ;;
    *) exit 1 ;;
esac
SH
chmod +x "$work/git" "$work/skopeo" "$work/buildah"
export PATH="$work:$PATH" TAGS="$work/tags" PUSHES="$work/pushes" REGISTRY_OK=yes
export CI_REGISTRY=local CI_IMAGE=local/test CI_REGISTRY_PASSWORD=test CI_REGISTRY_USER=test
export DOCKERHUB_REPOSITORY=test/example DOCKERHUB_USERNAME=test DOCKERHUB_TOKEN=test PUBLIC_FIRST_VERSION=v1.0.0
printf '%s\n' '{"Tags":["latest","v1.0.0-20261101-p200","v0.9.0-20261001-p999"]}' > "$TAGS"
CI_PIPELINE_ID=201 sh ci/publish-image.sh
grep -qx 'docker://docker.io/test/example:latest' "$PUSHES"
rm "$PUSHES"
if CI_PIPELINE_ID=199 sh ci/publish-image.sh; then exit 1; fi
if REGISTRY_OK=no CI_PIPELINE_ID=202 sh ci/publish-image.sh; then exit 1; fi
test ! -e "$PUSHES"
