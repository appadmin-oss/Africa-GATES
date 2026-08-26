<?php
require __DIR__ . '/../../vendor/autoload.php';
use AfricaGates\Support\Qr;
$out = [];
foreach (json_decode(file_get_contents($argv[1]), true) as $t) {
    $m = Qr::encodeBytes($t);
    $out[$t] = $m === null ? null
        : array_map(fn(array $row): string => implode('', array_map(fn($b) => $b ? '1' : '0', $row)), $m);
}
echo json_encode($out);
