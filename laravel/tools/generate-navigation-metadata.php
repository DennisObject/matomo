<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$translations = [];
$files = array_merge(glob($root.'/lang/en.json') ?: [], glob($root.'/plugins/*/lang/en.json') ?: []);
sort($files);
foreach ($files as $file) {
    $values = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (is_array($values)) {
        foreach ($values as $namespace => $messages) {
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
}
$convert = function (SimpleXMLElement $node) use (&$convert, $translations): mixed {
    if ($node->children()->count() === 0) {
        $value = (string) $node;

        return isset($translations[$value]) ? ['translationKey' => $translations[$value]] : $value;
    }
    $names = [];
    foreach ($node->children() as $name => $_child) {
        $names[] = $name;
    }
    $result = [];
    if (array_unique($names) === ['row']) {
        foreach ($node->children() as $child) {
            $result[] = $convert($child);
        }

        return $result;
    }
    foreach ($node->children() as $name => $child) {
        $result[$name] = $convert($child);
    }

    return $result;
};

foreach ([
    'report-pages-metadata.php' => 'test_apiGetReportMetadata__API.getReportPagesMetadata.xml',
    'widget-metadata.php' => 'test_apiGetReportMetadata__API.getWidgetMetadata.xml',
] as $target => $source) {
    $xml = simplexml_load_file($root.'/tests/PHPUnit/System/expected/'.$source);
    if ($xml === false) {
        throw new RuntimeException("The {$source} fixture could not be read.");
    }
    $rows = [];
    foreach ($xml->row as $row) {
        $rows[] = $convert($row);
    }
    $output = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($rows, true).";\n";
    $targetPath = __DIR__.'/../resources/matomo/'.$target;
    if (file_put_contents($targetPath, $output) === false) {
        throw new RuntimeException("The {$target} catalog could not be written.");
    }

    $pint = __DIR__.'/../vendor/bin/pint';
    if (! is_file($pint)) {
        throw new RuntimeException('Install Laravel dependencies before generating the catalogs.');
    }

    passthru(escapeshellarg($pint).' '.escapeshellarg($targetPath).' --quiet', $status);
    if ($status !== 0) {
        throw new RuntimeException("The {$target} catalog could not be formatted.");
    }
}
