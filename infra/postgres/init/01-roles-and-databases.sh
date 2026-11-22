#!/bin/sh
# Creates the Tabularius and business databases (plus *_test) and their roles (docs/architecture.md#database-roles).
# Runs once on an empty data directory. Passwords come from the environment (.env), never from this file.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  -v tm_pw="$TABULARIUS_MIGRATOR_PASSWORD" -v ta_pw="$TABULARIUS_APP_PASSWORD" \
  -v bo_pw="$BUSINESS_OWNER_PASSWORD" -v br_pw="$BUSINESS_READER_PASSWORD" <<'SQL'
CREATE ROLE tabularius_migrator LOGIN PASSWORD :'tm_pw';
CREATE ROLE tabularius_app LOGIN PASSWORD :'ta_pw';
CREATE ROLE business_owner LOGIN PASSWORD :'bo_pw';
CREATE ROLE business_reader LOGIN PASSWORD :'br_pw';
SQL

for db in tabularius tabularius_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "CREATE DATABASE $db OWNER tabularius_migrator"
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<SQL
REVOKE ALL ON DATABASE $db FROM PUBLIC;
GRANT CONNECT ON DATABASE $db TO tabularius_app;
ALTER SCHEMA public OWNER TO tabularius_migrator;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO tabularius_app;
ALTER DEFAULT PRIVILEGES FOR ROLE tabularius_migrator IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO tabularius_app;
ALTER DEFAULT PRIVILEGES FOR ROLE tabularius_migrator IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO tabularius_app;
SQL
done

for db in business business_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "CREATE DATABASE $db OWNER business_owner"
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<SQL
REVOKE ALL ON DATABASE $db FROM PUBLIC;
GRANT CONNECT ON DATABASE $db TO business_reader;
ALTER SCHEMA public OWNER TO business_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO business_reader;
-- No default privileges: business_reader gets SELECT only on reporting views, granted explicitly by migrations.
SQL
done
