<?php
// A-Alerts API gateway: configuration. No secrets here.
// The real API (Cloudflare Worker). Override with the environment variable AALERTS_UPSTREAM (used by the tests).
const UPSTREAM = 'https://p2000-apeldoorn-api.lrvdlinden.workers.dev';
const GATEWAY_VERSION = '1.0.1';
// Seconds to wait for the real API before answering 504.
const UPSTREAM_TIMEOUT = 25;
