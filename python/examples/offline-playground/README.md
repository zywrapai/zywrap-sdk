# Zywrap Python SDK Example

This directory contains a complete, runnable example for integrating Zywrap's offline data bundle with a Python application using a **PostgreSQL** database.

## Files

* `schema.postgres.sql`: The SQL schema for creating all necessary tables.
* `db.py`: The database connection script (using `psycopg2`).
* `download_bundle.py`: A script to programmatically download the `zywrap-data.zip` bundle.
* `import.py`: A script to perform a full, one-time import of the `zywrap-data.json` file.
* `zywrap-sync.py`: A script to fetch and apply delta-updates (for a cron job).
* `app.py`: A Flask backend server that mimics the Zywrap API for the local playground.
* `playground.html`: A frontend HTML file to interact with your local `app.py` server.
* `requirements.txt`: Project dependencies.

## 🚀 How to Run

1.  **Database Setup:**
    * Create a new PostgreSQL database (e.g., `zywrap_db`).
    * Import the schema: `psql -U postgres -d zywrap_db -f schema.postgres.sql`
    * Edit `db.py` with your database credentials.

2.  **Install Dependencies:**
    * Create a virtual environment: `python -m venv venv`
    * Activate it: `source venv/bin/activate` (or `venv\Scripts\activate` on Windows)
    * Install requirements: `pip install -r requirements.txt`

3.  **Get Data:**
    * Download the `zywrap-data.zip` bundle from your [Zywrap account](https://zywrap.com/sdk/python).
    * Unzip it to get `zywrap-data.json` and place it in this directory.
    * (Alternatively, edit `download_bundle.py` with your API key and run `python download_bundle.py`).

4.  **Initial Import:**
    * Run the import script:
    ```bash
    python import.py
    ```

5.  **Run the Playground:**
    * Start the local Flask server:
    ```bash
    flask --app app run
    ```
    * The server will run on `http://localhost:5000`.
    * Open `playground.html` in your browser (it's configured to talk to port 5000).
    * **Note:** You must edit `app.py` and set `YOUR_ZYWRAP_API_KEY` to test the "Run Wrapper" button.

6.  **Set Up Sync:**
    * Edit `zywrap-sync.py` with your `YOUR_ZYWRAP_API_KEY_HERE`.
    * Set up a cron job to run this script daily:
    ```bash
    0 3 * * * /path/to/your/project/venv/bin/python /path/to/your/project/python/zywrap-sync.py
    ```