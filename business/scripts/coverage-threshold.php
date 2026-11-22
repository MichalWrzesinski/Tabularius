<?php

declare(strict_types=1);

// Fails when line coverage in a PHPUnit --coverage-text summary is below the threshold.
[, $file, $threshold] = $argv + [null, null, null];
$text = (string) preg_replace("/\e\[[\d;]*m/", "", (string) @file_get_contents((string) $file));
if (!preg_match('/^\s*Lines:\s+([\d.]+)%/m', $text, $m)) {
    fwrite(STDERR, "No line coverage found in {$file}\n");
    exit(1);
}
$lines = (float) $m[1];
if ($lines < (float) $threshold) {
    fwrite(STDERR, sprintf("Line coverage %.2f%% is below the threshold %s%%\n", $lines, $threshold));
    exit(1);
}
printf("Line coverage %.2f%% (threshold %s%%)\n", $lines, $threshold);
