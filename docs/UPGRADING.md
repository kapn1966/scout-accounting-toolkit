# Database schema workflow

SAT is new development with no deployed installations requiring a sequential
history of database upgrades. `database/migrations/001-install.sql` is the
authoritative complete schema for a fresh installation, including the frozen
transfer-OFX batch tables. Fresh installations should run 001 only.

For a development database created from the original 001 schema, apply
`database/migrations/002-transfer-ofx-batches.sql` to add the transfer-batch
tables and review-route columns. This migration is a direct delta from 001; it
does not depend on removed historical migration files or change the plugin's
upgrade runner. Acceptance databases may also be recreated or updated manually
after the schema design is reviewed.
