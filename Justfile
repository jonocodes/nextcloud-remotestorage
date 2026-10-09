# PHP tooling runs in Docker; no local PHP needed. (No -u: rootless podman maps container root to you.)
composer := "docker run --rm -v $PWD:/app -w /app -e COMPOSER_HOME=/tmp/composer composer:2"
harness := env_var_or_default("HARNESS_DIR", "../remotestorage-nextcloud-harness")

# Install dev dependencies
install:
    {{composer}} composer install --no-interaction --no-progress

# Unit tests (pure logic; DAV integration is tested by the harness)
test: install
    {{composer}} vendor/bin/phpunit

# Syntax-check every PHP file; print only failures and exit non-zero if any
lint:
    {{composer}} sh -c 'errs=$(find lib appinfo templates tests -name "*.php" -print0 | xargs -0 -n1 php -l 2>/dev/null | grep -v "^No syntax errors"); if [ -n "$errs" ]; then printf "%s\n" "$errs"; exit 1; fi'

# Integration: run the harness's rsapp variant against this checkout
harness *versions="35 34":
    cd {{harness}} && RS_APP_DIR={{justfile_directory()}} VERSIONS="{{versions}}" ./app/run.sh

# Build the app-store tarball into build/
# Build the app-store tarball into build/ from tracked files (uncommitted edits included,
# untracked files not); what is left out is set by export-ignore in .gitattributes
package:
    #!/usr/bin/env bash
    set -euo pipefail
    mkdir -p build
    ref=$(git stash create); git archive --format=tar.gz --prefix=remotestorage/ -o build/remotestorage.tar.gz "${ref:-HEAD}"
    echo "build/remotestorage.tar.gz ($(just version))"

# Check the tarball's layout: one remotestorage/ folder, app files in, dev files out
package-check: package
    #!/usr/bin/env bash
    set -euo pipefail
    files=$(tar -tzf build/remotestorage.tar.gz)
    fail() { echo "package-check: $*" >&2; exit 1; }
    if grep -qv '^remotestorage/' <<<"$files"; then fail "entries outside remotestorage/"; fi
    for want in appinfo/info.xml lib/ templates/ LICENSE README.md CHANGELOG.md; do
        grep -qx "remotestorage/$want" <<<"$files" || fail "missing $want"
    done
    for unwanted in tests/ docs/ .github/ vendor/ build/ composer.json Justfile AGENTS.md; do
        if grep -q "^remotestorage/$unwanted" <<<"$files"; then fail "should not ship $unwanted"; fi
    done
    echo "package-check: ok ($(wc -l <<<"$files") entries)"

# The app version (appinfo/info.xml is the source of truth)
version:
    @sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml

# Print a version's CHANGELOG.md section (the release notes)
release-notes version:
    @awk -v head='## [{{version}}]' 'index($0, head) == 1 { f = 1; next } /^## \[/ { f = 0 } f && (n || NF) { n = 1; print }' CHANGELOG.md

# Fail unless a version is releasable: SemVer, matches info.xml, has a dated, non-empty changelog section
release-check version:
    #!/usr/bin/env bash
    set -euo pipefail
    v='{{version}}'
    fail() { echo "release-check: $*" >&2; exit 1; }
    [[ $v =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || fail "$v is not SemVer (X.Y.Z or X.Y.Z-pre)"
    [[ $(just version) == "$v" ]] || fail "appinfo/info.xml says $(just version), not $v"
    grep -Eq "^## \[${v//./\\.}\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$" CHANGELOG.md || fail "CHANGELOG.md has no '## [$v] - YYYY-MM-DD' section"
    [[ -n $(just release-notes "$v" | tr -d '[:space:]') ]] || fail "CHANGELOG.md section for $v is empty"
    echo "release-check: $v ok"

# Start a release: set the version in info.xml and date the Unreleased changelog section
release-prep version:
    #!/usr/bin/env bash
    set -euo pipefail
    v='{{version}}'
    [[ -n $(just release-notes Unreleased | tr -d '[:space:]') ]] || { echo "release-prep: nothing under [Unreleased]" >&2; exit 1; }
    sed -i "s:<version>.*</version>:<version>$v</version>:" appinfo/info.xml
    sed -i "s:^## \[Unreleased\]$:## [Unreleased]\n\n## [$v] - $(date +%F):" CHANGELOG.md
    just release-check "$v"
    echo "Review, commit and merge to main, then: just release-tag"

# Tag main's HEAD with the info.xml version; pushing the tag publishes the GitHub release
release-tag:
    #!/usr/bin/env bash
    set -euo pipefail
    v=$(just version)
    [[ -z $(git status --porcelain) ]] || { echo "release-tag: working tree not clean" >&2; exit 1; }
    [[ $(git branch --show-current) == main ]] || { echo "release-tag: not on main" >&2; exit 1; }
    just release-check "$v"
    git tag -a "v$v" -m "remoteStorage $v"
    echo "Tagged v$v. Publish with: git push origin v$v"
