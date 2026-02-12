// FILE: app.js
// A simple Express server to replicate the 'api.php' playground backend.
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
const pool = require('./db'); // Your database connection pool

const app = express();
const port = 3000;

app.use(cors()); // Enable Cross-Origin Resource Sharing
app.use(express.json()); // To parse JSON bodies

const ZYWRAP_API_KEY = "YOUR_ZYWRAP_API_KEY";
const ZYWRAP_PROXY_URL = 'https://api.zywrap.com/v1/proxy';

// --- Database Helper Functions ---

async function getCategories(client) {
    const { rows } = await client.query("SELECT code, name FROM categories ORDER BY ordering ASC");
    return rows;
}

async function getLanguages(client) {
    const { rows } = await client.query("SELECT code, name FROM languages ORDER BY ordering ASC");
    return rows;
}

async function getWrappersByCategory(client, categoryCode) {
    const { rows } = await client.query(
        "SELECT code, name, featured, base FROM wrappers WHERE category_code = $1 ORDER BY ordering ASC",
        [categoryCode]
    );
    return rows;
}

async function getBlockTemplates(client) {
    const { rows } = await client.query("SELECT type, code, name FROM block_templates ORDER BY type, name ASC");
    // Group by type for easy use on the frontend
    const grouped = {};
    for (const row of rows) {
        if (!grouped[row.type]) grouped[row.type] = [];
        grouped[row.type].push({ code: row.code, name: row.name });
    }
    return grouped;
}

async function getAiModels(client) {
    const { rows } = await client.query("SELECT code, name FROM ai_models ORDER BY ordering ASC");
    return rows;
}

async function executeZywrapProxy(apiKey, model, wrapperCode, prompt, language = null, overrides = {}) {
    const payloadData = {
        model,
        wrapperCodes: [wrapperCode],
        prompt
    };
    
    if (language) payloadData.language = language;
    if (overrides) Object.assign(payloadData, overrides);
    
    const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${apiKey}`
    };

    try {
        // We use responseType: 'text' to get the raw stream data string
        // This blocks until the stream finishes (similar to PHP curl_exec)
        const response = await axios.post(ZYWRAP_PROXY_URL, payloadData, { 
            headers,
            responseType: 'text', 
            timeout: 300000 // 5 minutes
        });

        // SUCCESS (200): Parse the text/event-stream
        // Response string looks like: "data: {...}\n\n: keep-alive..."
        const lines = response.data.split('\n');
        let finalJson = null;

        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed.startsWith('data: ')) {
                const jsonStr = trimmed.substring(6); // remove "data: "
                try {
                    const data = JSON.parse(jsonStr);
                    if (data && (data.output || data.error)) {
                        finalJson = data;
                    }
                } catch (e) {
                    // Ignore keep-alive lines or partial chunks
                }
            }
        }

        if (finalJson) {
            return { status: 200, data: finalJson };
        } else {
            return { status: 500, data: { error: 'Failed to parse streaming response from Zywrap.' } };
        }

    } catch (error) {
        // ERROR (4xx/5xx): The body is already standard JSON
        const status = error.response?.status || 500;
        let errorData = { error: error.message };

        if (error.response?.data) {
            // If responseType is 'text', axios returns string even for JSON errors
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
// This single route mimics the 'api.php?action=...' behavior
app.all('/api.php', async (req, res) => {
    const client = await pool.connect();
    try {
        if (req.method === 'GET') {
            const { action, category } = req.query;
            switch (action) {
                case 'get_categories':
                    return res.json(await getCategories(client));
                case 'get_languages':
                    return res.json(await getLanguages(client));
                case 'get_ai_models':
                    return res.json(await getAiModels(client));
                case 'get_block_templates':
                    return res.json(await getBlockTemplates(client));
                case 'get_wrappers':
                    return res.json(await getWrappersByCategory(client, category));
                default:
                    return res.status(400).json({ error: 'Invalid action' });
            }
        }

        if (req.method === 'POST') {
            const { action, model, wrapperCode, prompt, language, overrides } = req.body;
            
            if (action === 'execute') {
                const { data, status } = await executeZywrapProxy(
                    ZYWRAP_API_KEY,
                    model,
                    wrapperCode,
                    prompt,
                    language,
                    overrides
                );
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
