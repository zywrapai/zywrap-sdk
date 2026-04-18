# Zywrap Node.js SDK Example

This directory contains a complete, runnable example for integrating Zywrap's offline data bundle with a Node.js application using a **PostgreSQL** database.

## Files

* `schema.postgres.sql`: The SQL schema for creating all necessary tables.
* `db.js`: The database connection script (using `pg`).
* `download-bundle.js`: A script to programmatically download the `zywrap-data.zip` bundle.
* `import.js`: A script to perform a full, one-time import of the `zywrap-data.json` file.
* `zywrap-sync.js`: A script to fetch and apply delta-updates (for a cron job).
* `app.js`: An Express.js backend server that mimics the Zywrap API for the local playground.
* `playground.html`: A frontend HTML file to interact with your local `app.js` server.
* `package.json`: Project dependencies.

## 🚀 How to Run

1.  **Database Setup:**
    * Create a new PostgreSQL database (e.g., `zywrap_db`).
    * Import the schema: `psql -U postgres -d zywrap_db -f schema.postgres.sql`
    * Edit `db.js` with your database credentials.

2.  **Install Dependencies:**
    * Run `npm install`

3.  **Get Data:**
    * Download the `zywrap-data.zip` bundle from your [Zywrap account](https://zywrap.com/sdk/nodejs).
    * Unzip it to get `zywrap-data.json` and place it in this directory.
    * (Alternatively, edit `download-bundle.js` with your API key and run `npm run download`).

4.  **Initial Import:**
    * Run the import script:
    ```bash
    npm run import
    ```

5.  **Run the Playground:**
    * Start the local Express server:
    ```bash
    npm start
    ```
    * The server will run on `http://localhost:3000`.
    * Open `playground.html` in your browser (it's configured to talk to port 3000).
    * **Note:** You must edit `app.js` and set `YOUR_ZYWRAP_API_KEY` to test the "Run Wrapper" button.

6.  **Set Up Sync:**
    * Edit `zywrap-sync.js` with your `YOUR_ZYWRAP_API_KEY_HERE`.
    * Set up a cron job to run this script daily:
    ```bash
    0 3 * * * /usr/bin/node /path/to/your/project/nodejs/zywrap-sync.js
    ```