<?php

namespace Zywrap;

use Exception;
use RuntimeException;

class Zywrap
{
    private string $apiKey;
    private string $baseUrl;

    /**
     * Initialize the Zywrap client.
     *
     * @param string $apiKey Your Zywrap API Key.
     * @param string $baseUrl Optional custom proxy endpoint.
     * @throws Exception
     */
    public function __construct(string $apiKey, string $baseUrl = 'https://api.zywrap.com/v1/proxy')
    {
        $apiKey = trim($apiKey);
        if (empty($apiKey)) {
            throw new Exception("Zywrap Initialization Error: API Key is required.");
        }
        
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
    }

    /**
     * Execute a Zywrap AI Wrapper.
     *
     * @param array $params Contains 'model', 'wrapperCodes', 'variables', 'prompt', and 'language'.
     * @return array The decoded JSON response.
     * @throws Exception
     */
    public function execute(array $params): array
    {
        if (empty($params['model']) || empty($params['wrapperCodes']) || !is_array($params['wrapperCodes'])) {
            throw new Exception("Zywrap Execution Error: 'model' and 'wrapperCodes' (array) are required.");
        }

        $payload = [
            'model' => $params['model'],
            'wrapperCodes' => array_values($params['wrapperCodes']),
            'variables' => $params['variables'] ?? new \stdClass(), // Ensure empty array becomes {} in JSON
            'prompt' => $params['prompt'] ?? '',
            'source' => 'php_sdk'
        ];

        if (!empty($params['language'])) {
            $payload['language'] = $params['language'];
        }

        $ch = curl_init($this->baseUrl);
        $jsonPayload = json_encode($payload);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json, text/event-stream',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: Zywrap/PhpSDK/1.0.1',
                'Content-Length: ' . strlen($jsonPayload)
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Zywrap Network Error: " . $error);
        }

        // --- THE FIX: Parse the SSE Stream ---
        $finalJson = null;
        $lines = explode("\n", $response);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, 'data: ') === 0) {
                $jsonStr = substr($line, 6);
                
                if ($jsonStr === '[DONE]') continue;
                
                $parsed = json_decode($jsonStr, true);
                if (is_array($parsed) && (isset($parsed['output']) || isset($parsed['error']))) {
                    $finalJson = $parsed;
                }
            }
        }

        // Fallback for standard JSON if not streaming
        if ($finalJson === null) {
            $finalJson = json_decode($response, true);
        }

        // Total parsing failure fallback
        if ($finalJson === null) {
            $rawSample = substr($response, 0, 250);
            throw new Exception("Failed to parse response. HTTP {$httpCode}. Raw text: '{$rawSample}...'");
        }

        // Handle API-level HTTP errors
        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $finalJson['error'] ?? "HTTP Error " . $httpCode;
            throw new Exception("Zywrap API Error: " . $errorMessage);
        }

        return ['data' => $finalJson, 'status' => $httpCode];
    }
}