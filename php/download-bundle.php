<?php
// FILE: download-bundle.php
// USAGE: php download-bundle.php

$apiKey = 'YOUR_API_KEY';
$apiUrl = 'https://api.zywrap.com/v1/sdk/download';
$outputFile = 'zywrap-data.zip';

echo "Downloading latest wrapper data from Zywrap...\\n";

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
]);

$zipData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("Error: Failed to download file. Status code: {$httpCode}\\nResponse: {$zipData}\\n");
}

file_put_contents($outputFile, $zipData);

echo "✅ Sync complete. Data saved to {$outputFile}.\\n";
echo "Run 'unzip {$outputFile}' to extract the data, then run 'php import.php'.\\n";
?>