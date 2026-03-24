#!/usr/bin/env python3
"""
backend/collector/setup_interfaces.py

One-time (or periodic) helper that:
  1. Connects to each active router via SSH.
  2. Discovers physical/virtual interfaces (excluding lo, dummy, etc.).
  3. Upserts them into the `interfaces` table.

Run ONCE after adding new routers or when interfaces change.
"""

import argparse
import json
import os
import re
import sys

import mysql.connector
import paramiko

DEFAULT_CONFIG = os.path.join(
    os.path.dirname(__file__), '../../config/collector_config.json'
)

SKIP_RE = re.compile(
    r'^(lo|dummy|bond\d+|tun\d*|tap\d*|veth|docker|br-|virbr|sit\d*|gre\d*)'
)


def load_config(path):
    with open(path) as fh:
        return json.load(fh)


def discover_interfaces_ssh(router: dict, cfg: dict) -> list[str]:
    """Return list of interface names on the remote router."""
    defaults = cfg.get('ssh_defaults', {})
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    kwargs = {
        'hostname': router['ip'],
        'username': router['ssh_user'],
        'port':     router.get('ssh_port') or defaults.get('port', 22),
        'timeout':  defaults.get('timeout_seconds', 10),
    }
    key = router.get('ssh_key_path') or defaults.get('key_path')
    if key and os.path.exists(key):
        kwargs['key_filename'] = key
        kwargs['look_for_keys'] = False
        kwargs['allow_agent']   = False

    client.connect(**kwargs)

    cmd = "ls /sys/class/net/"
    _, stdout, _ = client.exec_command(cmd, timeout=10)
    ifaces = stdout.read().decode().split()
    client.close()

    return [i for i in ifaces if not SKIP_RE.match(i)]


def upsert_interfaces(db, router_id: int, ifaces: list[str]) -> int:
    cur = db.cursor()
    count = 0
    for ifname in ifaces:
        cur.execute("""
            INSERT INTO interfaces (router_id, ifname, description, active)
            VALUES (%s, %s, '', 1)
            ON DUPLICATE KEY UPDATE active = 1
        """, (router_id, ifname))
        count += cur.rowcount
    db.commit()
    cur.close()
    return count


def main():
    parser = argparse.ArgumentParser(description='Discover and register interfaces')
    parser.add_argument('--config', default=DEFAULT_CONFIG)
    parser.add_argument('--router-id', type=int, default=None,
                        help='Limit to a single router ID')
    args = parser.parse_args()

    cfg = load_config(args.config)
    db  = mysql.connector.connect(**{
        'host':     cfg['db']['host'],
        'port':     cfg['db'].get('port', 3306),
        'database': cfg['db']['database'],
        'user':     cfg['db']['user'],
        'password': cfg['db']['password'],
    })

    cur = db.cursor(dictionary=True)
    query = "SELECT id, name, ip, ssh_user, ssh_port, ssh_key_path FROM routers WHERE active=1"
    params = ()
    if args.router_id:
        query += " AND id = %s"
        params = (args.router_id,)
    cur.execute(query, params)
    routers = cur.fetchall()
    cur.close()

    for router in routers:
        print(f"[{router['name']}] Connecting to {router['ip']}...")
        try:
            ifaces = discover_interfaces_ssh(router, cfg)
            print(f"  Found: {', '.join(ifaces)}")
            n = upsert_interfaces(db, router['id'], ifaces)
            print(f"  Registered/updated {n} interface(s).")
        except Exception as exc:
            print(f"  ERROR: {exc}", file=sys.stderr)

    db.close()
    print("Done.")


if __name__ == '__main__':
    main()
