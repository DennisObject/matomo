<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = $root.'/plugins/API/tests/System/expected/'
    .'test_ApiTest_compliancePolicyFeatureFlagEnabled__API.getSegmentsMetadata.xml';
$xml = simplexml_load_file($source);
if ($xml === false) {
    throw new RuntimeException('The segment metadata fixture could not be read.');
}

$translations = [];
$languageFiles = array_merge(
    glob($root.'/lang/en.json') ?: [],
    glob($root.'/plugins/*/lang/en.json') ?: [],
);
sort($languageFiles);
foreach ($languageFiles as $file) {
    $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        continue;
    }

    $plugin = basename(dirname(dirname($file)));
    if ($plugin === 'lang') {
        $plugin = 'General';
    }
    foreach ($decoded as $key => $value) {
        if (is_string($key) && is_string($value) && $value !== '') {
            $translations[$value] ??= $plugin.'_'.$key;
        }
    }
}

$rows = [];
foreach ($xml->row as $row) {
    $metadata = [];
    foreach ($row->children() as $name => $value) {
        if ($value->row->count() > 0) {
            $items = [];
            foreach ($value->row as $entry) {
                $item = (string) $entry;
                if ($item !== '') {
                    $items[] = $item;
                }
            }
            $metadata[$name] = $items;
        } else {
            $metadata[$name] = (string) $value;
        }
    }

    foreach (['category', 'name', 'acceptedValues'] as $field) {
        $value = $metadata[$field] ?? null;
        if (is_string($value) && isset($translations[$value])) {
            $metadata[$field.'Key'] = $translations[$value];
            unset($metadata[$field]);
        }
    }
    $rows[] = $metadata;
}

$output = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($rows, true).";\n";
$target = __DIR__.'/../resources/matomo/segment-metadata.php';
if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
    throw new RuntimeException('The metadata resource directory could not be created.');
}
if (file_put_contents($target, $output) === false) {
    throw new RuntimeException('The segment metadata catalog could not be written.');
}
