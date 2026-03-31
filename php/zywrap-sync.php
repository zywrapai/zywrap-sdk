
<?php
// FILE: zywrap-sync.php
/**
 * Zywrap V1 SDK - Smart Data Synchronizer
 *
 * STRATEGY: "Download & Reconcile"
 * 1. Downloads the latest full data bundle (zip) if FULL_RESET.
 * 2. Updates existing records and inserts new ones if DELTA_UPDATE.
 *
 * USAGE: php zywrap-sync.php
 */

// Increase execution time and memory limit for large data processing
ini_set('max_execution_time', '300');
ini_set('memory_limit', '512M'); // ✅ FIX: Added limit

// --- CONFIGURATION ---
$apiKey = "YOUR_ZYWRAP_API_KEY"; 
$apiUrl = 'https://api.zywrap.com/v1/sdk/v1/sync'; // V1 Sync Endpoint
require_once 'db.php'; 

// --- HELPER: UPSERT BATCH (For Delta Updates) ---
function upsertBatch(PDO $pdo, string $tableName, array $rows, array $columns, string $pk = 'code') {
    if (empty($rows)) return;
    
    $colList = implode(", ", $columns);
    $placeholders = implode(", ", array_fill(0, count($columns), "?"));
    
    $updateClause = [];
    foreach ($columns as $col) {
        if ($col !== $pk && $col !== 'type') { 
            $updateClause[] = "$col = VALUES($col)";
        }
    }
    $updateSql = implode(", ", $updateClause);
    $sql = "INSERT INTO $tableName ($colList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateSql";
    
    $stmt = $pdo->prepare($sql);
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) $stmt->execute($row);
        $pdo->commit();
        echo "   [+] Upserted " . count($rows) . " records into '$tableName'.\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "   [!] Error upserting $tableName: " . $e->getMessage() . "\n";
    }
}

// --- HELPER: DELETE BATCH ---
function deleteBatch(PDO $pdo, string $tableName, array $ids, string $pk = 'code') {
    if (empty($ids)) return;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM $tableName WHERE $pk = ?");
        foreach ($ids as $id) $stmt->execute([$id]);
        $pdo->commit();
        echo "   [-] Deleted " . count($ids) . " records from '$tableName'.\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "   [!] Error deleting from $tableName: " . $e->getMessage() . "\n";
    }
}

// =================================================================
// MAIN LOGIC
// =================================================================

echo "--- 🚀 Starting Zywrap V1 Sync ---\n";

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'data_version'");
$localVersion = $stmt->fetchColumn() ?: '';
echo "🔹 Local Version: " . ($localVersion ?: 'None') . "\n";

// Call API
$url = $apiUrl . '?fromVersion=' . urlencode($localVersion);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) die("❌ API Error ($httpCode): $response\n");
$json = json_decode($response, true);
if (!$json) die("❌ Invalid JSON response.\n");

$mode = $json['mode'] ?? 'UNKNOWN';
echo "🔹 Sync Mode: $mode\n";

// --- SCENARIO A: FULL RESET ---
if ($mode === 'FULL_RESET') {
    // 🟢 1. Save it with the official name so the user recognizes it
    $zipFile = 'zywrap-data.zip'; 
    $downloadUrl = $json['wrappers']['downloadUrl'];

    echo "⬇️  Attempting automatic download from Zywrap...\n";
    
    $fp = fopen($zipFile, 'w+');
    $chDl = curl_init($downloadUrl);
    curl_setopt($chDl, CURLOPT_TIMEOUT, 300);
    curl_setopt($chDl, CURLOPT_FILE, $fp); 
    curl_setopt($chDl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($chDl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chDl, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $apiKey]);
    
    $success = curl_exec($chDl);
    $httpCodeDl = curl_getinfo($chDl, CURLINFO_HTTP_CODE);
    curl_close($chDl);
    fclose($fp);
    
    // 2. Check if the download succeeded
    if ($httpCodeDl === 200 && $success && file_exists($zipFile) && filesize($zipFile) > 0) {
        
        $mbSize = round(filesize($zipFile) / 1024 / 1024, 2);
        echo "✅ Data bundle downloaded successfully ({$mbSize} MB).\n";

        // 3. Attempt Auto-Unzip
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($zipFile) === TRUE) {
                $zip->extractTo(__DIR__);
                $zip->close();
                echo "✅ Auto-unzip successful. Running import script...\n";
                @unlink($zipFile); // Clean up the zip file after successful extraction
                include 'import.php'; 
            } else {
                // If it fails due to folder permissions
                echo "⚠️ Failed to auto-unzip (Check directory permissions).\n";
                echo "\n👉 ACTION REQUIRED:\n";
                echo "   1. Please manually unzip 'zywrap-data.zip' in this folder.\n";
                echo "   2. Then run: php import.php\n";
            }
        } else {
            // 🟢 4. The graceful fallback if ZipArchive is missing!
            echo "⚠️ PHP 'ZipArchive' extension is missing. Auto-unzip skipped.\n";
            echo "\n👉 ACTION REQUIRED:\n";
            echo "   1. Please manually extract the 'zywrap-data.zip' file into this folder.\n";
            echo "   2. Once extracted, run this command to update your database:\n";
            echo "      php import.php\n";
        }
    } else {
        echo "❌ Automatic download failed. HTTP Status: $httpCodeDl\n";
        @unlink($zipFile); // Clean up broken/empty file
    }
}
// --- SCENARIO B: DELTA UPDATE ---
elseif ($mode === 'DELTA_UPDATE') {
    
    // 1. Categories (Prisma uses 'position' or 'displayOrder')
    $rows = [];
    foreach(($json['metadata']['categories']??[]) as $r) {
        $status = (!isset($r['status']) || $r['status']) ? 1 : 0;
        $rows[] = [$r['code'], $r['name'], $status, $r['position'] ?? $r['displayOrder'] ?? $r['ordering'] ?? null];
    }
    upsertBatch($pdo, 'categories', $rows, ['code', 'name', 'status', 'ordering']);

    // 2. Languages (Manually mapped in backend)
    $rows = [];
    foreach(($json['metadata']['languages']??[]) as $r) {
        $status = (!isset($r['status']) || $r['status']) ? 1 : 0;
        $rows[] = [$r['code'], $r['name'], $status, $r['ordering'] ?? null];
    }
    upsertBatch($pdo, 'languages', $rows, ['code', 'name', 'status', 'ordering']);

    // 3. AI Models (Prisma uses 'providerId' and 'displayOrder')
    $rows = [];
    foreach(($json['metadata']['aiModels']??[]) as $r) {
        $status = (!isset($r['status']) || $r['status']) ? 1 : 0;
        $rows[] = [
            $r['code'], 
            $r['name'], 
            $status,
            $r['displayOrder'] ?? $r['ordering'] ?? null
        ];
    }
    upsertBatch($pdo, 'ai_models', $rows, ['code', 'name', 'status', 'ordering']);

    // 4. Templates
    $rows = [];
    if (!empty($json['metadata']['templates'])) {
        foreach ($json['metadata']['templates'] as $type => $items) {
            foreach ($items as $item) {
                $status = (!isset($item['status']) || $item['status']) ? 1 : 0;
                $rows[] = [$type, $item['code'], $item['label'] ?? $item['name'] ?? null, $status];
            }
        }
    }
    upsertBatch($pdo, 'block_templates', $rows, ['type', 'code', 'name', 'status']);

    // 5. Use Cases & Schemas
    if (!empty($json['useCases']['upserts'])) {
        $rows = [];
        foreach($json['useCases']['upserts'] as $uc) {
            $schemaJson = !empty($uc['schema']) ? json_encode($uc['schema']) : null;
            $status = (!isset($uc['status']) || $uc['status']) ? 1 : 0;
            $rows[] = [
                $uc['code'], 
                $uc['name'], 
                $uc['description'] ?? null, 
                $uc['categoryCode'] ?? null, 
                $schemaJson, 
                $status,
                $uc['displayOrder'] ?? $uc['ordering'] ?? null
            ];
        }
        upsertBatch($pdo, 'use_cases', $rows, ['code', 'name', 'description', 'category_code', 'schema_data', 'status', 'ordering']);
    }

    // 6. Wrappers
    if (!empty($json['wrappers']['upserts'])) {
        $rows = [];
        foreach($json['wrappers']['upserts'] as $w) {
            $featured = !empty($w['featured'] ?? $w['isFeatured']) ? 1 : 0;
            $base = !empty($w['base'] ?? $w['isBaseWrapper']) ? 1 : 0;
            $status = (!isset($w['status']) || $w['status']) ? 1 : 0;
            
            $rows[] = [
                $w['code'], 
                $w['name'], 
                $w['description'] ?? null, 
                $w['useCaseCode'] ?? $w['categoryCode'] ?? null, 
                $featured, 
                $base, 
                $status,
                $w['displayOrder'] ?? $w['ordering'] ?? null
            ];
        }
        upsertBatch($pdo, 'wrappers', $rows, ['code', 'name', 'description', 'use_case_code', 'featured', 'base', 'status', 'ordering']);
    }

    // 7. Deletes
    if (!empty($json['wrappers']['deletes'])) deleteBatch($pdo, 'wrappers', $json['wrappers']['deletes']);
    if (!empty($json['useCases']['deletes'])) deleteBatch($pdo, 'use_cases', $json['useCases']['deletes']);

    // 8. Update Version
    if (!empty($json['newVersion'])) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', ?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$json['newVersion'], $json['newVersion']]);
    }
    
    echo "✅ Delta Sync Complete.\n";
}
?>
