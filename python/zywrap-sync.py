# FILE: zywrap-sync.py
# USAGE: python zywrap-sync.py

import requests
import sys
from db import get_db_connection

# --- CONFIGURATION ---
DEVELOPER_API_KEY = 'YOUR_ZYWRAP_API_KEY_HERE'
ZYWRAP_API_ENDPOINT = 'https://api.zywrap.com/v1/sdk/export/updates'
# ---------------------

# Block template types from PHP SDK
BLOCK_TYPES = ['tones', 'styles', 'formattings', 'complexities', 'lengths', 'outputTypes', 'responseGoals', 'audienceLevels']


def get_current_version(cur):
    cur.execute("SELECT setting_value FROM settings WHERE setting_key = 'data_version'")
    result = cur.fetchone()
    return result[0] if result else None

def save_new_version(cur, version):
    cur.execute(
        "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', %s) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
        (version,)
    )

def main():
    print("Starting Zywrap data sync...")
    conn = get_db_connection()
    try:
        with conn.cursor() as cur:
            current_version = get_current_version(cur)
            if not current_version:
                raise Exception("No version found. Run the full 'import.py' script first.")
            print(f"Current local version: {current_version}")

            # 1. Fetch patch from Zywrap API
            headers = {'Authorization': f'Bearer {DEVELOPER_API_KEY}', 'Accept': 'application/json'}
            params = {'fromVersion': current_version}
            response = requests.get(ZYWRAP_API_ENDPOINT, headers=headers, params=params)
            response.raise_for_status()
            
            patch = response.json()
            if not patch or not patch.get('newVersion'):
                print("No new updates found. Local data is already up to date.")
                conn.close()
                return
                
            print(f"Successfully fetched patch version: {patch['newVersion']}")
            
            # 2. Apply patch
            # Process Updates/Creations (UPSERT)
            if patch.get('updates'):
                updates = patch['updates']
                
                # Update Wrappers
                if updates.get('wrappers'):
                    print(f"Updating {len(updates['wrappers'])} wrapper(s)...")
                    for w in updates['wrappers']:
                        cur.execute(
                            "INSERT INTO wrappers (code, name, description, category_code, featured, base, updated_at) VALUES (%s, %s, %s, %s, %s, %s, NOW()) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, category_code = EXCLUDED.category_code, featured = EXCLUDED.featured, base = EXCLUDED.base, updated_at = NOW()",
                            (w['code'], w['name'], w.get('desc'), w.get('cat'), w.get('featured'), w.get('base'))
                        )
                
                # Update Categories
                if updates.get('categories'):
                    print(f"Updating {len(updates['categories'])} category(s)...")
                    for code, c in updates['categories'].items():
                        cur.execute(
                            "INSERT INTO categories (code, name, updated_at) VALUES (%s, %s, NOW()) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, updated_at = NOW()",
                            (code, c['name'])
                        )

                # Update Languages
                if updates.get('languages'):
                    print(f"Updating {len(updates['languages'])} language(s)...")
                    for code, name in updates['languages'].items():
                        cur.execute(
                            "INSERT INTO languages (code, name, updated_at) VALUES (%s, %s, NOW()) ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, updated_at = NOW()",
                            (code, name)
                        )

                # Update AI Models
                if updates.get('aiModels'):
                     print(f"Updating {len(updates['aiModels'])} AI model(s)...")
                     for code, m in updates['aiModels'].items():
                        cur.execute(
                            "INSERT INTO ai_models (code, name, provider_id, updated_at) VALUES (%s, %s, %s, NOW()) ON CONFLICT (code) DO UPDATE SET name=EXCLUDED.name, provider_id=EXCLUDED.provider_id, updated_at=NOW()", 
                            (code, m['name'], m.get('provId'))
                        )
                
                # Update Block Templates (Correct logic)
                print("Checking for block template updates...")
                for type in BLOCK_TYPES:
                    if updates.get(type):
                        for code, name in updates[type].items():
                            cur.execute(
                                "INSERT INTO block_templates (type, code, name, updated_at) VALUES (%s, %s, %s, NOW()) ON CONFLICT (type, code) DO UPDATE SET name = EXCLUDED.name, updated_at = NOW()",
                                (type, code, name)
                            )

            # Process Deletions (Correct logic)
            if patch.get('deletions'):
                print(f"Processing {len(patch['deletions'])} deletion(s)...")
                for d in patch['deletions']:
                    delete_type = d.get('type')
                    delete_code = d.get('code')
                    
                    if delete_type == 'Wrapper':
                        cur.execute("DELETE FROM wrappers WHERE code = %s", (delete_code,))
                    elif delete_type == 'Category':
                        cur.execute("DELETE FROM categories WHERE code = %s", (delete_code,))
                    elif delete_type == 'Language':
                        cur.execute("DELETE FROM languages WHERE code = %s", (delete_code,))
                    elif delete_type == 'AIModel':
                        cur.execute("DELETE FROM ai_models WHERE code = %s", (delete_code,))
                    elif delete_type and 'BlockTemplate' in delete_type:
                        # Handles all block template types
                        cur.execute("DELETE FROM block_templates WHERE code = %s", (delete_code,))
            
            save_new_version(cur, patch['newVersion'])
            conn.commit()
            print(f"\n✅ Sync complete. Local data is now at version {patch['newVersion']}.")

    except Exception as e:
        conn.rollback()
        print(f"FATAL: Error during sync, transaction rolled back.\n{e}", file=sys.stderr)
    finally:
        conn.close()

if __name__ == "__main__":
    main()