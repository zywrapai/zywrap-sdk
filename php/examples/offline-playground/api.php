
<?php
// FILE: api.php
ini_set('max_execution_time', '300');
require 'db.php';
header('Content-Type: application/json');

$zywrapApiKey = "YOUR_ZYWRAP_API_KEY";

// --- V1 Backend Logic ---

function getCategories($pdo) {
    return $pdo->query("SELECT code, name FROM categories WHERE status = 1 ORDER BY ordering ASC")->fetchAll();
}

// 🚀 NEW: Fetch Solutions (Use Cases) by Category
function getUseCasesByCategory($pdo, $categoryCode) {
    $stmt = $pdo->prepare("
        SELECT code, name 
        FROM use_cases 
        WHERE category_code = ? AND status = 1 
        ORDER BY ordering ASC
    ");
    $stmt->execute([$categoryCode]);
    return $stmt->fetchAll();
}

// 🚀 UPDATED: Fetch Wrappers (Styles) by Use Case
function getWrappersByUseCase($pdo, $useCaseCode) {
    $stmt = $pdo->prepare("
        SELECT code, name, featured, base 
        FROM wrappers 
        WHERE use_case_code = ? AND status = 1
        ORDER BY ordering ASC
    ");
    $stmt->execute([$useCaseCode]);
    return $stmt->fetchAll();
}

function getSchemaByWrapper($pdo, $wrapperCode) {
    $stmt = $pdo->prepare("
        SELECT uc.schema_data 
        FROM use_cases uc 
        JOIN wrappers w ON w.use_case_code = uc.code 
        WHERE w.code = ?
    ");
    $stmt->execute([$wrapperCode]);
    $result = $stmt->fetchColumn();
    return $result ? json_decode($result, true) : null;
}

function getLanguages($pdo) { 
    return $pdo->query("SELECT code, name FROM languages WHERE status = 1 ORDER BY ordering ASC")->fetchAll(); 
}

function getAiModels($pdo) { 
    return $pdo->query("SELECT code, name FROM ai_models WHERE status = 1 ORDER BY ordering ASC")->fetchAll(); 
}

function getBlockTemplates($pdo) {
    $stmt = $pdo->query("SELECT type, code, name FROM block_templates WHERE status = 1 ORDER BY type, name ASC");
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[$row['type']][] = ['code' => $row['code'], 'name' => $row['name']];
    }
    return $grouped;
}

// --- Execution ---
function executeZywrapProxy($apiKey, $model, $wrapperCode, $prompt, $language, $variables, $overrides) {
    $url = 'https://api.zywrap.com/v1/proxy'; 
    
    $payloadData = [
        'model' => $model, 
        'wrapperCodes' => [$wrapperCode], 
        'prompt' => $prompt,
        'variables' => $variables,
        'source' => 'php_sdk'
    ];
    if (!empty($language)) $payloadData['language'] = $language;
    if (!empty($overrides)) $payloadData = array_merge($payloadData, $overrides);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadData));
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $lines = explode("\n", $rawResponse);
        $finalJson = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'data: ') === 0) {
                $data = json_decode(substr($line, 6), true);
                if ($data && (isset($data['output']) || isset($data['error']))) $finalJson = substr($line, 6);
            }
        }
        
        $statusCode = 200;
        if ($finalJson) {
            $parsed = json_decode($finalJson, true);
            if (isset($parsed['error'])) $statusCode = 400;
        }
        
        return ['status' => $statusCode, 'response' => $finalJson ?: json_encode(['error' => 'Stream parse failed'])];
    }
    return ['status' => $httpCode, 'response' => $rawResponse];
}

// --- API Router ---
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_categories': echo json_encode(getCategories($pdo)); break;
    case 'get_use_cases': echo json_encode(getUseCasesByCategory($pdo, $_GET['category'] ?? '')); break; // 🚀 NEW
    case 'get_wrappers': echo json_encode(getWrappersByUseCase($pdo, $_GET['usecase'] ?? '')); break; // 🚀 UPDATED
    case 'get_schema': echo json_encode(getSchemaByWrapper($pdo, $_GET['wrapper'] ?? '')); break;
    case 'get_languages': echo json_encode(getLanguages($pdo)); break;
    case 'get_ai_models': echo json_encode(getAiModels($pdo)); break;
    case 'get_block_templates': echo json_encode(getBlockTemplates($pdo)); break;
    
    case 'execute':
        $input = json_decode(file_get_contents('php://input'), true);
        
        $startTime = microtime(true);
        $result = executeZywrapProxy(
            $zywrapApiKey, 
            $input['model'] ?? null, 
            $input['wrapperCode'] ?? '',
            $input['prompt'] ?? '', 
            $input['language'] ?? null, 
            $input['variables'] ?? [],
            $input['overrides'] ?? []
        );
        $latencyMs = round((microtime(true) - $startTime) * 1000);

        // --- LOGGING ---
        try {
            $status = $result['status'] === 200 ? 'success' : 'error';
            $responseData = json_decode($result['response'], true);
            
            $traceId = $responseData['id'] ?? null;
            $promptTokens = $responseData['usage']['prompt_tokens'] ?? 0;
            $completionTokens = $responseData['usage']['completion_tokens'] ?? 0;
            $totalTokens = $responseData['usage']['total_tokens'] ?? 0;
            $creditsUsed = $responseData['cost']['credits_used'] ?? 0;
            
            $fallbackError = substr($result['response'], 0, 255) . (strlen($result['response']) > 255 ? '...' : '');
            $errorMessage = $status === 'error' ? ($responseData['error'] ?? $fallbackError) : null;

            $stmt = $pdo->prepare("INSERT INTO usage_logs (trace_id, wrapper_code, model_code, prompt_tokens, completion_tokens, total_tokens, credits_used, latency_ms, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$traceId, $input['wrapperCode'], $input['model'] ?? 'default', $promptTokens, $completionTokens, $totalTokens, $creditsUsed, $latencyMs, $status, $errorMessage]);
        } catch (Exception $e) {
            error_log("Failed to write to usage_logs: " . $e->getMessage());
        }

        http_response_code($result['status']);
        echo $result['response'];
        break;
}
?>
