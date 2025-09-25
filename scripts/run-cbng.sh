#!/bin/bash

# Aggressively cache
cat > /layers/heroku_php/platform/etc/php/conf.d/cache.ini <<EOF
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=1000
opcache.validate_timestamps=0
opcache.file_cache=/tmp/opcache
opcache.file_cache_only=0
opcache.file_cache_consistency_checks=0
EOF

# Launcher bot
exec launcher php -f cluebot-ng.php
