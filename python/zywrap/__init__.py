import requests
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
            "Authorization": f"Bearer {self.api_key}",
            "User-Agent": "Zywrap/PythonSDK/1.0.0"
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
            return {"data": response.json(), "status": response.status_code}
            
        except requests.exceptions.HTTPError as e:
            error_msg = str(e)
            try:
                error_data = response.json()
                if "error" in error_data:
                    error_msg = error_data["error"]
            except ValueError:
                pass
            raise ZywrapError(f"Zywrap API Error: {error_msg}") from e
        except requests.exceptions.RequestException as e:
            raise ZywrapError(f"Network error occurred: {str(e)}") from e