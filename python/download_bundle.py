# FILE: download_bundle.py
import requests
import sys

# --- CONFIGURATION ---
# Replace with your actual API key and the API endpoint
ZYWRAP_API_KEY = 'YOUR_API_KEY_HERE'
API_ENDPOINT = 'https://api.zywrap.com/v1/sdk/export/'
OUTPUT_FILE = 'zywrap-data.zip'
# ---------------------

def download_sdk_bundle():
    """
    Downloads the Zywrap data bundle from the API and saves it to a file.
    """
    print("Downloading latest wrapper data from Zywrap...")

    if not ZYWRAP_API_KEY or 'YOUR_API_KEY_HERE' in ZYWRAP_API_KEY:
        print("FATAL: Please replace 'YOUR_API_KEY_HERE' with your actual Zywrap API key.", file=sys.stderr)
        sys.exit(1)

    headers = {
        'Authorization': f'Bearer {ZYWRAP_API_KEY}',
        'Accept': 'application/zip' # Request a zip file
    }

    try:
        # Make the GET request to the download endpoint
        response = requests.get(API_ENDPOINT, headers=headers, stream=True, timeout=60)
        
        # Check if the request was successful
        response.raise_for_status()

        # Write the content to the output file
        with open(OUTPUT_FILE, 'wb') as f:
            for chunk in response.iter_content(chunk_size=8192):
                f.write(chunk)
        
        print(f"✅ Sync complete. Data saved to {OUTPUT_FILE}.")
        print(f"Run 'unzip {OUTPUT_FILE}' to extract the 'zywrap-data.json' file.")

    except requests.exceptions.HTTPError as e:
        print(f"FATAL: API request failed with status code {e.response.status_code}.", file=sys.stderr)
        try:
            # Try to print JSON error if possible
            print(f"Response: {e.response.json()}", file=sys.stderr)
        except requests.exceptions.JSONDecodeError:
            print(f"Response: {e.response.text}", file=sys.stderr)
        sys.exit(1)
    except requests.exceptions.RequestException as e:
        print(f"FATAL: A network error occurred: {e}", file=sys.stderr)
        sys.exit(1)
    except Exception as e:
        print(f"FATAL: An unexpected error occurred: {e}", file=sys.stderr)
        sys.exit(1)

# This allows the script to be run directly from the command line
if __name__ == "__main__":
    download_sdk_bundle()
