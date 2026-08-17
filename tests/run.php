<?php

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') as $testFile) {
    require $testFile;
}

exit($failures === 0 ? 0 : 1);
