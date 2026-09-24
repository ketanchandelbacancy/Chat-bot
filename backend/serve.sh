#!/usr/bin/env bash
# Starts the Laravel dev server with the upload limits from php/uploads.ini (no sudo / php.ini edit needed).
cd "$(dirname "$0")"
export PHP_INI_SCAN_DIR=":$(pwd)/php"
exec php artisan serve "$@"
