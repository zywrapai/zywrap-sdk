-- Database Schema for Zywrap Offline SDK (MySQL/MariaDB)

CREATE TABLE `ai_models` (
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `provider_id` varchar(255) DEFAULT NULL,
  `ordering` int(11) DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `categories` (
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `ordering` int(11) DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `languages` (
  `code` varchar(10) NOT NULL,
  `name` varchar(255) NOT NULL,
  `ordering` int(11) DEFAULT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `wrappers` (
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `category_code` varchar(255) DEFAULT NULL,
  `featured` tinyint(1) DEFAULT NULL,
  `base` tinyint(1) DEFAULT NULL,
  `ordering` int(11) DEFAULT NULL,
  PRIMARY KEY (`code`),
  KEY `category_code` (`category_code`),
  CONSTRAINT `wrappers_ibfk_1` FOREIGN KEY (`category_code`) REFERENCES `categories` (`code`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `block_templates` (
  `type` varchar(50) NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`type`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `settings` (
  `setting_key` VARCHAR(255) NOT NULL,
  `setting_value` TEXT,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;