/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `log_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(255) DEFAULT NULL,
  `causer_type` varchar(255) DEFAULT NULL,
  `causer_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attribute_changes`)),
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`),
  KEY `activity_log_company_id_index` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_pending_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_pending_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `conversation_id` varchar(255) DEFAULT NULL,
  `type` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `summary` varchar(500) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `remind_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `result` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_pending_actions_user_id_foreign` (`user_id`),
  KEY `ai_pending_actions_customer_id_foreign` (`customer_id`),
  KEY `ai_pending_actions_company_id_user_id_status_index` (`company_id`,`user_id`,`status`),
  KEY `ai_pending_actions_type_status_remind_at_delivered_at_index` (`type`,`status`,`remind_at`,`delivered_at`),
  CONSTRAINT `ai_pending_actions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_pending_actions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_pending_actions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_usage_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_usage_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `conversation_id` varchar(64) DEFAULT NULL,
  `model` varchar(60) NOT NULL,
  `input_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `output_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `cache_read_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `cache_write_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `cost_estimate` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_usage_log_user_id_foreign` (`user_id`),
  KEY `ai_usage_log_company_id_created_at_index` (`company_id`,`created_at`),
  CONSTRAINT `ai_usage_log_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_usage_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `attachable_type` varchar(255) NOT NULL,
  `attachable_id` bigint(20) unsigned NOT NULL,
  `disk` varchar(255) NOT NULL DEFAULT 'local',
  `path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `size` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `uploaded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attachments_company_id_foreign` (`company_id`),
  KEY `attachments_attachable_type_attachable_id_index` (`attachable_type`,`attachable_id`),
  KEY `attachments_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  CONSTRAINT `attachments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attachments_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guard` varchar(20) NOT NULL,
  `event` varchar(20) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `auth_events_ip_address_created_at_index` (`ip_address`,`created_at`),
  KEY `auth_events_email_created_at_index` (`email`,`created_at`),
  KEY `auth_events_event_index` (`event`),
  KEY `auth_events_guard_user_id_index` (`guard`,`user_id`),
  KEY `auth_events_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `iban` varchar(40) DEFAULT NULL,
  `account_name` varchar(160) DEFAULT NULL,
  `swift` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `show_on_invoices` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bank_accounts_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  CONSTRAINT `bank_accounts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_connections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `source` varchar(40) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_connections_company_id_source_index` (`company_id`,`source`),
  KEY `billing_connections_company_id_is_active_index` (`company_id`,`is_active`),
  CONSTRAINT `billing_connections_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `canned_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canned_replies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `canned_reply_category_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `body` text NOT NULL,
  `sort` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `canned_replies_company_id_foreign` (`company_id`),
  KEY `canned_replies_canned_reply_category_id_foreign` (`canned_reply_category_id`),
  CONSTRAINT `canned_replies_canned_reply_category_id_foreign` FOREIGN KEY (`canned_reply_category_id`) REFERENCES `canned_reply_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `canned_replies_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `canned_reply_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `canned_reply_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `sort` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `canned_reply_categories_company_id_name_unique` (`company_id`,`name`),
  CONSTRAINT `canned_reply_categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cmr_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cmr_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `cmr_note_id` bigint(20) unsigned NOT NULL,
  `marks_numbers` varchar(60) DEFAULT NULL,
  `packages_count` int(10) unsigned DEFAULT NULL,
  `packing_method` varchar(40) DEFAULT NULL,
  `nature_en` varchar(256) DEFAULT NULL,
  `statistical_no` varchar(20) DEFAULT NULL,
  `weight_kg` decimal(9,3) DEFAULT NULL,
  `volume_m3` decimal(9,3) DEFAULT NULL,
  `adr_class` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cmr_lines_company_id_foreign` (`company_id`),
  KEY `cmr_lines_cmr_note_id_index` (`cmr_note_id`),
  CONSTRAINT `cmr_lines_cmr_note_id_foreign` FOREIGN KEY (`cmr_note_id`) REFERENCES `cmr_notes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cmr_lines_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cmr_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cmr_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `number` int(10) unsigned NOT NULL,
  `reference_no` varchar(40) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `source_type` varchar(255) DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `issued_at` datetime NOT NULL,
  `sender_text` text DEFAULT NULL,
  `consignee_text` text DEFAULT NULL,
  `delivery_text` text DEFAULT NULL,
  `taking_over_place` varchar(255) DEFAULT NULL,
  `taking_over_at` datetime DEFAULT NULL,
  `carrier_name` varchar(255) DEFAULT NULL,
  `carrier_address` varchar(255) DEFAULT NULL,
  `successive_carrier` varchar(255) DEFAULT NULL,
  `carrier_reservations` text DEFAULT NULL,
  `tractor_plate` varchar(40) DEFAULT NULL,
  `trailer_plate` varchar(40) DEFAULT NULL,
  `annexed_documents` text DEFAULT NULL,
  `sender_instructions` text DEFAULT NULL,
  `special_agreements` text DEFAULT NULL,
  `freight_paid` tinyint(1) DEFAULT NULL,
  `charges_to_be_paid_by` varchar(12) DEFAULT NULL,
  `carriage_charges` decimal(14,2) DEFAULT NULL,
  `reductions` decimal(14,2) DEFAULT NULL,
  `balance` decimal(14,2) DEFAULT NULL,
  `supplement` decimal(14,2) DEFAULT NULL,
  `misc_charges` decimal(14,2) DEFAULT NULL,
  `total_charges` decimal(14,2) DEFAULT NULL,
  `cash_on_delivery` decimal(14,2) DEFAULT NULL,
  `established_place` varchar(255) DEFAULT NULL,
  `established_on` date DEFAULT NULL,
  `copies_count` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `printed` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cmr_notes_company_id_number_unique` (`company_id`,`number`),
  KEY `cmr_notes_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `cmr_notes_customer_id_foreign` (`customer_id`),
  KEY `cmr_notes_company_id_issued_at_index` (`company_id`,`issued_at`),
  CONSTRAINT `cmr_notes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cmr_notes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  `slug` varchar(255) NOT NULL,
  `country_code` varchar(2) NOT NULL DEFAULT 'GR',
  `einvoice_provider` varchar(20) NOT NULL DEFAULT 'gr-mydata',
  `einvoice_provider_key` varchar(40) DEFAULT NULL,
  `einvoice_provider_config` text DEFAULT NULL,
  `einvoice_provider_mode` varchar(16) NOT NULL DEFAULT 'off',
  `einvoice_include_customer_email` tinyint(1) NOT NULL DEFAULT 0,
  `afm` varchar(20) DEFAULT NULL,
  `tax_office` varchar(60) DEFAULT NULL,
  `kad_primary` varchar(20) DEFAULT NULL,
  `business_activity_type` varchar(20) DEFAULT NULL,
  `gemi` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `address_en` varchar(255) DEFAULT NULL,
  `city` varchar(60) DEFAULT NULL,
  `city_en` varchar(255) DEFAULT NULL,
  `postcode` varchar(10) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `pdf_footer_text` text DEFAULT NULL,
  `show_customer_balance_on_pdf` tinyint(1) NOT NULL DEFAULT 0,
  `mail_from_address` varchar(191) DEFAULT NULL,
  `mail_from_name` varchar(191) DEFAULT NULL,
  `invoice_audit_bcc` varchar(500) DEFAULT NULL,
  `auto_email_on_mydata_accept` tinyint(1) NOT NULL DEFAULT 0,
  `auto_email_on_issue` tinyint(1) NOT NULL DEFAULT 0,
  `mydata_auto_fetch_expenses` tinyint(1) NOT NULL DEFAULT 0,
  `mail_smtp_host` varchar(191) DEFAULT NULL,
  `mail_smtp_port` smallint(5) unsigned DEFAULT NULL,
  `mail_smtp_username` varchar(191) DEFAULT NULL,
  `mail_smtp_password` text DEFAULT NULL,
  `mail_smtp_encryption` varchar(10) DEFAULT NULL,
  `mail_subject_template` varchar(191) DEFAULT NULL,
  `mail_body_template` text DEFAULT NULL,
  `whmcs_api_url` varchar(500) DEFAULT NULL,
  `whmcs_fetch_via_bridge` tinyint(1) NOT NULL DEFAULT 0,
  `whmcs_push_payments` tinyint(1) NOT NULL DEFAULT 0,
  `whmcs_api_identifier` varchar(191) DEFAULT NULL,
  `whmcs_api_secret` text DEFAULT NULL,
  `whmcs_custom_field_map` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`whmcs_custom_field_map`)),
  `whmcs_webhook_secret` text DEFAULT NULL,
  `whmcs_invoice_min_date` date DEFAULT NULL,
  `whmcs_third_party_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `whmcs_auto_issue_immediate` tinyint(1) NOT NULL DEFAULT 0,
  `whmcs_default_invoice_type_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_default_receipt_type_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_default_unpaid_type_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_amount_includes_tax` tinyint(1) NOT NULL DEFAULT 1,
  `mydata_aade_id_sandbox` varchar(255) DEFAULT NULL,
  `mydata_subscription_key_sandbox` text DEFAULT NULL,
  `mydata_aade_id_production` varchar(255) DEFAULT NULL,
  `mydata_subscription_key_production` text DEFAULT NULL,
  `mydata_mode` varchar(16) NOT NULL DEFAULT 'off',
  `mydata_read_env` varchar(16) DEFAULT NULL,
  `ai_assistant_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `support_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `enable_domain_management` tinyint(1) NOT NULL DEFAULT 0,
  `ai_model` varchar(60) DEFAULT NULL,
  `ai_monthly_token_cap` bigint(20) unsigned DEFAULT NULL,
  `ai_api_key` text DEFAULT NULL,
  `mydata_send_item_descr` tinyint(1) NOT NULL DEFAULT 0,
  `gsis_username` varchar(120) DEFAULT NULL,
  `gsis_password` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `quote_counter` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `companies_slug_unique` (`slug`),
  KEY `companies_whmcs_default_invoice_type_id_foreign` (`whmcs_default_invoice_type_id`),
  KEY `companies_whmcs_default_receipt_type_id_foreign` (`whmcs_default_receipt_type_id`),
  KEY `companies_whmcs_default_unpaid_type_id_foreign` (`whmcs_default_unpaid_type_id`),
  CONSTRAINT `companies_whmcs_default_invoice_type_id_foreign` FOREIGN KEY (`whmcs_default_invoice_type_id`) REFERENCES `invoice_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `companies_whmcs_default_receipt_type_id_foreign` FOREIGN KEY (`whmcs_default_receipt_type_id`) REFERENCES `invoice_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `companies_whmcs_default_unpaid_type_id_foreign` FOREIGN KEY (`whmcs_default_unpaid_type_id`) REFERENCES `invoice_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_backup_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_backup_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `trigger` varchar(20) NOT NULL DEFAULT 'manual',
  `bucket` varchar(20) NOT NULL,
  `secrets_mode` varchar(20) NOT NULL,
  `destinations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`destinations`)),
  `bytes` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ok',
  `message` text DEFAULT NULL,
  `bundle_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_backup_runs_company_id_started_at_index` (`company_id`,`started_at`),
  CONSTRAINT `company_backup_runs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_backup_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_backup_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `frequency` varchar(10) NOT NULL DEFAULT 'off',
  `run_at_time` varchar(5) NOT NULL DEFAULT '02:00',
  `bucket` varchar(20) NOT NULL DEFAULT 'full',
  `secrets_mode` varchar(20) NOT NULL DEFAULT 'passphrase',
  `passphrase` text DEFAULT NULL,
  `retention_keep` int(10) unsigned NOT NULL DEFAULT 7,
  `retention_days` int(10) unsigned DEFAULT NULL,
  `destinations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`destinations`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_backup_settings_company_id_unique` (`company_id`),
  CONSTRAINT `company_backup_settings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_user` (
  `company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`company_id`,`user_id`),
  KEY `company_user_user_id_foreign` (`user_id`),
  CONSTRAINT `company_user_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conf_params`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `conf_params` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `varname` varchar(60) NOT NULL,
  `data_int` int(11) DEFAULT NULL,
  `data_string` varchar(120) DEFAULT NULL,
  `data_timestamp` timestamp NULL DEFAULT NULL,
  `data_float` double DEFAULT NULL,
  `data_numeric` decimal(15,3) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conf_params_company_id_varname_unique` (`company_id`,`varname`),
  CONSTRAINT `conf_params_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `role` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_contacts_customer_id_foreign` (`customer_id`),
  KEY `customer_contacts_company_id_customer_id_index` (`company_id`,`customer_id`),
  CONSTRAINT `customer_contacts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_contacts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_user_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_user_access` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_user_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'owner',
  `granted_by` bigint(20) unsigned DEFAULT NULL,
  `granted_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cua_login_company_customer_unique` (`customer_user_id`,`company_id`,`customer_id`),
  KEY `customer_user_access_customer_id_foreign` (`customer_id`),
  KEY `customer_user_access_granted_by_foreign` (`granted_by`),
  KEY `customer_user_access_company_id_customer_id_index` (`company_id`,`customer_id`),
  CONSTRAINT `customer_user_access_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_user_access_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_user_access_customer_user_id_foreign` FOREIGN KEY (`customer_user_id`) REFERENCES `customer_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_user_access_granted_by_foreign` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'invited',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `locale` varchar(12) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_users_email_unique` (`email`),
  UNIQUE KEY `customer_users_username_unique` (`username`),
  KEY `customer_users_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_users_password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_users_password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(60) DEFAULT NULL,
  `afm` varchar(20) DEFAULT NULL,
  `afm_key` varchar(32) DEFAULT NULL,
  `afm_key_parked` tinyint(1) NOT NULL DEFAULT 0,
  `name` varchar(191) NOT NULL,
  `address1` varchar(60) DEFAULT NULL,
  `address2` varchar(60) DEFAULT NULL,
  `city` varchar(60) DEFAULT NULL,
  `postcode` varchar(10) DEFAULT NULL,
  `phone1` varchar(30) DEFAULT NULL,
  `phone2` varchar(30) DEFAULT NULL,
  `fax` varchar(30) DEFAULT NULL,
  `occupation` varchar(120) DEFAULT NULL,
  `tax_office` varchar(60) DEFAULT NULL,
  `kad_primary` varchar(20) DEFAULT NULL,
  `discount` decimal(5,2) NOT NULL DEFAULT 0.00,
  `email` varchar(120) DEFAULT NULL,
  `secondary_email` varchar(120) DEFAULT NULL,
  `auto_email_invoices` tinyint(1) NOT NULL DEFAULT 1,
  `country` varchar(60) DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `vat_vies` varchar(30) DEFAULT NULL,
  `withhold_tax` int(11) DEFAULT NULL,
  `sort_order` int(10) unsigned DEFAULT NULL,
  `alt_customer_legacy_id` int(10) unsigned DEFAULT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `needs_immediate_invoice` tinyint(1) NOT NULL DEFAULT 0,
  `needs_invoice_before_payment` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `show_balance_on_pdf` tinyint(1) DEFAULT NULL,
  `peppol_endpoint` varchar(255) DEFAULT NULL,
  `referred_by_customer_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_client_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_reseller_routes` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  UNIQUE KEY `customers_company_afm_key_unique` (`company_id`,`afm_key`),
  KEY `customers_payment_method_id_foreign` (`payment_method_id`),
  KEY `customers_company_id_afm_index` (`company_id`,`afm`),
  KEY `customers_whmcs_client_id_index` (`whmcs_client_id`),
  KEY `customers_referred_by_customer_id_foreign` (`referred_by_customer_id`),
  KEY `customers_company_id_is_active_index` (`company_id`,`is_active`),
  KEY `customers_company_id_needs_immediate_invoice_index` (`company_id`,`needs_immediate_invoice`),
  KEY `customers_company_id_is_favorite_index` (`company_id`,`is_favorite`),
  CONSTRAINT `customers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customers_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customers_referred_by_customer_id_foreign` FOREIGN KEY (`referred_by_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_marks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_marks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `delivery_note_id` bigint(20) unsigned DEFAULT NULL,
  `mark` varchar(50) DEFAULT NULL,
  `cancellation_mark` varchar(40) DEFAULT NULL,
  `mydata_action` varchar(30) DEFAULT NULL,
  `provider_key` varchar(40) DEFAULT NULL,
  `authentication_code` varchar(255) DEFAULT NULL,
  `provider_delivery_state` varchar(80) DEFAULT NULL,
  `invoice_url` varchar(1500) DEFAULT NULL,
  `request` mediumtext DEFAULT NULL,
  `response` mediumtext DEFAULT NULL,
  `mark_date` date DEFAULT NULL,
  `mark_time` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `movable_type` varchar(255) DEFAULT NULL,
  `movable_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_marks_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  KEY `delivery_marks_delivery_note_id_index` (`delivery_note_id`),
  KEY `delivery_marks_movable_type_movable_id_index` (`movable_type`,`movable_id`),
  CONSTRAINT `delivery_marks_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_marks_delivery_note_id_foreign` FOREIGN KEY (`delivery_note_id`) REFERENCES `delivery_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_methods_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  CONSTRAINT `delivery_methods_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_note_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_note_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `delivery_note_id` bigint(20) unsigned DEFAULT NULL,
  `event_mark` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(30) NOT NULL,
  `event_timestamp` datetime DEFAULT NULL,
  `actor_vat` varchar(20) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `dedup_key` varchar(80) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `movable_type` varchar(255) DEFAULT NULL,
  `movable_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_note_events_delivery_note_id_dedup_key_unique` (`delivery_note_id`,`dedup_key`),
  UNIQUE KEY `delivery_note_events_movable_type_movable_id_dedup_key_unique` (`movable_type`,`movable_id`,`dedup_key`),
  KEY `delivery_note_events_company_id_delivery_note_id_index` (`company_id`,`delivery_note_id`),
  KEY `delivery_note_events_movable_type_movable_id_index` (`movable_type`,`movable_id`),
  CONSTRAINT `delivery_note_events_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_note_events_delivery_note_id_foreign` FOREIGN KEY (`delivery_note_id`) REFERENCES `delivery_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_note_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_note_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `delivery_note_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(9,3) NOT NULL DEFAULT 1.000,
  `measurement_unit` tinyint(3) unsigned DEFAULT NULL,
  `metric_unit` varchar(15) DEFAULT NULL,
  `move_purpose_line` tinyint(3) unsigned DEFAULT NULL,
  `product_descr` varchar(256) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_note_lines_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  KEY `delivery_note_lines_product_id_foreign` (`product_id`),
  KEY `delivery_note_lines_delivery_note_id_index` (`delivery_note_id`),
  CONSTRAINT `delivery_note_lines_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_note_lines_delivery_note_id_foreign` FOREIGN KEY (`delivery_note_id`) REFERENCES `delivery_notes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_note_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `invcode` varchar(30) DEFAULT NULL,
  `series` varchar(20) DEFAULT NULL,
  `code` bigint(20) unsigned DEFAULT NULL,
  `delivery_type_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `issued_at` datetime NOT NULL,
  `mydata_type` varchar(10) DEFAULT NULL,
  `move_purpose` tinyint(3) unsigned DEFAULT NULL,
  `other_move_purpose_title` varchar(120) DEFAULT NULL,
  `distribution_aim_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_method_id` bigint(20) unsigned DEFAULT NULL,
  `dispatch_at` datetime DEFAULT NULL,
  `vehicle_number` varchar(40) DEFAULT NULL,
  `transport_type` tinyint(3) unsigned DEFAULT NULL,
  `carrier_afm` varchar(20) DEFAULT NULL,
  `loading_street` varchar(120) DEFAULT NULL,
  `loading_number` varchar(20) DEFAULT NULL,
  `loading_postcode` varchar(10) DEFAULT NULL,
  `loading_city` varchar(60) DEFAULT NULL,
  `start_shipping_branch` int(10) unsigned DEFAULT NULL,
  `delivery_street` varchar(120) DEFAULT NULL,
  `delivery_number` varchar(20) DEFAULT NULL,
  `delivery_postcode` varchar(10) DEFAULT NULL,
  `delivery_city` varchar(60) DEFAULT NULL,
  `complete_shipping_branch` int(10) unsigned DEFAULT NULL,
  `recipient_name` varchar(120) DEFAULT NULL,
  `recipient_afm` varchar(20) DEFAULT NULL,
  `recipient_country` varchar(2) DEFAULT NULL,
  `third_party_collection` tinyint(1) NOT NULL DEFAULT 0,
  `local_status` varchar(20) NOT NULL DEFAULT 'draft',
  `printed` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `mydata_sent` tinyint(1) DEFAULT NULL,
  `mydata_state` varchar(30) DEFAULT NULL,
  `mydata_mark` varchar(120) DEFAULT NULL,
  `mydata_url` varchar(1500) DEFAULT NULL,
  `mydata_pending_since` timestamp NULL DEFAULT NULL,
  `delivery_state` varchar(30) DEFAULT NULL,
  `transfer_mark` varchar(50) DEFAULT NULL,
  `outcome_mark` varchar(50) DEFAULT NULL,
  `return_mark` varchar(50) DEFAULT NULL,
  `reject_mark` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_notes_company_id_invcode_unique` (`company_id`,`invcode`),
  KEY `delivery_notes_delivery_type_id_foreign` (`delivery_type_id`),
  KEY `delivery_notes_customer_id_foreign` (`customer_id`),
  KEY `delivery_notes_distribution_aim_id_foreign` (`distribution_aim_id`),
  KEY `delivery_notes_delivery_method_id_foreign` (`delivery_method_id`),
  KEY `delivery_notes_company_id_issued_at_index` (`company_id`,`issued_at`),
  KEY `delivery_notes_company_id_delivery_type_id_code_index` (`company_id`,`delivery_type_id`,`code`),
  KEY `delivery_notes_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `delivery_notes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_notes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_notes_delivery_method_id_foreign` FOREIGN KEY (`delivery_method_id`) REFERENCES `delivery_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_notes_delivery_type_id_foreign` FOREIGN KEY (`delivery_type_id`) REFERENCES `invoice_types` (`id`),
  CONSTRAINT `delivery_notes_distribution_aim_id_foreign` FOREIGN KEY (`distribution_aim_id`) REFERENCES `distribution_aims` (`id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_notes_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `distribution_aims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `distribution_aims` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `distribution_aims_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  CONSTRAINT `distribution_aims_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `domain_id` bigint(20) unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `name` varchar(190) NOT NULL,
  `org` varchar(190) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address1` varchar(190) DEFAULT NULL,
  `address2` varchar(190) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `postcode` varchar(20) DEFAULT NULL,
  `country` char(2) DEFAULT NULL,
  `registrar_contact_handle` varchar(40) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_contacts_domain_id_type_unique` (`domain_id`,`type`),
  KEY `domain_contacts_company_id_domain_id_index` (`company_id`,`domain_id`),
  CONSTRAINT `domain_contacts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `domain_contacts_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_nameservers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_nameservers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `domain_id` bigint(20) unsigned NOT NULL,
  `host` varchar(190) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_nameservers_domain_id_foreign` (`domain_id`),
  KEY `domain_nameservers_company_id_domain_id_index` (`company_id`,`domain_id`),
  CONSTRAINT `domain_nameservers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `domain_nameservers_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_registrar_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_registrar_connections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `registrar` varchar(40) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `mode` varchar(20) NOT NULL DEFAULT 'off',
  `config` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_registrar_connections_company_id_is_active_index` (`company_id`,`is_active`),
  KEY `domain_registrar_connections_company_id_registrar_index` (`company_id`,`registrar`),
  CONSTRAINT `domain_registrar_connections_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_registrar_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_registrar_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `domain_id` bigint(20) unsigned DEFAULT NULL,
  `registrar_connection_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `status` varchar(20) NOT NULL,
  `request` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request`)),
  `response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response`)),
  `error` text DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domain_registrar_logs_domain_id_foreign` (`domain_id`),
  KEY `domain_registrar_logs_registrar_connection_id_foreign` (`registrar_connection_id`),
  KEY `domain_registrar_logs_invoice_id_foreign` (`invoice_id`),
  KEY `domain_registrar_logs_company_id_domain_id_index` (`company_id`,`domain_id`),
  KEY `domain_registrar_logs_company_id_action_status_index` (`company_id`,`action`,`status`),
  CONSTRAINT `domain_registrar_logs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `domain_registrar_logs_domain_id_foreign` FOREIGN KEY (`domain_id`) REFERENCES `domains` (`id`) ON DELETE SET NULL,
  CONSTRAINT `domain_registrar_logs_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `domain_registrar_logs_registrar_connection_id_foreign` FOREIGN KEY (`registrar_connection_id`) REFERENCES `domain_registrar_connections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_tld_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_tld_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `domain_tld_id` bigint(20) unsigned NOT NULL,
  `operation` varchar(20) NOT NULL,
  `years` tinyint(3) unsigned NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'EUR',
  `cost` decimal(14,2) DEFAULT NULL,
  `price` decimal(14,2) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_tld_prices_domain_tld_id_operation_years_currency_unique` (`domain_tld_id`,`operation`,`years`,`currency`),
  KEY `domain_tld_prices_company_id_domain_tld_id_index` (`company_id`,`domain_tld_id`),
  CONSTRAINT `domain_tld_prices_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `domain_tld_prices_domain_tld_id_foreign` FOREIGN KEY (`domain_tld_id`) REFERENCES `domain_tlds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_tlds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_tlds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `tld` varchar(30) NOT NULL,
  `registrar_connection_id` bigint(20) unsigned DEFAULT NULL,
  `min_years` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `max_years` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `min_chars` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `max_chars` tinyint(3) unsigned NOT NULL DEFAULT 63,
  `allow_idn` tinyint(1) NOT NULL DEFAULT 0,
  `allow_transfer` tinyint(1) NOT NULL DEFAULT 1,
  `grace_period_days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `grace_fee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `redemption_period_days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `redemption_fee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `dns_management` tinyint(1) NOT NULL DEFAULT 0,
  `email_forwarding` tinyint(1) NOT NULL DEFAULT 0,
  `id_protection` tinyint(1) NOT NULL DEFAULT 0,
  `epp_code` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain_tlds_company_id_tld_unique` (`company_id`,`tld`),
  KEY `domain_tlds_registrar_connection_id_foreign` (`registrar_connection_id`),
  KEY `domain_tlds_company_id_is_active_index` (`company_id`,`is_active`),
  CONSTRAINT `domain_tlds_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `domain_tlds_registrar_connection_id_foreign` FOREIGN KEY (`registrar_connection_id`) REFERENCES `domain_registrar_connections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domains`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `service_contract_id` bigint(20) unsigned DEFAULT NULL,
  `domain_tld_id` bigint(20) unsigned NOT NULL,
  `registrar_connection_id` bigint(20) unsigned DEFAULT NULL,
  `sld` varchar(190) NOT NULL,
  `tld` varchar(30) NOT NULL,
  `fqdn` varchar(190) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'active',
  `registered_at` date DEFAULT NULL,
  `transferred_at` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `auto_renew` tinyint(1) NOT NULL DEFAULT 0,
  `transfer_lock` tinyint(1) NOT NULL DEFAULT 0,
  `whois_privacy` tinyint(1) NOT NULL DEFAULT 0,
  `dnssec_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `consent_publish` tinyint(1) NOT NULL DEFAULT 0,
  `registrar_domain_id` varchar(60) DEFAULT NULL,
  `idn_script` varchar(30) DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `sync_error` text DEFAULT NULL,
  `grace_days_override` smallint(5) unsigned DEFAULT NULL,
  `redemption_days_override` smallint(5) unsigned DEFAULT NULL,
  `fee_override` decimal(14,2) DEFAULT NULL,
  `price_override` decimal(14,2) DEFAULT NULL,
  `module_meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`module_meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `domains_company_id_fqdn_unique` (`company_id`,`fqdn`),
  UNIQUE KEY `domains_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  KEY `domains_customer_id_foreign` (`customer_id`),
  KEY `domains_service_contract_id_foreign` (`service_contract_id`),
  KEY `domains_domain_tld_id_foreign` (`domain_tld_id`),
  KEY `domains_registrar_connection_id_foreign` (`registrar_connection_id`),
  KEY `domains_company_id_status_index` (`company_id`,`status`),
  KEY `domains_company_id_expires_at_index` (`company_id`,`expires_at`),
  KEY `domains_company_id_customer_id_index` (`company_id`,`customer_id`),
  CONSTRAINT `domains_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `domains_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `domains_domain_tld_id_foreign` FOREIGN KEY (`domain_tld_id`) REFERENCES `domain_tlds` (`id`),
  CONSTRAINT `domains_registrar_connection_id_foreign` FOREIGN KEY (`registrar_connection_id`) REFERENCES `domain_registrar_connections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `domains_service_contract_id_foreign` FOREIGN KEY (`service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_classification_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_classification_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `supplier_afm` varchar(255) NOT NULL,
  `invoice_type` varchar(255) DEFAULT NULL,
  `classification_type` varchar(255) NOT NULL,
  `classification_category` varchar(255) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `priority` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_classification_rules_company_id_supplier_afm_index` (`company_id`,`supplier_afm`),
  CONSTRAINT `expense_classification_rules_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `expense_id` bigint(20) unsigned NOT NULL,
  `line_number` int(10) unsigned DEFAULT NULL,
  `item_code` varchar(191) DEFAULT NULL,
  `item_descr` varchar(256) DEFAULT NULL,
  `quantity` decimal(9,3) DEFAULT NULL,
  `measurement_unit` varchar(15) DEFAULT NULL,
  `net_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vat_category` tinyint(3) unsigned DEFAULT NULL,
  `vat_exemption_category` tinyint(3) unsigned DEFAULT NULL,
  `vat_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `classification_type` varchar(20) DEFAULT NULL,
  `classification_category` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_lines_company_id_foreign` (`company_id`),
  KEY `expense_lines_expense_id_index` (`expense_id`),
  CONSTRAINT `expense_lines_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_lines_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expense_marks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_marks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `expense_id` bigint(20) unsigned DEFAULT NULL,
  `mark` varchar(50) DEFAULT NULL,
  `mydata_action` varchar(40) DEFAULT NULL,
  `request` mediumtext DEFAULT NULL,
  `response` mediumtext DEFAULT NULL,
  `mark_date` date DEFAULT NULL,
  `mark_time` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expense_marks_expense_id_index` (`expense_id`),
  KEY `expense_marks_company_id_mark_index` (`company_id`,`mark`),
  CONSTRAINT `expense_marks_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_marks_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `mydata_mark` varchar(50) DEFAULT NULL,
  `uid` varchar(80) DEFAULT NULL,
  `authentication_code` varchar(120) DEFAULT NULL,
  `invoice_type` varchar(10) DEFAULT NULL,
  `series` varchar(60) DEFAULT NULL,
  `aa` varchar(60) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `supplier_afm` varchar(20) DEFAULT NULL,
  `supplier_name` varchar(191) DEFAULT NULL,
  `net_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vat_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gross_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `mydata_state` varchar(30) DEFAULT NULL,
  `cancelled_by_mark` varchar(50) DEFAULT NULL,
  `qr_url` varchar(1500) DEFAULT NULL,
  `downloading_invoice_url` varchar(1500) DEFAULT NULL,
  `classification_state` varchar(30) DEFAULT NULL,
  `classification_type` varchar(20) DEFAULT NULL,
  `classification_category` varchar(30) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `category` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `document_path` varchar(1024) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `expenses_company_id_mydata_mark_unique` (`company_id`,`mydata_mark`),
  KEY `expenses_company_id_issue_date_index` (`company_id`,`issue_date`),
  KEY `expenses_company_id_mydata_state_index` (`company_id`,`mydata_state`),
  KEY `expenses_supplier_id_index` (`supplier_id`),
  KEY `expenses_company_id_classification_state_index` (`company_id`,`classification_state`),
  KEY `expenses_company_id_source_category_index` (`company_id`,`source`,`category`),
  CONSTRAINT `expenses_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expenses_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `firebird_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `firebird_import_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'firebird',
  `uploaded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` bigint(20) unsigned NOT NULL,
  `file_sha256` varchar(64) NOT NULL,
  `uploaded_path` varchar(500) DEFAULT NULL,
  `source_files_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`source_files_json`)),
  `status` varchar(20) NOT NULL DEFAULT 'uploaded',
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `counts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counts_json`)),
  `error_message` text DEFAULT NULL,
  `failed_step` varchar(50) DEFAULT NULL,
  `fb_host` varchar(100) NOT NULL DEFAULT '127.0.0.1',
  `fb_user` varchar(100) NOT NULL DEFAULT 'SYSDBA',
  `fb_database` varchar(255) DEFAULT NULL,
  `afm_keep` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `firebird_import_runs_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `firebird_import_runs_company_id_status_created_at_index` (`company_id`,`status`,`created_at`),
  KEY `firebird_import_runs_file_sha256_index` (`file_sha256`),
  CONSTRAINT `firebird_import_runs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `firebird_import_runs_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inbound_delivery_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inbound_delivery_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `mydata_mark` varchar(255) NOT NULL,
  `issuer_afm` varchar(255) DEFAULT NULL,
  `issuer_name` varchar(255) DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_type` varchar(255) DEFAULT NULL,
  `aa` varchar(255) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `qr_code_url` varchar(255) DEFAULT NULL,
  `aade_delivery_status` tinyint(3) unsigned DEFAULT NULL,
  `local_state` varchar(30) NOT NULL DEFAULT 'new',
  `reject_mark` varchar(255) DEFAULT NULL,
  `outcome_mark` varchar(255) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `lifecycle` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`lifecycle`)),
  `last_fetched_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inbound_delivery_notes_company_id_mydata_mark_unique` (`company_id`,`mydata_mark`),
  KEY `inbound_delivery_notes_supplier_id_foreign` (`supplier_id`),
  KEY `inbound_delivery_notes_company_id_local_state_index` (`company_id`,`local_state`),
  CONSTRAINT `inbound_delivery_notes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inbound_delivery_notes_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `original_line_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(9,3) NOT NULL DEFAULT 1.000,
  `price_per_item` decimal(14,2) DEFAULT NULL,
  `discount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `vat_percent` decimal(5,2) DEFAULT NULL,
  `vat_exemption_category` tinyint(3) unsigned DEFAULT NULL,
  `mydata_income_class` varchar(32) DEFAULT NULL,
  `mydata_income_class_category` varchar(32) DEFAULT NULL,
  `net_price` decimal(14,2) DEFAULT NULL,
  `gross_price` decimal(14,2) DEFAULT NULL,
  `product_descr` varchar(256) DEFAULT NULL,
  `metric_unit` varchar(15) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_lines_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  KEY `invoice_lines_product_id_foreign` (`product_id`),
  KEY `invoice_lines_invoice_id_index` (`invoice_id`),
  KEY `invoice_lines_original_line_id_foreign` (`original_line_id`),
  CONSTRAINT `invoice_lines_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_lines_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_lines_original_line_id_foreign` FOREIGN KEY (`original_line_id`) REFERENCES `invoice_lines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoice_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_mail_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_mail_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `recipient` varchar(191) NOT NULL,
  `cc_list` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cc_list`)),
  `bcc_list` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bcc_list`)),
  `from_address` varchar(191) DEFAULT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `trigger` enum('auto','manual','batch') NOT NULL DEFAULT 'auto',
  `send_key` uuid DEFAULT NULL,
  `status` enum('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  `error_message` text DEFAULT NULL,
  `queued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `triggered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_mail_log_company_id_foreign` (`company_id`),
  KEY `invoice_mail_log_triggered_by_user_id_foreign` (`triggered_by_user_id`),
  KEY `invoice_mail_log_invoice_id_created_at_index` (`invoice_id`,`created_at`),
  KEY `invoice_mail_log_status_created_at_index` (`status`,`created_at`),
  KEY `invoice_mail_log_send_key_status_index` (`send_key`,`status`),
  CONSTRAINT `invoice_mail_log_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_mail_log_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_mail_log_triggered_by_user_id_foreign` FOREIGN KEY (`triggered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(6) NOT NULL,
  `name` varchar(120) NOT NULL,
  `invcount` bigint(20) unsigned NOT NULL DEFAULT 1,
  `show_on_menu` tinyint(1) NOT NULL DEFAULT 1,
  `is_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `is_credit` tinyint(1) NOT NULL DEFAULT 0,
  `is_return` tinyint(1) NOT NULL DEFAULT 0,
  `mydata_type` varchar(5) DEFAULT NULL,
  `mydata_income_class` varchar(30) DEFAULT NULL,
  `mydata_income_class_category` varchar(30) DEFAULT NULL,
  `distribution_aim_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_method_id` bigint(20) unsigned DEFAULT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `default_customer_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `mydata_requires_quantity` tinyint(1) NOT NULL DEFAULT 0,
  `is_delivery_note` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_types_company_id_code_unique` (`company_id`,`code`),
  KEY `invoice_types_distribution_aim_id_foreign` (`distribution_aim_id`),
  KEY `invoice_types_delivery_method_id_foreign` (`delivery_method_id`),
  KEY `invoice_types_payment_method_id_foreign` (`payment_method_id`),
  KEY `invoice_types_default_customer_id_foreign` (`default_customer_id`),
  KEY `invoice_types_company_id_is_favorite_index` (`company_id`,`is_favorite`),
  CONSTRAINT `invoice_types_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_types_default_customer_id_foreign` FOREIGN KEY (`default_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoice_types_delivery_method_id_foreign` FOREIGN KEY (`delivery_method_id`) REFERENCES `delivery_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoice_types_distribution_aim_id_foreign` FOREIGN KEY (`distribution_aim_id`) REFERENCES `distribution_aims` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoice_types_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `invcode` varchar(30) DEFAULT NULL,
  `series` varchar(20) DEFAULT NULL,
  `code` bigint(20) unsigned DEFAULT NULL,
  `invoice_type_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `issued_at` datetime NOT NULL,
  `distribution_aim_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_method_id` bigint(20) unsigned DEFAULT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `bank_account_id` bigint(20) unsigned DEFAULT NULL,
  `conv_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `credited_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `reissued_from_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_pending_id` bigint(20) unsigned DEFAULT NULL,
  `service_contract_id` bigint(20) unsigned DEFAULT NULL,
  `whmcs_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `header_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `net_total` decimal(14,2) DEFAULT NULL,
  `gross_total` decimal(14,2) DEFAULT NULL,
  `payable_total` decimal(14,2) DEFAULT NULL,
  `customer_balance_snapshot` decimal(14,2) DEFAULT NULL,
  `paid_total` decimal(14,2) DEFAULT NULL,
  `credited_total` decimal(14,2) DEFAULT NULL,
  `payment_status` varchar(12) DEFAULT NULL,
  `withhold_amount` decimal(14,2) DEFAULT NULL,
  `withhold_category` tinyint(3) unsigned DEFAULT NULL,
  `withhold_rate` decimal(7,4) DEFAULT NULL,
  `fees_amount` decimal(14,2) DEFAULT NULL,
  `fees_category` smallint(5) unsigned DEFAULT NULL,
  `fees_rate` decimal(7,4) DEFAULT NULL,
  `other_taxes_amount` decimal(14,2) DEFAULT NULL,
  `other_taxes_category` smallint(5) unsigned DEFAULT NULL,
  `other_taxes_rate` decimal(7,4) DEFAULT NULL,
  `stamp_duty_amount` decimal(14,2) DEFAULT NULL,
  `stamp_duty_category` smallint(5) unsigned DEFAULT NULL,
  `stamp_duty_rate` decimal(7,4) DEFAULT NULL,
  `deductions_amount` decimal(14,2) DEFAULT NULL,
  `deductions_category` smallint(5) unsigned DEFAULT NULL,
  `deductions_rate` decimal(7,4) DEFAULT NULL,
  `address1` varchar(60) DEFAULT NULL,
  `address2` varchar(60) DEFAULT NULL,
  `city` varchar(60) DEFAULT NULL,
  `postcode` varchar(10) DEFAULT NULL,
  `country` varchar(60) DEFAULT NULL,
  `counterpart_branch` smallint(5) unsigned NOT NULL DEFAULT 0,
  `company_name` varchar(191) DEFAULT NULL,
  `vat_no` varchar(20) DEFAULT NULL,
  `vies_vat` varchar(30) DEFAULT NULL,
  `occupation` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `language` varchar(8) DEFAULT NULL,
  `mydata_sent` tinyint(1) DEFAULT NULL,
  `mydata_state` varchar(30) DEFAULT NULL,
  `local_status` varchar(16) NOT NULL DEFAULT 'draft',
  `cancel_reason` text DEFAULT NULL,
  `mydata_mark` varchar(120) DEFAULT NULL,
  `mydata_url` varchar(1500) DEFAULT NULL,
  `is_delivery_note` tinyint(1) NOT NULL DEFAULT 0,
  `without_digital_transport_tracking` tinyint(1) NOT NULL DEFAULT 0,
  `move_purpose` tinyint(3) unsigned DEFAULT NULL,
  `other_move_purpose_title` varchar(120) DEFAULT NULL,
  `dispatch_at` datetime DEFAULT NULL,
  `vehicle_number` varchar(40) DEFAULT NULL,
  `loading_street` varchar(120) DEFAULT NULL,
  `loading_number` varchar(20) DEFAULT NULL,
  `loading_postcode` varchar(10) DEFAULT NULL,
  `loading_city` varchar(60) DEFAULT NULL,
  `start_shipping_branch` int(10) unsigned DEFAULT NULL,
  `delivery_street` varchar(120) DEFAULT NULL,
  `delivery_number` varchar(20) DEFAULT NULL,
  `delivery_postcode` varchar(10) DEFAULT NULL,
  `delivery_city` varchar(60) DEFAULT NULL,
  `complete_shipping_branch` int(10) unsigned DEFAULT NULL,
  `transport_type` tinyint(3) unsigned DEFAULT NULL,
  `carrier_afm` varchar(20) DEFAULT NULL,
  `delivery_state` varchar(30) DEFAULT NULL,
  `transfer_mark` varchar(50) DEFAULT NULL,
  `return_mark` varchar(50) DEFAULT NULL,
  `mydata_pending_since` timestamp NULL DEFAULT NULL,
  `mydata_type` varchar(5) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_company_id_invcode_unique` (`company_id`,`invcode`),
  UNIQUE KEY `invoices_company_legacy_unique` (`company_id`,`legacy_id`),
  KEY `invoices_invoice_type_id_foreign` (`invoice_type_id`),
  KEY `invoices_customer_id_foreign` (`customer_id`),
  KEY `invoices_distribution_aim_id_foreign` (`distribution_aim_id`),
  KEY `invoices_delivery_method_id_foreign` (`delivery_method_id`),
  KEY `invoices_payment_method_id_foreign` (`payment_method_id`),
  KEY `invoices_conv_invoice_id_foreign` (`conv_invoice_id`),
  KEY `invoices_company_id_issued_at_index` (`company_id`,`issued_at`),
  KEY `invoices_company_id_invoice_type_id_code_index` (`company_id`,`invoice_type_id`,`code`),
  KEY `invoices_credited_invoice_id_foreign` (`credited_invoice_id`),
  KEY `invoices_company_id_payment_status_index` (`company_id`,`payment_status`),
  KEY `invoices_company_id_local_status_index` (`company_id`,`local_status`),
  KEY `invoices_whmcs_pending_id_foreign` (`whmcs_pending_id`),
  KEY `invoices_company_whmcs_invoice_idx` (`company_id`,`whmcs_invoice_id`),
  KEY `invoices_bank_account_id_foreign` (`bank_account_id`),
  KEY `invoices_service_contract_id_foreign` (`service_contract_id`),
  KEY `invoices_reissued_from_invoice_id_foreign` (`reissued_from_invoice_id`),
  CONSTRAINT `invoices_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoices_conv_invoice_id_foreign` FOREIGN KEY (`conv_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_credited_invoice_id_foreign` FOREIGN KEY (`credited_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_delivery_method_id_foreign` FOREIGN KEY (`delivery_method_id`) REFERENCES `delivery_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_distribution_aim_id_foreign` FOREIGN KEY (`distribution_aim_id`) REFERENCES `distribution_aims` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_invoice_type_id_foreign` FOREIGN KEY (`invoice_type_id`) REFERENCES `invoice_types` (`id`),
  CONSTRAINT `invoices_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_reissued_from_invoice_id_foreign` FOREIGN KEY (`reissued_from_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_service_contract_id_foreign` FOREIGN KEY (`service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_whmcs_pending_id_foreign` FOREIGN KEY (`whmcs_pending_id`) REFERENCES `pending_whmcs_invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `lead_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `direction` varchar(10) DEFAULT NULL,
  `outcome` varchar(30) DEFAULT NULL,
  `happened_at` datetime NOT NULL,
  `body` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `lead_activities_lead_id_foreign` (`lead_id`),
  KEY `lead_activities_user_id_foreign` (`user_id`),
  KEY `lead_activities_company_id_lead_id_happened_at_index` (`company_id`,`lead_id`,`happened_at`),
  KEY `lead_activities_company_id_user_id_happened_at_index` (`company_id`,`user_id`,`happened_at`),
  CONSTRAINT `lead_activities_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_activities_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `mobile` varchar(60) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `afm` varchar(20) DEFAULT NULL,
  `address1` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `postcode` varchar(20) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `occupation` varchar(255) DEFAULT NULL,
  `source` varchar(30) DEFAULT NULL,
  `referred_by_customer_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `lost_reason` varchar(255) DEFAULT NULL,
  `assigned_user_id` bigint(20) unsigned DEFAULT NULL,
  `next_action_at` datetime DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `converted_customer_id` bigint(20) unsigned DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leads_converted_customer_id_unique` (`converted_customer_id`),
  KEY `leads_referred_by_customer_id_foreign` (`referred_by_customer_id`),
  KEY `leads_assigned_user_id_foreign` (`assigned_user_id`),
  KEY `leads_company_id_status_index` (`company_id`,`status`),
  KEY `leads_company_id_afm_index` (`company_id`,`afm`),
  KEY `leads_company_id_assigned_user_id_index` (`company_id`,`assigned_user_id`),
  KEY `leads_company_id_next_action_at_index` (`company_id`,`next_action_at`),
  CONSTRAINT `leads_assigned_user_id_foreign` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leads_converted_customer_id_foreign` FOREIGN KEY (`converted_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_referred_by_customer_id_foreign` FOREIGN KEY (`referred_by_customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `metric_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `metric_units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(15) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `metric_units_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  CONSTRAINT `metric_units_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`company_id`,`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_permissions_permission_id_foreign` (`permission_id`),
  KEY `model_has_permissions_team_foreign_key_index` (`company_id`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`company_id`,`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_roles_role_id_foreign` (`role_id`),
  KEY `model_has_roles_team_foreign_key_index` (`company_id`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mydata_marks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mydata_marks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `mark` varchar(50) DEFAULT NULL,
  `cancellation_mark` varchar(40) DEFAULT NULL,
  `mydata_action` varchar(30) DEFAULT NULL,
  `provider_key` varchar(40) DEFAULT NULL,
  `provider_identity` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_identity`)),
  `authentication_code` varchar(255) DEFAULT NULL,
  `uid` varchar(255) DEFAULT NULL,
  `remaining_invoices` int(10) unsigned DEFAULT NULL,
  `reception_emails` text DEFAULT NULL,
  `delivery_state` varchar(40) DEFAULT NULL,
  `invoice_url` varchar(1500) DEFAULT NULL,
  `request` mediumtext DEFAULT NULL,
  `response` mediumtext DEFAULT NULL,
  `mark_date` date DEFAULT NULL,
  `mark_time` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mydata_marks_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  KEY `mydata_marks_invoice_id_index` (`invoice_id`),
  CONSTRAINT `mydata_marks_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mydata_marks_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `notable_type` varchar(255) NOT NULL,
  `notable_id` bigint(20) unsigned NOT NULL,
  `body` text NOT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `source` varchar(255) DEFAULT NULL,
  `author_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notes_company_id_foreign` (`company_id`),
  KEY `notes_notable_type_notable_id_index` (`notable_type`,`notable_id`),
  KEY `notes_author_user_id_foreign` (`author_user_id`),
  CONSTRAINT `notes_author_user_id_foreign` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` uuid NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_access_tokens` (
  `id` char(80) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` uuid NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `scopes` text DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_access_tokens_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_auth_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_auth_codes` (
  `id` char(80) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `client_id` uuid NOT NULL,
  `scopes` text DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_auth_codes_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_clients` (
  `id` uuid NOT NULL,
  `owner_type` varchar(255) DEFAULT NULL,
  `owner_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `secret` varchar(255) DEFAULT NULL,
  `provider` varchar(255) DEFAULT NULL,
  `redirect_uris` text NOT NULL,
  `grant_types` text NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_clients_owner_type_owner_id_index` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_device_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_device_codes` (
  `id` char(80) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` uuid NOT NULL,
  `user_code` char(8) NOT NULL,
  `scopes` text NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `user_approved_at` datetime DEFAULT NULL,
  `last_polled_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `oauth_device_codes_user_code_unique` (`user_code`),
  KEY `oauth_device_codes_user_id_index` (`user_id`),
  KEY `oauth_device_codes_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_refresh_tokens` (
  `id` char(80) NOT NULL,
  `access_token_id` char(80) NOT NULL,
  `revoked` tinyint(1) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oauth_refresh_tokens_access_token_id_index` (`access_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_gateway_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_gateway_connections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `gateway` varchar(40) NOT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `sort` int(10) unsigned NOT NULL DEFAULT 0,
  `config` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_gateway_connections_company_id_is_active_index` (`company_id`,`is_active`),
  KEY `payment_gateway_connections_company_id_gateway_index` (`company_id`,`gateway`),
  KEY `payment_gateway_connections_payment_method_id_foreign` (`payment_method_id`),
  CONSTRAINT `payment_gateway_connections_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_gateway_connections_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_gateway_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_gateway_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `payment_intent_id` bigint(20) unsigned DEFAULT NULL,
  `gateway` varchar(40) NOT NULL,
  `order_id` varchar(64) DEFAULT NULL,
  `outcome` varchar(32) NOT NULL,
  `reason` varchar(64) DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `provider_status` varchar(40) DEFAULT NULL,
  `transaction_id` varchar(64) DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `currency` varchar(8) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_gateway_events_company_id_created_at_index` (`company_id`,`created_at`),
  KEY `payment_gateway_events_company_id_payment_intent_id_index` (`company_id`,`payment_intent_id`),
  KEY `payment_gateway_events_transaction_id_index` (`transaction_id`),
  CONSTRAINT `payment_gateway_events_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_intents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_intents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `customer_user_id` bigint(20) unsigned DEFAULT NULL,
  `gateway` varchar(40) NOT NULL,
  `payment_gateway_connection_id` bigint(20) unsigned DEFAULT NULL,
  `purpose` varchar(20) NOT NULL DEFAULT 'balance',
  `amount` decimal(14,2) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'EUR',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reference` varchar(60) NOT NULL,
  `instructions` text DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `settled_by` varchar(60) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_intents_company_id_reference_unique` (`company_id`,`reference`),
  KEY `payment_intents_customer_id_foreign` (`customer_id`),
  KEY `payment_intents_customer_user_id_foreign` (`customer_user_id`),
  KEY `payment_intents_company_id_status_index` (`company_id`,`status`),
  KEY `payment_intents_company_id_customer_id_index` (`company_id`,`customer_id`),
  KEY `payment_intents_payment_gateway_connection_id_foreign` (`payment_gateway_connection_id`),
  KEY `payment_intents_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `payment_intents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_intents_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_intents_customer_user_id_foreign` FOREIGN KEY (`customer_user_id`) REFERENCES `customer_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_intents_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_intents_payment_gateway_connection_id_foreign` FOREIGN KEY (`payment_gateway_connection_id`) REFERENCES `payment_gateway_connections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(120) DEFAULT NULL,
  `due_days` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `mydata_payment_type` tinyint(3) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_methods_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  CONSTRAINT `payment_methods_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `payment_intent_id` bigint(20) unsigned DEFAULT NULL,
  `kind` enum('payment','refund') NOT NULL DEFAULT 'payment',
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `bank_account_id` bigint(20) unsigned DEFAULT NULL,
  `pay_date` date DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reference` varchar(40) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  KEY `payments_customer_id_foreign` (`customer_id`),
  KEY `payments_company_id_customer_id_index` (`company_id`,`customer_id`),
  KEY `payments_invoice_id_foreign` (`invoice_id`),
  KEY `payments_payment_method_id_foreign` (`payment_method_id`),
  KEY `payments_company_id_invoice_id_index` (`company_id`,`invoice_id`),
  KEY `payments_company_id_reference_index` (`company_id`,`reference`),
  KEY `payments_bank_account_id_foreign` (`bank_account_id`),
  KEY `payments_payment_intent_id_foreign` (`payment_intent_id`),
  CONSTRAINT `payments_bank_account_id_foreign` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_payment_intent_id_foreign` FOREIGN KEY (`payment_intent_id`) REFERENCES `payment_intents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pending_whmcs_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pending_whmcs_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'whmcs',
  `whmcs_invoice_id` bigint(20) unsigned NOT NULL,
  `whmcs_userid` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `match_reason` varchar(20) NOT NULL,
  `third_party_state` varchar(16) DEFAULT NULL,
  `third_party_resolution` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`third_party_resolution`)),
  `status` varchar(20) NOT NULL DEFAULT 'pending_review',
  `notes` text DEFAULT NULL,
  `rejected_reason` varchar(200) DEFAULT NULL,
  `hold_reason` text DEFAULT NULL,
  `filed_at` timestamp NULL DEFAULT NULL,
  `whmcs_payment_pushed_at` timestamp NULL DEFAULT NULL,
  `filed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `mydata_mark` varchar(30) DEFAULT NULL,
  `legacy_invoiced` smallint(5) unsigned DEFAULT NULL,
  `whmcs_writeback_state` varchar(20) DEFAULT NULL,
  `whmcs_writeback_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pwi_company_invoice_unique` (`company_id`,`whmcs_invoice_id`),
  KEY `pending_whmcs_invoices_customer_id_foreign` (`customer_id`),
  KEY `pending_whmcs_invoices_filed_by_user_id_foreign` (`filed_by_user_id`),
  KEY `pwi_inbox_filter_idx` (`company_id`,`status`,`created_at`),
  KEY `pending_whmcs_invoices_invoice_id_foreign` (`invoice_id`),
  KEY `pwi_company_writeback_idx` (`company_id`,`whmcs_writeback_state`),
  KEY `pending_whmcs_invoices_company_id_source_index` (`company_id`,`source`),
  CONSTRAINT `pending_whmcs_invoices_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pending_whmcs_invoices_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pending_whmcs_invoices_filed_by_user_id_foreign` FOREIGN KEY (`filed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pending_whmcs_invoices_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_billing_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_billing_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `billing_cycle` varchar(20) NOT NULL,
  `setup_fee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_billing_prices_product_id_billing_cycle_unique` (`product_id`,`billing_cycle`),
  KEY `product_billing_prices_company_id_foreign` (`company_id`),
  CONSTRAINT `product_billing_prices_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_billing_prices_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `description_short` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `markup` decimal(5,2) DEFAULT NULL,
  `mydata_income_class` varchar(30) DEFAULT NULL,
  `mydata_income_class_category` varchar(30) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_categories_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  CONSTRAINT `product_categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_price_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_price_tiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `value` decimal(14,2) DEFAULT NULL,
  `discount_percent` decimal(5,2) DEFAULT NULL,
  `qty` decimal(9,3) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_price_tiers_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  UNIQUE KEY `product_price_tiers_product_id_qty_unique` (`product_id`,`qty`),
  CONSTRAINT `product_price_tiers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_price_tiers_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `barcode` varchar(25) DEFAULT NULL,
  `sku` varchar(40) DEFAULT NULL,
  `description_short` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `provisioning_module` varchar(40) NOT NULL DEFAULT 'none',
  `module_meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`module_meta`)),
  `default_suspend_after_days` smallint(5) unsigned DEFAULT NULL,
  `default_terminate_after_days` smallint(5) unsigned DEFAULT NULL,
  `dunning_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `track_stock` tinyint(1) NOT NULL DEFAULT 0,
  `reorder_level` decimal(12,3) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `supplier` varchar(120) DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `whmcs_product_id` int(10) unsigned DEFAULT NULL,
  `product_category_id` bigint(20) unsigned NOT NULL,
  `vat_category_id` bigint(20) unsigned NOT NULL,
  `mydata_tax_type` smallint(5) unsigned DEFAULT NULL,
  `mydata_tax_category` smallint(5) unsigned DEFAULT NULL,
  `mydata_tax_per_unit` decimal(14,4) DEFAULT NULL,
  `metric_unit_id` bigint(20) unsigned DEFAULT NULL,
  `buy_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sell_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `price_wvat` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reserve` decimal(7,3) NOT NULL DEFAULT 0.000,
  `reserve_secure` decimal(7,3) NOT NULL DEFAULT 0.000,
  `date_inserted` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  UNIQUE KEY `products_company_id_barcode_unique` (`company_id`,`barcode`),
  UNIQUE KEY `products_company_id_sku_unique` (`company_id`,`sku`),
  UNIQUE KEY `products_company_id_whmcs_product_id_unique` (`company_id`,`whmcs_product_id`),
  KEY `products_product_category_id_foreign` (`product_category_id`),
  KEY `products_vat_category_id_foreign` (`vat_category_id`),
  KEY `products_metric_unit_id_foreign` (`metric_unit_id`),
  KEY `products_company_id_is_favorite_index` (`company_id`,`is_favorite`),
  CONSTRAINT `products_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `products_metric_unit_id_foreign` FOREIGN KEY (`metric_unit_id`) REFERENCES `metric_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_product_category_id_foreign` FOREIGN KEY (`product_category_id`) REFERENCES `product_categories` (`id`),
  CONSTRAINT `products_vat_category_id_foreign` FOREIGN KEY (`vat_category_id`) REFERENCES `vat_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quote_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `quote_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `product_descr` varchar(255) DEFAULT NULL,
  `qty` decimal(9,3) NOT NULL DEFAULT 1.000,
  `price_per_item` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(5,2) NOT NULL DEFAULT 0.00,
  `vat_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `net_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gross_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `metric_unit` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quote_lines_company_id_foreign` (`company_id`),
  KEY `quote_lines_quote_id_foreign` (`quote_id`),
  KEY `quote_lines_product_id_foreign` (`product_id`),
  CONSTRAINT `quote_lines_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quote_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quote_lines_quote_id_foreign` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quote_mail_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quote_mail_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `quote_id` bigint(20) unsigned NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `cc_list` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cc_list`)),
  `bcc_list` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bcc_list`)),
  `from_address` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `trigger` varchar(255) NOT NULL DEFAULT 'manual',
  `status` varchar(255) NOT NULL DEFAULT 'queued',
  `error_message` text DEFAULT NULL,
  `queued_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `triggered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quote_mail_logs_company_id_foreign` (`company_id`),
  KEY `quote_mail_logs_quote_id_foreign` (`quote_id`),
  KEY `quote_mail_logs_triggered_by_user_id_foreign` (`triggered_by_user_id`),
  CONSTRAINT `quote_mail_logs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quote_mail_logs_quote_id_foreign` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quote_mail_logs_triggered_by_user_id_foreign` FOREIGN KEY (`triggered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `lead_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `legacy_id` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `issued_at` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `service_until` date DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `vat_no` varchar(255) DEFAULT NULL,
  `vies_vat` varchar(255) DEFAULT NULL,
  `occupation` varchar(255) DEFAULT NULL,
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `postcode` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT 'GR',
  `header_discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `net_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vat_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `gross_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `proposal_text` text DEFAULT NULL,
  `customer_notes` text DEFAULT NULL,
  `language` varchar(8) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `converted_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `converted_service_contract_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quotes_customer_id_foreign` (`customer_id`),
  KEY `quotes_converted_invoice_id_foreign` (`converted_invoice_id`),
  KEY `quotes_company_id_status_index` (`company_id`,`status`),
  KEY `quotes_company_id_valid_until_index` (`company_id`,`valid_until`),
  KEY `quotes_company_id_service_until_index` (`company_id`,`service_until`),
  KEY `quotes_converted_service_contract_id_foreign` (`converted_service_contract_id`),
  KEY `quotes_lead_id_foreign` (`lead_id`),
  CONSTRAINT `quotes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quotes_converted_invoice_id_foreign` FOREIGN KEY (`converted_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotes_converted_service_contract_id_foreign` FOREIGN KEY (`converted_service_contract_id`) REFERENCES `service_contracts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `quotes_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `return_invoice_extras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `return_invoice_extras` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `invoice_line_id` bigint(20) unsigned NOT NULL,
  `qty_given` decimal(9,3) DEFAULT NULL,
  `qty_returned` decimal(9,3) DEFAULT NULL,
  `qty_sent` decimal(9,3) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `return_invoice_extras_invoice_line_id_unique` (`invoice_line_id`),
  KEY `return_invoice_extras_company_id_foreign` (`company_id`),
  CONSTRAINT `return_invoice_extras_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `return_invoice_extras_invoice_line_id_foreign` FOREIGN KEY (`invoice_line_id`) REFERENCES `invoice_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_company_id_name_guard_name_unique` (`company_id`,`name`,`guard_name`),
  KEY `roles_team_foreign_key_index` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_task_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_task_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task` varchar(255) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'running',
  `exit_code` int(11) DEFAULT NULL,
  `duration_ms` bigint(20) unsigned DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scheduled_task_runs_task_started_at_index` (`task`,`started_at`),
  KEY `scheduled_task_runs_task_index` (`task`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `server_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `module` varchar(40) DEFAULT NULL,
  `username` varchar(190) DEFAULT NULL,
  `secret_encrypted` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `server_groups_company_id_foreign` (`company_id`),
  CONSTRAINT `server_groups_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `servers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `servers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `server_group_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `module` varchar(40) DEFAULT NULL,
  `hostname` varchar(190) DEFAULT NULL,
  `api_endpoint` varchar(255) DEFAULT NULL,
  `username` varchar(190) DEFAULT NULL,
  `secret_encrypted` text DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `servers_company_id_foreign` (`company_id`),
  KEY `servers_server_group_id_foreign` (`server_group_id`),
  CONSTRAINT `servers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `servers_server_group_id_foreign` FOREIGN KEY (`server_group_id`) REFERENCES `server_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_contracts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_id` bigint(20) unsigned DEFAULT NULL,
  `company_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_type_id` bigint(20) unsigned DEFAULT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `server_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `billing_cycle` varchar(20) NOT NULL,
  `quantity` decimal(9,3) NOT NULL DEFAULT 1.000,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `setup_fee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `vat_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `start_date` date DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `last_invoiced_at` datetime DEFAULT NULL,
  `last_renewal_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `suspended_at` datetime DEFAULT NULL,
  `dunning_suspended_at` datetime DEFAULT NULL,
  `terminated_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `suspend_after_days` smallint(5) unsigned DEFAULT NULL,
  `terminate_after_days` smallint(5) unsigned DEFAULT NULL,
  `dunning_enabled` tinyint(1) DEFAULT NULL,
  `domain` varchar(190) DEFAULT NULL,
  `provisioning_module` varchar(40) NOT NULL DEFAULT 'none',
  `module_meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`module_meta`)),
  `whmcs_service_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_contracts_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  KEY `service_contracts_customer_id_foreign` (`customer_id`),
  KEY `service_contracts_product_id_foreign` (`product_id`),
  KEY `service_contracts_invoice_type_id_foreign` (`invoice_type_id`),
  KEY `service_contracts_payment_method_id_foreign` (`payment_method_id`),
  KEY `service_contracts_server_id_foreign` (`server_id`),
  KEY `service_contracts_last_renewal_invoice_id_foreign` (`last_renewal_invoice_id`),
  KEY `service_contracts_company_id_status_next_due_date_index` (`company_id`,`status`,`next_due_date`),
  KEY `service_contracts_company_id_customer_id_index` (`company_id`,`customer_id`),
  CONSTRAINT `service_contracts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_contracts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `service_contracts_invoice_type_id_foreign` FOREIGN KEY (`invoice_type_id`) REFERENCES `invoice_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_contracts_last_renewal_invoice_id_foreign` FOREIGN KEY (`last_renewal_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_contracts_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_contracts_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_contracts_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `qty_change` decimal(12,3) NOT NULL,
  `reason` varchar(20) NOT NULL,
  `source_type` varchar(255) DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `note` text DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_movements_product_id_foreign` (`product_id`),
  KEY `stock_movements_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `stock_movements_company_id_product_id_index` (`company_id`,`product_id`),
  KEY `stock_movements_company_id_occurred_at_index` (`company_id`,`occurred_at`),
  CONSTRAINT `stock_movements_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `afm` varchar(20) DEFAULT NULL,
  `name` varchar(191) DEFAULT NULL,
  `tax_office` varchar(120) DEFAULT NULL,
  `occupation` varchar(191) DEFAULT NULL,
  `address1` varchar(191) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `postcode` varchar(20) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `country_code` varchar(2) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone1` varchar(60) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `suppliers_company_id_afm_unique` (`company_id`,`afm`),
  KEY `suppliers_company_id_is_active_index` (`company_id`,`is_active`),
  CONSTRAINT `suppliers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'string',
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_key_unique` (`key`),
  KEY `system_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `system_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `taggables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `taggables` (
  `tag_id` bigint(20) unsigned NOT NULL,
  `taggable_type` varchar(255) NOT NULL,
  `taggable_id` bigint(20) unsigned NOT NULL,
  UNIQUE KEY `taggables_unique` (`tag_id`,`taggable_id`,`taggable_type`),
  KEY `taggables_taggable_type_taggable_id_index` (`taggable_type`,`taggable_id`),
  CONSTRAINT `taggables_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `color` varchar(32) DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tags_company_id_name_unique` (`company_id`,`name`),
  KEY `tags_company_id_is_pinned_index` (`company_id`,`is_pinned`),
  CONSTRAINT `tags_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_blocked_senders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_blocked_senders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `pattern` varchar(254) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_blocked_senders_company_id_pattern_unique` (`company_id`,`pattern`),
  KEY `ticket_blocked_senders_created_by_foreign` (`created_by`),
  CONSTRAINT `ticket_blocked_senders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_blocked_senders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_department_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_department_user` (
  `ticket_department_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`ticket_department_id`,`user_id`),
  KEY `ticket_department_user_user_id_foreign` (`user_id`),
  CONSTRAINT `ticket_department_user_ticket_department_id_foreign` FOREIGN KEY (`ticket_department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_department_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `imap_host` varchar(255) DEFAULT NULL,
  `imap_port` smallint(5) unsigned NOT NULL DEFAULT 993,
  `imap_username` varchar(255) DEFAULT NULL,
  `imap_password` text DEFAULT NULL,
  `imap_encryption` varchar(10) NOT NULL DEFAULT 'ssl',
  `imap_folder` varchar(255) NOT NULL DEFAULT 'INBOX',
  `clients_only` tinyint(1) NOT NULL DEFAULT 0,
  `autoresponder` tinyint(1) NOT NULL DEFAULT 1,
  `feedback_on_close` tinyint(1) NOT NULL DEFAULT 0,
  `prevent_client_closure` tinyint(1) NOT NULL DEFAULT 0,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `sort` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_departments_company_id_name_unique` (`company_id`,`name`),
  UNIQUE KEY `ticket_departments_company_id_email_unique` (`company_id`,`email`),
  CONSTRAINT `ticket_departments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `author_role` varchar(12) NOT NULL,
  `author_id` bigint(20) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `body_original` text DEFAULT NULL,
  `is_internal_note` tinyint(1) NOT NULL DEFAULT 0,
  `via` varchar(12) NOT NULL DEFAULT 'operator',
  `email_message_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_messages_company_id_foreign` (`company_id`),
  KEY `ticket_messages_ticket_id_id_index` (`ticket_id`,`id`),
  KEY `ticket_messages_email_message_id_index` (`email_message_id`),
  CONSTRAINT `ticket_messages_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_messages_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_poll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_poll_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `ticket_department_id` bigint(20) unsigned DEFAULT NULL,
  `connected` tinyint(1) NOT NULL DEFAULT 0,
  `fetched` int(10) unsigned NOT NULL DEFAULT 0,
  `processed` int(10) unsigned NOT NULL DEFAULT 0,
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`errors`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_poll_runs_company_id_created_at_index` (`company_id`,`created_at`),
  KEY `ticket_poll_runs_ticket_department_id_created_at_index` (`ticket_department_id`,`created_at`),
  CONSTRAINT `ticket_poll_runs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_poll_runs_ticket_department_id_foreign` FOREIGN KEY (`ticket_department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ticket_watchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_watchers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `source` varchar(12) NOT NULL DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_watchers_ticket_id_user_id_unique` (`ticket_id`,`user_id`),
  UNIQUE KEY `ticket_watchers_ticket_id_email_unique` (`ticket_id`,`email`),
  KEY `ticket_watchers_user_id_foreign` (`user_id`),
  KEY `ticket_watchers_company_id_ticket_id_index` (`company_id`,`ticket_id`),
  CONSTRAINT `ticket_watchers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_watchers_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_watchers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(40) NOT NULL,
  `ticket_department_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `requester_email` varchar(255) DEFAULT NULL,
  `requester_name` varchar(255) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `priority` varchar(10) NOT NULL DEFAULT 'normal',
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `opened_via` varchar(12) NOT NULL DEFAULT 'operator',
  `last_reply_at` timestamp NULL DEFAULT NULL,
  `last_reply_role` varchar(12) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `rating` tinyint(3) unsigned DEFAULT NULL,
  `rating_comment` text DEFAULT NULL,
  `rated_at` timestamp NULL DEFAULT NULL,
  `merged_into_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tickets_company_id_reference_unique` (`company_id`,`reference`),
  KEY `tickets_ticket_department_id_foreign` (`ticket_department_id`),
  KEY `tickets_customer_id_foreign` (`customer_id`),
  KEY `tickets_assigned_to_foreign` (`assigned_to`),
  KEY `tickets_company_id_status_index` (`company_id`,`status`),
  KEY `tickets_company_id_ticket_department_id_index` (`company_id`,`ticket_department_id`),
  KEY `tickets_company_id_customer_id_index` (`company_id`,`customer_id`),
  KEY `tickets_company_id_assigned_to_index` (`company_id`,`assigned_to`),
  KEY `tickets_merged_into_id_foreign` (`merged_into_id`),
  CONSTRAINT `tickets_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_merged_into_id_foreign` FOREIGN KEY (`merged_into_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_ticket_department_id_foreign` FOREIGN KEY (`ticket_department_id`) REFERENCES `ticket_departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `update_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `update_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(255) NOT NULL DEFAULT 'queued',
  `kind` varchar(255) NOT NULL DEFAULT 'update',
  `phase` varchar(255) DEFAULT NULL,
  `strategy` varchar(255) NOT NULL DEFAULT 'php',
  `from_version` varchar(255) DEFAULT NULL,
  `from_ref` varchar(255) DEFAULT NULL,
  `to_version` varchar(255) DEFAULT NULL,
  `to_ref` varchar(255) DEFAULT NULL,
  `rollback_of_id` bigint(20) unsigned DEFAULT NULL,
  `snapshot_file` varchar(255) DEFAULT NULL,
  `restore_snapshot` varchar(255) DEFAULT NULL,
  `output` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `triggered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `update_runs_triggered_by_user_id_foreign` (`triggered_by_user_id`),
  KEY `update_runs_status_index` (`status`),
  KEY `update_runs_rollback_of_id_foreign` (`rollback_of_id`),
  CONSTRAINT `update_runs_rollback_of_id_foreign` FOREIGN KEY (`rollback_of_id`) REFERENCES `update_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `update_runs_triggered_by_user_id_foreign` FOREIGN KEY (`triggered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `app_authentication_secret` text DEFAULT NULL,
  `app_authentication_recovery_codes` text DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vat_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vat_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(120) DEFAULT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `vat_exemption_category` tinyint(3) unsigned DEFAULT NULL,
  `mydata_vat_category` tinyint(3) unsigned DEFAULT NULL,
  `long_description` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vat_categories_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  CONSTRAINT `vat_categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whmcs_income_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whmcs_income_maps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `scope` varchar(16) NOT NULL,
  `whmcs_key` bigint(20) unsigned NOT NULL,
  `income_class_category` varchar(32) NOT NULL,
  `income_class` varchar(32) DEFAULT NULL,
  `label` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `whmcs_income_maps_company_id_scope_whmcs_key_unique` (`company_id`,`scope`,`whmcs_key`),
  CONSTRAINT `whmcs_income_maps_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whmcs_invoice_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whmcs_invoice_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `legacy_id` int(10) unsigned DEFAULT NULL,
  `whmcs_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `whmcs_invoice_log_company_id_legacy_id_unique` (`company_id`,`legacy_id`),
  KEY `whmcs_invoice_log_invoice_id_foreign` (`invoice_id`),
  KEY `whmcs_invoice_log_whmcs_invoice_id_index` (`whmcs_invoice_id`),
  CONSTRAINT `whmcs_invoice_log_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `whmcs_invoice_log_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whmcs_payment_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `whmcs_payment_maps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `whmcs_gateway` varchar(64) NOT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `label` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `whmcs_payment_maps_company_id_whmcs_gateway_unique` (`company_id`,`whmcs_gateway`),
  KEY `whmcs_payment_maps_payment_method_id_foreign` (`payment_method_id`),
  CONSTRAINT `whmcs_payment_maps_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `whmcs_payment_maps_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

/*M!999999\- enable the sandbox mode */ 
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_05_26_000001_create_companies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_05_26_000002_create_company_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_05_26_000003_create_payment_methods_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_05_26_000004_create_delivery_methods_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_05_26_000005_create_distribution_aims_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_05_26_000006_create_metric_units_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_05_26_000007_create_vat_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_05_26_000008_create_product_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_05_26_000009_create_customers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_05_26_000010_create_invoice_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_05_26_000011_create_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_05_26_000012_create_product_price_tiers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_05_26_000013_create_invoices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_05_26_000014_create_invoice_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_05_26_000015_create_return_invoice_extras_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_05_26_000016_create_payments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_05_26_000017_create_mydata_marks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_05_26_000018_create_conf_params_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_05_26_000019_create_whmcs_invoice_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_05_26_080823_create_activity_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_05_26_080823_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_05_26_090000_add_country_profile_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_05_27_000001_rename_header_discount_to_percent_on_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_05_27_000002_change_mark_time_to_time_on_mydata_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_05_27_000003_add_lifecycle_columns_to_customers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_05_27_000004_change_product_category_markup_to_percent',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_05_27_000005_add_forward_looking_columns_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_05_27_000006_add_unique_qty_on_product_price_tiers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_05_27_000007_add_gsis_registry_credentials_and_kad',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_05_27_000008_replace_mydata_production_with_mode',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_05_27_000009_make_mydata_marks_mark_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_05_27_000010_add_mydata_type_snapshot_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_05_27_000011_add_branding_and_mail_columns_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_05_27_000012_create_invoice_mail_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_05_27_000013_add_whmcs_api_credentials_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_05_27_000014_add_company_legacy_unique_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_05_27_000020_create_firebird_import_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_05_28_000001_create_pending_whmcs_invoices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_05_28_000002_add_whmcs_webhook_secret_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_05_28_000003_add_whmcs_invoice_min_date_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_05_28_000010_add_invoice_id_to_pending_whmcs_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_05_28_000011_add_writeback_state_to_pending_whmcs_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_05_28_000020_add_money_columns_to_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_05_28_000021_add_money_cache_columns_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_05_28_000022_add_local_status_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_05_28_000023_add_third_party_invoicing_to_whmcs_bridge',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_05_28_000024_add_whmcs_pending_id_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_05_28_000025_add_mydata_withholding_and_exemption',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_05_28_000026_add_whmcs_and_mydata_filing_correctness',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_05_28_000027_add_auto_email_controls',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_05_28_000028_add_whmcs_auto_issue_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_05_29_000001_split_mydata_credentials_by_environment',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_05_30_000001_create_suppliers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_05_30_000002_create_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_05_30_000003_create_expense_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_05_30_000004_create_expense_marks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_05_30_000005_add_classification_to_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_05_30_000006_add_hold_reason_to_pending_whmcs_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_05_31_000001_add_self_declared_to_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_05_31_000002_create_quotes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_05_31_000003_create_quote_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_05_31_000004_add_quote_counter_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_05_31_000005_create_quote_mail_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_05_31_201800_add_company_id_to_activity_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_06_01_000001_add_legacy_invoiced_to_pending_whmcs_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_06_01_000002_add_whmcs_invoice_id_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_06_01_000003_create_billing_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_06_01_000004_add_source_to_pending_whmcs_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_06_01_000005_seed_whmcs_billing_connections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_06_02_000001_add_is_favorite_to_pickers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_06_02_000001_create_customer_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_06_02_000001_drop_legacy_mail_print_flags_from_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_06_02_000002_create_attachments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_06_02_000002_create_tags_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_06_02_000003_add_whmcs_fetch_via_bridge_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_06_02_000003_create_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_06_02_000004_add_source_to_firebird_import_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_06_03_000001_add_source_to_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_06_03_000001_create_delivery_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_06_03_000002_create_delivery_note_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_06_03_000002_migrate_customer_details_to_notes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_06_03_000003_create_delivery_marks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_06_03_000003_drop_details_from_customers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_06_03_010000_add_app_authentication_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_06_03_020000_add_track_stock_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_06_03_020001_create_stock_movements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_06_03_020002_add_invoice_id_to_delivery_notes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_06_03_020003_add_reorder_level_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_06_03_030000_add_reference_to_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_06_03_040000_add_transaction_id_to_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_06_03_050000_create_bank_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_06_03_050001_add_bank_account_id_to_payments_and_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_06_03_060000_add_kind_to_payments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_06_03_162018_create_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_06_04_000001_add_recurring_to_products',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_06_04_000002_create_product_billing_prices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_06_04_000003_create_server_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_06_04_000004_create_servers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_06_04_000005_create_service_contracts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_06_04_000006_add_service_contract_id_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_06_05_000001_add_dunning_toggle',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_06_05_000003_add_einvoice_provider_config_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_06_05_000004_add_provider_columns_to_mydata_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_06_05_000005_add_mydata_send_item_descr_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_06_05_000010_add_cancellation_mark_to_mydata_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_06_06_000001_add_converted_service_contract_id_to_quotes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_06_06_000001_add_provider_columns_to_delivery_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_06_09_000001_create_delivery_note_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_06_09_000002_change_mark_time_to_time_on_delivery_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_06_09_000003_create_company_backup_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_06_09_000004_create_company_backup_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_06_09_000005_add_mydata_vat_category_to_vat_categories',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_06_09_000006_add_additional_taxes_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_06_10_000001_add_product_linked_taxes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_06_10_000002_create_scheduled_task_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_06_10_000003_create_system_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_06_10_000004_add_document_path_to_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_06_10_000005_add_pdf_language_to_invoices_and_quotes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_06_10_000006_add_payable_total_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_06_11_000001_add_customer_balance_snapshot_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_06_11_000001_add_whmcs_default_receipt_type_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_06_11_000002_add_show_balance_on_pdf_toggles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_06_17_000001_create_ai_assistant_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_06_17_000001_create_expense_classification_rules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_06_17_000002_create_ai_pending_actions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_06_18_000001_add_english_identity_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_06_18_000002_create_cmr_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_06_18_000003_create_cmr_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_06_19_000001_add_show_on_invoices_to_bank_accounts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_06_25_000001_widen_hold_reason_on_pending_whmcs_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_07_05_000001_add_original_line_id_to_invoice_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_07_05_000002_add_gemi_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_07_05_000003_default_company_backup_bucket_to_full',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_07_06_000001_add_income_class_to_product_categories',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_07_07_000001_add_mydata_pending_since_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_07_10_000001_add_batch_to_invoice_mail_log_trigger',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_07_11_000001_add_send_key_to_invoice_mail_log',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_07_12_000001_add_mydata_auto_fetch_expenses_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_07_13_000001_add_whmcs_default_unpaid_type_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_07_14_000001_add_whmcs_outbound_payment_push_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_07_14_000002_add_fb_database_to_firebird_import_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_08_26_000001_create_update_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_08_26_000002_add_rollback_to_update_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_08_26_044846_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_08_26_050423_create_oauth_auth_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_08_26_050424_create_oauth_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_08_26_050425_create_oauth_refresh_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_08_26_050426_create_oauth_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_08_26_050427_create_oauth_device_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_09_01_000001_add_recipient_country_to_delivery_notes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_09_01_000001_create_leads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_09_01_000002_create_lead_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_09_02_000001_add_lead_id_to_quotes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_09_02_000001_widen_invoice_party_snapshot_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_09_02_000002_add_series_to_numbered_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_09_02_000002_recompute_leads_last_activity_at',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_09_02_000003_add_mydata_pending_since_to_delivery_notes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_09_02_000004_add_cancellation_mark_to_delivery_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_09_02_000005_backfill_inverted_provider_cancellation_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_09_02_000010_add_uid_to_mydata_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_09_02_000020_add_vat_exemption_category_to_invoice_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_09_02_000021_backfill_zero_vat_line_exemption',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_09_03_000001_add_afm_key_unique_to_customers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_09_03_000001_add_provider_identity_to_mydata_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_09_03_000010_add_business_activity_type_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_09_03_000020_add_provider_operational_evidence_to_mydata_marks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_09_03_000020_create_whmcs_income_maps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_09_03_000021_add_income_class_snapshot_to_invoice_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_09_03_000030_add_mydata_read_env_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_09_03_000030_add_reissued_from_invoice_id_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_09_03_000030_create_whmcs_payment_maps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_09_04_000001_allow_provisional_document_numbering',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_09_04_100000_create_customer_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_09_04_110000_create_customer_user_access_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_09_04_120000_create_customer_users_password_reset_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_09_05_000001_allow_provisional_delivery_note_numbering',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_09_05_000001_create_payment_gateway_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_09_05_000002_create_payment_intents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_09_05_000003_add_connection_to_payment_intents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_09_06_000001_add_needs_invoice_before_payment_to_customers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_09_07_000001_add_einvoice_include_customer_email_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_09_08_000001_add_country_code_to_customers_and_suppliers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_09_08_000002_add_counterpart_branch_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_09_09_000001_add_invoice_id_to_payment_intents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_09_09_000002_add_payment_intent_id_to_payments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_09_09_000003_create_payment_gateway_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_09_09_000004_add_payment_method_id_to_payment_gateway_connections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_09_10_000001_add_support_enabled_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_09_10_000002_create_ticket_departments_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_09_10_000003_create_tickets_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_09_10_000004_create_canned_replies_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_09_10_000005_create_ticket_poll_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_09_11_000001_create_ticket_watchers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_09_12_000001_add_rating_to_tickets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_09_13_000001_create_ticket_blocked_senders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_09_14_000001_add_merged_into_id_to_tickets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_09_15_000001_add_enable_domain_management_to_companies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_09_15_000002_create_domain_registrar_connections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2026_09_15_000003_create_domain_tlds_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2026_09_15_000004_create_domain_tld_prices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2026_09_15_000005_create_domains_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2026_09_15_000006_create_domain_nameservers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2026_09_15_000007_create_domain_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2026_09_16_000001_create_domain_registrar_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2026_09_17_000001_add_afm_key_parked_to_customers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2026_09_17_000002_add_afm_keep_to_firebird_import_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2026_09_18_000001_normalise_einvoice_provider_key',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2026_09_19_000001_create_auth_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2026_09_19_000002_add_last_login_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2026_09_20_000001_add_return_mark_to_delivery_notes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2026_09_21_000001_add_movement_columns_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2026_09_21_000002_add_is_delivery_note_to_invoice_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2026_09_21_000003_make_delivery_audit_polymorphic',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (217,'2026_09_21_000004_add_without_digital_transport_tracking_to_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (218,'2026_09_21_000005_complete_delivery_events_morph',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (219,'2026_09_21_000006_normalise_tda_invoice_type_flag',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (220,'2026_09_21_000007_create_inbound_delivery_notes_table',1);
