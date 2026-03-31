
-- Database Schema for Zywrap Offline SDK v1.0 (PostgreSQL)

CREATE TABLE "ai_models" (
  "code" VARCHAR(255) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "status" BOOLEAN DEFAULT TRUE,
  "ordering" INT
);

CREATE TABLE "categories" (
  "code" VARCHAR(255) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "status" BOOLEAN DEFAULT TRUE,
  "ordering" INT
);

CREATE TABLE "languages" (
  "code" VARCHAR(10) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "status" BOOLEAN DEFAULT TRUE,
  "ordering" INT
);

CREATE TABLE "use_cases" (
  "code" VARCHAR(255) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "description" TEXT,
  "category_code" VARCHAR(255) REFERENCES categories(code) ON DELETE SET NULL,
  "schema_data" JSONB,
  "status" BOOLEAN DEFAULT TRUE,
  "ordering" BIGINT
);

CREATE TABLE "wrappers" (
  "code" VARCHAR(255) PRIMARY KEY,
  "name" VARCHAR(255) NOT NULL,
  "description" TEXT,
  "use_case_code" VARCHAR(255) REFERENCES use_cases(code) ON DELETE SET NULL,
  "featured" BOOLEAN DEFAULT FALSE,
  "base" BOOLEAN DEFAULT FALSE,
  "status" BOOLEAN DEFAULT TRUE,
  "ordering" BIGINT
);

CREATE TABLE "block_templates" (
  "type" VARCHAR(50) NOT NULL,
  "code" VARCHAR(255) NOT NULL,
  "name" VARCHAR(255) NOT NULL,
  "status" BOOLEAN DEFAULT TRUE,
  PRIMARY KEY ("type", "code")
);

CREATE TABLE "settings" (
  "setting_key" VARCHAR(255) PRIMARY KEY,
  "setting_value" TEXT
);

CREATE TABLE "usage_logs" (
  "id" BIGSERIAL PRIMARY KEY,
  "trace_id" VARCHAR(255),
  "wrapper_code" VARCHAR(255),
  "model_code" VARCHAR(255),
  "prompt_tokens" INT DEFAULT 0,
  "completion_tokens" INT DEFAULT 0,
  "total_tokens" INT DEFAULT 0,
  "credits_used" BIGINT DEFAULT 0,
  "latency_ms" INT DEFAULT 0,
  "status" VARCHAR(50) DEFAULT 'success',
  "error_message" TEXT,
  "created_at" TIMESTAMPTZ DEFAULT NOW()
);

-- Indexes for performance
CREATE INDEX idx_usage_wrapper ON usage_logs(wrapper_code);
CREATE INDEX idx_usage_model ON usage_logs(model_code);
CREATE INDEX idx_use_case_cat ON use_cases(category_code);
CREATE INDEX idx_wrapper_uc ON wrappers(use_case_code);
