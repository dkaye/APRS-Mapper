#!/usr/bin/bash

# UpdateNetcontrol.sh
#
# ©2025 Doug Kaye, K6DRK
#
# This script downloads then executes a child script, IUpdateNetControl2.sh.
#
# Why a two-step process? So that if there's a problem with the child script, it can be edited.
# Therefore, this script should only be edited under rare circumstancess.
#
# fetch latest version of the child script
cd /home/pi
sudo wget -q https://marsaprs.org/display/UpdateNetControl2.sh -O UpdateNetControl2.sh
#
# now run the script we just downloaded
sudo chmod +x UpdateNetControl2.sh
/home/pi/UpdateNetControl2.sh