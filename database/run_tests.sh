#!/usr/bin/env bash
# Builds the scratch database lms_test from the numbered SQL files, then runs tests.sql on it.
# The demo database (lms) is never touched.
#   bash database/run_tests.sh
set -euo pipefail
RUN_TESTS=1 bash "$(dirname "${BASH_SOURCE[0]}")/reset.sh" lms_test
