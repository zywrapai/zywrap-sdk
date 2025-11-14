// FILE: zywrap-sync.js
// USAGE: node zywrap-sync.js

const axios = require('axios'); // npm install axios
const pool = require('./db');

// --- CONFIGURATION ---
const DEVELOPER_API_KEY = 'YOUR_ZYWRAP_API_KEY_HERE';
const ZYWRAP_API_ENDPOINT = 'https://api.zywrap.com/v1/sdk/export/updates';
// ---------------------

async function getCurrentVersion(client) {
    const res = await client.query("SELECT setting_value FROM settings WHERE setting_key = 'data_version'");
    return res.rows[0]?.setting_value;
}

async function saveNewVersion(client, version) {
    await client.query(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', $1) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
        [version]
    );
}

// Block template types from PHP SDK
const blockTypes = ['tones', 'styles', 'formattings', 'complexities', 'lengths', 'outputTypes', 'responseGoals', 'audienceLevels'];

async function main() {
    console.log('Starting Zywrap data sync...');
    const client = await pool.connect();

    try {
        const currentVersion = await getCurrentVersion(client);
        if (!currentVersion) {
            throw new Error("No version found in 'settings' table. Run the full 'import.js' script first.");
        }
        console.log(`Current local version: ${currentVersion}`);

        const response = await axios.get(ZYWRAP_API_ENDPOINT, {
            params: { fromVersion: currentVersion },
            headers: { 'Authorization': `Bearer ${DEVELOPER_API_KEY}` }
        });
        
        const patch = response.data;
        if (!patch || !patch.newVersion) {
             console.log("No new updates found. Local data is already up to date.");
             client.release();
             return;
        }
        
        console.log(`Successfully fetched patch version: ${patch.newVersion}`);

        await client.query('BEGIN');

        // Process Updates/Creations (UPSERT)
        if (patch.updates) {
            // Update Wrappers
            if (patch.updates.wrappers) {
                console.log(`Updating ${patch.updates.wrappers.length} wrapper(s)...`);
                for (const w of patch.updates.wrappers) {
                    await client.query(
                        'INSERT INTO wrappers (code, name, description, category_code, featured, base, updated_at) VALUES ($1, $2, $3, $4, $5, $6, NOW()) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, category_code = EXCLUDED.category_code, featured = EXCLUDED.featured, base = EXCLUDED.base, updated_at = NOW()', 
                        [w.code, w.name, w.desc, w.cat, w.featured, w.base]
                    );
                }
            }
            // Update Categories
            if (patch.updates.categories) {
                console.log(`Updating ${Object.keys(patch.updates.categories).length} category(s)...`);
                for (const [code, c] of Object.entries(patch.updates.categories)) {
                    await client.query(
                        'INSERT INTO categories (code, name, updated_at) VALUES ($1, $2, NOW()) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, updated_at = NOW()', 
                        [code, c.name]
                    );
                }
            }
            // Update Languages
            if (patch.updates.languages) {
                console.log(`Updating ${Object.keys(patch.updates.languages).length} language(s)...`);
                for (const [code, name] of Object.entries(patch.updates.languages)) {
                    await client.query(
                        'INSERT INTO languages (code, name, updated_at) VALUES ($1, $2, NOW()) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, updated_at = NOW()', 
                        [code, name]
                    );
                }
            }
            // Update AI Models
            if (patch.updates.aiModels) {
                console.log(`Updating ${Object.keys(patch.updates.aiModels).length} AI model(s)...`);
                for (const [code, m] of Object.entries(patch.updates.aiModels)) {
                    await client.query(
                        'INSERT INTO ai_models (code, name, provider_id, updated_at) VALUES ($1, $2, $3, NOW()) ON CONFLICT (code) DO UPDATE SET name=EXCLUDED.name, provider_id=EXCLUDED.provider_id, updated_at=NOW()', 
                        [code, m.name, m.provId]
                    );
                }
            }
            
            // Update Block Templates (Correct logic)
            console.log('Checking for block template updates...');
            for (const type of blockTypes) {
                if (patch.updates[type]) {
                    for (const [code, name] of Object.entries(patch.updates[type])) {
                         await client.query(
                            'INSERT INTO block_templates (type, code, name, updated_at) VALUES ($1, $2, $3, NOW()) ON CONFLICT (type, code) DO UPDATE SET name = EXCLUDED.name, updated_at = NOW()',
                            [type, code, name]
                        );
                    }
                }
            }
        }

        // Process Deletions (Correct logic)
        if (patch.deletions?.length) {
            console.log(`Processing ${patch.deletions.length} deletion(s)...`);
            for (const d of patch.deletions) {
                switch(d.type) {
                    case 'Wrapper':
                        await client.query('DELETE FROM wrappers WHERE code = $1', [d.code]);
                        break;
                    case 'Category':
                        await client.query('DELETE FROM categories WHERE code = $1', [d.code]);
                        break;
                    case 'Language':
                        await client.query('DELETE FROM languages WHERE code = $1', [d.code]);
                        break;
                    case 'AIModel':
                        await client.query('DELETE FROM ai_models WHERE code = $1', [d.code]);
                        break;
                    // Note: Block templates are deleted by their type name
                    case (d.type.endsWith('BlockTemplate') ? d.type : null):
                        // This logic assumes d.type is e.g. 'ToneBlockTemplate'
                        // The PHP SDK implies it's just 'Tone' and 'code'
                        // Let's match the PHP SDK's simple logic for block template deletion
                        await client.query('DELETE FROM block_templates WHERE code = $1', [d.code]);
                        break;
                }
            }
        }
        
        await saveNewVersion(client, patch.newVersion);
        await client.query('COMMIT');

        console.log(`\n✅ Sync complete. Local data is now at version ${patch.newVersion}.\n`);
    } catch (e) {
        await client.query('ROLLBACK');
        console.error('Error during sync, transaction rolled back:', e.response?.data || e.message);
        throw e;
    } finally {
        client.release();
    }
}

main().catch(() => process.exit(1));