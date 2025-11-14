<?php

// File: zywrap-sync.php
/**
 * Zywrap SDK - Local Data Synchronizer
 *
 * This script connects to the official Zywrap API to fetch delta updates
 * and applies them to a local MySQL database.
 *
 * USAGE:
 * 1. Configure the variables in the "CONFIGURATION" section below.
 * 2. Run from the command line: 'php zywrap-sync.php'
 * 3. Set up a cron job to run this script automatically (e.g., daily).
 */

// --- CONFIGURATION ---------------------------------------------------
$zywrapApiKey = "YOUR_ZYWRAP_API_KEY";
$zywrapApiEndpoint = 'https://api.zywrap.com/v1/sdk/export/updates'; // Updated Endpoint
$versionFilePath = __DIR__ . '/.zywrap_version'; // A simple file to store the current data version

// Include the developer's local database connection
require 'db.php'; // This file should provide the $pdo object
// ---------------------------------------------------------------------


/**
 * Reads the last sync version from the local database settings table.
 * @param PDO $pdo
 * @return string|null
 */
function getCurrentVersion(PDO $pdo): ?string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'data_version'");
    $stmt->execute();
    $result = $stmt->fetchColumn();
    return $result ?: null;
}

/**
 * Saves the new version timestamp to the local database settings table.
 * @param PDO $pdo
 * @param string $version
 */
function saveNewVersion(PDO $pdo, string $version): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$version]);
}

// --- MAIN EXECUTION --------------------------------------------------

echo "Starting Zywrap data sync...\n";

// 1. Get the current local data version from the database
$currentVersion = getCurrentVersion($pdo);
if (!$currentVersion) {
    die("Error: No version found in the 'settings' table. Please run the full 'import.php' script first to establish a baseline.\n");
}
echo "Current local version: {$currentVersion}\n";

// 2. Make an authenticated API call to the Zywrap sync endpoint
$urlWithVersion = $zywrapApiEndpoint . '?fromVersion=' . urlencode($currentVersion);

$ch = curl_init($urlWithVersion);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Authorization: Bearer ' . $developerApiKey]);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $zywrapApiKey
]);

$responseJson = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("Error: API request failed with status code {$httpCode}. Response: {$responseJson}\n");
}

$patch = json_decode($responseJson, true);
if (!$patch) {
    die("Error: Could not decode a valid patch from the API response.\n");
}

echo "Successfully fetched patch version: " . $patch['newVersion'] . "\n";

// 3. Apply the patch to the local database within a transaction
try {
    $pdo->beginTransaction();

    // Process Updates/Creations (UPSERT)
    if (!empty($patch['updates'])) {
        // --- Process Wrappers ---
        if (!empty($patch['updates']['wrappers'])) {
            echo "Processing wrapper updates...\n";
            $stmt = $pdo->prepare("INSERT INTO wrappers (code, name, description, category_code) VALUES (:code, :name, :description, :category_code) ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), category_code=VALUES(category_code)");
            foreach ($patch['updates']['wrappers'] as $item) $stmt->execute($item);
        }
        
        // --- Process Categories ---
        if (!empty($patch['updates']['categories'])) {
            echo "Processing category updates...\n";
            $stmt = $pdo->prepare("INSERT INTO categories (code, name) VALUES (:code, :name) ON DUPLICATE KEY UPDATE name=VALUES(name)");
            foreach ($patch['updates']['categories'] as $item) $stmt->execute($item);
        }

        // --- Process Languages ---
        if (!empty($patch['updates']['languages'])) {
            echo "Processing language updates...\n";
            $stmt = $pdo->prepare("INSERT INTO languages (code, name) VALUES (:code, :name) ON DUPLICATE KEY UPDATE name=VALUES(name)");
            foreach ($patch['updates']['languages'] as $item) $stmt->execute($item);
        }

        // --- Process AI Models ---
        if (!empty($patch['updates']['aiModels'])) {
            echo "Processing AI model updates...\n";
            $stmt = $pdo->prepare("INSERT INTO ai_models (code, name, provider_id) VALUES (:code, :name, :provider_id) ON DUPLICATE KEY UPDATE name=VALUES(name), provider_id=VALUES(provider_id)");
            foreach ($patch['updates']['aiModels'] as $item) $stmt->execute($item);
        }

        // --- Process Block Templates ---
        // (This assumes your 'export.js' groups them, if not, adjust)
        $blockTypes = ['tones', 'styles', 'formattings', 'complexities', 'lengths', 'outputTypes', 'responseGoals', 'audienceLevels'];
        foreach ($blockTypes as $type) {
             if (!empty($patch['updates'][$type])) {
                echo "Processing {$type} updates...\n";
                $stmt = $pdo->prepare("INSERT INTO block_templates (type, code, name) VALUES (:type, :code, :name) ON DUPLICATE KEY UPDATE name=VALUES(name)");
                foreach ($patch['updates'][$type] as $item) {
                    $item['type'] = $type; // Add the 'type' for the composite key
                    $stmt->execute($item);
                }
            }
        }
        
    }

    // Process Deletions
    if (!empty($patch['deletions'])) {
        echo "Processing deletions...\n";
        $deleteStmts = [
            'Wrapper' => $pdo->prepare("DELETE FROM wrappers WHERE code = ?"),
            'Category' => $pdo->prepare("DELETE FROM categories WHERE code = ?"),
            'Language' => $pdo->prepare("DELETE FROM languages WHERE code = ?"),
            'AIModel' => $pdo->prepare("DELETE FROM ai_models WHERE code = ?"),
            // Add other types as needed
        ];

        foreach ($patch['deletions'] as $item) {
            if (isset($deleteStmts[$item['type']])) {
                $deleteStmts[$item['type']]->execute([$item['code']]);
            }
            // Handle block templates (composite key)
            if (str_ends_with($item['type'], 'BlockTemplate')) {
                $stmt = $pdo->prepare("DELETE FROM block_templates WHERE code = ?");
                $stmt->execute([$item['code']]);
            }
        }
    }

    $pdo->commit();
    echo "Database successfully updated.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    die("Database error: Failed to apply patch. Transaction rolled back. Reason: " . $e->getMessage() . "\n");
}

// 4. Save the new version to the database
saveNewVersion($pdo, $patch['newVersion']);
echo "Sync complete. Local data is now at version " . $patch['newVersion'] . ".\n";

?>