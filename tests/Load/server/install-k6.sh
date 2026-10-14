#!/usr/bin/env bash
# Installs a pinned k6 release after checking it against the release checksums.
#
#   K6_VERSION=v1.0.0 tests/Load/server/install-k6.sh
set -euo pipefail

version=${K6_VERSION:?K6_VERSION}
base="https://github.com/grafana/k6/releases/download/${version}"
archive="k6-${version}-linux-amd64.tar.gz"
fetch() { curl -fsSL --retry 5 --retry-all-errors --retry-delay 5 -o "$1" "$2"; }
fetch "/tmp/${archive}" "${base}/${archive}"
fetch /tmp/k6-checksums.txt "${base}/k6-${version}-checksums.txt"
(cd /tmp && grep " ${archive}\$" k6-checksums.txt | sha256sum -c -)
tar -xzf "/tmp/${archive}" -C /tmp
sudo install "/tmp/k6-${version}-linux-amd64/k6" /usr/local/bin/k6
k6 version
