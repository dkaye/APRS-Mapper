#!/usr/bin/bash
# UpdateNetControl.sh
#
# ©2026 Doug Kaye, K6DRK
# This script downloads and installs apps and other files used at Net Control.
#
# Add/edit files to be updated or perform other tasks.

function msg {
        printf "\033[31m%s\033[0m\n" "$1"
}
function fetch {
	# syntax: fetch <source_directory> <input_filename> <target_directory>
	local source_directory="$1"
	local input_filename="$2"
	local suffix=".php"
	local replacement=".ph"
	if [[ -n "$3" ]]; then
		local target_directory="$3/"
	else
		local target_directory=""
	fi
	# Check if the string ends with ".php" using pattern matching
	if [[ "$input_filename" == *"$suffix" ]]; then
		# If it matches, use parameter expansion to replace the suffix
		# The '%' operator removes the shortest matching suffix pattern
		modified_string="${input_filename%$suffix}$replacement"
		input_filename="$modified_string"
	fi
        cmd="sudo wget -q https://marsaprs.org/display/$source_directory/$input_filename -O $target_directory$2"
	echo "$cmd"
	eval "$cmd"
}

#fetch all our custom files and apps from MARS...
msg "Fetching NetControl files and apps from MARS server..."
cd /home/pi
declare -a files=(
"available-wifi.sh"
"check-swapping.sh"
"update-wifi.php"
"wifi.conf"
"StatsRequestListener.php"
"add_wifi.php"
)
for i in "${!files[@]}"; do
        fetch ''  ${files[$i]}
done

declare -a files=(
"UpdateNetControl.sh"
"UpdateNetControl2.sh"
)
for i in "${!files[@]}"; do
        fetch NetControl  ${files[$i]}
done
sudo chmod +x *.sh *.php *.desktop
sudo chown pi:pi *

#update wifi ssid list
msg "Updating list of wifi SSIDs. Goes a bit slowly..."
cd /home/pi
sudo chmod +x update-wifi.php
./update-wifi.php

