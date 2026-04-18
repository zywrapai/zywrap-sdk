
// FILE: server.js
// A simple Express server to replicate the 'api.php' V1 playground backend.
//
// REQUIREMENTS:
// npm install express cors pg
// (Requires Node 18+ for native fetch)
//
// USAGE:
// 1. Save this as 'server.js'
// 2. Run: node server.js
// 3. Open 'playground.html' in your browser.

const express = require('express');
const cors = require('cors');
const { pool } = require('./db.js');

const app = express();
app.use(cors());
app.use(express.json());

const ZYWRAP_API_KEY = "YOUR_ZYWRAP_API_KEY";
const ZYWRAP_PROXY_URL = 'https://api.zywrap.com/v1/proxy';

// --- Database Helper Functions ---

async function getCategories() {
    const res = await pool.query("SELECT code, name FROM categories WHERE status = TRUE ORDER BY ordering ASC");
    return res.rows;
}

// 🚀 NEW: Fetch Solutions (Use Cases) by Category
async function getUseCases(categoryCode) {
    const res = await pool.query(
        "SELECT code, name FROM use_cases WHERE category_code = $1 AND status = TRUE ORDER BY ordering ASC",
        [categoryCode]
    );
    return res.rows;
}

// 🚀 UPDATED: Fetch Wrappers (Styles) by Use Case
async function getWrappersByUseCase(useCaseCode) {
    const res = await pool.query(
        "SELECT code, name, featured, base FROM wrappers WHERE use_case_code = $1 AND status = TRUE ORDER BY ordering ASC",
        [useCaseCode]
    );
    return res.rows;
}

async function getSchemaByWrapper(wrapperCode) {
    const res = await pool.query(
        "SELECT uc.schema_data FROM use_cases uc JOIN wrappers w ON w.use_case_code = uc.code WHERE w.code = $1 AND w.status = TRUE AND uc.status = TRUE",
        [wrapperCode]
    );
    return res.rows[0] ? res.rows[0].schema_data : null;
}

async function getLanguages() {
    const res = await pool.query("SELECT code, name FROM languages WHERE status = TRUE ORDER BY ordering ASC");
    return res.rows;
}

async function getAiModels() {
    const res = await pool.query("SELECT code, name FROM ai_models WHERE status = TRUE ORDER BY ordering ASC");
    return res.rows;
}

async function getBlockTemplates() {
    const res = await pool.query("SELECT type, code, name FROM block_templates WHERE status = TRUE ORDER BY type, name ASC");
    const grouped = {};
    res.rows.forEach(row => {
        if (!grouped[row.type]) grouped[row.type] = [];
        grouped[row.type].push({ code: row.code, name: row.name });
    });
    return grouped;
}

// ✅ HYBRID PROXY EXECUTION
async function executeZywrapProxy(apiKey, model, wrapperCode, prompt, language, variables = {}, overrides = {}) {
    const payloadData = {
        model: model,
        wrapperCodes: [wrapperCode],
        prompt: prompt,
        variables: variables,
        source: 'node_sdk'
    };
    
    if (language) payloadData.language = language;
    if (Object.keys(overrides).length > 0) Object.assign(payloadData, overrides);
        
    const cleanKey = apiKey.trim();
    
    try {
        const response = await fetch(ZYWRAP_PROXY_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + cleanKey,
                'User-Agent': 'ZywrapNodeSDK/1.1'
            },
            body: JSON.stringify(payloadData)
        });

        if (!response.ok) {
            const errText = await response.text();
            try { return { status: response.status, data: JSON.parse(errText) }; }
            catch (e) { return { status: response.status, data: { error: errText } }; }
        }

        // Parse SSE Stream
        const text = await response.text();
        const lines = text.split('\n');
        let finalJson = null;
        
        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed.startsWith('data: ')) {
                const jsonStr = trimmed.substring(6);
                try {
                    const data = JSON.parse(jsonStr);
                    if (data && (data.output || data.error)) finalJson = data;
                } catch (e) { /* ignore parse errors on partial streams */ }
            }
        }

        if (finalJson) {
            return { status: finalJson.error ? 400 : 200, data: finalJson };
        } else {
            return { status: 500, data: { error: 'Stream parse failed' } };
        }

    } catch (e) {
        return { status: 500, data: { error: e.message } };
    }
}

// --- API Router ---
app.all('/api', async (req, res) => {
    try {
        const action = req.query.action || (req.body && req.body.action);

        if (req.method === 'GET') {
            if (action === 'get_categories') return res.json(await getCategories());
            if (action === 'get_use_cases') return res.json(await getUseCases(req.query.category));
            if (action === 'get_wrappers') return res.json(await getWrappersByUseCase(req.query.usecase));
            if (action === 'get_languages') return res.json(await getLanguages());
            if (action === 'get_ai_models') return res.json(await getAiModels());
            if (action === 'get_block_templates') return res.json(await getBlockTemplates());
            if (action === 'get_schema') return res.json(await getSchemaByWrapper(req.query.wrapper));
        }

        if (req.method === 'POST') {
            if (action === 'execute') {
                const startTime = Date.now();
                const inputData = req.body;
                
                const executionResult = await executeZywrapProxy(
                    ZYWRAP_API_KEY,
                    inputData.model,
                    inputData.wrapperCode || '',
                    inputData.prompt || '',
                    inputData.language,
                    inputData.variables || {},
                    inputData.overrides || {}
                );
                
                const status = executionResult.status;
                const result = executionResult.data;
                const latencyMs = Date.now() - startTime;

                // Async Logging
                try {
                    const statusText = status === 200 ? 'success' : 'error';
                    const traceId = result.id || null;
                    
                    const usage = result.usage || {};
                    const pTokens = usage.prompt_tokens || 0;
                    const cTokens = usage.completion_tokens || 0;
                    const tTokens = usage.total_tokens || 0;
                    
                    const creditsUsed = (result.cost || {}).credits_used || 0;
                    let errorMessage = statusText === 'error' ? result.error : null;
                    
                    if (errorMessage) {
                        const errMsgStr = String(errorMessage);
                        errorMessage = errMsgStr.length > 255 ? errMsgStr.substring(0, 252) + '...' : errMsgStr;
                    }

                    await pool.query(
                        "INSERT INTO usage_logs (trace_id, wrapper_code, model_code, prompt_tokens, completion_tokens, total_tokens, credits_used, latency_ms, status, error_message) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)",
                        [traceId, inputData.wrapperCode, inputData.model || 'default', pTokens, cTokens, tTokens, creditsUsed, latencyMs, statusText, errorMessage]
                    );
                } catch (logErr) {
                    console.error("Failed to write to usage_logs:", logErr.message);
                }

                return res.status(status).json(result);
            }
        }
        
        return res.status(400).json({ error: 'Invalid action' });

    } catch (e) {
        return res.status(500).json({ error: e.message });
    }
});

const PORT = 5000;
app.listen(PORT, () => {
    console.log("Zywrap Node SDK Playground backend listening at http://localhost:" + PORT);
});
