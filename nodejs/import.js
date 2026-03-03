
// FILE: import.js
// USAGE: node import.js
// This script assumes you have 'zywrap-data.json' in the same directory.

const fs = require('fs/promises');
const pool = require('./db');

// Helper to expand tabular JSON data into arrays of objects
function extractTabular(tabularData) {
    if (!tabularData || !tabularData.cols || !tabularData.data) return [];
    const cols = tabularData.cols;
    return tabularData.data.map(row => {
        const obj = {};
        cols.forEach((col, i) => obj[col] = row[i]);
        return obj;
    });
}

async function main() {
    console.log('Starting lightning-fast v1.0 data import...');

    let data;
    try {
        const jsonFile = await fs.readFile('zywrap-data.json', 'utf8');
        data = JSON.parse(jsonFile);
    } catch (e) {
        console.error('FATAL: zywrap-data.json not found or invalid.', e.message);
        process.exit(1);
    }

    const client = await pool.connect();
    try {
        await client.query('BEGIN');

        console.log('Clearing tables...');
        await client.query('TRUNCATE wrappers, use_cases, categories, languages, block_templates, ai_models, settings RESTART IDENTITY CASCADE');

        // 1. Import Categories
        if (data.categories) {
            for (const c of extractTabular(data.categories)) {
                await client.query(
                    'INSERT INTO categories (code, name, status, ordering) VALUES ($1, $2, TRUE, $3)', 
                    [c.code, c.name, c.ordering ?? 99999]
                );
            }
            console.log('Categories imported successfully.');
        }
        
        // 2. Import Use Cases
        if (data.useCases) {
            for (const uc of extractTabular(data.useCases)) {
                const schemaJson = uc.schema ? JSON.stringify(uc.schema) : null;
                await client.query(
                    'INSERT INTO use_cases (code, name, description, category_code, schema_data, status, ordering) VALUES ($1, $2, $3, $4, $5, TRUE, $6)', 
                    [uc.code, uc.name, uc.desc, uc.cat, schemaJson, uc.ordering ?? 999999999]
                );
            }
            console.log('Use Cases imported successfully.');
        }

        // 3. Import Wrappers
        if (data.wrappers) {
            for (const w of extractTabular(data.wrappers)) {
                await client.query(
                    'INSERT INTO wrappers (code, name, description, use_case_code, featured, base, status, ordering) VALUES ($1, $2, $3, $4, $5, $6, TRUE, $7)',
                    [w.code, w.name, w.desc, w.usecase, !!w.featured, !!w.base, w.ordering ?? 999999999]
                );
            }
            console.log('Wrappers imported successfully.');
        }

        // 4. Import Languages
        if (data.languages) {
            let ord = 1;
            for (const l of extractTabular(data.languages)) {
                await client.query(
                    'INSERT INTO languages (code, name, status, ordering) VALUES ($1, $2, TRUE, $3)', 
                    [l.code, l.name, ord++]
                );
            }
            console.log('Languages imported successfully.');
        }

        // 5. Import AI Models
        if (data.aiModels) {
            for (const m of extractTabular(data.aiModels)) {
                await client.query(
                    'INSERT INTO ai_models (code, name, status, ordering) VALUES ($1, $2, TRUE, $3)',
                    [m.code, m.name, m.ordering ?? 99999]
                );
            }
            console.log('AI Models imported successfully.');
        }

        // 6. Import Block Templates
        if (data.templates) {
            for (const [type, tabular] of Object.entries(data.templates)) {
                for (const tpl of extractTabular(tabular)) {
                    await client.query(
                        'INSERT INTO block_templates (type, code, name, status) VALUES ($1, $2, $3, TRUE)', 
                        [type, tpl.code, tpl.name]
                    );
                }
            }
            console.log('Block templates imported successfully.');
        }

        // 7. Store Version
        if (data.version) {
            await client.query(
                "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', $1) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
                [data.version]
            );
            console.log('Data version saved to settings table.');
        }

        await client.query('COMMIT');
        console.log(`\n✅ v1.0 Import complete! Version: ${data.version || 'N/A'}`);

    } catch (e) {
        await client.query('ROLLBACK');
        console.error('FATAL: Database error during import. Transaction rolled back.', e.message);
        process.exit(1);
    } finally {
        client.release();
    }
}

main();
