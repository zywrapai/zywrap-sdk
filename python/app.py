# FILE: app.py
# A simple Flask server to replicate the 'api.php' V1 playground backend.
#
# REQUIREMENTS:
# pip install flask flask-cors requests psycopg2-binary
#
# USAGE:
# 1. Save this as 'app.py'
# 2. Run: flask --app app run
# 3. Open 'playground.html' in your browser.

import json
import time
import requests
import sys
from db import get_db_connection
from flask import Flask, request, jsonify, Response
from flask_cors import CORS
from psycopg2.extras import RealDictCursor 

app = Flask(__name__)
CORS(app) 

ZYWRAP_API_KEY = "YOUR_ZYWRAP_API_KEY"
ZYWRAP_PROXY_URL = 'https://api.zywrap.com/v1/proxy'

# --- Database Helper Functions ---
# (These are the equivalent of the functions in api.php)

def get_categories(cur):
    cur.execute("SELECT code, name FROM categories WHERE status = TRUE ORDER BY ordering ASC")
    return cur.fetchall()

def get_languages(cur):
    cur.execute("SELECT code, name FROM languages WHERE status = TRUE ORDER BY ordering ASC")
    return cur.fetchall()

def get_ai_models(cur):
    cur.execute("SELECT code, name FROM ai_models WHERE status = TRUE ORDER BY ordering ASC")
    return cur.fetchall()

def get_block_templates(cur):
    cur.execute("SELECT type, code, name FROM block_templates WHERE status = TRUE ORDER BY type, name ASC")
    results = cur.fetchall()
    # Group by type for easy use on the frontend
    grouped = {}
    for row in results:
        t = row['type']
        if t not in grouped: grouped[t] = []
        grouped[t].append({'code': row['code'], 'name': row['name']})
    return grouped

def get_wrappers_by_category(cur, category_code):
    cur.execute("""
        SELECT w.code, w.name, w.featured, w.base 
        FROM wrappers w 
        JOIN use_cases uc ON w.use_case_code = uc.code 
        WHERE uc.category_code = %s AND w.status = TRUE AND uc.status = TRUE
        ORDER BY w.ordering ASC
    """, (category_code,))
    return cur.fetchall()

def get_schema_by_wrapper(cur, wrapper_code):
    cur.execute("""
        SELECT uc.schema_data 
        FROM use_cases uc 
        JOIN wrappers w ON w.use_case_code = uc.code 
        WHERE w.code = %s AND w.status = TRUE AND uc.status = TRUE
    """, (wrapper_code,))
    res = cur.fetchone()
    return res['schema_data'] if res else None

# ✅ HYBRID PROXY EXECUTION
def execute_zywrap_proxy(api_key, model, wrapper_code, prompt, language=None, variables={}, overrides={}):
    payload_data = {
        'model': model,
        'wrapperCodes': [wrapper_code],
        'prompt': prompt,
        'variables': variables
    }
    
    if language: payload_data['language'] = language
    if overrides: payload_data.update(overrides)
        
    clean_key = api_key.strip()
    headers = {
        'Content-Type': 'application/json',
        'Authorization': f'Bearer {clean_key}',
        'User-Agent': 'ZywrapPythonSDK/1.1'
    }
    
    try:
        response = requests.post(ZYWRAP_PROXY_URL, json=payload_data, headers=headers, stream=True, timeout=300)
        
        if response.status_code == 200:
            final_json = None
            for line in response.iter_lines():
                if line:
                    decoded_line = line.decode('utf-8').strip()
                    if decoded_line.startswith('data: '):
                        json_str = decoded_line[6:]
                        try:
                            data = json.loads(json_str)
                            if data and ('output' in data or 'error' in data):
                                final_json = data
                        except json.JSONDecodeError:
                            pass
            
            if final_json:
                return final_json, 200
            else:
                return {'error': 'Stream parse failed'}, 500
        else:
            try: return response.json(), response.status_code
            except ValueError: return {'error': response.text}, response.status_code

    except requests.exceptions.RequestException as e:
        error_msg = str(e)
        if e.response is not None:
             try: return e.response.json(), e.response.status_code
             except: return {'error': e.response.text}, e.response.status_code
        return {'error': error_msg}, 500


# --- API Router ---
@app.route('/api', methods=['GET', 'POST'])
def api_router():
    conn = get_db_connection()
    try:
        # Use RealDictCursor to automatically convert SQL rows to dictionaries
        with conn.cursor(cursor_factory=RealDictCursor) as cur:
            
            if request.method == 'GET':
                action = request.args.get('action')
                if action == 'get_categories': return jsonify(get_categories(cur))
                if action == 'get_languages': return jsonify(get_languages(cur))
                if action == 'get_ai_models': return jsonify(get_ai_models(cur))
                if action == 'get_block_templates': return jsonify(get_block_templates(cur))
                if action == 'get_wrappers': return jsonify(get_wrappers_by_category(cur, request.args.get('category')))
                if action == 'get_schema': return jsonify(get_schema_by_wrapper(cur, request.args.get('wrapper')))

            if request.method == 'POST':
                input_data = request.get_json()
                action = request.args.get('action') or input_data.get('action')
                
                if action == 'execute':
                    # ⏱️ Start Local Timer
                    start_time = time.time()
                    
                    result, status_code = execute_zywrap_proxy(
                        ZYWRAP_API_KEY,
                        input_data.get('model'),
                        input_data.get('wrapperCode'),
                        input_data.get('prompt', ''),
                        input_data.get('language'),
                        input_data.get('variables', {}),
                        input_data.get('overrides', {})
                    )
                    
                    # ⏱️ End Local Timer
                    latency_ms = int((time.time() - start_time) * 1000)

                    # --- 📝 LOGGING TO LOCAL DATABASE ---
                    try:
                        status_text = 'success' if status_code == 200 else 'error'
                        trace_id = result.get('id')
                        
                        usage = result.get('usage', {})
                        p_tokens = usage.get('prompt_tokens', 0)
                        c_tokens = usage.get('completion_tokens', 0)
                        t_tokens = usage.get('total_tokens', 0)
                        
                        credits_used = result.get('cost', {}).get('credits_used', 0)
                        error_message = result.get('error') if status_text == 'error' else None

                        cur.execute("""
                            INSERT INTO usage_logs 
                            (trace_id, wrapper_code, model_code, prompt_tokens, completion_tokens, total_tokens, credits_used, latency_ms, status, error_message) 
                            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                        """, (
                            trace_id, input_data.get('wrapperCode'), input_data.get('model', 'default'),
                            p_tokens, c_tokens, t_tokens, credits_used, latency_ms, status_text, error_message
                        ))
                        conn.commit()
                    except Exception as log_err:
                        print(f"Failed to write to usage_logs: {log_err}", file=sys.stderr)
                        conn.rollback()

                    return jsonify(result), status_code
            
            return jsonify({'error': 'Invalid action'}), 400

    except Exception as e:
        return jsonify({'error': str(e)}), 500
    finally:
        conn.close()

if __name__ == '__main__':
    print(f"Zywrap Python SDK Playground backend listening at http://localhost:5000")
    app.run(debug=True, port=5000)
