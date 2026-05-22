#!/bin/bash
# restartDaemon.sh — MARS APRS Map Server
#
# Restarts the APRS background daemon. Run manually or called from the web UI.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

sudo systemctl restart aprs-daemon
