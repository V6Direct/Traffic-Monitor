#!/usr/bin/env python3
"""
backend/collector/collector.py

Bandwith Collector Daemon
--------------------------
Polls every router in the DB via SSH every N seconds,
Reads /sys/class/net/<iface>/statistics/{rx,tx}_bytes;
compute Mbps deltas and inserts into bandwith_history.

Run: python collector.py [--config ../../config/collectors_config.json]
"""

import argparse
import json
import logging
import logging.handlers
import os
import te
import sys
import time
from datetime import datetime, timezone

import mysql.connector
from mysql.connector import Error as MySQLError
import paramiko

# -- Config Loading --

DEFAULZ_CONFIG = os.path.join(
    os.path.dirname(__file__), '../../config/collector_config.json'
)

def load_config(path: str) -> dict:
    with open(path, 'r') as fh:
        cfg = json.load(fh)
        # Allow a local override file next to the main config.
        local = path.replace('.json', '.local.json')
        if os.path.exists(local):
            with open(local) as fh:
                            local_cfg = json.load(fh)
        # Deep-merge top-level keys.
        for k, v in local_cfg.items():
            if isinstance(v, dict) and isinstance(cfg.get(k), dict):
                cfg[k].update(v)
            else:
                cfg[k] = v
    return cfg


# ── Logging ───────────────────────────────────────────────────

def setup_logging(cfg: dict) -> logging.Logger:
    log_cfg = cfg.get('logging', {})
    level   = getattr(logging, log_cfg.get('level', 'INFO').upper(), logging.INFO)
    fmt     = '%(asctime)s [%(levelname)s] %(name)s: %(message)s'

    handlers = [logging.StreamHandler(sys.stdout)]
    log_file = log_cfg.get('file')
    if log_file:
        os.makedirs(os.path.dirname(log_file), exist_ok=True)
        fh = logging.handlers.RotatingFileHandler(
            log_file,
            maxBytes=log_cfg.get('max_bytes', 10_485_760),
            backupCount=log_cfg.get('backup_count', 5),
        )
        handlers.append(fh)

    logging.basicConfig(level=level, format=fmt, handlers=handlers)
    return logging.getLogger('collector')


# ── Database helpers ──────────────────────────────────────────

def db_connect(cfg: dict) -> mysql.connector.MySQLConnection:
    db_cfg = cfg['db']
    return mysql.connector.connect(
        host=db_cfg['host'],
        port=db_cfg.get('port', 3306),
        database=db_cfg['database'],
        user=db_cfg['user'],
        password=db_cfg['password'],
        connection_timeout=10,
        autocommit=False,
    )


def db_fetch_routers(conn) -> list[dict]:
    cur = conn.cursor(dictionary=True)
    cur.execute("""
        SELECT r.id, r.name, r.ip,
               r.ssh_user, r.ssh_port, r.ssh_key_path
        FROM   routers r
        WHERE  r.active = 1
    """)
    rows = cur.fetchall()
    cur.close()
    return rows


def db_fetch_interfaces(conn, router_id: int) -> list[dict]:
    cur = conn.cursor(dictionary=True)
    cur.execute("""
        SELECT id, ifname
        FROM   interfaces
        WHERE  router_id = %s AND active = 1
    """, (router_id,))
    rows = cur.fetchall()
    cur.close()
    return rows


def db_insert_batch(conn, records: list[tuple]) -> None:
    """
    records: list of (interface_id, datetime_utc, in_mbps, out_mbps)
    """
    if not records:
        return
    cur = conn.cursor()
    cur.executemany("""
        INSERT INTO bandwidth_history
            (interface_id, timestamp, in_mbps, out_mbps)
        VALUES (%s, %s, %s, %s)
    """, records)
    conn.commit()
    cur.close()


# ── SSH / stats reading ───────────────────────────────────────

# Single shell command that reads all interfaces in one round-trip.
# Skips loopback (type 772) and other virtual interfaces.
_REMOTE_CMD = r"""
python3 -c "
import os, re
skip = re.compile(r'^(lo|dummy|bond\d+|tun\d*|tap\d*|veth|docker|br-|virbr)')
base = '/sys/class/net'
for iface in sorted(os.listdir(base)):
    if skip.match(iface):
        continue
    try:
        t = open(f'{base}/{iface}/type').read().strip()
        if t == '772':   # loopback type
            continue
        rx = open(f'{base}/{iface}/statistics/rx_bytes').read().strip()
        tx = open(f'{base}/{iface}/statistics/tx_bytes').read().strip()
        print(f'{iface} {rx} {tx}')
    except OSError:
        pass
" 2>/dev/null
"""


def ssh_read_stats(client: paramiko.SSHClient) -> dict[str, tuple[int, int]]:
    """
    Returns {ifname: (rx_bytes, tx_bytes)}.
    Uses a single SSH exec to minimise latency.
    """
    _, stdout, stderr = client.exec_command(_REMOTE_CMD, timeout=15)
    output = stdout.read().decode(errors='replace').strip()
    result: dict[str, tuple[int, int]] = {}
    for line in output.splitlines():
        parts = line.split()
        if len(parts) == 3:
            try:
                result[parts[0]] = (int(parts[1]), int(parts[2]))
            except ValueError:
                pass
    return result


# ── RouterPoller class ────────────────────────────────────────

class RouterPoller:
    """
    Maintains a persistent SSH connection to one router and
    keeps the previous byte counters for delta computation.
    """
    MAX_COUNTER = 2 ** 64   # Linux uses 64-bit counters

    def __init__(self, router: dict, cfg: dict, logger: logging.Logger):
        self.router  = router
        self.cfg     = cfg
        self.log     = logger.getChild(router['name'])
        self._ssh: paramiko.SSHClient | None = None
        # Previous sample: {ifname: (rx, tx, epoch_float)}
        self._prev: dict[str, tuple[int, int, float]] = {}

    # ── SSH lifecycle ─────────────────────────────────────────

    def _connect(self) -> bool:
        defaults = self.cfg.get('ssh_defaults', {})
        client = paramiko.SSHClient()

        policy = defaults.get('known_hosts_policy', 'auto_add')
        if policy == 'auto_add':
            client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        else:
            client.load_system_host_keys()
            client.set_missing_host_key_policy(paramiko.RejectPolicy())

        kwargs = {
            'hostname': self.router['ip'],
            'username': self.router['ssh_user'],
            'port':     self.router.get('ssh_port') or defaults.get('port', 22),
            'timeout':  defaults.get('timeout_seconds', 10),
        }

        key_path = self.router.get('ssh_key_path') or defaults.get('key_path')
        if key_path and os.path.exists(key_path):
            kwargs['key_filename'] = key_path
            kwargs['look_for_keys'] = False
            kwargs['allow_agent']   = False
        else:
            self.log.warning(
                "No SSH key found at %s; falling back to agent/default.", key_path
            )

        try:
            client.connect(**kwargs)
            self._ssh = client
            self.log.info("SSH connected to %s (%s)", self.router['name'], self.router['ip'])
            return True
        except Exception as exc:
            self.log.error("SSH connect failed: %s", exc)
            return False

    def _disconnect(self) -> None:
        if self._ssh:
            try:
                self._ssh.close()
            except Exception:
                pass
            self._ssh = None

    def _ensure_connected(self) -> bool:
        if self._ssh is None:
            return self._connect()
        # Check transport is alive.
        transport = self._ssh.get_transport()
        if transport is None or not transport.is_active():
            self._disconnect()
            return self._connect()
        return True

    # ── Poll ─────────────────────────────────────────────────

    def poll(self, interfaces: list[dict]) -> list[tuple]:
        """
        Returns list of DB records: (interface_id, ts, in_mbps, out_mbps).
        """
        if not self._ensure_connected():
            return []

        now = time.monotonic()
        wall = datetime.now(timezone.utc).replace(tzinfo=None)  # stored as UTC

        try:
            stats = ssh_read_stats(self._ssh)
        except paramiko.SSHException as exc:
            self.log.error("SSH exec error: %s – will reconnect.", exc)
            self._disconnect()
            return []
        except Exception as exc:
            self.log.error("Unexpected poll error: %s", exc)
            return []

        records: list[tuple] = []

        for iface in interfaces:
            ifname = iface['ifname']
            if ifname not in stats:
                continue

            rx_cur, tx_cur = stats[ifname]

            if ifname in self._prev:
                rx_prev, tx_prev, t_prev = self._prev[ifname]
                dt = now - t_prev

                if dt < 0.1:          # guard against tiny delta
                    continue

                # Handle 64-bit counter wrap.
                rx_diff = (rx_cur - rx_prev) % self.MAX_COUNTER
                tx_diff = (tx_cur - tx_prev) % self.MAX_COUNTER

                # Bytes → Mbps  (bytes * 8 / seconds / 1_000_000)
                in_mbps  = (rx_diff * 8) / (dt * 1_000_000)
                out_mbps = (tx_diff * 8) / (dt * 1_000_000)

                # Sanity clamp: 1 Tbps max to filter junk.
                in_mbps  = max(0.0, min(in_mbps,  1_000_000.0))
                out_mbps = max(0.0, min(out_mbps, 1_000_000.0))

                records.append((
                    iface['id'],
                    wall,
                    round(in_mbps, 4),
                    round(out_mbps, 4),
                ))

            self._prev[ifname] = (rx_cur, tx_cur, now)

        return records

    def close(self) -> None:
        self._disconnect()


# ── Main loop ─────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(description='Bandwidth Collector Daemon')
    parser.add_argument('--config', default=DEFAULT_CONFIG,
                        help='Path to collector_config.json')
    args = parser.parse_args()

    cfg    = load_config(args.config)
    log    = setup_logging(cfg)
    interval = cfg.get('poll_interval_seconds', 5)

    log.info("Bandwidth Collector starting (interval=%ds).", interval)

    db: mysql.connector.MySQLConnection | None = None
    pollers: dict[int, RouterPoller] = {}

    # DB reload every 60 seconds to pick up new routers/interfaces.
    _router_reload_interval = 60
    _last_router_reload: float = 0.0
    cached_routers: list[dict] = []
    cached_interfaces: dict[int, list[dict]] = {}

    while True:
        loop_start = time.monotonic()

        # ── DB connection ──────────────────────────────────────
        try:
            if db is None or not db.is_connected():
                db = db_connect(cfg)
                log.info("MySQL connected.")
                _last_router_reload = 0.0   # force reload

            # ── Reload router/interface list periodically ──────
            if loop_start - _last_router_reload >= _router_reload_interval:
                cached_routers = db_fetch_routers(db)
                cached_interfaces = {
                    r['id']: db_fetch_interfaces(db, r['id'])
                    for r in cached_routers
                }
                _last_router_reload = loop_start

                # Create pollers for new routers.
                active_ids = {r['id'] for r in cached_routers}
                for router in cached_routers:
                    rid = router['id']
                    if rid not in pollers:
                        pollers[rid] = RouterPoller(router, cfg, log)
                        log.info("New router registered: %s", router['name'])

                # Remove pollers for deactivated routers.
                for rid in list(pollers.keys()):
                    if rid not in active_ids:
                        pollers[rid].close()
                        del pollers[rid]
                        log.info("Router deregistered: id=%d", rid)

            # ── Poll all active routers ────────────────────────
            all_records: list[tuple] = []
            for router in cached_routers:
                rid    = router['id']
                ifaces = cached_interfaces.get(rid, [])
                if not ifaces:
                    continue
                poller  = pollers.get(rid)
                if poller is None:
                    continue
                records = poller.poll(ifaces)
                all_records.extend(records)

            # ── Batch insert ───────────────────────────────────
            if all_records:
                db_insert_batch(db, all_records)
                log.debug("Inserted %d records.", len(all_records))

        except MySQLError as exc:
            log.error("MySQL error: %s – reconnecting.", exc)
            try:
                if db:
                    db.rollback()
            except Exception:
                pass
            db = None

        except Exception as exc:
            log.exception("Unhandled error in main loop: %s", exc)

        # ── Sleep for remainder of interval ───────────────────
        elapsed = time.monotonic() - loop_start
        sleep_for = max(0.0, interval - elapsed)
        time.sleep(sleep_for)


if __name__ == '__main__':
    main()