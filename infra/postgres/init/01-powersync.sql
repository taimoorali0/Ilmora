-- Replication user for PowerSync (reads the Ilmora database).
CREATE ROLE powersync_role WITH REPLICATION LOGIN PASSWORD 'powersync';
GRANT SELECT ON ALL TABLES IN SCHEMA public TO powersync_role;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO powersync_role;

-- Publication: PowerSync replicates every table; streams decide what each device receives.
CREATE PUBLICATION powersync FOR ALL TABLES;

-- Separate database + user for PowerSync's own bucket storage.
CREATE ROLE powersync_storage WITH LOGIN PASSWORD 'powersync';
CREATE DATABASE powersync_storage OWNER powersync_storage;
