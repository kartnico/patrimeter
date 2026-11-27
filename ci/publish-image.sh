#!/bin/sh
# Promote only a checked release, never the output of a branch or an obsolete pipeline.
set -eu
: "${DOCKERHUB_USERNAME:?}" "${DOCKERHUB_TOKEN:?}" "${DOCKERHUB_REPOSITORY:?}"
printf '%s\n' "$DOCKERHUB_REPOSITORY" | grep -Eq '^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9._-]*$'
case "$DOCKERHUB_REPOSITORY" in *[!a-z0-9_./-]*) exit 1;; esac
# One credentials file for buildah and skopeo: skopeo does not read buildah's default one (3.5.0 failed with a 403).
REGISTRY_AUTH_FILE="$(mktemp -d)/auth.json"
export REGISTRY_AUTH_FILE
printf '%s' "$CI_REGISTRY_PASSWORD" | buildah login --username "$CI_REGISTRY_USER" --password-stdin "$CI_REGISTRY"
# The version comes from the labels of the tested image, pulled the way the releases up to 3.4.1 did.
buildah pull --quiet "docker://$CI_IMAGE" > /dev/null
IMAGE_VERSION=$(buildah inspect --type image --format '{{ index .OCIv1.Config.Labels "org.opencontainers.image.version" }}' "$CI_IMAGE")
printf '%s\n' "$IMAGE_VERSION" | grep -Eq '^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
# Never publish an old private version during the transition, even if it is still the newest tag.
: "${PUBLIC_FIRST_VERSION:?Set the first reviewed public tag}"
printf '%s\n' "$PUBLIC_FIRST_VERSION" | grep -Eq '^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
test "$(printf '%s\n' "$PUBLIC_FIRST_VERSION" "$IMAGE_VERSION" | sort -V | head -n 1)" = "$PUBLIC_FIRST_VERSION"
# A delayed retry or an older daily rebuild must not roll back latest. Fail closed on network errors.
tags=$(git ls-remote --refs --tags origin 'v*')
latest=$(printf '%s\n' "$tags" | sed -nE 's@^.*refs/tags/(v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*))$@\1@p' | sort -V | tail -n 1)
if [ "$latest" != "$IMAGE_VERSION" ]; then
    echo 'Superseded release: refusing to update public tags.' >&2
    exit 1
fi
printf '%s' "$DOCKERHUB_TOKEN" | buildah login --username "$DOCKERHUB_USERNAME" --password-stdin docker.io
# The immutable build tag is pushed first, so it also guards retries after a partial promotion.
# Read the registry under the GitLab resource-group lock and do not treat lookup failures as an empty list.
case "$CI_PIPELINE_ID" in ''|*[!0-9]*) exit 1;; esac
listed=$(skopeo list-tags "docker://docker.io/$DOCKERHUB_REPOSITORY")
newest=$(printf '%s' "$listed" | jq -er --arg v "$IMAGE_VERSION-" '
    [.Tags[] | select(startswith($v)) | ltrimstr($v) | capture("^[0-9]{8}-p(?<id>[0-9]+)$").id | tonumber] | max // 0')
if [ "$CI_PIPELINE_ID" -lt "$newest" ]; then
    echo 'Superseded rebuild: refusing to replace newer public images.' >&2
    exit 1
fi
for tag in "$IMAGE_VERSION-$(date -u +%Y%m%d)-p$CI_PIPELINE_ID" "$IMAGE_VERSION" latest; do
    buildah push "$CI_IMAGE" "docker://docker.io/$DOCKERHUB_REPOSITORY:$tag"
done
