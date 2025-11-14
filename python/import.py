# FILE: import.py
# USAGE: python import.py
# This script assumes you have unzipped 'zywrap-data.zip' and have 'zywrap-data.json' in the same directory.

import json
import sys
from db import get_db_connection

def main():
    print("Starting full data import...")
    
    try:
        with open('zywrap-data.json', 'r', encoding='utf-8') as f:
            data = json.load(f)
    except FileNotFoundError:
        print("FATAL: zywrap-data.json not found. Please download it first.", file=sys.stderr)
        sys.exit(1)
    except json.JSONDecodeError:
        print("FATAL: Could not parse JSON file. Check for syntax errors.", file=sys.stderr)
        sys.exit(1)

    conn = get_db_connection()
    try:
        with conn.cursor() as cur:
            # 1. Clear existing data
            print("Clearing tables...")
            cur.execute("TRUNCATE wrappers, categories, languages, block_templates, ai_models, settings RESTART IDENTITY CASCADE")

            # 2. Import Categories
            if data.get('categories'):
                ordering = 1
                for code, category in data['categories'].items():
                    cur.execute(
                        "INSERT INTO categories (code, name, ordering) VALUES (%s, %s, %s)", 
                        (code, category.get('name'), ordering)
                    )
                    ordering += 1
                print("Categories imported successfully.")

            # 3. Import Languages
            if data.get('languages'):
                ordering = 1
                for code, name in data['languages'].items():
                    cur.execute(
                        "INSERT INTO languages (code, name, ordering) VALUES (%s, %s, %s)", 
                        (code, name, ordering)
                    )
                    ordering += 1
                print("Languages imported successfully.")

            # 4. Import AI Models
            if data.get('aiModels'):
                ordering = 1
                for code, model in data['aiModels'].items():
                    cur.execute(
                        "INSERT INTO ai_models (code, name, provider_id, ordering) VALUES (%s, %s, %s, %s)",
                        (code, model.get('name'), model.get('provId'), ordering)
                    )
                    ordering += 1
                print("AI Models imported successfully.")
                
            # 5. Import Block Templates
            if data.get('templates'):
                for type, templates in data['templates'].items():
                    for code, name in templates.items():
                        cur.execute(
                            "INSERT INTO block_templates (type, code, name) VALUES (%s, %s, %s)", 
                            (type, code, name)
                        )
                print("Block templates imported successfully.")

            # 6. Import Wrappers
            if data.get('wrappers'):
                ordering = 1
                for code, wrapper in data['wrappers'].items():
                    cur.execute(
                        "INSERT INTO wrappers (code, name, description, category_code, featured, base, ordering) VALUES (%s, %s, %s, %s, %s, %s, %s)",
                        (code, wrapper.get('name'), wrapper.get('desc'), wrapper.get('cat'), wrapper.get('featured'), wrapper.get('base'), ordering)
                    )
                    ordering += 1
                print("Wrappers imported successfully.")

            # 7. Store the version in the database
            if data.get('version'):
                cur.execute(
                    "INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', %s) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value",
                    (data['version'],)
                )
                print("Data version saved to settings table.")
            
            conn.commit()
            print(f"\n✅ Data import complete! Version: {data.get('version')}")

    except Exception as e:
        conn.rollback()
        print(f"FATAL: Database error during import. Transaction rolled back.\n{e}", file=sys.stderr)
    finally:
        conn.close()

if __name__ == "__main__":
    main()