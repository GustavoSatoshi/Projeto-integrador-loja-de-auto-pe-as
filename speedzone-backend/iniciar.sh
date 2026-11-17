#!/bin/sh
cd "$(dirname "$0")"
php bin/instalar.php || exit 1
echo "Abrindo em http://localhost:8000/  (Ctrl+C para parar)"
php -S localhost:8000 -t public public/index.php
