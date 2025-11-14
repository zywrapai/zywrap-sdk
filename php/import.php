<?php
// FILE: import.php
/**
 * Zywrap SDK - Full Data Importer
 *
 * This script unzips 'zywrap-data.zip', reads 'zywrap-data.json' from it,
 * clears local database tables, and performs a full data import.
 *
 * USAGE: php import.php
 */

// uncomment to increase execution time
//ini_set('max_execution_time', '1500'); //300 seconds = 5 minutes


require 'db.php'; // Your database connection file

$jsonFile = 'zywrap-data.json';
$jsonData = file_get_contents($jsonFile);

if ($jsonData === false) {
    die("Error: Could not find 'zywrap-data.json' file.\n");
}

$data = json_decode($jsonData, true);

if (!$data) {
    die("Error: Could not parse JSON data. Check for syntax errors.");
}

try {
    echo "Starting full data import...";
    
    // 1. Clear existing data
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
    $pdo->exec('TRUNCATE TABLE wrappers;');
    $pdo->exec('TRUNCATE TABLE categories;');
    $pdo->exec('TRUNCATE TABLE languages;');
    $pdo->exec('TRUNCATE TABLE block_templates;');
    $pdo->exec('TRUNCATE TABLE ai_models;');
    $pdo->exec('TRUNCATE TABLE settings;');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
    echo "<br>Tables cleared successfully.";

    // 2. Import Categories
    if (isset($data['categories']) && is_array($data['categories'])) {
        $cat_ordering = 1;
        $stmt = $pdo->prepare("INSERT INTO categories (code, name, ordering) VALUES (?, ?, ?)");
        foreach ($data['categories'] as $code=>$category) {
            $stmt->execute([$code, $category['name'],$cat_ordering]);
            $cat_ordering ++;
        }
        echo "<br>Categories imported successfully.";
    }
    
    // 3. Import Languages
    if (isset($data['languages']) && is_array($data['languages'])) {
        $lan_ordering = 1;
        $stmt = $pdo->prepare("INSERT INTO languages (code, name, ordering) VALUES (?, ?, ?)");
        foreach ($data['languages'] as $code=>$name) {
            $stmt->execute([$code, $name, $lan_ordering]);
            $lan_ordering ++;
        }
        echo "<br>Languages imported successfully.";
    }

    // 4. Import AI Models
    if (isset($data['aiModels']) && is_array($data['aiModels'])) {
        $mod_ordering = 1;
        $stmt = $pdo->prepare("INSERT INTO ai_models (code, name, provider_id, ordering) VALUES (?, ?, ?, ?)");
        foreach ($data['aiModels'] as $code => $model) {
            $stmt->execute([
                $code, $model['name'], $model['provId'], $mod_ordering
            ]);
            $mod_ordering ++;
        }
        echo "<br>AI Models imported successfully.";
    }
    
    // 5. Import Block Templates
    if (isset($data['templates']) && is_array($data['templates'])) {
        $stmt = $pdo->prepare("INSERT INTO block_templates (type, code, name) VALUES (?, ?, ?)");
        foreach ($data['templates'] as $type => $templates) {
            if (is_array($templates)) {
                foreach ($templates as $code => $name) {
                    $stmt->execute([$type, $code, $name]);
                }
            }
        }
        echo "<br>Block templates imported successfully.";
    }

    // 6. Import Wrappers
    if (isset($data['wrappers']) && is_array($data['wrappers'])) {
        $wrap_ordering = 1;
        $stmt = $pdo->prepare("INSERT INTO wrappers (code, name, description, category_code, featured, base, ordering) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($data['wrappers'] as $code => $wrapper) {
            $stmt->execute([$code, $wrapper['name'], $wrapper['desc'], $wrapper['cat'], $wrapper['featured'], $wrapper['base'], $wrap_ordering]);
            $wrap_ordering ++;
        }
        echo "<br>Wrappers imported successfully.";
    }

    // 7. Save Version
    if (isset($data['version'])) {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$data['version']]);
        echo "<br>Data version saved to settings table.";
    }

    echo "<br> Data import complete! Version: " . ($data['version'] ?? 'N/A') . "";

} catch (PDOException $e) {
    die("<br>Database error during import: " . $e->getMessage() . "");
}
?>