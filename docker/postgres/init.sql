-- Runs once when the database container is created, as the superuser "owner".
-- "app" is the role the application connects as: no superuser, no BYPASSRLS,
-- so PostgreSQL enforces row-level security on every query it makes.
CREATE ROLE app LOGIN PASSWORD 'app' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
CREATE DATABASE saas_test OWNER owner;
