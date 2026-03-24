```sql
-- =============================================================
-- sql/schema.sql  –  Bandwidth Monitor Database Schema
-- Run: mysql -u root -p < sql/schema.sql
-- =============================================================

CREATE DATABASE IF NOT EXISTS `bandwidth_monitor`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `bandwidth_monitor`;

-- ── Users ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)   NOT NULL,
    `password_hash` VARCHAR(255)  NOT NULL,
    `role`          ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
    `active`        TINYINT(1)    NOT NULL DEFAULT 1,
    `last_login`    DATETIME      NULL,
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PoPs (Points of Presence) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `pops` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(64)  NOT NULL,
    `location`   VARCHAR(128) NOT NULL DEFAULT '',
    `active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Routers ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `routers` (
    `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `pop_id`       INT UNSIGNED   NOT NULL,
    `name`         VARCHAR(64)    NOT NULL,
    `ip`           VARCHAR(45)    NOT NULL,
    `ssh_user`     VARCHAR(32)    NOT NULL DEFAULT 'root',
    `ssh_port`     SMALLINT UNSIGNED NOT NULL DEFAULT 22,
    `ssh_key_path` VARCHAR(255)   NULL DEFAULT NULL,
    `active`       TINYINT(1)     NOT NULL DEFAULT 1,
    `created_at`   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_pop_id` (`pop_id`),
    CONSTRAINT `fk_routers_pop`
        FOREIGN KEY (`pop_id`) REFERENCES `pops`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Interfaces ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `interfaces` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `router_id`   INT UNSIGNED NOT NULL,
    `ifname`      VARCHAR(32)  NOT NULL,
    `description` VARCHAR(128) NOT NULL DEFAULT '',
    `active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_router_iface` (`router_id`, `ifname`),
    INDEX `idx_router_id` (`router_id`),
    CONSTRAINT `fk_interfaces_router`
        FOREIGN KEY (`router_id`) REFERENCES `routers`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Bandwidth History ─────────────────────────────────────────
-- This is the hot table. Indexed on (interface_id, timestamp) for
-- fast range queries. Use partitioning for very large deployments.
CREATE TABLE IF NOT EXISTS `bandwidth_history` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `interface_id` INT UNSIGNED    NOT NULL,
    `timestamp`    DATETIME(3)     NOT NULL,
    `in_mbps`      DECIMAL(16,4)   NOT NULL DEFAULT 0.0000,
    `out_mbps`     DECIMAL(16,4)   NOT NULL DEFAULT 0.0000,
    PRIMARY KEY (`id`),
    INDEX `idx_iface_ts`  (`interface_id`, `timestamp`),
    INDEX `idx_timestamp` (`timestamp`),
    CONSTRAINT `fk_bw_iface`
        FOREIGN KEY (`interface_id`) REFERENCES `interfaces`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- Sample Data
-- ─────────────────────────────────────────────────────────────

-- Admin user placeholder. Replace password_hash via the CLI tool
-- described in README.md before going to production.
INSERT IGNORE INTO `users` (`id`, `username`, `password_hash`, `role`) VALUES
(1, 'admin', 'REPLACE_WITH_ARGON2ID_HASH', 'admin');

INSERT IGNORE INTO `pops` (`id`, `name`, `location`) VALUES
(1, 'FRA1', 'Frankfurt, DE'),
(2, 'AMS1', 'Amsterdam, NL'),
(3, 'LON1', 'London, GB');

INSERT IGNORE INTO `routers` (`id`, `pop_id`, `name`, `ip`, `ssh_user`, `ssh_port`, `ssh_key_path`) VALUES
(1, 1, 'fra1-core-01', '10.0.1.1',  'root', 22, '/root/.ssh/id_ed25519'),
(2, 1, 'fra1-edge-01', '10.0.1.2',  'root', 22, '/root/.ssh/id_ed25519'),
(3, 2, 'ams1-core-01', '10.0.2.1',  'root', 22, '/root/.ssh/id_ed25519'),
(4, 3, 'lon1-core-01', '10.0.3.1',  'root', 22, '/root/.ssh/id_ed25519');

INSERT IGNORE INTO `interfaces` (`id`, `router_id`, `ifname`, `description`) VALUES
(1, 1, 'eth0', 'Uplink – ISP A'),
(2, 1, 'eth1', 'Peering – DE-CIX'),
(3, 2, 'eth0', 'Transit – Cogent'),
(4, 3, 'eth0', 'Uplink – ISP B'),
(5, 3, 'eth1', 'Peering – AMS-IX'),
(6, 4, 'eth0', 'Uplink – ISP C');
