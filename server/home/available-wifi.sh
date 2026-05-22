#!/usr/bin/env bash
# available-wifi.sh — MARS APRS Server Pi
#
# Diagnostic: lists nearby WiFi networks, current connection, and IP addresses.
#
# Docs: https://github.com/dkaye/APRS-Mapper/blob/main/map/README.MD
# ©2025 Doug Kaye, K6DRK <doug@rds.com>

sudo iwlist wlan0 scan | grep SSID
iwconfig
ip addr show | grep  "inet "
