<?php
// FILE: api.php

require 'db.php';
header('Content-Type: application/json');

$zywrapApiKey = "YOUR_ZYWRAP_API_KEY";


// --- Helper Functions ---
function getCategories($pdo) {
    $stmt = $pdo->query("SELECT code, name FROM categories ORDER BY ordering ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getLanguages($pdo) {
    $stmt = $pdo->query("SELECT code, name FROM languages ORDER BY ordering ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getWrappersByCategory($pdo, $categoryCode) {
    $stmt = $pdo->prepare("SELECT code, name, featured, base FROM wrappers WHERE category_code = ? ORDER BY ordering ASC");
    $stmt->execute([$categoryCode]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getBlockTemplates($pdo) {
    $stmt = $pdo->query("SELECT type, code, name FROM block_templates ORDER BY type, name ASC");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by type for easy use on the frontend
    $grouped = [];
    foreach ($results as $row) {
        $grouped[$row['type']][] = ['code' => $row['code'], 'name' => $row['name']];
    }
    return $grouped;
}
function getAiModels($pdo) {
    $stmt = $pdo->query("SELECT code, name FROM ai_models ORDER BY ordering ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function executeZywrapProxy($apiKey, $model, $wrapperCode, $prompt, $language = null, $overrides = []) {
    $url = 'https://api.zywrap.com/v1/proxy'; // Your production API URL
    
    $payloadData = [
        'model' => $model, 
        'wrapperCodes' => [$wrapperCode], 
        'prompt' => $prompt
    ];
    
    if (!empty($language)) {
        $payloadData['language'] = $language;
    }
    if (!empty($overrides)) {
        $payloadData = array_merge($payloadData, $overrides);
    }
    
    $payload = json_encode($payloadData);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $httpCode, 'response' => $response];
}

// --- API Router ---
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_categories':
        echo json_encode(getCategories($pdo));
        break;
    case 'get_languages':
        echo json_encode(getLanguages($pdo));
        break;
    case 'get_wrappers':
        $categoryCode = $_GET['category'] ?? '';
        if (!$categoryCode) {
            echo json_encode([]);
            break;
        }
        echo json_encode(getWrappersByCategory($pdo, $categoryCode));
        break;
    case 'get_block_templates':
        echo json_encode(getBlockTemplates($pdo));
        break;
    case 'get_ai_models':
        echo json_encode(getAiModels($pdo));
        break;
    case 'execute':
        $input = json_decode(file_get_contents('php://input'), true);
        $result = executeZywrapProxy(
            $zywrapApiKey,
            $input['model'],
            $input['wrapperCode'],
            $input['prompt'],
            $input['language'] ?? null,
            $input['overrides'] ?? []
        );
        http_response_code($result['status']);
        echo $result['response'];
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
?>