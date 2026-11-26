#!/bin/sh
# Promote only a checked release, never the output of a branch or an obsolete pipeline.
set -eu
: "${DOCKERHUB_USERNAME:?}" "${DOCKERHUB_TOKEN:?}" "${DOCKERHUB_REPOSITORY:?}"
printf '%s\n' "$DOCKERHUB_REPOSITORY" | grep -Eq '^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9._-]*$'
case "$DOCKERHUB_REPOSITORY" in *[!a-z0-9_./-]*) exit 1;; esac
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
printf '%s' "$CI_REGISTRY_PASSWORD" | buildah login --username "$CI_REGISTRY_USER" --password-stdin "$CI_REGISTRY"
buildah pull --quiet "docker://$CI_IMAGE"
printf '%s' "$DOCKERHUB_TOKEN" | buildah login --username "$DOCKERHUB_USERNAME" --password-stdin docker.io
for tag in "$IMAGE_VERSION-$(date -u +%Y%m%d)-p$CI_PIPELINE_ID" "$IMAGE_VERSION" latest; do
    buildah push "$CI_IMAGE" "docker://docker.io/$DOCKERHUB_REPOSITORY:$tag"
done
