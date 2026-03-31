
// FILE: app.js
// A simple Express server to replicate the 'api.php' V1 playground backend.
//
// REQUIREMENTS:
// npm install express pg axios cors
//
// USAGE:
// 1. Save this as 'app.js'
// 2. Run: node app.js
// 3. Open 'playground.html' in your browser.

const express = require('express');
const cors = require('cors');
const axios = require('axios');
const pool = require('./db'); 

const app = express();
const port = 3000;

app.use(cors()); 
app.use(express.json());

const ZYWRAP_API_KEY = "YOUR_ZYWRAP_API_KEY";
const ZYWRAP_PROXY_URL = 'https://api.zywrap.com/v1/proxy';

// --- Database Helper Functions ---

async function getCategories(client) {
    const { rows } = await client.query("SELECT code, name FROM categories WHERE status = TRUE ORDER BY ordering ASC");
    return rows;
}

async function getLanguages(client) {
    const { rows } = await client.query("SELECT code, name FROM languages WHERE status = TRUE ORDER BY ordering ASC");
    return rows;
}

async function getAiModels(client) {
    const { rows } = await client.query("SELECT code, name FROM ai_models WHERE status = TRUE ORDER BY ordering ASC");
    return rows;
}

async function getBlockTemplates(client) {
    const { rows } = await client.query("SELECT type, code, name FROM block_templates WHERE status = TRUE ORDER BY type, name ASC");
    const grouped = {};
    for (const row of rows) {
        if (!grouped[row.type]) grouped[row.type] = [];
        grouped[row.type].push({ code: row.code, name: row.name });
    }
    return grouped;
}

async function getWrappersByCategory(client, categoryCode) {
    const { rows } = await client.query(
        `SELECT w.code, w.name, w.featured, w.base 
         FROM wrappers w 
         JOIN use_cases uc ON w.use_case_code = uc.code 
         WHERE uc.category_code = $1 AND w.status = TRUE AND uc.status = TRUE
         ORDER BY w.ordering ASC`,
        [categoryCode]
    );
    return rows;
}

async function getSchemaByWrapper(client, wrapperCode) {
    const { rows } = await client.query(
        `SELECT uc.schema_data 
         FROM use_cases uc 
         JOIN wrappers w ON w.use_case_code = uc.code 
         WHERE w.code = $1 AND w.status = TRUE AND uc.status = TRUE`,
        [wrapperCode]
    );
    return rows.length > 0 ? rows[0].schema_data : null;
}

// ✅ HYBRID PROXY EXECUTION
async function executeZywrapProxy(apiKey, model, wrapperCode, prompt, language = null, variables = {}, overrides = {}) {
    const payloadData = {
        model,
        wrapperCodes: [wrapperCode],
        prompt,
        variables,
        source: 'node_sdk' 
    };
    
    if (language) payloadData.language = language;
    if (overrides) Object.assign(payloadData, overrides);
    
    const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiKey.trim()}`,
        'User-Agent': 'ZywrapNodeSDK/1.1'
    };

    try {
        const response = await axios.post(ZYWRAP_PROXY_URL, payloadData, { 
            headers,
            responseType: 'text', 
            timeout: 300000 
        });

        const lines = response.data.split('\n');
        let finalJson = null;

        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed.startsWith('data: ')) {
                const jsonStr = trimmed.substring(6);
                try {
                    const data = JSON.parse(jsonStr);
                    if (data && (data.output || data.error)) {
                        finalJson = data;
                    }
                } catch (e) { }
            }
        }
        
        let statusCode = 200;
        if (finalJson && finalJson.error) {
            statusCode = 400;
        }

        return { status: statusCode, data: finalJson || { error: 'Failed to parse streaming response from Zywrap.' } };

    } catch (error) {
        const status = error.response?.status || 500;
        let errorData = { error: error.message };

        if (error.response?.data) {
            try {
                errorData = typeof error.response.data === 'string' 
                    ? JSON.parse(error.response.data) 
                    : error.response.data;
            } catch (e) {
                errorData = { error: error.response.data };
            }
        }
        return { status, data: errorData };
    }
}

// --- API Router ---
app.all('/api', async (req, res) => {
    const client = await pool.connect();
    try {
        if (req.method === 'GET') {
            const { action, category, wrapper } = req.query;
            switch (action) {
                case 'get_categories': return res.json(await getCategories(client));
                case 'get_languages': return res.json(await getLanguages(client));
                case 'get_ai_models': return res.json(await getAiModels(client));
                case 'get_block_templates': return res.json(await getBlockTemplates(client));
                case 'get_wrappers': return res.json(await getWrappersByCategory(client, category));
                case 'get_schema': return res.json(await getSchemaByWrapper(client, wrapper));
                default: return res.status(400).json({ error: 'Invalid action' });
            }
        }

        if (req.method === 'POST') {
            const { model, wrapperCode, prompt, language, variables, overrides } = req.body;
            
            const action = req.query.action || req.body.action; 
            
            if (action === 'execute') {
                // ⏱️ Start Local Timer
                const startTime = Date.now();
                
                const { data, status } = await executeZywrapProxy(
                    ZYWRAP_API_KEY, model, wrapperCode || '', prompt, language, variables, overrides
                );
                
                // ⏱️ End Local Timer
                const latencyMs = Date.now() - startTime;

                // --- 📝 LOGGING TO LOCAL DATABASE ---
                try {
                    const statusText = status === 200 ? 'success' : 'error';
                    const traceId = data.id || null;
                    const pTokens = data.usage?.prompt_tokens || 0;
                    const cTokens = data.usage?.completion_tokens || 0;
                    const tTokens = data.usage?.total_tokens || 0;
                    const creditsUsed = data.cost?.credits_used || 0;
                    
                    const rawErrorMsg = statusText === 'error' ? (data.error || 'Unknown Error') : null;
                    const errMsgStr = typeof rawErrorMsg === 'string' ? rawErrorMsg : JSON.stringify(rawErrorMsg);
                    const errMsg = errMsgStr ? (errMsgStr.length > 255 ? errMsgStr.substring(0, 255) + '...' : errMsgStr) : null;

                    await client.query(
                        `INSERT INTO usage_logs 
                        (trace_id, wrapper_code, model_code, prompt_tokens, completion_tokens, total_tokens, credits_used, latency_ms, status, error_message) 
                        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
                        [traceId, wrapperCode, model || 'default', pTokens, cTokens, tTokens, creditsUsed, latencyMs, statusText, errMsg]
                    );
                } catch (logErr) {
                    console.error('Failed to write to usage_logs:', logErr.message);
                }

                return res.status(status).json(data);
            }
            return res.status(400).json({ error: 'Invalid action' });
        }
    } catch (e) {
        console.error(e);
        res.status(500).json({ error: e.message });
    } finally {
        client.release();
    }
});

app.listen(port, () => {
    console.log(`Zywrap Node.js SDK Playground backend listening at http://localhost:${port}`);
});
