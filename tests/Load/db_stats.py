#!/usr/bin/env python3
"""Print the database's own counters for the headroom report, as JSON.

Run once before and once after a load, outside the time its CPU is counted:
each run starts the database's command-line client in its container. The
report divides the differences by the events stored. Reads the container
started from --image, as the database's administrator:

    python3 tests/Load/db_stats.py --image postgres:16 > db-before.json

PostgreSQL: transactions committed, rows changed, blocks read, WAL records,
bytes and syncs (and their time, with track_wal_io_timing on), the queue
table's row changes and scans, and each events index's scans. MySQL: rows
changed, buffer pool reads, redo log writes, bytes and syncs, binary log syncs
when the binary log is on (MySQL 8.0's default: one more sync per commit
group), and the queue table's and each events index's operations from the
performance schema. Both: max_connections.

PostgreSQL publishes a connection's counters within about ten seconds of it
going idle, so wait that long after a load before the second run.
"""

from __future__ import annotations

import argparse
import json
import subprocess


def container(image: str) -> str:
    ids = subprocess.run(["docker", "ps", "-q", "--filter", f"ancestor={image}"], capture_output=True, text=True, check=True).stdout.split()
    if not ids:
        raise SystemExit(f"No running container from {image}")
    return ids[0]


def postgres(cid: str, sql: str) -> list[list[str]]:
    out = subprocess.run(["docker", "exec", cid, "psql", "-U", "aggregate", "-d", "aggregate", "-At", "-F", "\t", "-c", sql],
                         capture_output=True, text=True, check=True).stdout
    return [line.split("\t") for line in out.splitlines() if line]


def mysql(cid: str, sql: str) -> list[list[str]]:
    out = subprocess.run(["docker", "exec", cid, "mysql", "-uroot", "-proot-load", "-N", "-B", "-e", sql],
                         capture_output=True, text=True, check=True).stdout
    return [line.split("\t") for line in out.splitlines() if line]


def number(value: str | None) -> float:
    try:
        return float(value) if value not in (None, "", "NULL", "\\N") else 0.0
    except ValueError:
        return 0.0


def postgres_stats(cid: str) -> dict:
    db = postgres(cid, "SELECT xact_commit, tup_inserted, tup_updated, tup_deleted, blks_read, blks_hit FROM pg_stat_database WHERE datname = 'aggregate'")[0]
    wal = postgres(cid, "SELECT wal_records, wal_bytes, wal_sync, wal_sync_time FROM pg_stat_wal")[0]
    queue = postgres(cid, "SELECT COALESCE(n_tup_ins, 0), COALESCE(n_tup_upd, 0), COALESCE(n_tup_del, 0), COALESCE(seq_scan, 0) + COALESCE(idx_scan, 0) "
                          "FROM pg_stat_user_tables WHERE relname = 'messenger_messages'") or [["0", "0", "0", "0"]]
    indexes = postgres(cid, "SELECT indexrelname, idx_scan FROM pg_stat_user_indexes WHERE relname = 'events' ORDER BY indexrelname")
    return {
        "engine": "postgresql",
        "max_connections": number(postgres(cid, "SHOW max_connections")[0][0]),
        "transactions": number(db[0]),
        "rows_inserted": number(db[1]), "rows_updated": number(db[2]), "rows_deleted": number(db[3]),
        "blocks_read": number(db[4]), "blocks_hit": number(db[5]),
        "log_records": number(wal[0]), "log_bytes": number(wal[1]), "log_syncs": number(wal[2]), "log_sync_ms": number(wal[3]),
        "queue_writes": number(queue[0][0]) + number(queue[0][1]) + number(queue[0][2]),
        "queue_reads": number(queue[0][3]),
        "index_scans": {name: number(scans) for name, scans in indexes},
    }


def mysql_stats(cid: str) -> dict:
    status = {name: value for name, value in mysql(cid, "SHOW GLOBAL STATUS")}
    queue = mysql(cid, "SELECT COUNT_INSERT + COUNT_UPDATE + COUNT_DELETE, COUNT_FETCH FROM performance_schema.table_io_waits_summary_by_table "
                       "WHERE OBJECT_SCHEMA = 'aggregate' AND OBJECT_NAME = 'messenger_messages'") or [["0", "0"]]
    indexes = mysql(cid, "SELECT IFNULL(INDEX_NAME, '(table scan)'), COUNT_FETCH FROM performance_schema.table_io_waits_summary_by_index_usage "
                         "WHERE OBJECT_SCHEMA = 'aggregate' AND OBJECT_NAME = 'events' ORDER BY INDEX_NAME")
    binlog_on = number(mysql(cid, "SELECT @@log_bin")[0][0]) == 1
    # Each sync of the binary log is a "misc" file operation; opening and
    # closing one are too, but happen once per file, not per commit.
    binlog = mysql(cid, "SELECT COUNT_MISC FROM performance_schema.file_summary_by_event_name WHERE EVENT_NAME = 'wait/io/file/sql/binlog'") or [["0"]]
    return {
        "engine": "mysql",
        "max_connections": number(mysql(cid, "SELECT @@max_connections")[0][0]),
        "rows_inserted": number(status.get("Innodb_rows_inserted")), "rows_updated": number(status.get("Innodb_rows_updated")),
        "rows_deleted": number(status.get("Innodb_rows_deleted")),
        "blocks_read": number(status.get("Innodb_buffer_pool_reads")), "blocks_hit": number(status.get("Innodb_buffer_pool_read_requests")),
        "log_records": number(status.get("Innodb_log_writes")), "log_bytes": number(status.get("Innodb_os_log_written")),
        "log_syncs": number(status.get("Innodb_os_log_fsyncs")), "log_sync_ms": None,
        "binlog": binlog_on, "binlog_syncs": number(binlog[0][0]) if binlog_on else 0.0,
        "queue_writes": number(queue[0][0]), "queue_reads": number(queue[0][1]),
        "index_scans": {name: number(fetches) for name, fetches in indexes},
        "max_connections_used": number(status.get("Max_used_connections")),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--image", required=True, help="Image of the database container")
    args = parser.parse_args()
    cid = container(args.image)
    stats = postgres_stats(cid) if args.image.startswith("postgres") else mysql_stats(cid)
    print(json.dumps(stats))


if __name__ == "__main__":
    main()
