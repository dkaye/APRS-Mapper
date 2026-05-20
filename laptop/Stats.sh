#!/usr/bin/env bash
#
# Run this to start monitoring of MARS APRS iGates and digipeaters
#

# start the StatsRequester detached
sudo php StatsRequester.php $1 $2 $3 &
STATSREQUESTER_PID=$!
trap "kill $STATSREQUESTER_PID" EXIT

# start the StatsFromSocket (NOT detached!)
sudo php StatsFromSocket.php
