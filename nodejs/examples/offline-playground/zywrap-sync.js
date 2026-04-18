
// FILE: zywrap-sync.js
// USAGE: node zywrap-sync.js
// DEPENDENCIES: npm install axios pg adm-zip

const axios = require('axios');
const fs = require('fs');
const AdmZip = require('adm-zip');
const { execSync } = require('child_process');
const { pool } = require('./db.js'); 

// --- CONFIGURATION ---
const API_KEY = 'YOUR_ZYWRAP_API_KEY_HERE'; 
const API_URL = 'https://api.zywrap.com/v1/sdk/v1/sync';

// --- HELPER FUNCTIONS ---

async function upsertBatch(client, tableName, rows, columns, pk = 'code') {
    if (!rows.length) return;
    
    const BATCH_SIZE = 1000; 
    const colNames = columns.map(c => '"' + c + '"').join(', ');
    const updateCols = columns
        .filter(c => c !== pk && c !== 'type')
        .map(c => '"' + c + '" = EXCLUDED."' + c + '"')
        .join(', ');
    const conflictTarget = pk === 'compound_template' ? '(type, code)' : '("' + pk + '")';

    for (let i = 0; i < rows.length; i += BATCH_SIZE) {
        const chunk = rows.slice(i, i + BATCH_SIZE);
        const values = [];
        const rowPlaceholders = [];
        let counter = 1;

        for (const row of chunk) {
            const rowPh = [];
            for (const cell of row) {
                rowPh.push('$' + counter++);
                values.push(cell);
            }
            rowPlaceholders.push('(' + rowPh.join(', ') + ')');
        }

        const query = "INSERT INTO " + tableName + " (" + colNames + ") VALUES " + rowPlaceholders.join(', ') + " ON CONFLICT " + conflictTarget + " DO UPDATE SET " + updateCols;

        try {
            await client.query(query, values);
        } catch (e) {
            console.error("\n   [!] Error upserting batch in " + tableName + ":", e.message);
            throw e; 
        }
    }
    console.log("   [+] Upserted " + rows.length + " records into " + tableName);
}

async function deleteBatch(client, tableName, ids, pk = 'code') {
    if (!ids.length) return;
    const BATCH_SIZE = 2000;
    for (let i = 0; i < ids.length; i += BATCH_SIZE) {
        const chunk = ids.slice(i, i + BATCH_SIZE);
        await client.query('DELETE FROM "' + tableName + '" WHERE "' + pk + '" = ANY($1)', [chunk]);
    }
    console.log("   [-] Deleted " + ids.length + " records from " + tableName);
}

// --- MAIN ---
(async () => {
    console.log('--- 🚀 Starting Zywrap V1 Sync ---');
    const client = await pool.connect();
    
    try {
        const verRes = await client.query("SELECT setting_value FROM settings WHERE setting_key = 'data_version'");
        const localVersion = verRes.rows[0]?.setting_value || '';
        console.log("🔹 Local Version: " + (localVersion || 'None'));

        const response = await axios.get(API_URL, {
            params: { fromVersion: localVersion },
            headers: { 'Authorization': 'Bearer ' + API_KEY }
        });
        
        const json = response.data;
        console.log("🔹 Sync Mode: " + json.mode);

        if (json.mode === 'FULL_RESET') {
            const zipPath = 'zywrap-data.zip';
            const downloadUrl = json.wrappers.downloadUrl;

            console.log("⬇️  Attempting automatic download from Zywrap...");
            
            try {
                const dl = await axios({
                    url: downloadUrl,
                    method: 'GET',
                    responseType: 'arraybuffer',
                    headers: { 'Authorization': 'Bearer ' + API_KEY }
                });

                fs.writeFileSync(zipPath, dl.data);
                const mbSize = (fs.statSync(zipPath).size / (1024 * 1024)).toFixed(2);
                console.log("✅ Data bundle downloaded successfully (" + mbSize + " MB).");
                
                try {
                    console.log('📦 Attempting auto-unzip...');
                    const zip = new AdmZip(zipPath);
                    zip.extractAllTo(__dirname, true);
                    console.log('✅ Auto-unzip successful. Running import script...');
                    
                    fs.unlinkSync(zipPath);
                    
                    // Run the import script automatically
                    execSync('node import.js', { stdio: 'inherit' });
                    
                } catch (zErr) {
                    console.log("⚠️ Failed to auto-unzip (Check directory permissions).");
                    console.log("\n👉 ACTION REQUIRED:");
                    console.log("   1. Please manually unzip '" + zipPath + "' in this folder.");
                    console.log("   2. Then run: node import.js");
                }

            } catch (dlErr) {
                if (fs.existsSync(zipPath)) fs.unlinkSync(zipPath);
                console.log("❌ Automatic download failed. HTTP Status: " + (dlErr.response?.status || 'Unknown'));
            }

        } else if (json.mode === 'DELTA_UPDATE') {
            await client.query('BEGIN');
            const meta = json.metadata || {};

            // Categories
            if (meta.categories) {
                const rows = meta.categories.map(r => [r.code, r.name, r.status ?? true, r.position || r.displayOrder || r.ordering]);
                await upsertBatch(client, 'categories', rows, ['code', 'name', 'status', 'ordering']);
            }

            // Languages
            if (meta.languages) {
                const rows = meta.languages.map(r => [r.code, r.name, r.status ?? true, r.ordering]);
                await upsertBatch(client, 'languages', rows, ['code', 'name', 'status', 'ordering']);
            }

            // AI Models
            if (meta.aiModels) {
                const rows = meta.aiModels.map(r => [r.code, r.name, r.status ?? true, r.displayOrder || r.ordering]);
                await upsertBatch(client, 'ai_models', rows, ['code', 'name', 'status', 'ordering']);
            }

            // Templates
            if (meta.templates) {
                const rows = [];
                for (const [type, items] of Object.entries(meta.templates)) {
                    for (const i of items) rows.push([type, i.code, i.label || i.name, i.status ?? true]);
                }
                await upsertBatch(client, 'block_templates', rows, ['type', 'code', 'name', 'status'], 'compound_template');
            }

            // Use Cases
            if (json.useCases?.upserts?.length) {
                const rows = json.useCases.upserts.map(uc => [
                    uc.code, uc.name, uc.description, uc.categoryCode, 
                    uc.schema ? JSON.stringify(uc.schema) : null, 
                    uc.status ?? true, uc.displayOrder || uc.ordering
                ]);
                await upsertBatch(client, 'use_cases', rows, ['code', 'name', 'description', 'category_code', 'schema_data', 'status', 'ordering']);
            }

            // Wrappers
            if (json.wrappers?.upserts?.length) {
                const rows = json.wrappers.upserts.map(w => [
                    w.code, w.name, w.description, w.useCaseCode || w.categoryCode, 
                    !!(w.featured || w.isFeatured), !!(w.base || w.isBaseWrapper), 
                    w.status ?? true, w.displayOrder || w.ordering
                ]);
                await upsertBatch(client, 'wrappers', rows, ['code', 'name', 'description', 'use_case_code', 'featured', 'base', 'status', 'ordering']);
            }

            // Deletes
            if (json.wrappers?.deletes?.length) await deleteBatch(client, 'wrappers', json.wrappers.deletes);
            if (json.useCases?.deletes?.length) await deleteBatch(client, 'use_cases', json.useCases.deletes);
            
            if (json.newVersion) {
                await client.query("INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', $1) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value", [json.newVersion]);
            }
            
            await client.query('COMMIT');
            console.log('✅ Delta Sync Complete.');
        } else {
            console.log('✅ No updates needed.');
        }

    } catch (e) {
        if (client) await client.query('ROLLBACK');
        console.error('\n❌ Sync Error:', e.message);
    } finally {
        if (client) client.release();
        await pool.end();
    }
})();
