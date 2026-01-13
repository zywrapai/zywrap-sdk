
// FILE: zywrap-sync.js
// USAGE: node zywrap-sync.js
// DEPENDENCIES: npm install axios pg adm-zip

const axios = require('axios');
const { Pool } = require('pg');
const fs = require('fs');
const path = require('path');
const AdmZip = require('adm-zip');
const pool = require('./db'); 

// --- CONFIGURATION ---
const API_KEY = 'YOUR_ZYWRAP_API_KEY_HERE'; // Your Actual Key
const API_URL = 'https://api.zywrap.com/v1/sdk/export/updates';

// --- HELPER FUNCTIONS ---

/**
 * Upserts a batch of records. (Used for Delta Updates & Mirror Sync)
 */
async function upsertBatch(client, tableName, rows, columns, pk = 'code') {
    if (!rows.length) return;
    
    // Batch size (Safety limit for Postgres params is ~65k. 1000 rows * 10 cols = 10k params, which is safe)
    const BATCH_SIZE = 1000; 

    // 1. Prepare Query Parts (Constant for all batches)
    const colNames = columns.map(c => `"${c}"`).join(', ');
    const updateCols = columns
        .filter(c => c !== pk && c !== 'type')
        .map(c => `"${c}" = EXCLUDED."${c}"`)
        .join(', ');
    const conflictTarget = pk === 'compound_template' ? '(type, code)' : `("${pk}")`;

    console.log(`   [+] Upserting ${rows.length} records into '${tableName}'...`);

    // 2. Loop through chunks
    for (let i = 0; i < rows.length; i += BATCH_SIZE) {
        const chunk = rows.slice(i, i + BATCH_SIZE);
        const values = [];
        const rowPlaceholders = [];
        let counter = 1;

        for (const row of chunk) {
            const rowPh = [];
            for (const cell of row) {
                rowPh.push(`$${counter++}`);
                values.push(cell);
            }
            rowPlaceholders.push(`(${rowPh.join(', ')})`);
        }

        const query = `
            INSERT INTO "${tableName}" (${colNames}) 
            VALUES ${rowPlaceholders.join(', ')}
            ON CONFLICT ${conflictTarget} 
            DO UPDATE SET ${updateCols}
        `;

        try {
            await client.query(query, values);
            // Show a dot for every batch to indicate liveliness
            process.stdout.write('.'); 
        } catch (e) {
            console.error(`\n   [!] Error upserting batch in ${tableName}:`, e.message);
            throw e; // Critical: Stop transaction on error
        }
    }
    console.log(`\n   [✓] Finished upserting '${tableName}'.`);
}

/**
 * Deletes specific IDs. (Used for Delta Updates)
 */
async function deleteBatch(client, tableName, ids, pk = 'code') {
    if (!ids.length) return;
    
    const BATCH_SIZE = 2000;
    console.log(`   [-] Deleting ${ids.length} records from '${tableName}'...`);

    for (let i = 0; i < ids.length; i += BATCH_SIZE) {
        const chunk = ids.slice(i, i + BATCH_SIZE);
        try {
            await client.query(`DELETE FROM "${tableName}" WHERE "${pk}" = ANY($1)`, [chunk]);
            process.stdout.write('.');
        } catch (e) {
            console.error(`\n   [!] Error deleting batch in ${tableName}:`, e.message);
            throw e;
        }
    }
    console.log(`\n   [✓] Finished deletion.`);
}

/**
 * Mirror Sync: Upserts everything provided, then deletes anything local that wasn't in the provided list.
 */
async function syncTableFullMirror(client, tableName, rows, columns, pk = 'code') {
    console.log(`   Mirroring '${tableName}' (${rows.length} records)...`);
    
    // 1. Upsert everything first (Chunking handled inside upsertBatch)
    await upsertBatch(client, tableName, rows, columns, pk);

    // 2. Get all Local IDs to find deletions
    const isComposite = pk === 'compound_template';
    const idQuery = isComposite ? 'SELECT type, code FROM block_templates' : `SELECT "${pk}" FROM "${tableName}"`;
    const res = await client.query(idQuery);
    
    const localIds = new Set();
    res.rows.forEach(r => {
        const key = isComposite ? `${r.type}|${r.code}` : r[pk];
        localIds.add(key);
    });

    // 3. Get Incoming IDs
    const incomingIds = new Set();
    for (const row of rows) {
        // Assumes row structure: [PK, ...] or [Type, Code, ...]
        const key = isComposite ? `${row[0]}|${row[1]}` : row[0];
        incomingIds.add(key);
    }

    // 4. Calculate Difference
    const toDelete = [...localIds].filter(x => !incomingIds.has(x));

    // 5. Delete Obsolete
    if (toDelete.length > 0) {
        console.log(`   [-] Cleaning up ${toDelete.length} obsolete records...`);
        if (isComposite) {
            // Composite keys are tricky to batch delete via standard SQL arrays, loop is safer for moderate sizes
            for (const combo of toDelete) {
                const [t, c] = combo.split('|');
                await client.query('DELETE FROM block_templates WHERE type = $1 AND code = $2', [t, c]);
            }
        } else {
            await deleteBatch(client, tableName, toDelete, pk);
        }
    }
}

async function processMetadata(client, data, mode) {
    const syncFunc = mode === 'FULL_RESET' ? syncTableFullMirror : upsertBatch;

    // Categories
    let catRows = [];
    if (Array.isArray(data.categories)) catRows = data.categories.map(r => [r.code, r.name, r.ordering]);
    else if (data.categories) {
        let ord = 1;
        catRows = Object.entries(data.categories).map(([k, v]) => [k, v.name, ord++]);
    }
    await syncFunc(client, 'categories', catRows, ['code', 'name', 'ordering']);

    // Languages
    let langRows = [];
    if (Array.isArray(data.languages)) langRows = data.languages.map(r => [r.code, r.name, r.ordering]);
    else if (data.languages) {
        let ord = 1;
        langRows = Object.entries(data.languages).map(([k, v]) => [k, v, ord++]);
    }
    await syncFunc(client, 'languages', langRows, ['code', 'name', 'ordering']);

    // AI Models
    let aiRows = [];
    if (Array.isArray(data.aiModels)) aiRows = data.aiModels.map(r => [r.code, r.name, r.provider_id || null, r.ordering]);
    else if (data.aiModels) {
        let ord = 1;
        aiRows = Object.entries(data.aiModels).map(([k, v]) => [k, v.name, v.provId, ord++]);
    }
    await syncFunc(client, 'ai_models', aiRows, ['code', 'name', 'provider_id', 'ordering']);

    // Templates
    let tplRows = [];
    if (data.templates) {
        for (const [type, items] of Object.entries(data.templates)) {
            if (Array.isArray(items)) items.forEach(i => tplRows.push([type, i.code, i.label || i.name]));
            else for (const [c, n] of Object.entries(items)) tplRows.push([type, c, n]);
        }
    }
    await syncTableFullMirror(client, 'block_templates', tplRows, ['type', 'code', 'name'], 'compound_template');
}

// --- MAIN ---
(async () => {
    console.log('--- 🚀 Starting Zywrap Sync ---');
    const client = await pool.connect();
    
    try {
        const verRes = await client.query("SELECT setting_value FROM settings WHERE setting_key = 'data_version'");
        const localVersion = verRes.rows[0]?.setting_value;
        console.log(`🔹 Local Version: ${localVersion || 'None'}`);

        const response = await axios.get(API_URL, {
            params: { fromVersion: localVersion },
            headers: { 'Authorization': `Bearer ${API_KEY}` }
        });
        
        const json = response.data;
        console.log(`🔹 Sync Mode: ${json.mode}`);

        if (json.mode === 'FULL_RESET') {
            const zipPath = 'zywrap_temp.zip';
            const jsonPath = 'zywrap-data.json';
            const downloadUrl = json.wrappers.downloadUrl;

            console.log(`⬇️  Downloading bundle...`);
            
            const dl = await axios({
                url: downloadUrl,
                method: 'GET',
                responseType: 'arraybuffer',
                headers: { 'Authorization': `Bearer ${API_KEY}` }
            });

            const contentType = dl.headers['content-type'];
            if (contentType && (contentType.includes('application/json') || contentType.includes('text/html'))) {
                console.error("❌ Download Failed: Server returned text/json instead of zip.");
                return;
            }

            fs.writeFileSync(zipPath, dl.data);
            
            console.log('📦 Unzipping...');
            const zip = new AdmZip(zipPath);
            zip.extractAllTo(__dirname, true);

            if (fs.existsSync(jsonPath)) {
                console.log('📦 Parsing JSON...');
                const fData = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));

                await client.query('BEGIN');
                await processMetadata(client, fData, 'FULL_RESET');

                let wRows = [];
                let ord = 1;
                if (fData.wrappers) {
                    for (const [code, w] of Object.entries(fData.wrappers)) {
                        wRows.push([code, w.name, w.desc, w.cat, w.featured, w.base, ord++]);
                    }
                }
                
                // This call previously failed. Now passing through batched upsert.
                await syncTableFullMirror(client, 'wrappers', wRows, 
                    ['code', 'name', 'description', 'category_code', 'featured', 'base', 'ordering']);

                const newVersion = json.wrappers.version;
                await client.query("INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', $1) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value", [newVersion]);
                await client.query('COMMIT');
                
                fs.unlinkSync(zipPath);
                fs.unlinkSync(jsonPath);
                console.log('\n🎉 Full Reset Complete.');
            } else {
                console.error('❌ Error: JSON file not found inside ZIP.');
            }

        } else if (json.mode === 'DELTA_UPDATE') {
            await client.query('BEGIN');
            await processMetadata(client, json.metadata, 'DELTA_UPDATE');

            if (json.wrappers.upserts?.length) {
                const wRows = json.wrappers.upserts.map(w => [w.code, w.name, w.description, w.categoryCode, w.featured, w.base, w.ordering]);
                await upsertBatch(client, 'wrappers', wRows, ['code', 'name', 'description', 'category_code', 'featured', 'base', 'ordering']);
            }
            if (json.wrappers.deletes?.length) {
                await deleteBatch(client, 'wrappers', json.wrappers.deletes);
            }
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
