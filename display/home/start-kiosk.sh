#!/bin/bash
rm -f ~/.config/chromium/Singleton*
exec chromium --password-store=basic --kiosk --noerrdialogs --disable-infobars --disable-dev-shm-usage --incognito --disable-features=BlockInsecurePrivateNetworkRequests https://marsaprs.org/
