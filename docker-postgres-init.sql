-- Development credentials only. The application does not run as a superuser.
CREATE ROLE ucg WITH LOGIN PASSWORD 'ucg-local-development' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE DATABASE ucg_platform OWNER ucg;
