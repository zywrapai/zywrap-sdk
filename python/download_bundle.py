
# FILE: download_bundle.py
import requests
import sys
import os

# --- CONFIGURATION ---
ZYWRAP_API_KEY = 'YOUR_API_KEY_HERE'
API_ENDPOINT = 'https://api.zywrap.com/v1/sdk/v1/download'
OUTPUT_FILE = 'zywrap-data.zip'
# ---------------------

def download_sdk_bundle():
    print("Downloading latest V1 wrapper data from Zywrap...")

    if not ZYWRAP_API_KEY or 'YOUR_API_KEY_HERE' in ZYWRAP_API_KEY:
        print("FATAL: Please replace 'YOUR_API_KEY_HERE' with your actual Zywrap API key.", file=sys.stderr)
        sys.exit(1)

    headers = {'Authorization': f'Bearer {ZYWRAP_API_KEY}'}

    try:
        # stream=True ensures we don't load the entire zip into RAM at once
        response = requests.get(API_ENDPOINT, headers=headers, stream=True, timeout=300, verify=False)
        response.raise_for_status()

        # Write directly to disk
        with open(OUTPUT_FILE, 'wb') as f:
            for chunk in response.iter_content(chunk_size=8192):
                f.write(chunk)
        
        print(f"✅ Sync complete. Data saved to {OUTPUT_FILE}.")
        print(f"Run 'unzip {OUTPUT_FILE}' to extract the 'zywrap-data.json' file, then run 'python import.py'.")

    except requests.exceptions.HTTPError as e:
        if os.path.exists(OUTPUT_FILE): os.remove(OUTPUT_FILE)
        print(f"FATAL: API request failed with status code {e.response.status_code}.", file=sys.stderr)
        sys.exit(1)
    except Exception as e:
        if os.path.exists(OUTPUT_FILE): os.remove(OUTPUT_FILE)
        print(f"FATAL: An error occurred: {e}", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    download_sdk_bundle()
