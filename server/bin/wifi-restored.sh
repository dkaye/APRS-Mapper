#!/bin/bash
# aprs-pi: restart APRS daemon (reconnects to APRS-IS) on WiFi restore
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

systemctl restart aprs-daemon
