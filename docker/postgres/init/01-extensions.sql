-- PipeMind — required PostgreSQL extensions.
-- Runs once, on first initialisation of an empty data volume.

CREATE EXTENSION IF NOT EXISTS vector;        -- pgvector: failure + knowledge embeddings
CREATE EXTENSION IF NOT EXISTS pg_trgm;       -- trigram search over error text (global search)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";   -- uuid_generate_v4() defaults
CREATE EXTENSION IF NOT EXISTS btree_gin;     -- composite GIN indexes on jsonb + scalar
