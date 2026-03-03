# FILE: import.py
# USAGE: python import.py
# This script assumes you have 'zywrap-data.json' in the same directory.

import json
import sys
from db import get_db_connection

def extract_tabular(tabular_data):
    """Helper to expand tabular JSON data into dictionaries"""
    if not tabular_data or not tabular_data.get('cols') or not tabular_data.get('data'):
        return []
    cols = tabular_data['cols']
    return [dict(zip(cols, row)) for row in tabular_data['data']]

def main():
    print("Starting lightning-fast v1.0 data import...")
    
    try:
        with open('zywrap-data.json', 'r', encoding='utf-8') as f:
            data = json.load(f)
    except FileNotFoundError:
        print("FATAL: zywrap-data.json not found.", file=sys.stderr)
        sys.exit(1)

    conn = get_db_connection()
    try:
        with conn.cursor() as cur:
            # 1. Clear existing data
            print("Clearing tables...")
            cur.execute("TRUNCATE wrappers, use_cases, categories, languages, block_templates, ai_models, settings RESTART IDENTITY CASCADE")

            # ✅ psycopg2 autocommits by default only outside blocks, so we are in a transaction implicitly.
            
            # 1. Import Categories
            if 'categories' in data:
                for c in extract_tabular(data['categories']):
                    cur.execute(
                        "INSERT INTO categories (code, name, status, ordering) VALUES (%s, %s, TRUE, %s)", 
                        (c['code'], c['name'], c.get('ordering', 99999))
                    )
                print("Categories imported successfully.")

            # 2. Import Use Cases
            if 'useCases' in data:
                for uc in extract_tabular(data['useCases']):
                    schema_json = json.dumps(uc['schema']) if uc.get('schema') else None
                    cur.execute(
                        "INSERT INTO use_cases (code, name, description, category_code, schema_data, status, ordering) VALUES (%s, %s, %s, %s, %s, TRUE, %s)", 
                        (uc['code'], uc['name'], uc.get('desc'), uc.get('cat'), schema_json, uc.get('ordering', 999999999))
                    )
                print("Use Cases imported successfully.")

            # 3. Import Wrappers
            if 'wrappers' in data:
                for w in extract_tabular(data['wrappers']):
                    featured = bool(w.get('featured'))
                    base = bool(w.get('base'))
                    cur.execute(
                        "INSERT INTO wrappers (code, name, description, use_case_code, featured, base, status, ordering) VALUES (%s, %s, %s, %s, %s, %s, TRUE, %s)",
                        (w['code'], w['name'], w.get('desc'), w.get('usecase'), featured, base, w.get('ordering', 999999999))
                    )
                print("Wrappers imported successfully.")

            # 4. Import Languages
            if 'languages' in data:
                ord_counter = 1
                for l in extract_tabular(data['languages']):
                    cur.execute(
                        "INSERT INTO languages (code, name, status, ordering) VALUES (%s, %s, TRUE, %s)", 
                        (l['code'], l['name'], ord_counter)
                    )
                    ord_counter += 1
                print("Languages imported successfully.")

            # 5. Import AI Models
            if 'aiModels' in data:
                for m in extract_tabular(data['aiModels']):
                    cur.execute(
                        "INSERT INTO ai_models (code, name, status, ordering) VALUES (%s, %s, TRUE, %s)",
                        (m['code'], m['name'], m.get('ordering', 99999))
                    )
                print("AI Models imported successfully.")
                
            # 6. Import Block Templates
            if 'templates' in data:
                for type_name, tabular in data['templates'].items():
                    for tpl in extract_tabular(tabular):
                        cur.execute(
                            "INSERT INTO block_templates (type, code, name, status) VALUES (%s, %s, %s, TRUE)", 
                            (type_name, tpl['code'], tpl['name'])
                        )
                print("Block templates imported successfully.")

            # 7. Store the version
            if 'version' in data:
                cur.execute(
                    "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', %s) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
                    (data['version'],)
                )
                print("Data version saved to settings table.")
            
            conn.commit()
            print(f"\n✅ v1.0 Import complete! Version: {data.get('version', 'N/A')}")

    except Exception as e:
        conn.rollback()
        print(f"FATAL: Database error during import.\n{e}", file=sys.stderr)
    finally:
        conn.close()

if __name__ == "__main__":
    main()
