# FILE: db.py
# Uses the 'psycopg2' library for PostgreSQL
# pip install psycopg2-binary

import psycopg2
import psycopg2.extras 
import sys

# Replace with your actual database credentials
DB_SETTINGS = {
    "dbname": "zywrap_db",
    "user": "postgres",
    "password": "password",
    "host": "localhost",
    "port": "5432"
}

def get_db_connection():
    """Establishes and returns a new database connection."""
    try:
        conn = psycopg2.connect(**DB_SETTINGS)
        return conn
    except psycopg2.OperationalError as e:
        print(f"FATAL: Could not connect to the database.\n{e}", file=sys.stderr)
        sys.exit(1)