# Zywrap PHP SDK Example

This directory contains a complete, runnable example for integrating Zywrap's offline data bundle with a PHP application using a **MySQL/MariaDB** database.

## Files

* `schema.mysql.sql`: The SQL schema for creating all necessary tables.
* `db.php`: The database connection script (PDO).
* `download-bundle.php`: A script to programmatically download the `zywrap-data.zip` bundle.
* `import.php`: A script to perform a full, one-time import of the `zywrap-data.json` file.
* `zywrap-sync.php`: A script to fetch and apply delta-updates (for a cron job).
* `api.php`: A backend server that mimics the Zywrap API for the local playground.
* `playground.html`: A frontend HTML file to interact with your local `api.php` server.

## 🚀 How to Run

1.  **Database Setup:**
    * Create a new MySQL/MariaDB database (e.g., `zywrap_db`).
    * Import the schema: `mysql -u root -p zywrap_db < schema.mysql.sql`
    * Edit `db.php` with your database credentials.

2.  **Get Data:**
    * Download the `zywrap-data.zip` bundle from your [Zywrap account](https://zywrap.com/sdk/php).
    * Unzip it to get `zywrap-data.json` and place it in this directory.
    * (Alternatively, edit `download-bundle.php` with your API key and run `php download-bundle.php`).

3.  **Initial Import:**
    * Run the import script from your terminal:
    ```bash
    php import.php
    ```

4.  **Run the Playground:**
    * Start the local PHP server:
    ```bash
    php -S localhost:8000
    ```
    * Open `http://localhost:8000/playground.html` in your browser.
    * **Note:** You must edit `api.php` and set `YOUR_ZYWRAP_API_KEY` to test the "Run Wrapper" button.

5.  **Set Up Sync:**
    * Edit `zywrap-sync.php` with your `YOUR_ZYWRAP_API_KEY`.
    * Set up a cron job to run this script daily:
    ```bash
    0 3 * * * /usr/bin/php /path/to/your/project/php/zywrap-sync.php
    ```