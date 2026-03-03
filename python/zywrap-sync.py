# FILE: zywrap-sync.py
# USAGE: python zywrap-sync.py
# REQUIREMENTS: pip install requests psycopg2-binary

import requests
import sys
import os
import zipfile
import psycopg2.extras 
from db import get_db_connection

# --- CONFIGURATION ---
DEVELOPER_API_KEY = 'YOUR_ZYWRAP_API_KEY_HERE'
ZYWRAP_API_ENDPOINT = 'https://api.zywrap.com/v1/sdk/v1/sync'
# ---------------------

def get_current_version(cur):
    cur.execute("SELECT setting_value FROM settings WHERE setting_key = 'data_version'")
    result = cur.fetchone()
    return result[0] if result else ''

def save_new_version(cur, version):
    cur.execute(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', %s) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
        (version,)
    )

# --- HELPER FUNCTIONS ---

def upsert_batch(cur, table, rows, cols, pk='code'):
    """Optimized Upsert using Postgres ON CONFLICT"""
    if not rows: return
    
    col_names = ", ".join(cols)
    updates = [f"{c} = EXCLUDED.{c}" for c in cols if c != pk and c != 'type']
    update_clause = ", ".join(updates)
    conflict_target = f"({pk})" if pk != 'compound_template' else "(type, code)"
    
    query = f"""
        INSERT INTO {table} ({col_names}) VALUES %s
        ON CONFLICT {conflict_target} DO UPDATE SET {update_clause}
    """
    try:
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

# --- MAIN LOGIC ---

def main():
    print("--- 🚀 Starting Zywrap V1 Sync ---")
    conn = get_db_connection()
    try:
        with conn.cursor() as cur:
            current_version = get_current_version(cur)
            print(f"🔹 Local Version: {current_version or 'None'}")
            
            # 🟢 FIX: Commit immediately to release the read-lock on the settings table!
            # Without this, import.py will deadlock when trying to TRUNCATE.
            conn.commit()

            # 1. Fetch update info
            headers = {'Authorization': f'Bearer {DEVELOPER_API_KEY}', 'Accept': 'application/json'}
            params = {'fromVersion': current_version}
            
            try:
                response = requests.get(ZYWRAP_API_ENDPOINT, headers=headers, params=params, verify=False)
                response.raise_for_status()
            except Exception as e:
                print(f"❌ API Error: {e}")
                return

            patch = response.json()
            mode = patch.get('mode', 'UNKNOWN')
            print(f"🔹 Sync Mode: {mode}")

            # --- SCENARIO A: FULL RESET ---
            if mode == 'FULL_RESET':
                zip_path = 'zywrap-data.zip'
                download_url = patch['wrappers']['downloadUrl']
                
                print(f"⬇️  Attempting automatic download from Zywrap...")
                dl = requests.get(download_url, headers=headers, stream=True, verify=False)
                
                if dl.status_code == 200:
                    with open(zip_path, 'wb') as f:
                        for chunk in dl.iter_content(chunk_size=8192): f.write(chunk)
                    
                    mb_size = round(os.path.getsize(zip_path) / 1024 / 1024, 2)
                    print(f"✅ Data bundle downloaded successfully ({mb_size} MB).")
                    
                    try:
                        print("📦 Attempting auto-unzip...")
                        with zipfile.ZipFile(zip_path, 'r') as z: 
                            z.extractall('.')
                        print("✅ Auto-unzip successful. Running import script...")
                        os.remove(zip_path)
                        
                        import importlib.util
                        spec = importlib.util.spec_from_file_location("import_script", "import.py")
                        import_module = importlib.util.module_from_spec(spec)
                        spec.loader.exec_module(import_module)
                        import_module.main()

                    except Exception as z_err:
                        print("⚠️ Failed to auto-unzip (Check directory permissions).")
                        print("\\n👉 ACTION REQUIRED:")
                        print(f"   1. Please manually unzip '{zip_path}' in this folder.")
                        print("   2. Then run: python import.py")
                else:
                    print(f"❌ Automatic download failed. HTTP Status: {dl.status_code}")

            # --- SCENARIO B: DELTA UPDATE ---
            elif mode == 'DELTA_UPDATE':
                meta = patch.get('metadata', {})
                
                # Categories
                rows = [(r['code'], r['name'], bool(r.get('status', True)), r.get('position') or r.get('displayOrder') or r.get('ordering')) for r in meta.get('categories', [])]
                upsert_batch(cur, 'categories', rows, ['code', 'name', 'status', 'ordering'])

                # Languages
                rows = [(r['code'], r['name'], bool(r.get('status', True)), r.get('ordering')) for r in meta.get('languages', [])]
                upsert_batch(cur, 'languages', rows, ['code', 'name', 'status', 'ordering'])

                # AI Models
                rows = [(r['code'], r['name'], bool(r.get('status', True)), r.get('displayOrder') or r.get('ordering')) for r in meta.get('aiModels', [])]
                upsert_batch(cur, 'ai_models', rows, ['code', 'name', 'status', 'ordering'])

                # Templates
                rows = []
                for type_name, items in meta.get('templates', {}).items():
                    for i in items:
                        rows.append((type_name, i['code'], i.get('label') or i.get('name'), bool(i.get('status', True))))
                upsert_batch(cur, 'block_templates', rows, ['type', 'code', 'name', 'status'], pk='compound_template')

                # Use Cases
                upserts = patch.get('useCases', {}).get('upserts', [])
                if upserts:
                    rows = []
                    for uc in upserts:
                        schema_str = json.dumps(uc['schema']) if uc.get('schema') else None
                        rows.append((uc['code'], uc['name'], uc.get('description'), uc.get('categoryCode'), schema_str, bool(uc.get('status', True)), uc.get('displayOrder') or uc.get('ordering')))
                    upsert_batch(cur, 'use_cases', rows, ['code', 'name', 'description', 'category_code', 'schema_data', 'status', 'ordering'])

                # Wrappers
                upserts = patch.get('wrappers', {}).get('upserts', [])
                if upserts:
                    rows = []
                    for w in upserts:
                        rows.append((w['code'], w['name'], w.get('description'), w.get('useCaseCode') or w.get('categoryCode'), bool(w.get('featured') or w.get('isFeatured')), bool(w.get('base') or w.get('isBaseWrapper')), bool(w.get('status', True)), w.get('displayOrder') or w.get('ordering')))
                    upsert_batch(cur, 'wrappers', rows, ['code', 'name', 'description', 'use_case_code', 'featured', 'base', 'status', 'ordering'])

                # Deletes
                delete_batch(cur, 'wrappers', patch.get('wrappers', {}).get('deletes', []))
                delete_batch(cur, 'use_cases', patch.get('useCases', {}).get('deletes', []))
                
                # Version
                if patch.get('newVersion'):
                    save_new_version(cur, patch['newVersion'])
                
                conn.commit()
                print("✅ Delta Sync Complete.")
            else:
                print("✅ No updates needed.")

    except Exception as e:
        if not conn.closed:
            conn.rollback()
        print(f"FATAL: Sync Failed: {e}", file=sys.stderr)
    finally:
        if not conn.closed:
            conn.close()

if __name__ == "__main__":
    main()
