<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = $root.'/tests/PHPUnit/System/expected/test_apiGetReportMetadata__API.getReportMetadata_day.xml';
$xml = simplexml_load_file($source);
if ($xml === false) {
    throw new RuntimeException('The report metadata fixture could not be read.');
}

$translations = [];
$languageFiles = array_merge(glob($root.'/lang/en.json') ?: [], glob($root.'/plugins/*/lang/en.json') ?: []);
sort($languageFiles);
foreach ($languageFiles as $file) {
    $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        continue;
    }

    foreach ($decoded as $namespace => $messages) {
        if (! is_string($namespace) || ! is_array($messages)) {
            continue;
        }

        foreach ($messages as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $translations[$value] ??= $namespace.'_'.$key;
            }
        }
    }
}

$convert = function (SimpleXMLElement $node) use (&$convert, $translations): mixed {
    $children = $node->children();
    if ($children->count() === 0) {
        $value = (string) $node;

        return isset($translations[$value]) ? ['translationKey' => $translations[$value]] : $value;
    }
    $names = [];
    foreach ($children as $name => $_child) {
        $names[] = $name;
    }
    if (array_unique($names) === ['row']) {
        $result = [];
        foreach ($children as $child) {
            $result[] = $convert($child);
        }

        return $result;
    }
    $result = [];
    foreach ($children as $name => $child) {
        $result[$name] = $convert($child);
    }

    return $result;
};

$rows = [];
foreach ($xml->row as $row) {
    $metadata = $convert($row);
    if (is_array($metadata)) {
        unset($metadata['imageGraphUrl'], $metadata['imageGraphEvolutionUrl']);
        $rows[] = $metadata;
    }
}
$output = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($rows, true).";\n";
$target = __DIR__.'/../resources/matomo/report-metadata.php';
if (file_put_contents($target, $output) === false) {
    throw new RuntimeException('The report metadata catalog could not be written.');
}

$pint = __DIR__.'/../vendor/bin/pint';
if (! is_file($pint)) {
    throw new RuntimeException('Install Laravel dependencies before generating the catalog.');
}

passthru(escapeshellarg($pint).' '.escapeshellarg($target).' --quiet', $status);
if ($status !== 0) {
    throw new RuntimeException('The generated report metadata catalog could not be formatted.');
}
