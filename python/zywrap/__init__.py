import requests
import json
from typing import List, Dict, Any, Optional

class ZywrapError(Exception):
    """Custom exception for Zywrap API errors."""
    pass

class Zywrap:
    """Zywrap API Client for Python."""

    def __init__(self, api_key: str, base_url: str = "https://api.zywrap.com/v1/proxy"):
        if not api_key or not isinstance(api_key, str):
            raise ValueError("Zywrap Initialization Error: A valid API Key is required.")
        
        self.api_key = api_key.strip()
        self.base_url = base_url
        self._session = requests.Session()
        self._session.headers.update({
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Authorization": f"Bearer {self.api_key}",
            "User-Agent": "Zywrap/PythonSDK/1.0.2"
        })

    def execute(
        self, 
        model: str, 
        wrapper_codes: List[str], 
        variables: Optional[Dict[str, Any]] = None, 
        prompt: str = "", 
        language: str = ""
    ) -> Dict[str, Any]:
        """
        Execute a Zywrap AI Wrapper.
        """
        if not model or not wrapper_codes or not isinstance(wrapper_codes, list):
            raise ValueError("'model' and 'wrapper_codes' (list) are required parameters.")

        payload = {
            "model": model,
            "wrapperCodes": wrapper_codes,
            "variables": variables or {},
            "prompt": prompt,
            "source": "python_sdk"
        }

        if language:
            payload["language"] = language

        try:
            response = self._session.post(self.base_url, json=payload)
            response.raise_for_status()
            
            # --- THE FIX: Parse the SSE Stream ---
            final_json = None
            for line in response.text.splitlines():
                line = line.strip()
                if line.startswith("data: "):
                    json_str = line[6:]
                    
                    if json_str == "[DONE]":
                        continue
                        
                    try:
                        parsed = json.loads(json_str)
                        if parsed and ("output" in parsed or "error" in parsed):
                            final_json = parsed
                    except Exception:
                        pass # Ignore partial chunk parsing errors

            # Fallback for standard JSON if not streaming
            if not final_json:
                try:
                    final_json = response.json()
                except Exception:
                    pass # Catch ALL parsing errors to prevent crashing

            if not final_json:
                # If it completely fails, tell the developer exactly what the server sent
                raise ZywrapError(f"Failed to parse response. HTTP {response.status_code}. Raw text: '{response.text}'")

            return {"data": final_json, "status": response.status_code}
            
        except requests.exceptions.HTTPError as e:
            error_msg = str(e)
            try:
                error_data = response.json()
                if "error" in error_data:
                    error_msg = error_data["error"]
            except Exception:
                pass
            raise ZywrapError(f"Zywrap API Error: HTTP {response.status_code} - {error_msg}") from e
        except requests.exceptions.RequestException as e:
            # Only actual network drops will trigger this now
            raise ZywrapError(f"Network error occurred: {str(e)}") from e