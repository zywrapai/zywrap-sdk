
# FILE: zywrap-sync.py
# USAGE: python zywrap-sync.py
# REQUIREMENTS: pip install requests psycopg2-binary

import requests
import sys
import json
import os
import zipfile
import psycopg2.extras 
from db import get_db_connection

# --- CONFIGURATION ---
DEVELOPER_API_KEY = 'YOUR_ZYWRAP_API_KEY_HERE'
ZYWRAP_API_ENDPOINT = 'https://api.zywrap.com/v1/sdk/export/updates'
# ---------------------

def get_current_version(cur):
    cur.execute("SELECT setting_value FROM settings WHERE setting_key = 'data_version'")
    result = cur.fetchone()
    return result[0] if result else None

def save_new_version(cur, version):
    cur.execute(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', %s) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
        (version,)
    )

# --- HELPER FUNCTIONS ---

def upsert_batch(cur, table, rows, cols, pk='code'):
    """
    Optimized Upsert using execute_values for high performance.
    """
    if not rows: return
    
    # 1. Prepare SQL
    col_names = ", ".join(cols)
    # Exclude PK and 'type' from updates
    updates = [f"{c} = EXCLUDED.{c}" for c in cols if c != pk and c != 'type']
    update_clause = ", ".join(updates)
    conflict_target = f"({pk})" if pk != 'compound_template' else "(type, code)"
    
    query = f"""
        INSERT INTO {table} ({col_names}) VALUES %s
        ON CONFLICT {conflict_target} DO UPDATE SET {update_clause}
    """
    
    try:
        # execute_values handles batching automatically and efficiently
        psycopg2.extras.execute_values(cur, query, rows, page_size=1000)
        print(f"   [+] Upserted {len(rows)} records into '{table}'.")
    except Exception as e:
        print(f"   [!] Error upserting {table}: {e}")

def delete_batch(cur, table, ids, pk='code'):
    if not ids: return
    query = f"DELETE FROM {table} WHERE {pk} = ANY(%s)"
    try:
        cur.execute(query, (list(ids),))
        print(f"   [-] Deleted {len(ids)} records from '{table}'.")
    except Exception as e:
        print(f"   [!] Error deleting from {table}: {e}")

def sync_table_full_mirror(cur, table, rows, cols, pk='code'):
    print(f"   Mirroring '{table}' ({len(rows)} records)...")
    upsert_batch(cur, table, rows, cols, pk)
    
    # Get Local IDs
    id_col = f"{pk}" if pk != 'compound_template' else "type, code"
    cur.execute(f"SELECT {id_col} FROM {table}")
    
    local_ids = set()
    for res in cur.fetchall():
        key = f"{res[0]}|{res[1]}" if pk == 'compound_template' else res[0]
        local_ids.add(key)
            
    # Get Incoming IDs
    incoming_ids = set()
    for r in rows:
        key = f"{r[0]}|{r[1]}" if pk == 'compound_template' else r[0]
        incoming_ids.add(key)
            
    # Calculate Difference
    to_delete = list(local_ids - incoming_ids)
    
    if to_delete:
        print(f"   [-] Cleaning up {len(to_delete)} obsolete records...")
        if pk == 'compound_template':
            # Batch delete compound keys is tricky, doing one by one for safety
            for combo in to_delete:
                t, c = combo.split('|')
                cur.execute("DELETE FROM block_templates WHERE type = %s AND code = %s", (t, c))
        else:
            delete_batch(cur, table, to_delete, pk)

def process_metadata(cur, data, mode):
    sync_func = sync_table_full_mirror if mode == 'FULL_RESET' else upsert_batch
    
    # Categories
    rows = []
    if isinstance(data.get('categories'), list): rows = [(r['code'], r['name'], r['ordering']) for r in data['categories']]
    elif isinstance(data.get('categories'), dict):
        ord = 1
        for k, v in data['categories'].items():
            rows.append((k, v['name'], ord))
            ord += 1
    sync_func(cur, 'categories', rows, ['code', 'name', 'ordering'])

    # Languages
    rows = []
    if isinstance(data.get('languages'), list): rows = [(r['code'], r['name'], r['ordering']) for r in data['languages']]
    elif isinstance(data.get('languages'), dict):
        ord = 1
        for k, v in data['languages'].items():
            rows.append((k, v, ord))
            ord += 1
    sync_func(cur, 'languages', rows, ['code', 'name', 'ordering'])

    # AI Models
    rows = []
    if isinstance(data.get('aiModels'), list): rows = [(r['code'], r['name'], r.get('provider_id'), r['ordering']) for r in data['aiModels']]
    elif isinstance(data.get('aiModels'), dict):
        ord = 1
        for k, v in data['aiModels'].items():
            rows.append((k, v['name'], v.get('provId'), ord))
            ord += 1
    sync_func(cur, 'ai_models', rows, ['code', 'name', 'provider_id', 'ordering'])

    # Templates
    rows = []
    tpls = data.get('templates') or {}
    for type_name, items in tpls.items():
        if isinstance(items, list):
            for i in items: rows.append((type_name, i['code'], i.get('label') or i.get('name')))
        elif isinstance(items, dict):
            for k, v in items.items(): rows.append((type_name, k, v))
    sync_func(cur, 'block_templates', rows, ['type', 'code', 'name'], pk='compound_template')

# --- MAIN LOGIC ---

def main():
    print("--- 🚀 Starting Zywrap Sync ---")
    conn = get_db_connection()
    try:
        with conn.cursor() as cur:
            current_version = get_current_version(cur)
            print(f"🔹 Local Version: {current_version or 'None'}")

            # 1. Fetch update info
            headers = {'Authorization': f'Bearer {DEVELOPER_API_KEY}', 'Accept': 'application/json'}
            params = {'fromVersion': current_version}
            
            try:
                response = requests.get(ZYWRAP_API_ENDPOINT, headers=headers, params=params)
                response.raise_for_status()
            except Exception as e:
                print(f"❌ API Error: {e}")
                return

            patch = response.json()
            mode = patch.get('mode', 'UNKNOWN')
            print(f"🔹 Sync Mode: {mode}")

            if mode == 'FULL_RESET':
                zip_path = 'zywrap_temp.zip'
                json_path = 'zywrap-data.json'
                download_url = patch['wrappers']['downloadUrl']
                
                print(f"⬇️  Downloading bundle from: {download_url}")
                dl = requests.get(download_url, headers=headers, stream=True)
                
                # Check for error (API returns JSON on error, Zip on success)
                if 'application/json' in dl.headers.get('Content-Type', ''):
                    print("❌ Download Failed. Server returned JSON error.")
                    print(dl.text)
                    return

                with open(zip_path, 'wb') as f:
                    for chunk in dl.iter_content(chunk_size=8192): f.write(chunk)
                            
                print("📦 Unzipping...")
                with zipfile.ZipFile(zip_path, 'r') as z: z.extractall('.')
                        
                if os.path.exists(json_path):
                    print("📦 Parsing JSON...")
                    with open(json_path, 'r', encoding='utf-8') as f: file_data = json.load(f)
                            
                    process_metadata(cur, file_data, 'FULL_RESET')
                        
                    # Sync Wrappers (Mirror)
                    w_rows = []
                    ord_idx = 1
                    for code, w in file_data.get('wrappers', {}).items():
                        w_rows.append((code, w['name'], w.get('desc'), w.get('cat'), w.get('featured'), w.get('base'), ord_idx))
                        ord_idx += 1
                            
                    sync_table_full_mirror(cur, 'wrappers', w_rows, ['code', 'name', 'description', 'category_code', 'featured', 'base', 'ordering'])
                        
                    save_new_version(cur, patch['wrappers']['version'])
                    conn.commit()
                    
                    os.remove(zip_path)
                    os.remove(json_path)
                    print("🎉 Full Reset Complete.")
                else:
                    print(f"❌ '{json_path}' not found after unzip.")

            elif mode == 'DELTA_UPDATE':
                process_metadata(cur, patch['metadata'], 'DELTA_UPDATE')
                
                if patch['wrappers'].get('upserts'):
                    w_rows = []
                    for w in patch['wrappers']['upserts']:
                        w_rows.append((w['code'], w['name'], w['description'], w['categoryCode'], w['featured'], w['base'], w['ordering']))
                    upsert_batch(cur, 'wrappers', w_rows, ['code', 'name', 'description', 'category_code', 'featured', 'base', 'ordering'])
                
                if patch['wrappers'].get('deletes'):
                    delete_batch(cur, 'wrappers', patch['wrappers']['deletes'])
                
                if patch.get('newVersion'):
                    save_new_version(cur, patch['newVersion'])
                
                conn.commit()
                print("✅ Delta Sync Complete.")
            else:
                print("✅ No updates needed.")

    except Exception as e:
        conn.rollback()
        print(f"FATAL: Sync Failed: {e}", file=sys.stderr)
    finally:
        conn.close()

if __name__ == "__main__":
    main()
