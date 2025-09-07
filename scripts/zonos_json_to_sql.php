#!/usr/bin/env php
<?php
// Convert Zonos-style classification JSON into SQL INSERTs for wp_wrd_customs_profiles
// Usage:
//   php scripts/zonos_json_to_sql.php [input_json] [output_sql]
// Defaults:
//   input_json: zonos/zonos_classification_100.json
//   output_sql: php://stdout

declare(strict_types=1);

function normalize_description(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s;
}

function q($s): string { // simple SQL string escaper for single quotes
    return "'" . str_replace("'", "''", $s) . "'";
}

$input = $argv[1] ?? 'zonos/zonos_classification_100.json';
$output = $argv[2] ?? 'php://stdout';

if (!is_file($input)) {
    fwrite(STDERR, "Input JSON not found: {$input}\n");
    exit(1);
}

$json = file_get_contents($input);
if ($json === false) {
    fwrite(STDERR, "Unable to read input: {$input}\n");
    exit(1);
}

$data = json_decode($json, true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON: {$input}\n");
    exit(1);
}

$fh = fopen($output, 'w');
if ($fh === false) {
    fwrite(STDERR, "Failed to open output: {$output}\n");
    exit(1);
}

$today = (new DateTimeImmutable('now'))->format('Y-m-d');

fwrite($fh, "-- Seed generated from {$input} on " . date('c') . "\n");
fwrite($fh, "-- Table: wp_wrd_customs_profiles\n\n");

foreach ($data as $key => $entry) {
    $parts = explode('|', (string)$key, 2);
    if (count($parts) !== 2) {
        fwrite(STDERR, "Skipping key without country suffix: {$key}\n");
        continue;
    }
    [$description, $countryCode] = $parts;
    $description = trim($description);
    $countryCode = strtoupper(trim($countryCode));
    $descNorm = normalize_description($description);
    $hs = (string)($entry['hs_code'] ?? '');

    $udj = [];
    foreach (['postal', 'commercial'] as $channel) {
        if (!empty($entry[$channel]['rates']) && is_array($entry[$channel]['rates'])) {
            $udj[$channel] = [
                'rates' => (object)$entry[$channel]['rates'],
            ];
        }
    }

    $fta = []; // unknown from Zonos; leave empty

    $sql = sprintf(
        "INSERT INTO `wp_wrd_customs_profiles`\n (`description_raw`,`description_normalized`,`country_code`,`hs_code`,`us_duty_json`,`fta_flags`,`effective_from`,`effective_to`,`notes`)\n VALUES (%s,%s,%s,%s,%s,%s,%s,NULL,NULL);\n\n",
        q($description),
        q($descNorm),
        q($countryCode),
        q($hs),
        q(json_encode($udj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        q(json_encode($fta)),
        q($today)
    );

    fwrite($fh, $sql);
}

fclose($fh);
exit(0);

