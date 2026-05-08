#!/bin/bash
#
# APRS Tracker Map — publish script
#
# @author    Doug Kaye
# @copyright 2026 Doug Kaye. All Rights Reserved.
#
# Syncs ~/aprs/ to the Dropbox staging folder for the MARS FTP server,
# renaming .php → .ph and admin/password.txt → admin/password at the destination.
#

SRC=/Users/doug/aprs
DEST=/Users/doug/Dropbox/Radio/APRS/iGateMaster/APRS/html

# Copy everything, preserving subdirectory structure
rsync -rv --exclude start-local.sh "$SRC/" "$DEST/"

# Rename .php → .ph throughout the destination tree
find "$DEST" -name "*.php" | while IFS= read -r f; do
    mv "$f" "${f%.php}.ph"
done

# Rename admin/password.txt → admin/password
[ -f "$DEST/admin/password.txt" ] && mv "$DEST/admin/password.txt" "$DEST/admin/password"
