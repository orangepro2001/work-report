# Replication to the AI host

The board's database is the local `work-report-db` container. The AI host keeps a
standby copy so the service can be moved there when this VPS dies. Two mechanisms,
on purpose: **logical replication** for freshness, and **dump/restore** for
everything that replication cannot do — including recovery from replication itself.

Both are operated through `deploy/scripts/replication.sh` (see the header of that
file); nothing here needs a restart of the application or the database.

## The one restart that was needed

`wal_level` is not a dynamic setting. It went from `replica` to `logical` once,
which cost a ~5 s restart of `work-report-db` (the app reconnected by itself; the
unit's `Exec=` line is what carries it, so it is part of the install, not a manual
edit). **Everything else is ordinary SQL**: creating the publication, creating,
disabling, dropping and recreating the subscription, refreshing it, `pg_drop_
replication_slot`. That is the whole answer to "can this be done without a restart" —
it can, because the only restart-shaped piece is already done.

## What runs

| Where | Object | Meaning |
|---|---|---|
| local (publisher) | role `wr_replica` (LOGIN REPLICATION) | what the subscriber connects as; password in `~/.config/work-report/replica.env` |
| local | publication `wr_pub FOR ALL TABLES` | new tables are published automatically; **DDL is not replicated** |
| local | slot `wr_sub` | retains WAL while the AI host is behind; `max_slot_wal_keep_size=1GB` invalidates it rather than filling the disk |
| local | `tailscale0:5433` published | the only door the subscriber uses |
| AI (subscriber) | database `work_report` | tables owned by the `work_report` role so takeover needs no re-grant |
| AI | subscription `wr_sub` | applies changes; one apply worker |
| AI | `work_report_migration_snapshot` | the pre-migration copy, kept as the deep rollback point |

The publishing side is poked at by `REQUIRE`-less SQL only: the subscriber pulls, so
if the AI host is down nothing blocks the board. That is why the connection is
push-only from here.

## Fallback matrix (no restarts)

| Situation | Command | Effect |
|---|---|---|
| AI host offline for a while; slot eating WAL | `replication.sh pause` | stops applying, keeps the slot; resume with `resume` |
| Slot invalidated (`wal_status = lost`), or the copy drifted | `replication.sh resync` | dump the local database, rebuild the subscriber from it, follow changes again |
| A migration changed the schema | `replication.sh resync` | **required**: logical replication does not carry DDL |
| Replication should stop, data stays | `replication.sh drop` | subscription and slot removed; the subscriber keeps its rows |
| Need a restore-anywhere file | `deploy/scripts/backup.sh` / the nightly mirror | plain `pg_dump`, independent of the slot |

`resync` is the important one. It is a dump plus `copy_data = false`:

1. `drop subscription` and clean every stale `pg_*_sync_*` slot;
2. `pg_dump` the local database to a file (a broken pipe must not half-restore);
3. on the AI host: `drop schema public cascade`, recreate it **and the extensions**
   (they live in `public`, so the cascade takes them), then restore in one
   transaction;
4. `create subscription ... with (copy_data = false)` — the rows arrived with the
   dump, so copying them again would collide on every primary key;
5. verify with `status`.

Cost: the dump and the restore, on this data set a couple of seconds. Writes between
step 2 and step 4 are the one gap this path has; the nightly dump mirror is the
second line if that matters.

### The failure mode that wedges it

Every initial-copy table spawns a table-sync worker, and each worker takes a
replication slot of its own. A worker that dies leaves `pg_<oid>_sync_<oid>_<n>`
behind; when `max_replication_slots` is exhausted no new worker can start, and the
one that could not start leaves another slot behind — the copy then sits in `d`
forever while nothing is logged on the publisher. `resync` cleans these before
rebuilding, and the slot budget is 20 (applies at the next restart of the unit).

`status` prints the two numbers that matter: `wal_status` on the publisher
(`reserved` is healthy, `lost` means resync) and the count of tables in state `r` on
the subscriber (all of them, or something is stuck).

## What is not replicated, deliberately

- **DDL.** Migrations run at container boot; after any migration that touches a
  published table, run `resync`.
- **`sessions` churn.** The table is published (a standby should be able to take
  over a login), so expect a steady trickle of WAL for it.
- **Sequences.** Logical replication does not copy sequence values; a takeover must
  `setval()` them (or rely on `IssueKeyGenerator`'s counters, which are a table).

## Verify

```sh
deploy/scripts/replication.sh status      # slot, subscription, row parity
podman exec work-report-db psql -U work_report -d work_report \
  -c "select slot_name, active, wal_status from pg_replication_slots"
```

A write made on this host reaches the AI host in about a second.
