# Changelog

## v0.3.0

- Support exact MySQL 5.7.13 with DBAL 4 while retaining MySQL 8.0 and PostgreSQL.
- Require MySQL 5.7.13 or newer; continue rejecting MariaDB and older MySQL.
- Explicitly create the history table with ROW_FORMAT=DYNAMIC for its full index.
- Preserve InnoDB checks, explicit outer transactions, row locks and rollback.
- Test duplicate JSON key behavior separately for MySQL 5.7 and 8.0, NULL
  distinctions, indexed lookups and transaction failure handling.
- Add exact-version 5.7.13 CI alongside the existing database/PHP matrices.

MySQL 5.7 ignores CHECK constraints and keeps the first duplicate JSON key,
whereas MySQL 8.0 keeps the last. Native JSON storage limits still apply.
Barracuda, large-prefix support and a suitable InnoDB index limit are required.
Existing executed migrations and audit history are not rebuilt.

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
