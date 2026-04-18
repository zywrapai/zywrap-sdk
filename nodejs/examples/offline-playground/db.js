
// FILE: db.js
// Uses the popular 'pg' library for PostgreSQL
// npm install pg

const { Pool } = require('pg');

// Replace with your actual database credentials
const pool = new Pool({
  user: 'postgres',
  host: 'localhost',
  database: 'zywrap_db',
  password: 'password',
  port: 5432,
});

pool.on('error', (err, client) => {
  console.error('Unexpected error on idle client', err);
  process.exit(-1);
});

module.exports = {
  query: (text, params) => pool.query(text, params),
  pool
};
