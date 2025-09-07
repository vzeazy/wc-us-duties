#!/usr/bin/env php
<?php
// Convert Zonos-style classification JSON into Customs Profiles CSV (docs §8.2)
// Usage:
//   php scripts/zonos_json_to_customs_csv.php [input_json] [output_csv]
// Defaults:
//   input_json: zonos/zonos_classification_100.json
//   output_csv: php://stdout

declare(strict_types=1);

function normalize_description(string $s): string {
    // Lowercase, trim, collapse internal whitespace to single spaces
    $s = strtolower($s);
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s;
}

function encode_json_column(array $value): string {
    // Produce minified JSON string for CSV column
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function open_output(string $path) {
    $fh = fopen($path, 'w');
    if ($fh === false) {
        fwrite(STDERR, "Failed to open output: {$path}\n");
        exit(1);
    }
    return $fh;
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

$out = open_output($output);

// CSV Header (docs §8.2)
fputcsv($out, [
    'description',
    'country_code',
    'hs_code',
    'fta_flags',
    'us_duty_json',
    'effective_from',
    'effective_to',
    'notes',
]);

$today = (new DateTimeImmutable('now'))->format('Y-m-d');

foreach ($data as $key => $entry) {
    // Expect key format: "Description|CC"
    $parts = explode('|', (string)$key, 2);
    if (count($parts) !== 2) {
        fwrite(STDERR, "Skipping key without country suffix: {$key}\n");
        continue;
    }
    [$description, $countryCode] = $parts;
    $description = trim($description);
    $countryCode = strtoupper(trim($countryCode));

    $hs = (string)($entry['hs_code'] ?? '');
    if ($hs === '') {
        fwrite(STDERR, "Warning: missing hs_code for {$key}\n");
    }

    // Build us_duty_json from available channels and their rates only
    $udj = [];
    foreach (['postal', 'commercial'] as $channel) {
        if (!empty($entry[$channel]['rates']) && is_array($entry[$channel]['rates'])) {
            $udj[$channel] = [
                // We intentionally omit de_minimis here unless provided in input
                'rates' => (object)$entry[$channel]['rates'],
            ];
        }
    }

    // fta_flags unknown from Zonos; default to empty array
    $fta = [];

    $row = [
        $description,
        $countryCode,
        $hs,
        encode_json_column($fta),
        encode_json_column($udj),
        $today,   // effective_from (default to today)
        '',       // effective_to
        '',       // notes
    ];

    fputcsv($out, $row);
}

if ($output !== 'php://stdout') {
    fclose($out);
}

// Done
exit(0);

