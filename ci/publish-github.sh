#!/bin/sh
# Build the public trees from the tagged source and this pipeline's screenshots, never from the runner's checkout.
set -eu
: "${GITHUB_PUBLISH_TOKEN:?}" "${GITHUB_REPOSITORY:?}" "${DOCKERHUB_REPOSITORY:?}"
case "$GITHUB_REPOSITORY" in *[!a-zA-Z0-9_./-]*) exit 1;; esac
printf '%s\n' "$GITHUB_REPOSITORY" | grep -Eq '^[a-zA-Z0-9][a-zA-Z0-9-]*/[a-zA-Z0-9][a-zA-Z0-9._-]*$'
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir "$work/source"
git archive --format=tar "$CI_COMMIT_SHA" -- . ':(exclude).github/workflows' > "$work/source.tar"
tar -xf "$work/source.tar" -C "$work/source"
test "v$(cat "$work/source/VERSION")" = "$CI_COMMIT_TAG"
case "$DOCKERHUB_REPOSITORY" in *[!a-z0-9_./-]*) exit 1;; esac
owner=${GITHUB_REPOSITORY%/*}
name=${GITHUB_REPOSITORY#*/}
base="https://$owner.github.io/$name"
# Render the public coordinates only in the exported copy; the private checkout is unchanged.
for config in "$work/source/documentation/zensical"*.toml; do
    sed -i -e "s|https://kartnico.github.io/patrimeter|$base|g" \
        -e "s|https://github.com/kartnico/patrimeter|https://github.com/$GITHUB_REPOSITORY|g" \
        -e "s|repo_name = .*|repo_name = \"$GITHUB_REPOSITORY\"|" \
        -e "s|link = \"/patrimeter/|link = \"/$name/|g" "$config"
done
# Keep the private CI badges available in GitLab documentation, not as broken images on the public landing page.
for file in "$work/source/README.md" "$work/source/README.en.md"; do
    sed -i -e '/^\[!\[.*gitlab\./d' -e "s|https://kartnico.github.io/patrimeter|$base|g" \
        -e "s|DOCKERHUB_NAMESPACE/patrimeter|$DOCKERHUB_REPOSITORY|g" "$file"
done
for file in "$work/source/docs/docker.md" "$work/source/docs/docker.en.md"; do
    sed -i "s|DOCKERHUB_NAMESPACE/patrimeter|$DOCKERHUB_REPOSITORY|g" "$file"
done
for file in "$work/source/.env" "$work/source/.env.example" "$work/source/docker/patrimeter.env.example"; do
    sed -i "s|https://kartnico.github.io/patrimeter|$base|g" "$file"
done
sh ci/prepare-public-tree.sh "$work/source"
cp -R "$work/source" "$work/docs"
for lang in fr en; do
    test -n "$(find "documentation/$lang/captures" -name '*.png' -print -quit)"
    cp -R "documentation/$lang/captures" "$work/docs/documentation/$lang/"
done
sh "$work/docs/documentation/build.sh"
site="$work/docs/documentation/site"
test -s "$site/fr/index.html" && test -s "$site/en/index.html"
# Credentials live only in the protected job environment, never in a remote URL or a snapshot.
printf '%s\n' '#!/bin/sh' 'case "$1" in *Username*) echo x-access-token;; *) printf "%s\n" "$GITHUB_PUBLISH_TOKEN";; esac' > "$work/auth"
chmod 700 "$work/auth"
export GIT_ASKPASS="$work/auth" GIT_TERMINAL_PROMPT=0
repo="$work/public.git"
git init -q --bare "$repo"
export GIT_DIR="$repo" GIT_AUTHOR_NAME='Patrimeter Releases' GIT_AUTHOR_EMAIL='releases@users.noreply.github.com'
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME" GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
git remote add public "https://github.com/$GITHUB_REPOSITORY.git"
git fetch --quiet --no-tags public '+refs/heads/*:refs/public/*' '+refs/tags/v*:refs/tags/v*'
if git show-ref --verify --quiet "refs/tags/$CI_COMMIT_TAG"; then
    echo 'Tag already published; the atomic push has already completed.'; exit 0
fi
latest=$(git tag --sort=-version:refname | head -n 1)
if [ -n "$latest" ]; then
    test "$(printf '%s\n' "$latest" "$CI_COMMIT_TAG" | sort -V | tail -n 1)" = "$CI_COMMIT_TAG"
fi
snapshot() {
    branch=$1; export GIT_INDEX_FILE="$work/index-$branch"
    git --work-tree="$2" -C "$2" add --force --all -- .
    tree=$(git write-tree)
    set --
    if parent=$(git rev-parse --verify "refs/public/$branch" 2>/dev/null); then set -- -p "$parent"; fi
    commit=$(git commit-tree "$tree" "$@" -m "Release $CI_COMMIT_TAG")
    git update-ref "refs/heads/$branch" "$commit"
}
snapshot main "$work/source"
snapshot gh-pages "$site"
git tag -a "$CI_COMMIT_TAG" -m "Release $CI_COMMIT_TAG" refs/heads/main
git push --atomic public refs/heads/main refs/heads/gh-pages "refs/tags/$CI_COMMIT_TAG"
