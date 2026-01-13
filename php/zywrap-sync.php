
<?php

// FILE: zywrap-sync.php
/**
 * Zywrap SDK - Smart Data Synchronizer
 *
 * STRATEGY: "Download & Reconcile"
 * 1. Downloads the latest full data bundle (zip).
 * 2. Updates existing records and inserts new ones.
 * 3. IDENTIFIES AND DELETES records that no longer exist in the bundle.
 *
 * USAGE: php zywrap-sync.php
 */

// --- CONFIGURATION ---
$apiKey = "YOUR_ZYWRAP_API_KEY"; // 🔑 Replace with your actual Key
$apiUrl = 'https://api.zywrap.com/v1/sdk/export/updates'; // Updated Endpoint
require 'db.php'; // 🔌 Your PDO database connection

// --- HELPER: UPSERT BATCH (For Delta Updates) ---
// Inserts new records or Updates existing ones. Does NOT delete.
function upsertBatch(PDO $pdo, string $tableName, array $rows, array $columns, string $pk = 'code') {
    if (empty($rows)) return;
    
    // Prepare column lists
    $colList = implode(", ", $columns);
    $placeholders = implode(", ", array_fill(0, count($columns), "?"));
    
    // ON DUPLICATE KEY UPDATE ...
    $updateClause = [];
    foreach ($columns as $col) {
        if ($col !== $pk && $col !== 'type') { // Don't update Primary Keys
            $updateClause[] = "$col = VALUES($col)";
        }
    }
    $updateSql = implode(", ", $updateClause);
    
    $sql = "INSERT INTO $tableName ($colList) VALUES ($placeholders) 
            ON DUPLICATE KEY UPDATE $updateSql";
    
    $stmt = $pdo->prepare($sql);
    
    $count = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $stmt->execute($row);
            $count++;
        }
        $pdo->commit();
        echo "   [+] Upserted $count records into '$tableName'.<br>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "   [!] Error upserting $tableName: " . $e->getMessage() . "<br>";
    }
}

// --- HELPER: DELETE BATCH (For Explicit Deletions) ---
function deleteBatch(PDO $pdo, string $tableName, array $ids, string $pk = 'code') {
    if (empty($ids)) return;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM $tableName WHERE $pk = ?");
        foreach ($ids as $id) {
            $stmt->execute([$id]);
        }
        $pdo->commit();
        echo "   [-] Deleted " . count($ids) . " records from '$tableName'.<br>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "   [!] Error deleting from $tableName: " . $e->getMessage() . "<br>";
    }
}

// --- HELPER: FULL MIRROR SYNC (For Zip Import) ---
// Upserts everything AND deletes anything in local DB that is missing from the Zip
function syncTableFullMirror(PDO $pdo, string $tableName, array $rows, array $columns, string $pk = 'code') {
    echo "   Wait.. Mirroring '$tableName' (" . count($rows) . " records)...<br>";

    // 1. Get all Local IDs
    $existingIds = [];
    if ($tableName === 'block_templates') {
        $stmt = $pdo->query("SELECT type, code FROM $tableName");
        while ($row = $stmt->fetch()) $existingIds[$row['type'].'|'.$row['code']] = true;
    } else {
        $stmt = $pdo->query("SELECT $pk FROM $tableName");
        $existingIds = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // 2. Upsert Batch
    $colList = implode(", ", $columns);
    $placeholders = implode(", ", array_fill(0, count($columns), "?"));
    $updateClause = [];
    foreach ($columns as $col) if ($col !== $pk && $col !== 'type') $updateClause[] = "$col = VALUES($col)";
    $updateSql = implode(", ", $updateClause);
    
    $stmt = $pdo->prepare("INSERT INTO $tableName ($colList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateSql");

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            // Remove from deletion list (Mark as Seen)
            if ($tableName === 'block_templates') unset($existingIds[$row[0].'|'.$row[1]]);
            else unset($existingIds[$row[0]]);
            
            $stmt->execute($row);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "   [!] Error mirroring $tableName: " . $e->getMessage() . "<br>";
        return;
    }

    // 3. Delete Leftovers
    if (!empty($existingIds)) {
        $pdo->beginTransaction();
        try {
            if ($tableName === 'block_templates') {
                $delStmt = $pdo->prepare("DELETE FROM $tableName WHERE type = ? AND code = ?");
                foreach (array_keys($existingIds) as $key) {
                    [$t, $c] = explode('|', $key);
                    $delStmt->execute([$t, $c]);
                }
            } else {
                $delStmt = $pdo->prepare("DELETE FROM $tableName WHERE $pk = ?");
                foreach (array_keys($existingIds) as $id) $delStmt->execute([$id]);
            }
            $pdo->commit();
            echo "   [-] Cleaned up " . count($existingIds) . " obsolete records.<br>";
        } catch (Exception $e) { $pdo->rollBack(); }
    }
}

// =================================================================
// MAIN LOGIC
// =================================================================

echo "--- 🚀 Starting Zywrap Sync ---<br>";

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'data_version'");
$localVersion = $stmt->fetchColumn();
echo "🔹 Local Version: " . ($localVersion ?: 'None') . "<br>";

// 2. Call API
$url = $apiUrl . '?fromVersion=' . urlencode($localVersion);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
if ($response === false) {
    echo 'cURL Error: ' . curl_error($ch);
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) die("❌ API Error ($httpCode): $response<br>");
$json = json_decode($response, true);
if (!$json) die("❌ Invalid JSON response.<br>");

$mode = $json['mode'] ?? 'UNKNOWN';
echo "🔹 Sync Mode: $mode<br>";

// --- SCENARIO A: FULL RESET (Download Zip) ---
if ($mode === 'FULL_RESET') {
    $zipFile = 'zywrap_temp.zip';
    $jsonFile = 'zywrap-data.json';
    $downloadUrl = $json['wrappers']['downloadUrl'];

    // STEP 1: Attempt Auto Download & Unzip
    echo "⬇️  Attempting automatic download...<br>";
    $downloaded = @file_put_contents($zipFile, fopen($downloadUrl, 'r'));
    
    if ($downloaded) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($zipFile) === TRUE) {
                $zip->extractTo(__DIR__);
                $zip->close();
                echo "✅ Auto-unzip successful.<br>";
            } else {
                echo "⚠️ Downloaded, but failed to unzip (Check permissions).<br>";
            }
        } else {
            echo "⚠️ Downloaded, but 'ZipArchive' class is missing.<br>";
        }
    } else {
        echo "⚠️ Automatic download failed.<br>";
    }

    // STEP 2: Check for JSON file (Auto or Manual)
    if (file_exists($jsonFile)) {
        echo "📦 Found '$jsonFile'. Parsing Map Structure...<br>";
        $data = json_decode(file_get_contents($jsonFile), true);
        
        if ($data) {
            // 1. Categories (MAP: code => {name})
            $rows = []; $ord = 1;
            if (isset($data['categories'])) {
                foreach($data['categories'] as $code => $cat) $rows[] = [$code, $cat['name'], $ord++];
            }
            syncTableFullMirror($pdo, 'categories', $rows, ['code', 'name', 'ordering']);

            // 2. Languages (MAP: code => name)
            $rows = []; $ord = 1;
            if (isset($data['languages'])) {
                foreach($data['languages'] as $code => $name) $rows[] = [$code, $name, $ord++];
            }
            syncTableFullMirror($pdo, 'languages', $rows, ['code', 'name', 'ordering']);

            // 3. AI Models (MAP: code => {name, provId})
            $rows = []; $ord = 1;
            if (isset($data['aiModels'])) {
                foreach($data['aiModels'] as $code => $m) $rows[] = [$code, $m['name'], $m['provId'], $ord++];
            }
            syncTableFullMirror($pdo, 'ai_models', $rows, ['code', 'name', 'provider_id', 'ordering']);

            // 4. Templates (MAP: type => { code => name })
            $rows = [];
            if (isset($data['templates'])) {
                foreach ($data['templates'] as $type => $items) {
                    if (is_array($items)) {
                        foreach ($items as $code => $name) $rows[] = [$type, $code, $name];
                    }
                }
            }
            syncTableFullMirror($pdo, 'block_templates', $rows, ['type', 'code', 'name']);

            // 5. Wrappers (MAP: code => {name, desc, cat, ...})
            $rows = []; $ord = 1;
            if (isset($data['wrappers'])) {
                foreach($data['wrappers'] as $code => $w) {
                    $rows[] = [
                        $code, $w['name'], $w['desc'], $w['cat'], 
                        $w['featured'], $w['base'], $ord++
                    ];
                }
            }
            syncTableFullMirror($pdo, 'wrappers', $rows, ['code', 'name', 'description', 'category_code', 'featured', 'base', 'ordering']);

            // Version
            $newVersion = $json['wrappers']['version'];
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$newVersion]);

            // D. Cleanup
            @unlink($zipFile);
            @unlink($jsonFile);
            echo "🎉 Full Reset Complete.<br>";
        } else {
            echo "❌ Error: '$jsonFile' is empty or invalid JSON.<br>";
        }

    } else {
        // STEP 3: FAILSAFE - Ask User for Help
        echo "❌ AUTOMATIC UPDATE FAILED.<br>";
        echo "------------------------------------------------<br>";
        echo "<br>The system could not download or unzip the update bundle automatically.<br>";
        echo "<br>PLEASE FOLLOW THESE MANUAL STEPS:<br><br>";
        echo "<br>1. Download the data bundle manually:<br>";
        echo "<br>   👉 https://zywrap.com/sdk/download<br>";
        echo "<br>2. Unzip the file ('zywrap-data.json') into this folder:<br>";
        echo "<br>   📂 " . __DIR__ . "<br><br>";
        echo "<br>3. Run this script again.<br>";
        echo "------------------------------------------------<br>";
    }

} 
// --- SCENARIO B: DELTA UPDATE (Using API LIST Structure) ---
elseif ($mode === 'DELTA_UPDATE') {
    
    // 1. Categories (LIST)
    $rows = [];
    foreach(($json['metadata']['categories']??[]) as $r) $rows[] = [$r['code'], $r['name'], $r['ordering']];
    upsertBatch($pdo, 'categories', $rows, ['code', 'name', 'ordering']);

    // 2. Languages (LIST)
    $rows = [];
    foreach(($json['metadata']['languages']??[]) as $r) $rows[] = [$r['code'], $r['name'], $r['ordering']];
    upsertBatch($pdo, 'languages', $rows, ['code', 'name', 'ordering']);

    // 3. AI Models (LIST)
    $rows = [];
    foreach(($json['metadata']['aiModels']??[]) as $r) $rows[] = [$r['code'], $r['name'], $r['provider_id']??null, $r['ordering']];
    upsertBatch($pdo, 'ai_models', $rows, ['code', 'name', 'provider_id', 'ordering']);

    // 4. Templates (MAP of LISTS: type => [ {code, label} ])
    $rows = [];
    if (!empty($json['metadata']['templates'])) {
        foreach ($json['metadata']['templates'] as $type => $items) {
            foreach ($items as $item) $rows[] = [$type, $item['code'], $item['label'] ?? $item['name']];
        }
    }
    upsertBatch($pdo, 'block_templates', $rows, ['type', 'code', 'name']);

    // 5. Wrappers (LIST)
    if (!empty($json['wrappers']['upserts'])) {
        $rows = [];
        foreach($json['wrappers']['upserts'] as $w) {
            $rows[] = [$w['code'], $w['name'], $w['description'], $w['categoryCode'], $w['featured'], $w['base'], $w['ordering']];
        }
        upsertBatch($pdo, 'wrappers', $rows, ['code', 'name', 'description', 'category_code', 'featured', 'base', 'ordering']);
    }

    // 3. Wrappers: Deletes
    if (!empty($json['wrappers']['deletes'])) {
        deleteBatch($pdo, 'wrappers', $json['wrappers']['deletes']);
    }

    // 4. Update Version
    if (!empty($json['newVersion'])) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$json['newVersion']]);
    }
    
    echo "✅ Delta Sync Complete.<br>";
}

?>
