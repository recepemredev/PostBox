#!/bin/sh
set -e

# The test suite runs against PostgreSQL, not SQLite: Row Level Security,
# partitioning and CHECK constraints are the behaviour under test from Step 2
# onward, and none of it exists on another engine. The suite gets its own
# database so a test run can never touch development data.
#
# Runs once, when the data volume is first initialised.
psql --username "${POSTGRES_USER}" --dbname "${POSTGRES_DB}" <<-SQL
    CREATE DATABASE ${POSTGRES_DB}_test OWNER ${POSTGRES_USER};
SQL
