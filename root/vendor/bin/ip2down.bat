@ECHO OFF
setlocal DISABLEDELAYEDEXPANSION
SET BIN_TARGET=%~dp0/../zoujingli/ip2region/bin/ip2down
php "%BIN_TARGET%" %*
