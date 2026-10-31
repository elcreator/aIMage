#!/usr/bin/env bash
# Rebuilds the vendored Painterro bundle (public/painterro/painterro.min.js) from source.
#
# Painterro's toolchain (webpack 5.21, css-loader 5, eslint 6) predates Node 17 and does not
# run on a current Node, so the build happens in a node:16 container. Only the browser
# bundle is built (webpack target `var`, global `Painterro`), without a source map.
#
#   ci/build-painterro.sh            # the pinned tag below
#   PAINTERRO_REF=v1.2.93 ci/build-painterro.sh
set -euo pipefail

REF="${PAINTERRO_REF:-v1.2.92}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$HERE/public/painterro"
DOCKER="${DOCKER:-docker.exe}"
command -v "$DOCKER" >/dev/null 2>&1 || DOCKER=docker

mkdir -p "$OUT"

# The container writes into /out; MSYS must not rewrite the mount spec on Windows.
MSYS_NO_PATHCONV=1 "$DOCKER" run --rm \
    -e REF="$REF" \
    -v "$(cd "$OUT" && pwd -W 2>/dev/null || pwd):/out" \
    node:16-bullseye bash -euo pipefail -c '
        git clone --quiet --depth 1 --branch "$REF" https://github.com/devforth/painterro.git /src
        cd /src
        npm ci --ignore-scripts --no-audit --no-fund --legacy-peer-deps >/dev/null
        cat > /tmp/webpack.var.js <<EOF
const configs = require("/src/webpack.config.js");
const browser = configs[1]; // var-latest: build/painterro.min.js
browser.devtool = false;
browser.output.path = "/out";
module.exports = browser;
EOF
        node node_modules/webpack-cli/bin/cli.js --config /tmp/webpack.var.js --no-color >/tmp/build.log 2>&1 \
            || { tail -40 /tmp/build.log; exit 1; }
        cp LICENSE /out/LICENSE
        printf "%s %s\n" "$REF" "$(git rev-parse HEAD)" > /out/VERSION
    '

echo "Built Painterro $(cat "$OUT/VERSION") -> $OUT/painterro.min.js ($(wc -c < "$OUT/painterro.min.js") bytes)"
