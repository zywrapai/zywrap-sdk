# FILE: app.py
# A simple Flask server to replicate the 'api.php' playground backend.
#
# REQUIREMENTS:
# pip install flask flask-cors requests psycopg2-binary
#
# USAGE:
# 1. Save this as 'app.py'
# 2. Run: flask --app app run
# 3. Open 'playground.html' in your browser.

import json
import requests
from db import get_db_connection
from flask import Flask, request, jsonify, Response
from flask_cors import CORS
from psycopg2.extras import DictCursor # To fetch rows as dictionaries

app = Flask(__name__)
CORS(app) # Enable Cross-Origin Resource Sharing for the playground

ZYWRAP_API_KEY = "YOUR_ZYWRAP_API_KEY"
ZYWRAP_PROXY_URL = 'https://api.zywrap.com/v1/proxy'

# --- Database Helper Functions ---
# (These are the equivalent of the functions in api.php)

def get_categories(cur):
    cur.execute("SELECT code, name FROM categories ORDER BY ordering ASC")
    return [{'code': row[0], 'name': row[1]} for row in cur.fetchall()]

def get_languages(cur):
    cur.execute("SELECT code, name FROM languages ORDER BY ordering ASC")
    return [{'code': row[0], 'name': row[1]} for row in cur.fetchall()]

def get_wrappers_by_category(cur, category_code):
    cur.execute(
        "SELECT code, name, featured, base FROM wrappers WHERE category_code = %s ORDER BY ordering ASC",
        (category_code,)
    )
    return [{'code': row[0], 'name': row[1], 'featured': row[2], 'base': row[3]} for row in cur.fetchall()]

def get_block_templates(cur):
    cur.execute("SELECT type, code, name FROM block_templates ORDER BY type, name ASC")
    results = cur.fetchall()
    # Group by type for easy use on the frontend
    grouped = {}
    for row in results:
        type, code, name = row
        if type not in grouped:
            grouped[type] = []
        grouped[type].append({'code': code, 'name': name})
    return grouped

def get_ai_models(cur):
    cur.execute("SELECT code, name FROM ai_models ORDER BY ordering ASC")
    return [{'code': row[0], 'name': row[1]} for row in cur.fetchall()]

def execute_zywrap_proxy(api_key, model, wrapper_code, prompt, language=None, overrides={}):
    payload_data = {
        'model': model,
        'wrapperCodes': [wrapper_code],
        'prompt': prompt
    }
    
    if language:
        payload_data['language'] = language
    if overrides:
        payload_data.update(overrides)
        
    headers = {
        'Content-Type': 'application/json',
        'Authorization': f'Bearer {api_key}'
    }
    
    try:
        response = requests.post(ZYWRAP_PROXY_URL, json=payload_data, headers=headers)
        response.raise_for_status() # Raise an exception for bad status codes
        return response.json(), response.status_code
    except requests.exceptions.RequestException as e:
        return {'error': str(e), 'response': e.text if e.response else 'No response'}, 500


# --- API Router ---
# This single route mimics the 'api.php?action=...' behavior
@app.route('/api.php', methods=['GET', 'POST'])
def api_router():
    conn = get_db_connection()
    try:
        with conn.cursor() as cur:
            
            if request.method == 'GET':
                action = request.args.get('action')
                if action == 'get_categories':
                    return jsonify(get_categories(cur))
                if action == 'get_languages':
                    return jsonify(get_languages(cur))
                if action == 'get_ai_models':
                    return jsonify(get_ai_models(cur))
                if action == 'get_block_templates':
                    return jsonify(get_block_templates(cur))
                if action == 'get_wrappers':
                    category_code = request.args.get('category')
                    if not category_code:
                        return jsonify([])
                    return jsonify(get_wrappers_by_category(cur, category_code))

            if request.method == 'POST':
                input_data = request.get_json()
                action = input_data.get('action')
                
                if action == 'execute':
                    result, status_code = execute_zywrap_proxy(
                        ZYWRAP_API_KEY,
                        input_data.get('model'),
                        input_data.get('wrapperCode'),
                        input_data.get('prompt'),
                        input_data.get('language'),
                        input_data.get('overrides')
                    )
                    return jsonify(result), status_code
            
            # If no action matched
            return jsonify({'error': 'Invalid action'}), 400

    except Exception as e:
        return jsonify({'error': str(e)}), 500
    finally:
        conn.close()

if __name__ == '__main__':
    app.run(debug=True, port=5000)