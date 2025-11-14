-- Database Schema for Zywrap Offline SDK (PostgreSQL)
-- This schema includes all necessary tables, columns, and indexes.

CREATE TABLE "settings" (
  "setting_key" VARCHAR(255) PRIMARY KEY,
  "setting_value" TEXT
);

CREATE TABLE "categories" (
  "code" VARCHAR(255) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "ordering" INT,
  "created_at" TIMESTAMPTZ DEFAULT NOW(),
  "updated_at" TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE "languages" (
  "code" VARCHAR(10) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "ordering" INT,
  "created_at" TIMESTAMPTZ DEFAULT NOW(),
  "updated_at" TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE "ai_models" (
  "code" VARCHAR(255) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "provider_id" VARCHAR(255),
  "ordering" INT,
  "created_at" TIMESTAMPTZ DEFAULT NOW(),
  "updated_at" TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE "wrappers" (
  "code" VARCHAR(255) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "description" TEXT,
  "category_code" VARCHAR(255) REFERENCES categories(code) ON DELETE SET NULL,
  "featured" BOOLEAN DEFAULT FALSE,
  "base" BOOLEAN DEFAULT FALSE,
  "ordering" INT,
  "created_at" TIMESTAMPTZ DEFAULT NOW(),
  "updated_at" TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE "block_templates" (
  "type" VARCHAR(50) NOT NULL,
  "code" VARCHAR(255) NOT NULL,
  "name" VARCHAR(255) NOT NULL,
  "created_at" TIMESTAMPTZ DEFAULT NOW(),
  "updated_at" TIMESTAMPTZ DEFAULT NOW(),
  PRIMARY KEY ("type", "code")
);