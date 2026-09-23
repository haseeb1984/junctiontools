#!/usr/bin/env bash
set -u

# CI wrapper for the minimal JunctionTools Tool Factory E2E smoke test.
#
# Exit codes are intentionally identical to tests/run-tool-factory-e2e.php:
#   0 = PASS
#   1 = EXPECTED TEST FAILURE
#   2 = INVALID TEST INPUT
#   3 = FACTORY/ENVIRONMENT UNAVAILABLE
#   4 = TEST RUNNER ERROR
#
# On failure, the generated output directory and test log are preserved.
# On success, the underlying PHP test is responsible for cleaning its
# temporary E2E output directory.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_TEST="${ROOT_DIR}/tests/run-tool-factory-e2e.php"
E2E_OUTPUT_DIR="${ROOT_DIR}/storage/e2e-test"
CI_OUTPUT_DIR="${ROOT_DIR}/storage/e2e-test-ci"
LOG_FILE="${CI_OUTPUT_DIR}/tool-factory-e2e.log"

mkdir -p "${CI_OUTPUT_DIR}"

if [[ ! -f "${PHP_TEST}" ]]; then
    printf '[FAIL] E2E PHP test not found: %s\n' "${PHP_TEST}" | tee "${LOG_FILE}" >&2
    exit 3
fi

# Keep a CI copy of the log even when the PHP test exits early.
set +e
JUNCTIONTOOLS_E2E_OUTPUT_DIR="${E2E_OUTPUT_DIR}" php "${PHP_TEST}" 2>&1 | tee "${LOG_FILE}"
TEST_EXIT=${PIPESTATUS[0]}
set -e

if [[ "${TEST_EXIT}" -eq 0 ]]; then
    # Successful runs should not leave generated test artifacts behind.
    # Preserve only the CI log; remove the wrapper directory if empty.
    rm -rf "${E2E_OUTPUT_DIR}" 2>/dev/null || true
    printf '[PASS] JunctionTools Tool Factory E2E CI wrapper\n'
    exit 0
fi

# Failure path: deliberately preserve generated output and the log.
printf '[FAIL] JunctionTools Tool Factory E2E exited with code %s\n' "${TEST_EXIT}" >&2
printf '[INFO] Preserved test log: %s\n' "${LOG_FILE}" >&2
printf '[INFO] Preserved generated output: %s\n' "${E2E_OUTPUT_DIR}" >&2

# Never translate the PHP test result. CI receives the exact same exit code.
exit "${TEST_EXIT}"
