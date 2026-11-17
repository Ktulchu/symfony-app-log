# Changelog

## v0.2.0

- Add MySQL 8.0+ native JSON audit snapshots and indexed entity history lookup.
- Preserve PostgreSQL JSON/JSONB behavior and migrations.
- Distinguish SQL NULL and JSON null using database-built typed envelopes.
- Compare MySQL's stored normalized JSON without decoding numbers in PHP.
- Reject non-InnoDB audited or audit tables before business writes.
- Cover MySQL transaction rollback, audit insertion failure, no-op updates,
  exclusions, triggers and concurrent row locks with regression tests.

MySQL requires InnoDB for both business and audit tables. Every audited flush
requires an outer transaction. Direct SQL, bulk DQL and association-only changes
remain outside the audit scope. MySQL JSON normalization and numeric storage
limits apply; PostgreSQL-only JSONB mappings are rejected on MySQL.
