#!/usr/bin/env bash
#
# Offline Avro compatibility gate. Runs the Confluent schema-registry-maven-plugin
# `test-local-compatibility` goal against the committed schema set — no running
# Schema Registry. Asserts:
#   1. the current committed schemas ARE compatible with their prior versions, and
#   2. a deliberately incompatible change IS rejected (so the gate isn't vacuous).
#
set -euo pipefail

cd "$(dirname "$0")"

GOAL="io.confluent:kafka-schema-registry-maven-plugin:test-local-compatibility"

echo "== schema-compat: current versions must stay compatible with prior versions =="
mvn -B -q -f pom.xml "${GOAL}"

echo "== schema-compat: an incompatible change must be rejected (negative control) =="
if mvn -B -q -f pom-incompatible.xml "${GOAL}"; then
  echo "ERROR: the compatibility gate ACCEPTED an incompatible schema change." >&2
  exit 1
fi

echo "schema-compat gate OK"
