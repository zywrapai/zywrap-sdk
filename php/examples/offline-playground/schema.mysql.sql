
-- Database Schema for Zywrap Offline SDK V1 (MySQL/MariaDB)

CREATE TABLE `ai_models` (
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `categories` (
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `languages` (
  `code` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` tinyint(1) DEFAULT 1,
  `ordering` int(11) DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `use_cases` (
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `category_code` varchar(255) DEFAULT NULL,
  `schema_data` json DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `ordering` bigint DEFAULT NULL,
  PRIMARY KEY (`code`),
  KEY `category_code` (`category_code`),
  CONSTRAINT `use_cases_ibfk_1` FOREIGN KEY (`category_code`) REFERENCES `categories` (`code`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `wrappers` (
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `use_case_code` varchar(255) DEFAULT NULL,
  `featured` tinyint(1) DEFAULT NULL,
  `base` tinyint(1) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `ordering` bigint DEFAULT NULL,
  PRIMARY KEY (`code`),
  KEY `use_case_code` (`use_case_code`),
  CONSTRAINT `wrappers_ibfk_1` FOREIGN KEY (`use_case_code`) REFERENCES `use_cases` (`code`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `block_templates` (
  `type` varchar(50) NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`type`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `settings` (
  `setting_key` VARCHAR(255) NOT NULL,
  `setting_value` TEXT,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `usage_logs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `trace_id` varchar(255) DEFAULT NULL,
  `wrapper_code` varchar(255) DEFAULT NULL,
  `model_code` varchar(255) DEFAULT NULL,
  `prompt_tokens` int(11) DEFAULT 0,
  `completion_tokens` int(11) DEFAULT 0,
  `total_tokens` int(11) DEFAULT 0,
  `credits_used` bigint DEFAULT 0,
  `latency_ms` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'success',
  `error_message` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `wrapper_idx` (`wrapper_code`),
  KEY `model_idx` (`model_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
