#!/bin/sh
#
# Split rehearsal: proves that each package installs ALONE and that its tests pass ALONE.
#
# Usage: tools/split-rehearsal.sh <package> [--infection | --lowest]
#   <package>: core, storage-postgresql, transport-amqp, webhook-http or ratelimit-redis
#   --infection: also run Infection with the configuration of the package (its infection.json5).
#   --lowest: an adapter only. The core is not a path package: Composer takes it from Packagist with
#             --prefer-lowest --prefer-stable. It shows whether the core floor of the adapter is too low.
#
# The script runs inside the PHP environment (the `php` service of compose, or the CI runner).
# It is POSIX sh (the PHP image is Alpine and has no bash). It needs PHP, Composer and the extensions of the packages. It does not call Docker or Git.
# Functional tests need the PostgreSQL and RabbitMQ settings, the URL of the webhook test server (WEBHOOK_SERVER_URL)
# and the Redis host (REDIS_HOST) in the environment (see compose.yaml).
#
# Steps:
#   1. Copy the package under test and the packages that it needs into a temporary folder. An adapter
#      is its folder <role>/<technology>/ as it is. The core is the root files of its package (composer.json,
#      src/, tests/, infection.json5, README.md, LICENSE), and the development part of the root composer.json
#      (the path repositories, the four adapters and their test namespaces) is removed. Files are copied, never linked.
#   2. In the package under test, add Composer path repositories for the packages that it needs. Each path
#      package gets the lowest version that the constraint of the package under test accepts (^0.15 gives
#      0.15.0), so no package of this repo comes from Packagist.
#   3. composer update, then PHPUnit (and Infection with --infection).
set -eu

usage() {
    echo "Usage: tools/split-rehearsal.sh <core|storage-postgresql|transport-amqp|webhook-http|ratelimit-redis> [--infection|--lowest]" >&2
    exit 2
}

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then usage; fi
PACKAGE="$1"
MODE=""
if [ "$#" -eq 2 ]; then
    case "$2" in
        --infection) MODE="infection" ;;
        --lowest) MODE="lowest" ;;
        *) usage ;;
    esac
fi

# The packages of this repo that each package needs (require or require-dev).
case "${PACKAGE}" in
    core) NEEDS="" ;;
    storage-postgresql) NEEDS="core" ;;
    transport-amqp) NEEDS="core storage-postgresql" ;;
    webhook-http) NEEDS="core storage-postgresql" ;;
    ratelimit-redis) NEEDS="core" ;;
    *) usage ;;
esac

if [ "${MODE}" = "lowest" ] && [ "${PACKAGE}" = "core" ]; then
    echo "--lowest is for an adapter: it takes the core from Packagist." >&2
    exit 2
fi

# The folder of a package: the repo root for the core, <role>/<technology> for an adapter.
package_dir() {
    case "$1" in
        core) echo "." ;;
        *) echo "$1" | sed 's#-#/#' ;;
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

# Step 1: copy the package under test and the packages that it needs.
copy_package() {
    target="${WORK}/$1"
    if [ "$1" = "core" ]; then
        mkdir -p "${target}"
        cp "${REPO_ROOT}/composer.json" "${REPO_ROOT}/infection.json5" "${REPO_ROOT}/README.md" "${REPO_ROOT}/LICENSE" "${target}/"
        cp -R "${REPO_ROOT}/src" "${target}/src"
        cp -R "${REPO_ROOT}/tests" "${target}/tests"
        # The core package alone: no path repositories, no adapter, no test namespace of an adapter.
        php -r '
            $file = $argv[1];
            $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            unset($manifest["repositories"]);
            foreach (array_keys($manifest["require-dev"]) as $name) {
                if (str_starts_with($name, "freyr/")) {
                    unset($manifest["require-dev"][$name]);
                }
            }
            foreach (array_keys($manifest["autoload-dev"]["psr-4"]) as $prefix) {
                if ($prefix !== "Freyr\\MessageBroker\\Tests\\") {
                    unset($manifest["autoload-dev"]["psr-4"][$prefix]);
                }
            }
            file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        ' "${target}/composer.json"
    else
        cp -R "${REPO_ROOT}/$(package_dir "$1")" "${target}"
        # A local install of the adapter folder is not part of the package.
        rm -rf "${target}/vendor" "${target}/composer.lock" "${target}/var" "${target}/.phpunit.cache"
    fi
}

copy_package "${PACKAGE}"
PATH_PACKAGES=""
for need in ${NEEDS}; do
    if [ "${MODE}" = "lowest" ] && [ "${need}" = "core" ]; then continue; fi
    copy_package "${need}"
    PATH_PACKAGES="${PATH_PACKAGES} ${need}"
done

# The lowest version that the constraint of the package under test accepts: "^0.15" gives 0.15.0, "^0.15.4" gives 0.15.4.
path_version() {
    php -r '
        $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        $constraint = $manifest["require"][$argv[2]] ?? $manifest["require-dev"][$argv[2]] ?? null;
        if (!is_string($constraint) || preg_match("/^\^(\d+)\.(\d+)(?:\.(\d+))?$/", $constraint, $m) !== 1) {
            fwrite(STDERR, "The constraint on {$argv[2]} must be ^X.Y or ^X.Y.Z.\n");
            exit(1);
        }
        echo $m[1].".".$m[2].".".($m[3] ?? "0");
    ' "$1" "$2"
}

# Step 2: path repositories in the package under test.
cd "${WORK}/${PACKAGE}"
for need in ${PATH_PACKAGES}; do
    version="$(path_version composer.json "$(package_name "${need}")")"
    composer config --no-interaction --json "repositories.${need}" \
        "{\"type\":\"path\",\"url\":\"../${need}\",\"options\":{\"symlink\":false,\"versions\":{\"$(package_name "${need}")\":\"${version}\"}}}"
done

# Step 3: install the package alone, then run its tests alone.
composer update --prefer-stable --no-interaction --no-progress
if [ "${MODE}" = "lowest" ]; then
    # Only the core goes to its lowest version: the run checks the core floor of the adapter, not the
    # floors of the other dependencies (a lowest predis/predis with psr/http-message 1.0 does not load).
    composer update "$(package_name core)" --prefer-lowest --prefer-stable --no-interaction --no-progress
fi
# Every path package must come from its path repository, never from Packagist; with --lowest the core must come from Packagist.
for need in ${NEEDS}; do
    # The dist line of a path package is "dist : [path] ../<name> <reference>".
    if composer show --no-interaction "$(package_name "${need}")" | grep -Eq '^dist +: +\[path\]'; then
        from_path=1
    else
        from_path=0
    fi
    if [ "${MODE}" = "lowest" ] && [ "${need}" = "core" ]; then
        [ "${from_path}" -eq 0 ] || { echo "$(package_name core) comes from a path repository" >&2; exit 1; }
    else
        [ "${from_path}" -eq 1 ] || { echo "$(package_name "${need}") does not come from the path repository" >&2; exit 1; }
    fi
done

# The core keeps the configuration of its tests alone in tests/ (the root phpunit.xml.dist is the whole repo).
PHPUNIT_CONFIG="phpunit.xml.dist"
[ "${PACKAGE}" != "core" ] || PHPUNIT_CONFIG="tests/phpunit.xml.dist"
vendor/bin/phpunit --configuration "${PHPUNIT_CONFIG}"

if [ "${MODE}" = "infection" ]; then
    # pcov only collects coverage below pcov.directory; the work folder is outside the repo folder.
    vendor/bin/infection --threads=max --no-progress \
        --initial-tests-php-options="-d pcov.directory=${WORK}/${PACKAGE}/src"
fi

STATUS="passed"
SUMMARY="${PACKAGE}"
[ -z "${PATH_PACKAGES}" ] || SUMMARY="${SUMMARY}, path packages:${PATH_PACKAGES}"
[ "${MODE}" != "lowest" ] || SUMMARY="${SUMMARY}, the core from Packagist with the lowest versions ($(composer show --no-interaction "$(package_name core)" | sed -n 's/^versions *: *\* *//p'))"
[ "${MODE}" != "infection" ] || SUMMARY="${SUMMARY}, with Infection"
echo "Split rehearsal passed: ${SUMMARY}"
