<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
fwrite(STDERR, "Enter a strong password (input is visible in this basic CLI helper): ");
$pw = trim((string) fgets(STDIN));
if (strlen($pw) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters.\n");
    exit(1);
}
echo password_hash($pw, PASSWORD_DEFAULT), PHP_EOL;