#!/bin/bash
# aprs-pi: restart APRS daemon (reconnects to APRS-IS) and refresh DDNS on WiFi restore
systemctl restart aprs-daemon
systemctl restart noip-duc
