
<?php
// FILE: download-bundle.php
// USAGE: php download-bundle.php

$apiKey = 'YOUR_API_KEY';
$apiUrl = 'https://api.zywrap.com/v1/sdk/v1/download'; // V1 Download URL
$outputFile = 'zywrap-data.zip';

echo "Downloading latest V1 wrapper data from Zywrap...\n";

$fp = fopen($outputFile, 'w+');
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
curl_setopt($ch, CURLOPT_FILE, $fp); // Let cURL write directly to the file
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Add this to prevent SSL errors
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
]);

// $success will be true/false, NOT the file contents!
$success = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Close curl AND close the file pointer to save it to disk!
curl_close($ch);
fclose($fp);

if ($httpCode !== 200 || !$success) {
    @unlink($outputFile); // ✅ FIX: Delete corrupted text file masquerading as ZIP on error
    die("Error: Failed to download file. Status code: {$httpCode}\n");
}

echo "✅ Sync complete. Data saved to {$outputFile}.\n";
echo "Run 'unzip {$outputFile}' to extract the data, then run 'php import.php'.\n";
?>
