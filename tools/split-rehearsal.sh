#!/bin/sh
#
# Split rehearsal: proves that each future package installs ALONE and that its tests pass ALONE.
#
# Usage: tools/split-rehearsal.sh <core|postgresql|amqp> [--infection]
#
# The script runs inside the PHP environment (the `php` service of compose, or the CI runner).
# It is POSIX sh (the PHP image is Alpine and has no bash). It needs PHP, Composer and the extensions of the packages. It does not call Docker or Git.
# Functional tests need the PostgreSQL and RabbitMQ settings in the environment (see compose.yaml).
#
# Steps:
#   1. Assemble the three future repos in a temporary folder: the files of packages/<name>/ at the
#      root, the code folder as src/ and the tests folder as tests/. Files are copied, never linked.
#   2. In the package under test, add Composer path repositories for the packages that it needs.
#      Each path package gets the rehearsal version, so no package of this repo comes from Packagist.
#   3. composer update, then vendor/bin/phpunit (and vendor/bin/infection with --infection).
set -eu

# The rehearsal version: the current 0.x line. It is the version that each path package gets, and
# it must satisfy the ^0.x constraints between the packages/*/composer.json files.
# Change it together with those constraints at each minor release.
REHEARSAL_VERSION="${REHEARSAL_VERSION:-0.7.0}"
export COMPOSER_ROOT_VERSION="${REHEARSAL_VERSION}"

usage() {
    echo "Usage: tools/split-rehearsal.sh <core|postgresql|amqp> [--infection]" >&2
    exit 2
}

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then usage; fi
PACKAGE="$1"
INFECTION=0
if [ "$#" -eq 2 ]; then
    [ "$2" = "--infection" ] || usage
    INFECTION=1
fi

# Per package: the code folder, the tests folder, the composer name, and the path packages it needs.
case "${PACKAGE}" in
    core) NEEDS="" ;;
    postgresql) NEEDS="core" ;;
    amqp) NEEDS="core postgresql" ;;
    *) usage ;;
esac

package_src() {
    case "$1" in
        core) echo "src/broker" ;;
        *) echo "src/adapter/$1" ;;
    esac
}

package_tests() {
    case "$1" in
        core) echo "tests/broker" ;;
        *) echo "tests/adapter/$1" ;;
    esac
}

package_name() {
    case "$1" in
        core) echo "freyr/message-broker" ;;
        *) echo "freyr/message-broker-$1" ;;
    esac
}

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/split-rehearsal.XXXXXX")"
STATUS="failed"

finish() {
    if [ "${STATUS}" = "passed" ]; then
        rm -rf "${WORK}"
    else
        echo "Rehearsal FAILED for ${PACKAGE}. The work folder is kept: ${WORK}" >&2
    fi
}
trap finish EXIT

# Step 1: assemble the three future repos.
for name in core postgresql amqp; do
    target="${WORK}/${name}"
    mkdir -p "${target}"
    cp "${REPO_ROOT}/packages/${name}/composer.json" \
        "${REPO_ROOT}/packages/${name}/phpunit.xml.dist" \
        "${REPO_ROOT}/packages/${name}/infection.json5" "${REPO_ROOT}/packages/${name}/README.md" "${target}/"
    cp -R "${REPO_ROOT}/$(package_src "${name}")" "${target}/src"
    cp -R "${REPO_ROOT}/$(package_tests "${name}")" "${target}/tests"
done

# Step 2: path repositories in the package under test.
cd "${WORK}/${PACKAGE}"
for need in ${NEEDS}; do
    composer config --no-interaction --json "repositories.${need}" \
        "{\"type\":\"path\",\"url\":\"../${need}\",\"options\":{\"symlink\":false,\"versions\":{\"$(package_name "${need}")\":\"${REHEARSAL_VERSION}\"}}}"
done

# Step 3: install the package alone, then run its tests alone.
composer update --prefer-stable --no-interaction --no-progress
if [ -n "${NEEDS}" ]; then
    # Every package of this repo must come from a path repository, never from Packagist.
    for need in ${NEEDS}; do
        location="$(composer show --no-interaction --path "$(package_name "${need}")")"
        case "${location}" in
            *"${WORK}"*) ;;
            *) echo "$(package_name "${need}") does not come from the path repository" >&2; exit 1 ;;
        esac
    done
fi

vendor/bin/phpunit

if [ "${INFECTION}" -eq 1 ]; then
    # pcov only collects coverage below pcov.directory; the work folder is outside the repo folder.
    vendor/bin/infection --threads=max --no-progress \
        --initial-tests-php-options="-d pcov.directory=${WORK}/${PACKAGE}/src"
fi

STATUS="passed"
SUMMARY="${PACKAGE}, version ${REHEARSAL_VERSION}"
[ -z "${NEEDS}" ] || SUMMARY="${SUMMARY}, path packages: ${NEEDS}"
[ "${INFECTION}" -eq 0 ] || SUMMARY="${SUMMARY}, with Infection"
echo "Split rehearsal passed: ${SUMMARY}"
