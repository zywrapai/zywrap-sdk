// FILE: db.js
// Uses the popular 'pg' library for PostgreSQL
// npm install pg

const { Pool } = require('pg');

const pool = new Pool({
  user: 'postgres',       // Your DB user
  host: 'localhost',
  database: 'zywrap_db',  // Your DB name
  password: 'password',   // Your DB password
  port: 5432,
});

module.exports = pool;