#!/usr/bin/env bash
#
# Run this plugin's unit suite against an Evolution CMS core.
#
# The three plugin repositories that share this pipeline test in two different
# ways, and TEST_MODE in ci/plugin.env says which one applies here:
#
#   borrowed-core  The plugin has no vendor tree. Its bootstrap borrows the
#                  core's autoloader (EVO_CORE_PATH_TEST) and it is run with
#                  the CMS's Pest binary. Needs a tree built with --dev.
#   own-vendor     The plugin has its own vendor tree, with Evolution CMS in it
#                  as an ordinary composer dependency. `composer test`.
#   none           No suite. Only the install smoke test in ci/smoke.sh covers
#                  this plugin.
#
# Usage: ci/run-tests.sh <evo-dir>
#
#   <evo-dir>  a tree produced by ci/compose-evo.sh --dev (borrowed-core only;
#              ignored in the other modes)
#
set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
plugin_dir=$(cd "$here/.." && pwd)
# shellcheck source=plugin.env
. "$here/plugin.env"

evo_dir=${1:-}
# Anything after <evo-dir> is passed straight through to the test runner, so
# `ci/run-tests.sh /tmp/evo --filter Executor` works.
[ $# -gt 0 ] && shift

step() { printf '\n==> %s\n' "$*"; }

case "$TEST_MODE" in
    borrowed-core)
        if [ -z "$evo_dir" ]; then
            echo "usage: ci/run-tests.sh <evo-dir>" >&2
            exit 2
        fi

        evo_dir=$(cd "$evo_dir" && pwd)

        # Cores up to 3.5.9 carry Pest in core/vendor, later ones in the root
        # vendor tree. Pest resolves its test directory against the parent of
        # the vendor tree it lives in, and refuses to start if it is missing.
        pest="$evo_dir/core/vendor/pestphp/pest/bin/pest"
        test_dir='tests'
        if [ ! -f "$pest" ]; then
            pest="$evo_dir/vendor/pestphp/pest/bin/pest"
            test_dir='core/tests'
        fi

        if [ ! -f "$pest" ]; then
            echo "run-tests.sh: no Pest in $evo_dir/core/vendor or $evo_dir/vendor." >&2
            echo "The tree must be built with dev dependencies: compose-evo.sh --dev" >&2
            exit 1
        fi

        step "Pest ($PLUGIN_PACKAGE, core at $evo_dir)"
        # Run from the plugin so Pest picks up this repository's phpunit.xml and
        # its bootstrap, rather than the core's.
        cd "$plugin_dir"
        EVO_CORE_PATH_TEST="$evo_dir/core" php "$pest" --test-directory="$test_dir" --no-coverage "$@"
        ;;

    own-vendor)
        step "composer install ($PLUGIN_PACKAGE)"
        cd "$plugin_dir"
        composer install --no-interaction --no-progress --prefer-dist

        step "composer test"
        composer test
        ;;

    none)
        step "No unit suite in this repository (TEST_MODE=none)"
        step "php -l over the source"
        cd "$plugin_dir"
        for dir in src plugins config database; do
            [ -d "$dir" ] || continue
            find "$dir" -name '*.php' -print0 | xargs -0 -r -n1 php -l
        done
        ;;

    *)
        echo "run-tests.sh: unknown TEST_MODE '$TEST_MODE' in ci/plugin.env" >&2
        exit 2
        ;;
esac
