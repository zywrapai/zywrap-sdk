// FILE: import.js
// USAGE: node import.js
// This script assumes you have unzipped 'zywrap-data.zip' and have 'zywrap-data.json' in the same directory.

const fs = require('fs/promises');
const pool = require('./db'); // Your database connection pool

async function main() {
  console.log('Starting full data import...');

  const jsonFile = await fs.readFile('zywrap-data.json', 'utf8');
  const data = JSON.parse(jsonFile);

  if (!data) {
    throw new Error('Could not parse JSON file.');
  }

  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    // 1. Clear existing data
    console.log('Clearing tables...');
    await client.query('TRUNCATE wrappers, categories, languages, block_templates, ai_models, settings RESTART IDENTITY CASCADE');

    // 2. Import Categories
    if (data.categories) {
        let ordering = 1;
        for (const [code, category] of Object.entries(data.categories)) {
            await client.query(
                'INSERT INTO categories (code, name, ordering) VALUES ($1, $2, $3)', 
                [code, category.name, ordering++]
            );
        }
        console.log('Categories imported successfully.');
    }
    
    // 3. Import Languages
    if (data.languages) {
        let ordering = 1;
        for (const [code, name] of Object.entries(data.languages)) {
            await client.query(
                'INSERT INTO languages (code, name, ordering) VALUES ($1, $2, $3)', 
                [code, name, ordering++]
            );
        }
        console.log('Languages imported successfully.');
    }

    // 4. Import AI Models
    if (data.aiModels) {
        let ordering = 1;
        for (const [code, model] of Object.entries(data.aiModels)) {
            await client.query(
                'INSERT INTO ai_models (code, name, provider_id, ordering) VALUES ($1, $2, $3, $4)',
                [code, model.name, model.provId, ordering++]
            );
        }
        console.log('AI Models imported successfully.');
    }

    // 5. Import Block Templates
    if (data.templates) {
        for (const [type, templates] of Object.entries(data.templates)) {
            for (const [code, name] of Object.entries(templates)) {
                await client.query(
                    'INSERT INTO block_templates (type, code, name) VALUES ($1, $2, $3)', 
                    [type, code, name]
                );
            }
        }
        console.log('Block templates imported successfully.');
    }

    // 6. Import Wrappers
    if (data.wrappers) {
        let ordering = 1;
        for (const [code, wrapper] of Object.entries(data.wrappers)) {
            await client.query(
                'INSERT INTO wrappers (code, name, description, category_code, featured, base, ordering) VALUES ($1, $2, $3, $4, $5, $6, $7)',
                [code, wrapper.name, wrapper.desc, wrapper.cat, wrapper.featured, wrapper.base, ordering++]
            );
        }
        console.log('Wrappers imported successfully.');
    }

    // 7. Store the version in the database
    if (data.version) {
        await client.query(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', $1)",
            [data.version]
        );
        console.log('Data version saved to settings table.');
    }

    await client.query('COMMIT');
    console.log(`\n✅ Data import complete! Version: ${data.version}`);

  } catch (e) {
    await client.query('ROLLBACK');
    console.error('Database error during import. Transaction rolled back.', e.message);
    throw e;
  } finally {
    client.release();
  }
}

main().catch(err => {
    console.error('Script failed:', err);
    process.exit(1);
});