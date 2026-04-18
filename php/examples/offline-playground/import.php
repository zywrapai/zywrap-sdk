
<?php
// FILE: import.php
/**
 * Zywrap V1 SDK - Tabular Data Importer
 * USAGE: php import.php
 */
ini_set('max_execution_time', '300');
ini_set('memory_limit', '512M');
require_once 'db.php';

$jsonFile = 'zywrap-data.json';
if (!file_exists($jsonFile)) die("Error: Could not find 'zywrap-data.json'.\n");

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data) die("Error: Could not parse JSON data.");

// Helper to expand tabular JSON data into associative arrays
function extractTabular($tabularData) {
    if (empty($tabularData['cols']) || empty($tabularData['data'])) return [];
    $cols = $tabularData['cols'];
    $result = [];
    foreach ($tabularData['data'] as $row) {
        $result[] = array_combine($cols, $row);
    }
    return $result;
}

try {
    echo "Starting full V1 data import...\n";
    
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
    $pdo->exec('TRUNCATE TABLE wrappers; TRUNCATE TABLE use_cases; TRUNCATE TABLE categories;');
    $pdo->exec('TRUNCATE TABLE languages; TRUNCATE TABLE block_templates;');
    $pdo->exec('TRUNCATE TABLE ai_models; TRUNCATE TABLE settings;');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');

    // START TRANSACTION (Makes imports 100x faster)
    $pdo->beginTransaction();
    echo "Clearing tables...\n";

    // 1. Categories
    if (isset($data['categories'])) {
        $stmt = $pdo->prepare("INSERT INTO categories (code, name, status, ordering) VALUES (?, ?, 1, ?)");
        foreach (extractTabular($data['categories']) as $c) {
            $stmt->execute([$c['code'], $c['name'], $c['ordering'] ?? 99999]);
        }
        echo "Categories imported successfully.\n";
    }
    
    // 2. Use Cases 
    if (isset($data['useCases'])) {
        $stmt = $pdo->prepare("INSERT INTO use_cases (code, name, description, category_code, schema_data, status, ordering) VALUES (?, ?, ?, ?, ?, 1, ?)");
        foreach (extractTabular($data['useCases']) as $uc) {
            $schemaJson = !empty($uc['schema']) ? json_encode($uc['schema']) : null;
            $stmt->execute([$uc['code'], $uc['name'], $uc['desc'], $uc['cat'], $schemaJson, $uc['ordering'] ?? 999999999]);
        }
        echo "Use Cases imported successfully.\n";
    }

    // 3. Wrappers
    if (isset($data['wrappers'])) {
        $stmt = $pdo->prepare("INSERT INTO wrappers (code, name, description, use_case_code, featured, base, status, ordering) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
        foreach (extractTabular($data['wrappers']) as $w) {
            $featured = !empty($w['featured']) ? 1 : 0;
            $base = !empty($w['base']) ? 1 : 0;
            $stmt->execute([$w['code'], $w['name'], $w['desc'], $w['usecase'], $featured, $base, $w['ordering'] ?? 999999999]);
        }
        echo "Wrappers imported successfully.\n";
    }

    // 4. Languages
    if (isset($data['languages'])) {
        $stmt = $pdo->prepare("INSERT INTO languages (code, name, status, ordering) VALUES (?, ?, 1, ?)");
        $ord = 1;
        foreach (extractTabular($data['languages']) as $l) {
            $stmt->execute([$l['code'], $l['name'], $ord++]);
        }
        echo "Languages imported successfully.\n";
    }
    
    // 5. AI Models
    if (isset($data['aiModels'])) {
        $stmt = $pdo->prepare("INSERT INTO ai_models (code, name, status, ordering) VALUES (?, ?, 1, ?)");
        foreach (extractTabular($data['aiModels']) as $m) {
            $stmt->execute([$m['code'], $m['name'], $m['ordering'] ?? 99999]);
        }
        echo "AI Models imported successfully.\n";
    }

    // 6. Block Templates 
    if (isset($data['templates'])) {
        $stmt = $pdo->prepare("INSERT INTO block_templates (type, code, name, status) VALUES (?, ?, ?, 1)");
        foreach ($data['templates'] as $type => $tabular) {
            foreach (extractTabular($tabular) as $tpl) {
                $stmt->execute([$type, $tpl['code'], $tpl['name']]);
            }
        }
        echo "Block templates imported successfully.\n";
    }

    // 7. Save Version
    if (isset($data['version'])) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$data['version'], $data['version']]);
        echo "Data version saved to settings table.\n";
    }
    
    // COMMIT TRANSACTION (Saves everything to hard drive instantly)
    $pdo->commit();

    echo "\n✅ V1 Import complete! Version: " . ($data['version'] ?? 'N/A') . "\n";

} catch (PDOException $e) {
    // If anything fails, undo all changes
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("Database error during import: " . $e->getMessage() . "\n");
}
?>
