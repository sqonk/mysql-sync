<?php
chdir(__DIR__);
passthru('vendor/bin/phpstan analyse -c phpstan.neon');