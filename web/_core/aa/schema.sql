-- A-Alerts API on MariaDB: the same tables and columns as the Cloudflare Worker (p2000-apeldoorn/worker/schema.sql),
-- so the logic and the answers can be ported one to one. Times are unix milliseconds, as in the Worker.
-- Only CREATE TABLE IF NOT EXISTS: running it again changes nothing.
CREATE TABLE IF NOT EXISTS meldingen (
  guid VARCHAR(64) NOT NULL PRIMARY KEY,
  title TEXT NOT NULL,
  full_melding TEXT,
  dienst VARCHAR(32),
  prioriteit VARCHAR(8),
  datum BIGINT NOT NULL,
  locatie VARCHAR(255),
  lat DOUBLE,
  lon DOUBLE,
  stad VARCHAR(96),
  regio VARCHAR(128),
  capcode VARCHAR(32),
  categorie VARCHAR(128),
  grip VARCHAR(16),
  opschaling VARCHAR(128),
  eenheden MEDIUMTEXT,
  voertuigen MEDIUMTEXT,
  link VARCHAR(512),
  views INT NOT NULL DEFAULT 0,
  created_at BIGINT NOT NULL,
  incident VARCHAR(64),
  vlaggen VARCHAR(64),
  dedup_key TEXT,
  dedup_hash CHAR(64) AS (SHA2(dedup_key,256)) PERSISTENT,
  bron VARCHAR(16),
  UNIQUE KEY meldingen_dedup (dedup_hash),
  KEY meldingen_datum (datum),
  KEY meldingen_incident (incident),
  KEY meldingen_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS devices (
  token VARCHAR(255) NOT NULL PRIMARY KEY,
  platform VARCHAR(16) NOT NULL,
  environment VARCHAR(16) NOT NULL,
  language VARCHAR(8),
  app_version VARCHAR(32),
  enabled TINYINT NOT NULL DEFAULT 1,
  plaatsen TEXT NOT NULL,
  diensten TEXT NOT NULL,
  prioriteiten TEXT NOT NULL,
  quiet TINYINT NOT NULL DEFAULT 0,
  quiet_from INT NOT NULL DEFAULT 1380,
  quiet_to INT NOT NULL DEFAULT 420,
  critical TINYINT NOT NULL DEFAULT 1,
  radius_km DOUBLE,
  lat DOUBLE,
  lon DOUBLE,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS outbox (
  guid VARCHAR(64) NOT NULL, token VARCHAR(255) NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY (guid, token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS weather (guid VARCHAR(64) NOT NULL PRIMARY KEY, data MEDIUMTEXT NOT NULL, fetched_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS kv (`key` VARCHAR(191) NOT NULL PRIMARY KEY, value MEDIUMTEXT, at BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS overrides (
  guid VARCHAR(64) NOT NULL PRIMARY KEY, hidden TINYINT NOT NULL DEFAULT 0, title TEXT, categorie VARCHAR(128), locatie VARCHAR(255),
  lat DOUBLE, lon DOUBLE, updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS installs (
  id VARCHAR(96) NOT NULL PRIMARY KEY, platform VARCHAR(16) NOT NULL, model VARCHAR(96), os_version VARCHAR(32), app_version VARCHAR(32),
  build VARCHAR(32), apple_login TINYINT NOT NULL DEFAULT 0, push TINYINT NOT NULL DEFAULT 0, language VARCHAR(8),
  first_seen BIGINT NOT NULL, last_seen BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS install_days (day VARCHAR(10) NOT NULL, id VARCHAR(96) NOT NULL, PRIMARY KEY (day, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS visits (
  day VARCHAR(10) NOT NULL, visitor VARCHAR(96) NOT NULL, pages INT NOT NULL DEFAULT 1, device VARCHAR(32), PRIMARY KEY (day, visitor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pageviews (day VARCHAR(10) NOT NULL, path VARCHAR(191) NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (day, path)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS admin_devices (
  token VARCHAR(255) NOT NULL PRIMARY KEY, environment VARCHAR(16) NOT NULL, platform VARCHAR(16), created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS waarschuwingen (
  id VARCHAR(191) NOT NULL PRIMARY KEY, bron VARCHAR(32) NOT NULL, titel TEXT, tekst MEDIUMTEXT, tekst_en MEDIUMTEXT, plaats VARCHAR(96),
  niveau VARCHAR(16), soort VARCHAR(96), start_at BIGINT NOT NULL, end_at BIGINT, status VARCHAR(16) NOT NULL, gebied MEDIUMTEXT,
  lat DOUBLE, lon DOUBLE, link VARCHAR(512), first_seen BIGINT NOT NULL, updated_at BIGINT NOT NULL, notified VARCHAR(16),
  KEY waarschuwingen_start (start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS waarschuwing_knmi (id VARCHAR(191) NOT NULL PRIMARY KEY, data MEDIUMTEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS device_prefs (token VARCHAR(255) NOT NULL PRIMARY KEY, data MEDIUMTEXT NOT NULL, updated_at BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS waarschuwing_overrides (id VARCHAR(191) NOT NULL PRIMARY KEY, hidden TINYINT NOT NULL DEFAULT 0, ended_at BIGINT, updated_at BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS admin_prefs (token VARCHAR(255) NOT NULL PRIMARY KEY, bronnen INT NOT NULL DEFAULT 1, updated_at BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS downloads (
  day VARCHAR(10) NOT NULL, app VARCHAR(32) NOT NULL, version VARCHAR(32) NOT NULL, visitor VARCHAR(96) NOT NULL, at BIGINT NOT NULL,
  PRIMARY KEY (day, app, version, visitor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS heli_posities (
  heli VARCHAR(40) NOT NULL, at BIGINT NOT NULL, lat DOUBLE NOT NULL, lon DOUBLE NOT NULL,
  hoogte_ft INT, snelheid_kt INT, koers INT, grond TINYINT NOT NULL DEFAULT 0, PRIMARY KEY (heli, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS status_dag (
  onderdeel VARCHAR(48) NOT NULL, dag VARCHAR(10) NOT NULL, ok INT NOT NULL DEFAULT 0, warn INT NOT NULL DEFAULT 0,
  down INT NOT NULL DEFAULT 0, maint INT NOT NULL DEFAULT 0, PRIMARY KEY (onderdeel, dag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS status_push (
  bucket BIGINT NOT NULL, platform VARCHAR(16) NOT NULL, ok INT NOT NULL DEFAULT 0, fail INT NOT NULL DEFAULT 0,
  lat_sum BIGINT NOT NULL DEFAULT 0, lat_n INT NOT NULL DEFAULT 0, PRIMARY KEY (bucket, platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS status_incidenten (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, titel TEXT NOT NULL, titel_en TEXT, soort VARCHAR(32) NOT NULL, onderdelen TEXT NOT NULL,
  start_at BIGINT NOT NULL, eind_at BIGINT, gepland_start BIGINT, gepland_eind BIGINT, updates MEDIUMTEXT NOT NULL,
  created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS melding_routes (guid VARCHAR(64) NOT NULL PRIMARY KEY, data MEDIUMTEXT NOT NULL, at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS melding_feedback (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, guid VARCHAR(96) NOT NULL, reden VARCHAR(48) NOT NULL, toelichting TEXT, lat DOUBLE, lon DOUBLE,
  platform VARCHAR(16), bron VARCHAR(32), at BIGINT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'open', KEY melding_feedback_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS device_install (
  token VARCHAR(255) NOT NULL PRIMARY KEY, install_id VARCHAR(96) NOT NULL, at BIGINT NOT NULL, KEY device_install_id (install_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nieuws (
  id VARCHAR(64) NOT NULL PRIMARY KEY, soort VARCHAR(16) NOT NULL, bron VARCHAR(32) NOT NULL, ref VARCHAR(191),
  titel TEXT NOT NULL, intro MEDIUMTEXT, plaats VARCHAR(96), lat DOUBLE, lon DOUBLE, url VARCHAR(512),
  categorie VARCHAR(96), status VARCHAR(16), niveau VARCHAR(16),
  at BIGINT NOT NULL, updated_at BIGINT NOT NULL, created_at BIGINT NOT NULL,
  data MEDIUMTEXT, foto VARCHAR(96), hidden TINYINT NOT NULL DEFAULT 0, notified VARCHAR(16),
  KEY nieuws_at (at), KEY nieuws_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nieuws_updates (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, nieuws_id VARCHAR(64) NOT NULL, titel TEXT, tekst MEDIUMTEXT, url VARCHAR(512), foto VARCHAR(96),
  at BIGINT NOT NULL, created_at BIGINT NOT NULL, KEY nieuws_updates_n (nieuws_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nieuws_liveblog (id VARCHAR(64) NOT NULL PRIMARY KEY, at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nieuws_stockfoto (id VARCHAR(64) NOT NULL PRIMARY KEY, foto VARCHAR(191) NOT NULL, at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nieuws_fotos (id VARCHAR(96) NOT NULL PRIMARY KEY, mime VARCHAR(48) NOT NULL, data LONGBLOB NOT NULL, at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS widget_sites (site VARCHAR(191) NOT NULL PRIMARY KEY, widgets TEXT, eerst BIGINT NOT NULL, laatst BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS widget_views (site VARCHAR(191) NOT NULL, dag VARCHAR(10) NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (site, dag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS widget_blok (site VARCHAR(191) NOT NULL PRIMARY KEY, at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS post_inzet (post VARCHAR(96) NOT NULL, incident VARCHAR(64) NOT NULL, at BIGINT NOT NULL, PRIMARY KEY (post, incident)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Order of rows with the same time, as the Worker database returns them (its rowid), then the own insert order.
ALTER TABLE meldingen ADD COLUMN IF NOT EXISTS volgorde BIGINT NULL;
ALTER TABLE waarschuwingen ADD COLUMN IF NOT EXISTS volgorde BIGINT NULL;
