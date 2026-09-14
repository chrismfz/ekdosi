CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "app_authentication_secret" text,
  "app_authentication_recovery_codes" text,
  "last_login_at" datetime,
  "last_login_ip" varchar
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" varchar not null,
  "queue" varchar not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs"(
  "connection",
  "queue",
  "failed_at"
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "company_user"(
  "company_id" integer not null,
  "user_id" integer not null,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  primary key("company_id", "user_id")
);
CREATE TABLE IF NOT EXISTS "payment_methods"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "description" varchar,
  "due_days" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "mydata_payment_type" integer,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "payment_methods_company_id_legacy_id_unique" on "payment_methods"(
  "company_id",
  "legacy_id"
);
CREATE TABLE IF NOT EXISTS "delivery_methods"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "description" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "delivery_methods_company_id_legacy_id_unique" on "delivery_methods"(
  "company_id",
  "legacy_id"
);
CREATE TABLE IF NOT EXISTS "distribution_aims"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "description" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "distribution_aims_company_id_legacy_id_unique" on "distribution_aims"(
  "company_id",
  "legacy_id"
);
CREATE TABLE IF NOT EXISTS "metric_units"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "name" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "metric_units_company_id_legacy_id_unique" on "metric_units"(
  "company_id",
  "legacy_id"
);
CREATE TABLE IF NOT EXISTS "vat_categories"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "description" varchar,
  "rate" numeric not null default '0',
  "long_description" text,
  "is_default" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "vat_exemption_category" integer,
  "mydata_vat_category" integer,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "vat_categories_company_id_legacy_id_unique" on "vat_categories"(
  "company_id",
  "legacy_id"
);
CREATE TABLE IF NOT EXISTS "invoice_types"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "code" varchar not null,
  "name" varchar not null,
  "invcount" integer not null default '1',
  "show_on_menu" tinyint(1) not null default '1',
  "is_credit" tinyint(1) not null default '0',
  "is_return" tinyint(1) not null default '0',
  "mydata_type" varchar,
  "mydata_income_class" varchar,
  "mydata_income_class_category" varchar,
  "distribution_aim_id" integer,
  "delivery_method_id" integer,
  "payment_method_id" integer,
  "default_customer_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "mydata_requires_quantity" tinyint(1) not null default '0',
  "is_favorite" tinyint(1) not null default '0',
  "is_delivery_note" tinyint(1) not null default '0',
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("distribution_aim_id") references "distribution_aims"("id") on delete set null,
  foreign key("delivery_method_id") references "delivery_methods"("id") on delete set null,
  foreign key("payment_method_id") references "payment_methods"("id") on delete set null,
  foreign key("default_customer_id") references "customers"("id") on delete set null
);
CREATE UNIQUE INDEX "invoice_types_company_id_code_unique" on "invoice_types"(
  "company_id",
  "code"
);
CREATE TABLE IF NOT EXISTS "products"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "barcode" varchar,
  "description_short" varchar not null,
  "description" text,
  "product_category_id" integer not null,
  "vat_category_id" integer not null,
  "metric_unit_id" integer,
  "buy_price" numeric not null default '0',
  "sell_price" numeric not null default '0',
  "price_wvat" numeric not null default '0',
  "reserve" numeric not null default '0',
  "reserve_secure" numeric not null default '0',
  "date_inserted" date,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "is_active" tinyint(1) not null default '1',
  "sku" varchar,
  "supplier" varchar,
  "internal_notes" text,
  "whmcs_product_id" integer,
  "is_favorite" tinyint(1) not null default '0',
  "track_stock" tinyint(1) not null default '0',
  "reorder_level" numeric,
  "is_recurring" tinyint(1) not null default '0',
  "provisioning_module" varchar not null default 'none',
  "module_meta" text,
  "default_suspend_after_days" integer,
  "default_terminate_after_days" integer,
  "dunning_enabled" tinyint(1) not null default '0',
  "mydata_tax_type" integer,
  "mydata_tax_category" integer,
  "mydata_tax_per_unit" numeric,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("product_category_id") references "product_categories"("id") on delete restrict,
  foreign key("vat_category_id") references "vat_categories"("id") on delete restrict,
  foreign key("metric_unit_id") references "metric_units"("id") on delete set null
);
CREATE UNIQUE INDEX "products_company_id_legacy_id_unique" on "products"(
  "company_id",
  "legacy_id"
);
CREATE UNIQUE INDEX "products_company_id_barcode_unique" on "products"(
  "company_id",
  "barcode"
);
CREATE TABLE IF NOT EXISTS "product_price_tiers"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "product_id" integer not null,
  "value" numeric,
  "discount_percent" numeric,
  "qty" numeric,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete cascade
);
CREATE UNIQUE INDEX "product_price_tiers_company_id_legacy_id_unique" on "product_price_tiers"(
  "company_id",
  "legacy_id"
);
CREATE TABLE IF NOT EXISTS "return_invoice_extras"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "invoice_line_id" integer not null,
  "qty_given" numeric,
  "qty_returned" numeric,
  "qty_sent" numeric,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("invoice_line_id") references "invoice_lines"("id") on delete cascade
);
CREATE UNIQUE INDEX "return_invoice_extras_invoice_line_id_unique" on "return_invoice_extras"(
  "invoice_line_id"
);
CREATE TABLE IF NOT EXISTS "conf_params"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "varname" varchar not null,
  "data_int" integer,
  "data_string" varchar,
  "data_timestamp" datetime,
  "data_float" float,
  "data_numeric" numeric,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "conf_params_company_id_varname_unique" on "conf_params"(
  "company_id",
  "varname"
);
CREATE TABLE IF NOT EXISTS "whmcs_invoice_log"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "whmcs_invoice_id" integer,
  "invoice_id" integer,
  "message" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("invoice_id") references "invoices"("id") on delete set null
);
CREATE UNIQUE INDEX "whmcs_invoice_log_company_id_legacy_id_unique" on "whmcs_invoice_log"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "whmcs_invoice_log_whmcs_invoice_id_index" on "whmcs_invoice_log"(
  "whmcs_invoice_id"
);
CREATE TABLE IF NOT EXISTS "activity_log"(
  "id" integer primary key autoincrement not null,
  "log_name" varchar,
  "description" text not null,
  "subject_type" varchar,
  "subject_id" integer,
  "event" varchar,
  "causer_type" varchar,
  "causer_id" integer,
  "attribute_changes" text,
  "properties" text,
  "created_at" datetime,
  "updated_at" datetime,
  "company_id" integer
);
CREATE INDEX "subject" on "activity_log"("subject_type", "subject_id");
CREATE INDEX "causer" on "activity_log"("causer_type", "causer_id");
CREATE INDEX "activity_log_log_name_index" on "activity_log"("log_name");
CREATE TABLE IF NOT EXISTS "permissions"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "guard_name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "permissions_name_guard_name_unique" on "permissions"(
  "name",
  "guard_name"
);
CREATE TABLE IF NOT EXISTS "roles"(
  "id" integer primary key autoincrement not null,
  "company_id" integer,
  "name" varchar not null,
  "guard_name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "roles_team_foreign_key_index" on "roles"("company_id");
CREATE UNIQUE INDEX "roles_company_id_name_guard_name_unique" on "roles"(
  "company_id",
  "name",
  "guard_name"
);
CREATE TABLE IF NOT EXISTS "model_has_permissions"(
  "permission_id" integer not null,
  "model_type" varchar not null,
  "model_id" integer not null,
  "company_id" integer not null,
  foreign key("permission_id") references "permissions"("id") on delete cascade,
  primary key("company_id", "permission_id", "model_id", "model_type")
);
CREATE INDEX "model_has_permissions_model_id_model_type_index" on "model_has_permissions"(
  "model_id",
  "model_type"
);
CREATE INDEX "model_has_permissions_team_foreign_key_index" on "model_has_permissions"(
  "company_id"
);
CREATE TABLE IF NOT EXISTS "model_has_roles"(
  "role_id" integer not null,
  "model_type" varchar not null,
  "model_id" integer not null,
  "company_id" integer not null,
  foreign key("role_id") references "roles"("id") on delete cascade,
  primary key("company_id", "role_id", "model_id", "model_type")
);
CREATE INDEX "model_has_roles_model_id_model_type_index" on "model_has_roles"(
  "model_id",
  "model_type"
);
CREATE INDEX "model_has_roles_team_foreign_key_index" on "model_has_roles"(
  "company_id"
);
CREATE TABLE IF NOT EXISTS "role_has_permissions"(
  "permission_id" integer not null,
  "role_id" integer not null,
  foreign key("permission_id") references "permissions"("id") on delete cascade,
  foreign key("role_id") references "roles"("id") on delete cascade,
  primary key("permission_id", "role_id")
);
CREATE TABLE IF NOT EXISTS "customers"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "type" varchar,
  "afm" varchar,
  "name" varchar not null,
  "address1" varchar,
  "address2" varchar,
  "city" varchar,
  "postcode" varchar,
  "phone1" varchar,
  "phone2" varchar,
  "fax" varchar,
  "occupation" varchar,
  "tax_office" varchar,
  "discount" numeric not null default('0'),
  "email" varchar,
  "secondary_email" varchar,
  "country" varchar,
  "vat_vies" varchar,
  "withhold_tax" integer,
  "sort_order" integer,
  "alt_customer_legacy_id" integer,
  "payment_method_id" integer,
  "whmcs_client_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "needs_immediate_invoice" tinyint(1) not null default '0',
  "is_active" tinyint(1) not null default '1',
  "peppol_endpoint" varchar,
  "referred_by_customer_id" integer,
  "kad_primary" varchar,
  "whmcs_reseller_routes" integer not null default '0',
  "auto_email_invoices" tinyint(1) not null default '1',
  "is_favorite" tinyint(1) not null default '0',
  "show_balance_on_pdf" tinyint(1),
  "afm_key" varchar,
  "needs_invoice_before_payment" tinyint(1) not null default '0',
  "country_code" varchar,
  "afm_key_parked" tinyint(1) not null default '0',
  foreign key("payment_method_id") references payment_methods("id") on delete set null on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("referred_by_customer_id") references "customers"("id") on delete set null
);
CREATE INDEX "customers_company_id_afm_index" on "customers"(
  "company_id",
  "afm"
);
CREATE UNIQUE INDEX "customers_company_id_legacy_id_unique" on "customers"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "customers_whmcs_client_id_index" on "customers"(
  "whmcs_client_id"
);
CREATE INDEX "customers_company_id_is_active_index" on "customers"(
  "company_id",
  "is_active"
);
CREATE INDEX "customers_company_id_needs_immediate_invoice_index" on "customers"(
  "company_id",
  "needs_immediate_invoice"
);
CREATE TABLE IF NOT EXISTS "product_categories"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "description_short" varchar not null,
  "description" text,
  "markup" numeric,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "mydata_income_class" varchar,
  "mydata_income_class_category" varchar,
  foreign key("company_id") references companies("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "product_categories_company_id_legacy_id_unique" on "product_categories"(
  "company_id",
  "legacy_id"
);
CREATE UNIQUE INDEX "products_company_id_sku_unique" on "products"(
  "company_id",
  "sku"
);
CREATE UNIQUE INDEX "products_company_id_whmcs_product_id_unique" on "products"(
  "company_id",
  "whmcs_product_id"
);
CREATE UNIQUE INDEX "product_price_tiers_product_id_qty_unique" on "product_price_tiers"(
  "product_id",
  "qty"
);
CREATE TABLE IF NOT EXISTS "mydata_marks"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "invoice_id" integer,
  "mark" varchar,
  "mydata_action" varchar,
  "invoice_url" varchar,
  "request" text,
  "response" text,
  "mark_date" date,
  "mark_time" time,
  "created_at" datetime,
  "updated_at" datetime,
  "provider_key" varchar,
  "authentication_code" varchar,
  "delivery_state" varchar,
  "cancellation_mark" varchar,
  "uid" varchar,
  "provider_identity" text,
  "remaining_invoices" integer,
  "reception_emails" text,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("invoice_id") references invoices("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "mydata_marks_company_id_legacy_id_unique" on "mydata_marks"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "mydata_marks_invoice_id_index" on "mydata_marks"("invoice_id");
CREATE TABLE IF NOT EXISTS "firebird_import_runs"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "uploaded_by_user_id" integer,
  "file_name" varchar not null,
  "file_size" integer not null,
  "file_sha256" varchar not null,
  "uploaded_path" varchar,
  "status" varchar not null default 'uploaded',
  "started_at" datetime,
  "finished_at" datetime,
  "counts_json" text,
  "error_message" text,
  "failed_step" varchar,
  "fb_host" varchar not null default '127.0.0.1',
  "fb_user" varchar not null default 'SYSDBA',
  "created_at" datetime,
  "updated_at" datetime,
  "source" varchar not null default 'firebird',
  "source_files_json" text,
  "fb_database" varchar,
  "afm_keep" varchar,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("uploaded_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "firebird_import_runs_company_id_status_created_at_index" on "firebird_import_runs"(
  "company_id",
  "status",
  "created_at"
);
CREATE INDEX "firebird_import_runs_file_sha256_index" on "firebird_import_runs"(
  "file_sha256"
);
CREATE TABLE IF NOT EXISTS "expenses"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "supplier_id" integer,
  "mydata_mark" varchar,
  "uid" varchar,
  "authentication_code" varchar,
  "invoice_type" varchar,
  "series" varchar,
  "aa" varchar,
  "issue_date" date,
  "currency" varchar not null default 'EUR',
  "supplier_afm" varchar,
  "supplier_name" varchar,
  "net_total" numeric not null default '0',
  "vat_total" numeric not null default '0',
  "gross_total" numeric not null default '0',
  "mydata_state" varchar,
  "cancelled_by_mark" varchar,
  "qr_url" varchar,
  "downloading_invoice_url" varchar,
  "classification_state" varchar,
  "source" varchar not null default 'manual',
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "classification_type" varchar,
  "classification_category" varchar,
  "category" varchar,
  "document_path" varchar,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("supplier_id") references "suppliers"("id") on delete set null
);
CREATE UNIQUE INDEX "expenses_company_id_mydata_mark_unique" on "expenses"(
  "company_id",
  "mydata_mark"
);
CREATE INDEX "expenses_company_id_issue_date_index" on "expenses"(
  "company_id",
  "issue_date"
);
CREATE INDEX "expenses_company_id_mydata_state_index" on "expenses"(
  "company_id",
  "mydata_state"
);
CREATE INDEX "expenses_supplier_id_index" on "expenses"("supplier_id");
CREATE TABLE IF NOT EXISTS "expense_lines"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "expense_id" integer not null,
  "line_number" integer,
  "item_code" varchar,
  "item_descr" varchar,
  "quantity" numeric,
  "measurement_unit" varchar,
  "net_value" numeric not null default '0',
  "vat_category" integer,
  "vat_exemption_category" integer,
  "vat_amount" numeric not null default '0',
  "classification_type" varchar,
  "classification_category" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("expense_id") references "expenses"("id") on delete cascade
);
CREATE INDEX "expense_lines_expense_id_index" on "expense_lines"("expense_id");
CREATE TABLE IF NOT EXISTS "expense_marks"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "expense_id" integer,
  "mark" varchar,
  "mydata_action" varchar,
  "request" text,
  "response" text,
  "mark_date" date,
  "mark_time" time,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("expense_id") references "expenses"("id") on delete cascade
);
CREATE INDEX "expense_marks_expense_id_index" on "expense_marks"("expense_id");
CREATE INDEX "expense_marks_company_id_mark_index" on "expense_marks"(
  "company_id",
  "mark"
);
CREATE INDEX "expenses_company_id_classification_state_index" on "expenses"(
  "company_id",
  "classification_state"
);
CREATE INDEX "expenses_company_id_source_category_index" on "expenses"(
  "company_id",
  "source",
  "category"
);
CREATE TABLE IF NOT EXISTS "quote_lines"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "quote_id" integer not null,
  "product_id" integer,
  "product_descr" varchar,
  "qty" numeric not null default '1',
  "price_per_item" numeric not null default '0',
  "discount" numeric not null default '0',
  "vat_percent" numeric not null default '0',
  "net_price" numeric not null default '0',
  "gross_price" numeric not null default '0',
  "metric_unit" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("quote_id") references "quotes"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete set null
);
CREATE TABLE IF NOT EXISTS "quote_mail_logs"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "quote_id" integer not null,
  "recipient" varchar not null,
  "cc_list" text,
  "bcc_list" text,
  "from_address" varchar,
  "subject" varchar,
  "trigger" varchar not null default 'manual',
  "status" varchar not null default 'queued',
  "error_message" text,
  "queued_at" datetime,
  "sent_at" datetime,
  "failed_at" datetime,
  "triggered_by_user_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("quote_id") references "quotes"("id") on delete cascade,
  foreign key("triggered_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "activity_log_company_id_index" on "activity_log"("company_id");
CREATE TABLE IF NOT EXISTS "billing_connections"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "source" varchar not null,
  "label" varchar,
  "is_active" tinyint(1) not null default '1',
  "config" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE INDEX "billing_connections_company_id_source_index" on "billing_connections"(
  "company_id",
  "source"
);
CREATE INDEX "billing_connections_company_id_is_active_index" on "billing_connections"(
  "company_id",
  "is_active"
);
CREATE INDEX "invoice_types_company_id_is_favorite_index" on "invoice_types"(
  "company_id",
  "is_favorite"
);
CREATE INDEX "customers_company_id_is_favorite_index" on "customers"(
  "company_id",
  "is_favorite"
);
CREATE INDEX "products_company_id_is_favorite_index" on "products"(
  "company_id",
  "is_favorite"
);
CREATE TABLE IF NOT EXISTS "customer_contacts"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "customer_id" integer not null,
  "name" varchar not null,
  "role" varchar,
  "email" varchar,
  "phone" varchar,
  "is_primary" tinyint(1) not null default '0',
  "notes" text,
  "sort_order" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade
);
CREATE INDEX "customer_contacts_company_id_customer_id_index" on "customer_contacts"(
  "company_id",
  "customer_id"
);
CREATE TABLE IF NOT EXISTS "attachments"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "attachable_type" varchar not null,
  "attachable_id" integer not null,
  "disk" varchar not null default 'local',
  "path" varchar not null,
  "original_name" varchar not null,
  "mime_type" varchar,
  "size" integer,
  "title" varchar,
  "uploaded_by_user_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("uploaded_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "attachments_attachable_type_attachable_id_index" on "attachments"(
  "attachable_type",
  "attachable_id"
);
CREATE TABLE IF NOT EXISTS "tags"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "name" varchar not null,
  "color" varchar,
  "is_pinned" tinyint(1) not null default '0',
  "sort_order" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "tags_company_id_name_unique" on "tags"(
  "company_id",
  "name"
);
CREATE INDEX "tags_company_id_is_pinned_index" on "tags"(
  "company_id",
  "is_pinned"
);
CREATE TABLE IF NOT EXISTS "taggables"(
  "tag_id" integer not null,
  "taggable_type" varchar not null,
  "taggable_id" integer not null,
  foreign key("tag_id") references "tags"("id") on delete cascade
);
CREATE INDEX "taggables_taggable_type_taggable_id_index" on "taggables"(
  "taggable_type",
  "taggable_id"
);
CREATE UNIQUE INDEX "taggables_unique" on "taggables"(
  "tag_id",
  "taggable_id",
  "taggable_type"
);
CREATE TABLE IF NOT EXISTS "notes"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "notable_type" varchar not null,
  "notable_id" integer not null,
  "body" text not null,
  "is_pinned" tinyint(1) not null default '0',
  "author_user_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "source" varchar,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("author_user_id") references "users"("id") on delete set null
);
CREATE INDEX "notes_notable_type_notable_id_index" on "notes"(
  "notable_type",
  "notable_id"
);
CREATE TABLE IF NOT EXISTS "delivery_note_lines"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "delivery_note_id" integer not null,
  "product_id" integer,
  "qty" numeric not null default '1',
  "measurement_unit" integer,
  "metric_unit" varchar,
  "move_purpose_line" integer,
  "product_descr" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("delivery_note_id") references "delivery_notes"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete set null
);
CREATE UNIQUE INDEX "delivery_note_lines_company_id_legacy_id_unique" on "delivery_note_lines"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "delivery_note_lines_delivery_note_id_index" on "delivery_note_lines"(
  "delivery_note_id"
);
CREATE TABLE IF NOT EXISTS "stock_movements"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "product_id" integer not null,
  "qty_change" numeric not null,
  "reason" varchar not null,
  "source_type" varchar,
  "source_id" integer,
  "note" text,
  "occurred_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete cascade
);
CREATE INDEX "stock_movements_source_type_source_id_index" on "stock_movements"(
  "source_type",
  "source_id"
);
CREATE INDEX "stock_movements_company_id_product_id_index" on "stock_movements"(
  "company_id",
  "product_id"
);
CREATE INDEX "stock_movements_company_id_occurred_at_index" on "stock_movements"(
  "company_id",
  "occurred_at"
);
CREATE TABLE IF NOT EXISTS "bank_accounts"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "bank_name" varchar,
  "iban" varchar,
  "account_name" varchar,
  "swift" varchar,
  "is_active" tinyint(1) not null default '1',
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "show_on_invoices" tinyint(1) not null default '1',
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "bank_accounts_company_id_legacy_id_unique" on "bank_accounts"(
  "company_id",
  "legacy_id"
);
CREATE TABLE IF NOT EXISTS "notifications"(
  "id" varchar not null,
  "type" varchar not null,
  "notifiable_type" varchar not null,
  "notifiable_id" integer not null,
  "data" text not null,
  "read_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "notifications_notifiable_type_notifiable_id_index" on "notifications"(
  "notifiable_type",
  "notifiable_id"
);
CREATE TABLE IF NOT EXISTS "product_billing_prices"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "product_id" integer not null,
  "billing_cycle" varchar not null,
  "setup_fee" numeric not null default '0',
  "price" numeric not null default '0',
  "is_enabled" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete cascade
);
CREATE UNIQUE INDEX "product_billing_prices_product_id_billing_cycle_unique" on "product_billing_prices"(
  "product_id",
  "billing_cycle"
);
CREATE TABLE IF NOT EXISTS "server_groups"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "name" varchar not null,
  "module" varchar,
  "username" varchar,
  "secret_encrypted" text,
  "meta" text,
  "notes" text,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "servers"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "server_group_id" integer,
  "name" varchar not null,
  "module" varchar,
  "hostname" varchar,
  "api_endpoint" varchar,
  "username" varchar,
  "secret_encrypted" text,
  "meta" text,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("server_group_id") references "server_groups"("id") on delete set null
);
CREATE TABLE IF NOT EXISTS "service_contracts"(
  "id" integer primary key autoincrement not null,
  "legacy_id" integer,
  "company_id" integer not null,
  "customer_id" integer not null,
  "product_id" integer,
  "invoice_type_id" integer,
  "payment_method_id" integer,
  "server_id" integer,
  "description" varchar,
  "billing_cycle" varchar not null,
  "quantity" numeric not null default '1',
  "amount" numeric not null default '0',
  "setup_fee" numeric not null default '0',
  "vat_percent" numeric not null default '0',
  "status" varchar not null default 'pending',
  "start_date" date,
  "next_due_date" date,
  "end_date" date,
  "last_invoiced_at" datetime,
  "last_renewal_invoice_id" integer,
  "suspended_at" datetime,
  "terminated_at" datetime,
  "cancel_reason" varchar,
  "suspend_after_days" integer,
  "terminate_after_days" integer,
  "domain" varchar,
  "provisioning_module" varchar not null default 'none',
  "module_meta" text,
  "whmcs_service_id" integer,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "dunning_enabled" tinyint(1),
  "dunning_suspended_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete restrict,
  foreign key("product_id") references "products"("id") on delete set null,
  foreign key("invoice_type_id") references "invoice_types"("id") on delete set null,
  foreign key("payment_method_id") references "payment_methods"("id") on delete set null,
  foreign key("server_id") references "servers"("id") on delete set null,
  foreign key("last_renewal_invoice_id") references "invoices"("id") on delete set null
);
CREATE UNIQUE INDEX "service_contracts_company_id_legacy_id_unique" on "service_contracts"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "service_contracts_company_id_status_next_due_date_index" on "service_contracts"(
  "company_id",
  "status",
  "next_due_date"
);
CREATE INDEX "service_contracts_company_id_customer_id_index" on "service_contracts"(
  "company_id",
  "customer_id"
);
CREATE TABLE IF NOT EXISTS "delivery_marks"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "delivery_note_id" integer,
  "mark" varchar,
  "mydata_action" varchar,
  "invoice_url" varchar,
  "request" text,
  "response" text,
  "mark_date" date,
  "mark_time" time,
  "created_at" datetime,
  "updated_at" datetime,
  "provider_key" varchar,
  "authentication_code" varchar,
  "provider_delivery_state" varchar,
  "cancellation_mark" varchar,
  "movable_type" varchar,
  "movable_id" integer,
  foreign key("delivery_note_id") references delivery_notes("id") on delete cascade on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "delivery_marks_company_id_legacy_id_unique" on "delivery_marks"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "delivery_marks_delivery_note_id_index" on "delivery_marks"(
  "delivery_note_id"
);
CREATE TABLE IF NOT EXISTS "company_backup_settings"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "enabled" tinyint(1) not null default '0',
  "frequency" varchar not null default 'off',
  "run_at_time" varchar not null default '02:00',
  "bucket" varchar not null default 'settings_setup',
  "secrets_mode" varchar not null default 'passphrase',
  "passphrase" text,
  "retention_keep" integer not null default '7',
  "retention_days" integer,
  "destinations" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "company_backup_settings_company_id_unique" on "company_backup_settings"(
  "company_id"
);
CREATE TABLE IF NOT EXISTS "company_backup_runs"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "started_at" datetime not null,
  "finished_at" datetime,
  "trigger" varchar not null default 'manual',
  "bucket" varchar not null,
  "secrets_mode" varchar not null,
  "destinations" text,
  "bytes" integer,
  "status" varchar not null default 'ok',
  "message" text,
  "bundle_path" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE INDEX "company_backup_runs_company_id_started_at_index" on "company_backup_runs"(
  "company_id",
  "started_at"
);
CREATE TABLE IF NOT EXISTS "scheduled_task_runs"(
  "id" integer primary key autoincrement not null,
  "task" varchar not null,
  "status" varchar not null default 'running',
  "exit_code" integer,
  "duration_ms" integer,
  "summary" text,
  "started_at" datetime,
  "finished_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "scheduled_task_runs_task_started_at_index" on "scheduled_task_runs"(
  "task",
  "started_at"
);
CREATE INDEX "scheduled_task_runs_task_index" on "scheduled_task_runs"("task");
CREATE TABLE IF NOT EXISTS "system_settings"(
  "id" integer primary key autoincrement not null,
  "key" varchar not null,
  "value" text,
  "type" varchar not null default 'string',
  "updated_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("updated_by") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "system_settings_key_unique" on "system_settings"("key");
CREATE TABLE IF NOT EXISTS "ai_usage_log"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "user_id" integer,
  "conversation_id" varchar,
  "model" varchar not null,
  "input_tokens" integer not null default '0',
  "output_tokens" integer not null default '0',
  "cache_read_tokens" integer not null default '0',
  "cache_write_tokens" integer not null default '0',
  "cost_estimate" numeric not null default '0',
  "created_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE INDEX "ai_usage_log_company_id_created_at_index" on "ai_usage_log"(
  "company_id",
  "created_at"
);
CREATE TABLE IF NOT EXISTS "expense_classification_rules"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "supplier_afm" varchar not null,
  "invoice_type" varchar,
  "classification_type" varchar not null,
  "classification_category" varchar not null,
  "label" varchar,
  "is_active" tinyint(1) not null default '1',
  "priority" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE INDEX "expense_classification_rules_company_id_supplier_afm_index" on "expense_classification_rules"(
  "company_id",
  "supplier_afm"
);
CREATE TABLE IF NOT EXISTS "ai_pending_actions"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "user_id" integer,
  "conversation_id" varchar,
  "type" varchar not null,
  "status" varchar not null default 'pending',
  "customer_id" integer,
  "summary" varchar not null,
  "payload" text,
  "remind_at" datetime,
  "confirmed_at" datetime,
  "cancelled_at" datetime,
  "delivered_at" datetime,
  "result" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  foreign key("customer_id") references "customers"("id") on delete set null
);
CREATE INDEX "ai_pending_actions_company_id_user_id_status_index" on "ai_pending_actions"(
  "company_id",
  "user_id",
  "status"
);
CREATE INDEX "ai_pending_actions_type_status_remind_at_delivered_at_index" on "ai_pending_actions"(
  "type",
  "status",
  "remind_at",
  "delivered_at"
);
CREATE TABLE IF NOT EXISTS "cmr_notes"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "number" integer not null,
  "reference_no" varchar,
  "status" varchar not null default 'draft',
  "source_type" varchar,
  "source_id" integer,
  "customer_id" integer,
  "issued_at" datetime not null,
  "sender_text" text,
  "consignee_text" text,
  "delivery_text" text,
  "taking_over_place" varchar,
  "taking_over_at" datetime,
  "carrier_name" varchar,
  "carrier_address" varchar,
  "successive_carrier" varchar,
  "carrier_reservations" text,
  "tractor_plate" varchar,
  "trailer_plate" varchar,
  "annexed_documents" text,
  "sender_instructions" text,
  "special_agreements" text,
  "freight_paid" tinyint(1),
  "charges_to_be_paid_by" varchar,
  "carriage_charges" numeric,
  "reductions" numeric,
  "balance" numeric,
  "supplement" numeric,
  "misc_charges" numeric,
  "total_charges" numeric,
  "cash_on_delivery" numeric,
  "established_place" varchar,
  "established_on" date,
  "copies_count" integer not null default '4',
  "printed" tinyint(1) not null default '0',
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete set null
);
CREATE INDEX "cmr_notes_source_type_source_id_index" on "cmr_notes"(
  "source_type",
  "source_id"
);
CREATE UNIQUE INDEX "cmr_notes_company_id_number_unique" on "cmr_notes"(
  "company_id",
  "number"
);
CREATE INDEX "cmr_notes_company_id_issued_at_index" on "cmr_notes"(
  "company_id",
  "issued_at"
);
CREATE TABLE IF NOT EXISTS "cmr_lines"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "cmr_note_id" integer not null,
  "marks_numbers" varchar,
  "packages_count" integer,
  "packing_method" varchar,
  "nature_en" varchar,
  "statistical_no" varchar,
  "weight_kg" numeric,
  "volume_m3" numeric,
  "adr_class" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("cmr_note_id") references "cmr_notes"("id") on delete cascade
);
CREATE INDEX "cmr_lines_cmr_note_id_index" on "cmr_lines"("cmr_note_id");
CREATE TABLE IF NOT EXISTS "pending_whmcs_invoices"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "whmcs_invoice_id" integer not null,
  "whmcs_userid" integer,
  "customer_id" integer,
  "payload" text not null,
  "match_reason" varchar not null,
  "status" varchar not null default('pending_review'),
  "notes" text,
  "rejected_reason" varchar,
  "filed_at" datetime,
  "filed_by_user_id" integer,
  "mydata_mark" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "invoice_id" integer,
  "whmcs_writeback_state" varchar,
  "whmcs_writeback_error" text,
  "third_party_state" varchar,
  "third_party_resolution" text,
  "hold_reason" text,
  "legacy_invoiced" integer,
  "source" varchar not null default('whmcs'),
  "whmcs_payment_pushed_at" datetime,
  foreign key("invoice_id") references invoices("id") on delete restrict on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("customer_id") references customers("id") on delete set null on update no action,
  foreign key("filed_by_user_id") references users("id") on delete set null on update no action
);
CREATE INDEX "pending_whmcs_invoices_company_id_source_index" on "pending_whmcs_invoices"(
  "company_id",
  "source"
);
CREATE UNIQUE INDEX "pwi_company_invoice_unique" on "pending_whmcs_invoices"(
  "company_id",
  "whmcs_invoice_id"
);
CREATE INDEX "pwi_company_writeback_idx" on "pending_whmcs_invoices"(
  "company_id",
  "whmcs_writeback_state"
);
CREATE INDEX "pwi_inbox_filter_idx" on "pending_whmcs_invoices"(
  "company_id",
  "status",
  "created_at"
);
CREATE TABLE IF NOT EXISTS "invoice_lines"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "invoice_id" integer not null,
  "product_id" integer,
  "qty" numeric not null default('1'),
  "price_per_item" numeric,
  "discount" numeric not null default('0'),
  "vat_percent" numeric,
  "net_price" numeric,
  "gross_price" numeric,
  "product_descr" varchar,
  "metric_unit" varchar,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "original_line_id" integer,
  "vat_exemption_category" integer,
  "mydata_income_class" varchar,
  "mydata_income_class_category" varchar,
  foreign key("product_id") references products("id") on delete set null on update no action,
  foreign key("invoice_id") references invoices("id") on delete cascade on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("original_line_id") references "invoice_lines"("id") on delete set null
);
CREATE UNIQUE INDEX "invoice_lines_company_id_legacy_id_unique" on "invoice_lines"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "invoice_lines_invoice_id_index" on "invoice_lines"("invoice_id");
CREATE TABLE IF NOT EXISTS "invoice_mail_log"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "invoice_id" integer not null,
  "recipient" varchar not null,
  "cc_list" text,
  "bcc_list" text,
  "from_address" varchar,
  "subject" varchar,
  "trigger" varchar check("trigger" in('auto', 'manual', 'batch')) not null default 'auto',
  "status" varchar not null default('queued'),
  "error_message" text,
  "queued_at" datetime not null default(CURRENT_TIMESTAMP),
  "sent_at" datetime,
  "failed_at" datetime,
  "triggered_by_user_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "send_key" varchar,
  foreign key("triggered_by_user_id") references users("id") on delete set null on update no action,
  foreign key("invoice_id") references invoices("id") on delete cascade on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action
);
CREATE INDEX "invoice_mail_log_invoice_id_created_at_index" on "invoice_mail_log"(
  "invoice_id",
  "created_at"
);
CREATE INDEX "invoice_mail_log_status_created_at_index" on "invoice_mail_log"(
  "status",
  "created_at"
);
CREATE INDEX "invoice_mail_log_send_key_status_index" on "invoice_mail_log"(
  "send_key",
  "status"
);
CREATE TABLE IF NOT EXISTS "companies"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "slug" varchar not null,
  "afm" varchar,
  "tax_office" varchar,
  "address" varchar,
  "city" varchar,
  "postcode" varchar,
  "phone" varchar,
  "email" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "country_code" varchar not null default('GR'),
  "einvoice_provider" varchar not null default('gr-mydata'),
  "gsis_username" varchar,
  "gsis_password" text,
  "kad_primary" varchar,
  "mydata_mode" varchar not null default('off'),
  "logo_path" varchar,
  "pdf_footer_text" text,
  "mail_from_address" varchar,
  "mail_from_name" varchar,
  "invoice_audit_bcc" varchar,
  "auto_email_on_mydata_accept" tinyint(1) not null default('0'),
  "mail_smtp_host" varchar,
  "mail_smtp_port" integer,
  "mail_smtp_username" varchar,
  "mail_smtp_password" text,
  "mail_smtp_encryption" varchar,
  "mail_subject_template" varchar,
  "mail_body_template" text,
  "whmcs_api_url" varchar,
  "whmcs_api_identifier" varchar,
  "whmcs_api_secret" text,
  "whmcs_custom_field_map" text,
  "whmcs_webhook_secret" text,
  "whmcs_invoice_min_date" date,
  "whmcs_third_party_enabled" tinyint(1) not null default('0'),
  "whmcs_amount_includes_tax" tinyint(1) not null default('1'),
  "auto_email_on_issue" tinyint(1) not null default('0'),
  "whmcs_auto_issue_immediate" tinyint(1) not null default('0'),
  "whmcs_default_invoice_type_id" integer,
  "mydata_aade_id_sandbox" varchar,
  "mydata_subscription_key_sandbox" text,
  "mydata_aade_id_production" varchar,
  "mydata_subscription_key_production" text,
  "quote_counter" integer not null default('0'),
  "whmcs_fetch_via_bridge" tinyint(1) not null default('0'),
  "einvoice_provider_key" varchar,
  "einvoice_provider_config" text,
  "einvoice_provider_mode" varchar not null default('off'),
  "mydata_send_item_descr" tinyint(1) not null default('0'),
  "whmcs_default_receipt_type_id" integer,
  "show_customer_balance_on_pdf" tinyint(1) not null default('0'),
  "ai_assistant_enabled" tinyint(1) not null default('0'),
  "ai_model" varchar,
  "ai_monthly_token_cap" integer,
  "ai_api_key" text,
  "name_en" varchar,
  "address_en" varchar,
  "city_en" varchar,
  "gemi" varchar,
  "mydata_auto_fetch_expenses" tinyint(1) not null default('0'),
  "whmcs_default_unpaid_type_id" integer,
  "whmcs_push_payments" tinyint(1) not null default '0',
  "business_activity_type" varchar,
  "mydata_read_env" varchar,
  "einvoice_include_customer_email" tinyint(1) not null default '0',
  "support_enabled" tinyint(1) not null default '0',
  "enable_domain_management" tinyint(1) not null default '0',
  foreign key("whmcs_default_receipt_type_id") references invoice_types("id") on delete set null on update no action,
  foreign key("whmcs_default_invoice_type_id") references invoice_types("id") on delete set null on update no action,
  foreign key("whmcs_default_unpaid_type_id") references "invoice_types"("id") on delete set null
);
CREATE UNIQUE INDEX "companies_slug_unique" on "companies"("slug");
CREATE TABLE IF NOT EXISTS "update_runs"(
  "id" integer primary key autoincrement not null,
  "status" varchar not null default('queued'),
  "phase" varchar,
  "strategy" varchar not null default('php'),
  "from_version" varchar,
  "from_ref" varchar,
  "to_version" varchar,
  "to_ref" varchar,
  "snapshot_file" varchar,
  "output" text,
  "error_message" text,
  "triggered_by_user_id" integer,
  "started_at" datetime,
  "finished_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "kind" varchar not null default 'update',
  "rollback_of_id" integer,
  "restore_snapshot" varchar,
  foreign key("triggered_by_user_id") references users("id") on delete set null on update no action,
  foreign key("rollback_of_id") references "update_runs"("id") on delete set null
);
CREATE INDEX "update_runs_status_index" on "update_runs"("status");
CREATE TABLE IF NOT EXISTS "personal_access_tokens"(
  "id" integer primary key autoincrement not null,
  "tokenable_type" varchar not null,
  "tokenable_id" integer not null,
  "name" text not null,
  "token" varchar not null,
  "abilities" text,
  "last_used_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens"(
  "tokenable_type",
  "tokenable_id"
);
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens"(
  "token"
);
CREATE INDEX "personal_access_tokens_expires_at_index" on "personal_access_tokens"(
  "expires_at"
);
CREATE TABLE IF NOT EXISTS "oauth_auth_codes"(
  "id" varchar not null,
  "user_id" integer not null,
  "client_id" varchar not null,
  "scopes" text,
  "revoked" tinyint(1) not null,
  "expires_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_auth_codes_user_id_index" on "oauth_auth_codes"("user_id");
CREATE TABLE IF NOT EXISTS "oauth_access_tokens"(
  "id" varchar not null,
  "user_id" integer,
  "client_id" varchar not null,
  "name" varchar,
  "scopes" text,
  "revoked" tinyint(1) not null,
  "created_at" datetime,
  "updated_at" datetime,
  "expires_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_access_tokens_user_id_index" on "oauth_access_tokens"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "oauth_refresh_tokens"(
  "id" varchar not null,
  "access_token_id" varchar not null,
  "revoked" tinyint(1) not null,
  "expires_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_refresh_tokens_access_token_id_index" on "oauth_refresh_tokens"(
  "access_token_id"
);
CREATE TABLE IF NOT EXISTS "oauth_clients"(
  "id" varchar not null,
  "owner_type" varchar,
  "owner_id" integer,
  "name" varchar not null,
  "secret" varchar,
  "provider" varchar,
  "redirect_uris" text not null,
  "grant_types" text not null,
  "revoked" tinyint(1) not null,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_clients_owner_type_owner_id_index" on "oauth_clients"(
  "owner_type",
  "owner_id"
);
CREATE TABLE IF NOT EXISTS "oauth_device_codes"(
  "id" varchar not null,
  "user_id" integer,
  "client_id" varchar not null,
  "user_code" varchar not null,
  "scopes" text not null,
  "revoked" tinyint(1) not null,
  "user_approved_at" datetime,
  "last_polled_at" datetime,
  "expires_at" datetime,
  primary key("id")
);
CREATE INDEX "oauth_device_codes_user_id_index" on "oauth_device_codes"(
  "user_id"
);
CREATE INDEX "oauth_device_codes_client_id_index" on "oauth_device_codes"(
  "client_id"
);
CREATE UNIQUE INDEX "oauth_device_codes_user_code_unique" on "oauth_device_codes"(
  "user_code"
);
CREATE TABLE IF NOT EXISTS "leads"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "name" varchar not null,
  "contact_person" varchar,
  "phone" varchar,
  "mobile" varchar,
  "email" varchar,
  "website" varchar,
  "afm" varchar,
  "address1" varchar,
  "city" varchar,
  "postcode" varchar,
  "country" varchar,
  "occupation" varchar,
  "source" varchar,
  "referred_by_customer_id" integer,
  "status" varchar not null default 'new',
  "lost_reason" varchar,
  "assigned_user_id" integer,
  "next_action_at" datetime,
  "last_activity_at" datetime,
  "converted_customer_id" integer,
  "converted_at" datetime,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("referred_by_customer_id") references "customers"("id") on delete set null,
  foreign key("assigned_user_id") references "users"("id") on delete set null,
  foreign key("converted_customer_id") references "customers"("id") on delete set null
);
CREATE INDEX "leads_company_id_status_index" on "leads"(
  "company_id",
  "status"
);
CREATE INDEX "leads_company_id_afm_index" on "leads"("company_id", "afm");
CREATE INDEX "leads_company_id_assigned_user_id_index" on "leads"(
  "company_id",
  "assigned_user_id"
);
CREATE INDEX "leads_company_id_next_action_at_index" on "leads"(
  "company_id",
  "next_action_at"
);
CREATE UNIQUE INDEX "leads_converted_customer_id_unique" on "leads"(
  "converted_customer_id"
);
CREATE TABLE IF NOT EXISTS "lead_activities"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "lead_id" integer not null,
  "user_id" integer,
  "type" varchar not null,
  "direction" varchar,
  "outcome" varchar,
  "happened_at" datetime not null,
  "body" text,
  "meta" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("lead_id") references "leads"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE INDEX "lead_activities_company_id_lead_id_happened_at_index" on "lead_activities"(
  "company_id",
  "lead_id",
  "happened_at"
);
CREATE INDEX "lead_activities_company_id_user_id_happened_at_index" on "lead_activities"(
  "company_id",
  "user_id",
  "happened_at"
);
CREATE TABLE IF NOT EXISTS "quotes"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "customer_id" integer,
  "code" varchar,
  "legacy_id" varchar,
  "subject" varchar,
  "status" varchar not null default('draft'),
  "issued_at" date,
  "valid_until" date,
  "service_until" date,
  "company_name" varchar,
  "vat_no" varchar,
  "vies_vat" varchar,
  "occupation" varchar,
  "address1" varchar,
  "address2" varchar,
  "city" varchar,
  "postcode" varchar,
  "country" varchar default('GR'),
  "header_discount_percent" numeric not null default('0'),
  "net_total" numeric not null default('0'),
  "vat_total" numeric not null default('0'),
  "gross_total" numeric not null default('0'),
  "proposal_text" text,
  "customer_notes" text,
  "admin_notes" text,
  "converted_invoice_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "converted_service_contract_id" integer,
  "language" varchar,
  "lead_id" integer,
  foreign key("converted_service_contract_id") references service_contracts("id") on delete set null on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("customer_id") references customers("id") on delete set null on update no action,
  foreign key("converted_invoice_id") references invoices("id") on delete set null on update no action,
  foreign key("lead_id") references "leads"("id") on delete set null
);
CREATE INDEX "quotes_company_id_service_until_index" on "quotes"(
  "company_id",
  "service_until"
);
CREATE INDEX "quotes_company_id_status_index" on "quotes"(
  "company_id",
  "status"
);
CREATE INDEX "quotes_company_id_valid_until_index" on "quotes"(
  "company_id",
  "valid_until"
);
CREATE UNIQUE INDEX "customers_company_afm_key_unique" on "customers"(
  "company_id",
  "afm_key"
);
CREATE TABLE IF NOT EXISTS "whmcs_income_maps"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "scope" varchar not null,
  "whmcs_key" integer not null,
  "income_class_category" varchar not null,
  "income_class" varchar,
  "label" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "whmcs_income_maps_company_id_scope_whmcs_key_unique" on "whmcs_income_maps"(
  "company_id",
  "scope",
  "whmcs_key"
);
CREATE TABLE IF NOT EXISTS "whmcs_payment_maps"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "whmcs_gateway" varchar not null,
  "payment_method_id" integer,
  "label" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("payment_method_id") references "payment_methods"("id") on delete cascade
);
CREATE UNIQUE INDEX "whmcs_payment_maps_company_id_whmcs_gateway_unique" on "whmcs_payment_maps"(
  "company_id",
  "whmcs_gateway"
);
CREATE TABLE IF NOT EXISTS "invoices"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "invcode" varchar,
  "code" integer,
  "invoice_type_id" integer not null,
  "customer_id" integer,
  "issued_at" datetime not null,
  "distribution_aim_id" integer,
  "delivery_method_id" integer,
  "payment_method_id" integer,
  "conv_invoice_id" integer,
  "delivery_date" date,
  "header_discount_percent" numeric not null default('0'),
  "net_total" numeric,
  "gross_total" numeric,
  "withhold_amount" numeric,
  "address1" varchar,
  "address2" varchar,
  "city" varchar,
  "postcode" varchar,
  "country" varchar,
  "company_name" varchar,
  "vat_no" varchar,
  "vies_vat" varchar,
  "occupation" varchar,
  "notes" text,
  "mydata_sent" tinyint(1),
  "mydata_state" varchar,
  "mydata_mark" varchar,
  "mydata_url" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "mydata_type" varchar,
  "paid_total" numeric,
  "credited_total" numeric,
  "payment_status" varchar,
  "credited_invoice_id" integer,
  "local_status" varchar not null default('draft'),
  "cancel_reason" text,
  "whmcs_pending_id" integer,
  "withhold_category" integer,
  "whmcs_invoice_id" integer,
  "bank_account_id" integer,
  "service_contract_id" integer,
  "fees_amount" numeric,
  "fees_category" integer,
  "other_taxes_amount" numeric,
  "other_taxes_category" integer,
  "stamp_duty_amount" numeric,
  "stamp_duty_category" integer,
  "deductions_amount" numeric,
  "deductions_category" integer,
  "withhold_rate" numeric,
  "fees_rate" numeric,
  "other_taxes_rate" numeric,
  "stamp_duty_rate" numeric,
  "deductions_rate" numeric,
  "language" varchar,
  "payable_total" numeric,
  "customer_balance_snapshot" numeric,
  "mydata_pending_since" datetime,
  "series" varchar,
  "reissued_from_invoice_id" integer,
  "counterpart_branch" integer not null default '0',
  "is_delivery_note" tinyint(1) not null default '0',
  "move_purpose" integer,
  "other_move_purpose_title" varchar,
  "dispatch_at" datetime,
  "vehicle_number" varchar,
  "loading_street" varchar,
  "loading_number" varchar,
  "loading_postcode" varchar,
  "loading_city" varchar,
  "start_shipping_branch" integer,
  "delivery_street" varchar,
  "delivery_number" varchar,
  "delivery_postcode" varchar,
  "delivery_city" varchar,
  "complete_shipping_branch" integer,
  "transport_type" integer,
  "carrier_afm" varchar,
  "delivery_state" varchar,
  "transfer_mark" varchar,
  "return_mark" varchar,
  "without_digital_transport_tracking" tinyint(1) not null default '0',
  foreign key("reissued_from_invoice_id") references invoices("id") on delete set null on update no action,
  foreign key("service_contract_id") references service_contracts("id") on delete set null on update no action,
  foreign key("whmcs_pending_id") references pending_whmcs_invoices("id") on delete set null on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("invoice_type_id") references invoice_types("id") on delete restrict on update no action,
  foreign key("customer_id") references customers("id") on delete set null on update no action,
  foreign key("distribution_aim_id") references distribution_aims("id") on delete set null on update no action,
  foreign key("delivery_method_id") references delivery_methods("id") on delete set null on update no action,
  foreign key("payment_method_id") references payment_methods("id") on delete set null on update no action,
  foreign key("conv_invoice_id") references invoices("id") on delete set null on update no action,
  foreign key("credited_invoice_id") references invoices("id") on delete set null on update no action,
  foreign key("bank_account_id") references bank_accounts("id") on delete set null on update no action
);
CREATE UNIQUE INDEX "invoices_company_id_invcode_unique" on "invoices"(
  "company_id",
  "invcode"
);
CREATE INDEX "invoices_company_id_invoice_type_id_code_index" on "invoices"(
  "company_id",
  "invoice_type_id",
  "code"
);
CREATE INDEX "invoices_company_id_issued_at_index" on "invoices"(
  "company_id",
  "issued_at"
);
CREATE INDEX "invoices_company_id_local_status_index" on "invoices"(
  "company_id",
  "local_status"
);
CREATE INDEX "invoices_company_id_payment_status_index" on "invoices"(
  "company_id",
  "payment_status"
);
CREATE UNIQUE INDEX "invoices_company_legacy_unique" on "invoices"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "invoices_company_whmcs_invoice_idx" on "invoices"(
  "company_id",
  "whmcs_invoice_id"
);
CREATE TABLE IF NOT EXISTS "customer_users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar,
  "remember_token" varchar,
  "status" varchar not null default 'invited',
  "last_login_at" datetime,
  "last_login_ip" varchar,
  "username" varchar,
  "locale" varchar,
  "phone" varchar,
  "two_factor_secret" text,
  "two_factor_recovery_codes" text,
  "two_factor_confirmed_at" datetime,
  "password_changed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime
);
CREATE UNIQUE INDEX "customer_users_email_unique" on "customer_users"("email");
CREATE INDEX "customer_users_status_index" on "customer_users"("status");
CREATE UNIQUE INDEX "customer_users_username_unique" on "customer_users"(
  "username"
);
CREATE TABLE IF NOT EXISTS "customer_user_access"(
  "id" integer primary key autoincrement not null,
  "customer_user_id" integer not null,
  "company_id" integer not null,
  "customer_id" integer not null,
  "role" varchar not null default 'owner',
  "granted_by" integer,
  "granted_at" datetime,
  "revoked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("customer_user_id") references "customer_users"("id") on delete cascade,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete cascade,
  foreign key("granted_by") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "cua_login_company_customer_unique" on "customer_user_access"(
  "customer_user_id",
  "company_id",
  "customer_id"
);
CREATE INDEX "customer_user_access_company_id_customer_id_index" on "customer_user_access"(
  "company_id",
  "customer_id"
);
CREATE TABLE IF NOT EXISTS "customer_users_password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "delivery_notes"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "invcode" varchar,
  "code" integer,
  "delivery_type_id" integer not null,
  "customer_id" integer,
  "issued_at" datetime not null,
  "mydata_type" varchar,
  "move_purpose" integer,
  "other_move_purpose_title" varchar,
  "distribution_aim_id" integer,
  "delivery_method_id" integer,
  "dispatch_at" datetime,
  "vehicle_number" varchar,
  "transport_type" integer,
  "carrier_afm" varchar,
  "loading_street" varchar,
  "loading_number" varchar,
  "loading_postcode" varchar,
  "loading_city" varchar,
  "start_shipping_branch" integer,
  "delivery_street" varchar,
  "delivery_number" varchar,
  "delivery_postcode" varchar,
  "delivery_city" varchar,
  "complete_shipping_branch" integer,
  "recipient_name" varchar,
  "recipient_afm" varchar,
  "third_party_collection" tinyint(1) not null default('0'),
  "local_status" varchar not null default('draft'),
  "printed" tinyint(1) not null default('0'),
  "notes" text,
  "mydata_sent" tinyint(1),
  "mydata_state" varchar,
  "mydata_mark" varchar,
  "mydata_url" varchar,
  "delivery_state" varchar,
  "transfer_mark" varchar,
  "outcome_mark" varchar,
  "reject_mark" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "invoice_id" integer,
  "recipient_country" varchar,
  "series" varchar,
  "mydata_pending_since" datetime,
  "return_mark" varchar,
  foreign key("invoice_id") references invoices("id") on delete set null on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("delivery_type_id") references invoice_types("id") on delete restrict on update no action,
  foreign key("customer_id") references customers("id") on delete set null on update no action,
  foreign key("distribution_aim_id") references distribution_aims("id") on delete set null on update no action,
  foreign key("delivery_method_id") references delivery_methods("id") on delete set null on update no action
);
CREATE INDEX "delivery_notes_company_id_delivery_type_id_code_index" on "delivery_notes"(
  "company_id",
  "delivery_type_id",
  "code"
);
CREATE UNIQUE INDEX "delivery_notes_company_id_invcode_unique" on "delivery_notes"(
  "company_id",
  "invcode"
);
CREATE INDEX "delivery_notes_company_id_issued_at_index" on "delivery_notes"(
  "company_id",
  "issued_at"
);
CREATE TABLE IF NOT EXISTS "suppliers"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "afm" varchar,
  "name" varchar,
  "tax_office" varchar,
  "occupation" varchar,
  "address1" varchar,
  "city" varchar,
  "postcode" varchar,
  "country" varchar,
  "email" varchar,
  "phone1" varchar,
  "source" varchar not null default('manual'),
  "is_active" tinyint(1) not null default('1'),
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "country_code" varchar,
  foreign key("company_id") references companies("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "suppliers_company_id_afm_unique" on "suppliers"(
  "company_id",
  "afm"
);
CREATE INDEX "suppliers_company_id_is_active_index" on "suppliers"(
  "company_id",
  "is_active"
);
CREATE TABLE IF NOT EXISTS "payment_intents"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "customer_id" integer not null,
  "customer_user_id" integer,
  "gateway" varchar not null,
  "purpose" varchar not null default('balance'),
  "amount" numeric not null,
  "currency" varchar not null default('EUR'),
  "status" varchar not null default('pending'),
  "reference" varchar not null,
  "instructions" text,
  "expires_at" datetime,
  "settled_at" datetime,
  "settled_by" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "payment_gateway_connection_id" integer,
  "invoice_id" integer,
  foreign key("payment_gateway_connection_id") references payment_gateway_connections("id") on delete set null on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("customer_id") references customers("id") on delete cascade on update no action,
  foreign key("customer_user_id") references customer_users("id") on delete set null on update no action,
  foreign key("invoice_id") references "invoices"("id") on delete set null
);
CREATE INDEX "payment_intents_company_id_customer_id_index" on "payment_intents"(
  "company_id",
  "customer_id"
);
CREATE UNIQUE INDEX "payment_intents_company_id_reference_unique" on "payment_intents"(
  "company_id",
  "reference"
);
CREATE INDEX "payment_intents_company_id_status_index" on "payment_intents"(
  "company_id",
  "status"
);
CREATE TABLE IF NOT EXISTS "payments"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "customer_id" integer not null,
  "pay_date" date,
  "amount" numeric,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "invoice_id" integer,
  "payment_method_id" integer,
  "deleted_at" datetime,
  "reference" varchar,
  "transaction_id" varchar,
  "bank_account_id" integer,
  "kind" varchar not null default('payment'),
  "payment_intent_id" integer,
  foreign key("bank_account_id") references bank_accounts("id") on delete set null on update no action,
  foreign key("customer_id") references customers("id") on delete cascade on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("invoice_id") references invoices("id") on delete set null on update no action,
  foreign key("payment_method_id") references payment_methods("id") on delete set null on update no action,
  foreign key("payment_intent_id") references "payment_intents"("id") on delete set null
);
CREATE INDEX "payments_company_id_customer_id_index" on "payments"(
  "company_id",
  "customer_id"
);
CREATE INDEX "payments_company_id_invoice_id_index" on "payments"(
  "company_id",
  "invoice_id"
);
CREATE UNIQUE INDEX "payments_company_id_legacy_id_unique" on "payments"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "payments_company_id_reference_index" on "payments"(
  "company_id",
  "reference"
);
CREATE TABLE IF NOT EXISTS "payment_gateway_events"(
  "id" integer primary key autoincrement not null,
  "company_id" integer,
  "payment_intent_id" integer,
  "gateway" varchar not null,
  "order_id" varchar,
  "outcome" varchar not null,
  "reason" varchar,
  "verified" tinyint(1) not null default '0',
  "provider_status" varchar,
  "transaction_id" varchar,
  "amount" numeric,
  "currency" varchar,
  "ip" varchar,
  "message" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete set null
);
CREATE INDEX "payment_gateway_events_company_id_created_at_index" on "payment_gateway_events"(
  "company_id",
  "created_at"
);
CREATE INDEX "payment_gateway_events_company_id_payment_intent_id_index" on "payment_gateway_events"(
  "company_id",
  "payment_intent_id"
);
CREATE INDEX "payment_gateway_events_transaction_id_index" on "payment_gateway_events"(
  "transaction_id"
);
CREATE TABLE IF NOT EXISTS "payment_gateway_connections"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "gateway" varchar not null,
  "label" varchar,
  "is_active" tinyint(1) not null default('0'),
  "sort" integer not null default('0'),
  "config" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "payment_method_id" integer,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("payment_method_id") references "payment_methods"("id") on delete set null
);
CREATE INDEX "payment_gateway_connections_company_id_gateway_index" on "payment_gateway_connections"(
  "company_id",
  "gateway"
);
CREATE INDEX "payment_gateway_connections_company_id_is_active_index" on "payment_gateway_connections"(
  "company_id",
  "is_active"
);
CREATE TABLE IF NOT EXISTS "ticket_departments"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "name" varchar not null,
  "email" varchar,
  "imap_host" varchar,
  "imap_port" integer not null default '993',
  "imap_username" varchar,
  "imap_password" text,
  "imap_encryption" varchar not null default 'ssl',
  "imap_folder" varchar not null default 'INBOX',
  "clients_only" tinyint(1) not null default '0',
  "autoresponder" tinyint(1) not null default '1',
  "feedback_on_close" tinyint(1) not null default '0',
  "prevent_client_closure" tinyint(1) not null default '0',
  "is_hidden" tinyint(1) not null default '0',
  "sort" integer not null default '0',
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "ticket_departments_company_id_name_unique" on "ticket_departments"(
  "company_id",
  "name"
);
CREATE UNIQUE INDEX "ticket_departments_company_id_email_unique" on "ticket_departments"(
  "company_id",
  "email"
);
CREATE TABLE IF NOT EXISTS "ticket_department_user"(
  "ticket_department_id" integer not null,
  "user_id" integer not null,
  foreign key("ticket_department_id") references "ticket_departments"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  primary key("ticket_department_id", "user_id")
);
CREATE TABLE IF NOT EXISTS "ticket_messages"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "ticket_id" integer not null,
  "author_role" varchar not null,
  "author_id" integer,
  "body" text not null,
  "body_original" text,
  "is_internal_note" tinyint(1) not null default '0',
  "via" varchar not null default 'operator',
  "email_message_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("ticket_id") references "tickets"("id") on delete cascade
);
CREATE INDEX "ticket_messages_ticket_id_id_index" on "ticket_messages"(
  "ticket_id",
  "id"
);
CREATE INDEX "ticket_messages_email_message_id_index" on "ticket_messages"(
  "email_message_id"
);
CREATE TABLE IF NOT EXISTS "canned_reply_categories"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "name" varchar not null,
  "sort" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE UNIQUE INDEX "canned_reply_categories_company_id_name_unique" on "canned_reply_categories"(
  "company_id",
  "name"
);
CREATE TABLE IF NOT EXISTS "canned_replies"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "canned_reply_category_id" integer,
  "title" varchar not null,
  "body" text not null,
  "sort" integer not null default '0',
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("canned_reply_category_id") references "canned_reply_categories"("id") on delete set null
);
CREATE TABLE IF NOT EXISTS "ticket_poll_runs"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "ticket_department_id" integer,
  "connected" tinyint(1) not null default '0',
  "fetched" integer not null default '0',
  "processed" integer not null default '0',
  "errors" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("ticket_department_id") references "ticket_departments"("id") on delete set null
);
CREATE INDEX "ticket_poll_runs_company_id_created_at_index" on "ticket_poll_runs"(
  "company_id",
  "created_at"
);
CREATE INDEX "ticket_poll_runs_ticket_department_id_created_at_index" on "ticket_poll_runs"(
  "ticket_department_id",
  "created_at"
);
CREATE TABLE IF NOT EXISTS "ticket_watchers"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "ticket_id" integer not null,
  "user_id" integer,
  "email" varchar,
  "source" varchar not null default 'manual',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("ticket_id") references "tickets"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "ticket_watchers_ticket_id_user_id_unique" on "ticket_watchers"(
  "ticket_id",
  "user_id"
);
CREATE UNIQUE INDEX "ticket_watchers_ticket_id_email_unique" on "ticket_watchers"(
  "ticket_id",
  "email"
);
CREATE INDEX "ticket_watchers_company_id_ticket_id_index" on "ticket_watchers"(
  "company_id",
  "ticket_id"
);
CREATE TABLE IF NOT EXISTS "ticket_blocked_senders"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "pattern" varchar not null,
  "reason" varchar,
  "created_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("created_by") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "ticket_blocked_senders_company_id_pattern_unique" on "ticket_blocked_senders"(
  "company_id",
  "pattern"
);
CREATE TABLE IF NOT EXISTS "tickets"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "reference" varchar not null,
  "ticket_department_id" integer,
  "customer_id" integer,
  "requester_email" varchar,
  "requester_name" varchar,
  "subject" varchar not null,
  "status" varchar not null default('open'),
  "priority" varchar not null default('normal'),
  "assigned_to" integer,
  "opened_via" varchar not null default('operator'),
  "last_reply_at" datetime,
  "last_reply_role" varchar,
  "closed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "rating" integer,
  "rating_comment" text,
  "rated_at" datetime,
  "merged_into_id" integer,
  foreign key("assigned_to") references users("id") on delete set null on update no action,
  foreign key("customer_id") references customers("id") on delete set null on update no action,
  foreign key("ticket_department_id") references ticket_departments("id") on delete set null on update no action,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("merged_into_id") references "tickets"("id") on delete set null
);
CREATE INDEX "tickets_company_id_assigned_to_index" on "tickets"(
  "company_id",
  "assigned_to"
);
CREATE INDEX "tickets_company_id_customer_id_index" on "tickets"(
  "company_id",
  "customer_id"
);
CREATE UNIQUE INDEX "tickets_company_id_reference_unique" on "tickets"(
  "company_id",
  "reference"
);
CREATE INDEX "tickets_company_id_status_index" on "tickets"(
  "company_id",
  "status"
);
CREATE INDEX "tickets_company_id_ticket_department_id_index" on "tickets"(
  "company_id",
  "ticket_department_id"
);
CREATE TABLE IF NOT EXISTS "domain_registrar_connections"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "registrar" varchar not null,
  "label" varchar,
  "is_active" tinyint(1) not null default '0',
  "mode" varchar not null default 'off',
  "config" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade
);
CREATE INDEX "domain_registrar_connections_company_id_is_active_index" on "domain_registrar_connections"(
  "company_id",
  "is_active"
);
CREATE INDEX "domain_registrar_connections_company_id_registrar_index" on "domain_registrar_connections"(
  "company_id",
  "registrar"
);
CREATE TABLE IF NOT EXISTS "domain_tlds"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "tld" varchar not null,
  "registrar_connection_id" integer,
  "min_years" integer not null default '1',
  "max_years" integer not null default '10',
  "min_chars" integer not null default '3',
  "max_chars" integer not null default '63',
  "allow_idn" tinyint(1) not null default '0',
  "allow_transfer" tinyint(1) not null default '1',
  "grace_period_days" integer not null default '0',
  "grace_fee" numeric not null default '0',
  "redemption_period_days" integer not null default '0',
  "redemption_fee" numeric not null default '0',
  "dns_management" tinyint(1) not null default '0',
  "email_forwarding" tinyint(1) not null default '0',
  "id_protection" tinyint(1) not null default '0',
  "epp_code" tinyint(1) not null default '1',
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("registrar_connection_id") references "domain_registrar_connections"("id") on delete set null
);
CREATE UNIQUE INDEX "domain_tlds_company_id_tld_unique" on "domain_tlds"(
  "company_id",
  "tld"
);
CREATE INDEX "domain_tlds_company_id_is_active_index" on "domain_tlds"(
  "company_id",
  "is_active"
);
CREATE TABLE IF NOT EXISTS "domain_tld_prices"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "domain_tld_id" integer not null,
  "operation" varchar not null,
  "years" integer not null,
  "currency" varchar not null default 'EUR',
  "cost" numeric,
  "price" numeric,
  "is_enabled" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("domain_tld_id") references "domain_tlds"("id") on delete cascade
);
CREATE UNIQUE INDEX "domain_tld_prices_domain_tld_id_operation_years_currency_unique" on "domain_tld_prices"(
  "domain_tld_id",
  "operation",
  "years",
  "currency"
);
CREATE INDEX "domain_tld_prices_company_id_domain_tld_id_index" on "domain_tld_prices"(
  "company_id",
  "domain_tld_id"
);
CREATE TABLE IF NOT EXISTS "domains"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "legacy_id" integer,
  "customer_id" integer,
  "service_contract_id" integer,
  "domain_tld_id" integer not null,
  "registrar_connection_id" integer,
  "sld" varchar not null,
  "tld" varchar not null,
  "fqdn" varchar not null,
  "status" varchar not null default 'active',
  "registered_at" date,
  "transferred_at" date,
  "expires_at" date,
  "auto_renew" tinyint(1) not null default '0',
  "transfer_lock" tinyint(1) not null default '0',
  "whois_privacy" tinyint(1) not null default '0',
  "dnssec_enabled" tinyint(1) not null default '0',
  "consent_publish" tinyint(1) not null default '0',
  "registrar_domain_id" varchar,
  "idn_script" varchar,
  "last_synced_at" datetime,
  "sync_error" text,
  "grace_days_override" integer,
  "redemption_days_override" integer,
  "fee_override" numeric,
  "price_override" numeric,
  "module_meta" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("customer_id") references "customers"("id") on delete restrict,
  foreign key("service_contract_id") references "service_contracts"("id") on delete set null,
  foreign key("domain_tld_id") references "domain_tlds"("id") on delete restrict,
  foreign key("registrar_connection_id") references "domain_registrar_connections"("id") on delete set null
);
CREATE UNIQUE INDEX "domains_company_id_fqdn_unique" on "domains"(
  "company_id",
  "fqdn"
);
CREATE UNIQUE INDEX "domains_company_id_legacy_id_unique" on "domains"(
  "company_id",
  "legacy_id"
);
CREATE INDEX "domains_company_id_status_index" on "domains"(
  "company_id",
  "status"
);
CREATE INDEX "domains_company_id_expires_at_index" on "domains"(
  "company_id",
  "expires_at"
);
CREATE INDEX "domains_company_id_customer_id_index" on "domains"(
  "company_id",
  "customer_id"
);
CREATE TABLE IF NOT EXISTS "domain_nameservers"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "domain_id" integer not null,
  "host" varchar not null,
  "sort_order" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("domain_id") references "domains"("id") on delete cascade
);
CREATE INDEX "domain_nameservers_company_id_domain_id_index" on "domain_nameservers"(
  "company_id",
  "domain_id"
);
CREATE TABLE IF NOT EXISTS "domain_contacts"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "domain_id" integer not null,
  "type" varchar not null,
  "name" varchar not null,
  "org" varchar,
  "email" varchar,
  "phone" varchar,
  "address1" varchar,
  "address2" varchar,
  "city" varchar,
  "postcode" varchar,
  "country" varchar,
  "registrar_contact_handle" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("domain_id") references "domains"("id") on delete cascade
);
CREATE UNIQUE INDEX "domain_contacts_domain_id_type_unique" on "domain_contacts"(
  "domain_id",
  "type"
);
CREATE INDEX "domain_contacts_company_id_domain_id_index" on "domain_contacts"(
  "company_id",
  "domain_id"
);
CREATE TABLE IF NOT EXISTS "domain_registrar_logs"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "domain_id" integer,
  "registrar_connection_id" integer,
  "action" varchar not null,
  "status" varchar not null,
  "request" text,
  "response" text,
  "error" text,
  "invoice_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("domain_id") references "domains"("id") on delete set null,
  foreign key("registrar_connection_id") references "domain_registrar_connections"("id") on delete set null,
  foreign key("invoice_id") references "invoices"("id") on delete set null
);
CREATE INDEX "domain_registrar_logs_company_id_domain_id_index" on "domain_registrar_logs"(
  "company_id",
  "domain_id"
);
CREATE INDEX "domain_registrar_logs_company_id_action_status_index" on "domain_registrar_logs"(
  "company_id",
  "action",
  "status"
);
CREATE TABLE IF NOT EXISTS "auth_events"(
  "id" integer primary key autoincrement not null,
  "guard" varchar not null,
  "event" varchar not null,
  "user_id" integer,
  "email" varchar,
  "ip_address" varchar,
  "user_agent" text,
  "created_at" datetime
);
CREATE INDEX "auth_events_ip_address_created_at_index" on "auth_events"(
  "ip_address",
  "created_at"
);
CREATE INDEX "auth_events_email_created_at_index" on "auth_events"(
  "email",
  "created_at"
);
CREATE INDEX "auth_events_event_index" on "auth_events"("event");
CREATE INDEX "auth_events_guard_user_id_index" on "auth_events"(
  "guard",
  "user_id"
);
CREATE INDEX "auth_events_created_at_index" on "auth_events"("created_at");
CREATE INDEX "delivery_marks_movable_type_movable_id_index" on "delivery_marks"(
  "movable_type",
  "movable_id"
);
CREATE TABLE IF NOT EXISTS "delivery_note_events"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "delivery_note_id" integer,
  "event_mark" integer,
  "event_type" varchar not null,
  "event_timestamp" datetime,
  "actor_vat" varchar,
  "details" text,
  "dedup_key" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "movable_type" varchar,
  "movable_id" integer,
  foreign key("company_id") references companies("id") on delete cascade on update no action,
  foreign key("delivery_note_id") references "delivery_notes"("id") on delete cascade
);
CREATE INDEX "delivery_note_events_company_id_delivery_note_id_index" on "delivery_note_events"(
  "company_id",
  "delivery_note_id"
);
CREATE UNIQUE INDEX "delivery_note_events_delivery_note_id_dedup_key_unique" on "delivery_note_events"(
  "delivery_note_id",
  "dedup_key"
);
CREATE INDEX "delivery_note_events_movable_type_movable_id_index" on "delivery_note_events"(
  "movable_type",
  "movable_id"
);
CREATE UNIQUE INDEX "delivery_note_events_movable_type_movable_id_dedup_key_unique" on "delivery_note_events"(
  "movable_type",
  "movable_id",
  "dedup_key"
);
CREATE TABLE IF NOT EXISTS "inbound_delivery_notes"(
  "id" integer primary key autoincrement not null,
  "company_id" integer not null,
  "mydata_mark" varchar not null,
  "issuer_afm" varchar,
  "issuer_name" varchar,
  "supplier_id" integer,
  "invoice_type" varchar,
  "aa" varchar,
  "issue_date" date,
  "qr_code_url" varchar,
  "aade_delivery_status" integer,
  "local_state" varchar not null default 'new',
  "reject_mark" varchar,
  "outcome_mark" varchar,
  "payload" text not null,
  "lifecycle" text,
  "last_fetched_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("company_id") references "companies"("id") on delete cascade,
  foreign key("supplier_id") references "suppliers"("id") on delete set null
);
CREATE UNIQUE INDEX "inbound_delivery_notes_company_id_mydata_mark_unique" on "inbound_delivery_notes"(
  "company_id",
  "mydata_mark"
);
CREATE INDEX "inbound_delivery_notes_company_id_local_state_index" on "inbound_delivery_notes"(
  "company_id",
  "local_state"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2026_05_26_000001_create_companies_table',1);
INSERT INTO migrations VALUES(5,'2026_05_26_000002_create_company_user_table',1);
INSERT INTO migrations VALUES(6,'2026_05_26_000003_create_payment_methods_table',1);
INSERT INTO migrations VALUES(7,'2026_05_26_000004_create_delivery_methods_table',1);
INSERT INTO migrations VALUES(8,'2026_05_26_000005_create_distribution_aims_table',1);
INSERT INTO migrations VALUES(9,'2026_05_26_000006_create_metric_units_table',1);
INSERT INTO migrations VALUES(10,'2026_05_26_000007_create_vat_categories_table',1);
INSERT INTO migrations VALUES(11,'2026_05_26_000008_create_product_categories_table',1);
INSERT INTO migrations VALUES(12,'2026_05_26_000009_create_customers_table',1);
INSERT INTO migrations VALUES(13,'2026_05_26_000010_create_invoice_types_table',1);
INSERT INTO migrations VALUES(14,'2026_05_26_000011_create_products_table',1);
INSERT INTO migrations VALUES(15,'2026_05_26_000012_create_product_price_tiers_table',1);
INSERT INTO migrations VALUES(16,'2026_05_26_000013_create_invoices_table',1);
INSERT INTO migrations VALUES(17,'2026_05_26_000014_create_invoice_lines_table',1);
INSERT INTO migrations VALUES(18,'2026_05_26_000015_create_return_invoice_extras_table',1);
INSERT INTO migrations VALUES(19,'2026_05_26_000016_create_payments_table',1);
INSERT INTO migrations VALUES(20,'2026_05_26_000017_create_mydata_marks_table',1);
INSERT INTO migrations VALUES(21,'2026_05_26_000018_create_conf_params_table',1);
INSERT INTO migrations VALUES(22,'2026_05_26_000019_create_whmcs_invoice_log_table',1);
INSERT INTO migrations VALUES(23,'2026_05_26_080823_create_activity_log_table',1);
INSERT INTO migrations VALUES(24,'2026_05_26_080823_create_permission_tables',1);
INSERT INTO migrations VALUES(25,'2026_05_26_090000_add_country_profile_to_companies',1);
INSERT INTO migrations VALUES(26,'2026_05_27_000001_rename_header_discount_to_percent_on_invoices',1);
INSERT INTO migrations VALUES(27,'2026_05_27_000002_change_mark_time_to_time_on_mydata_marks',1);
INSERT INTO migrations VALUES(28,'2026_05_27_000003_add_lifecycle_columns_to_customers',1);
INSERT INTO migrations VALUES(29,'2026_05_27_000004_change_product_category_markup_to_percent',1);
INSERT INTO migrations VALUES(30,'2026_05_27_000005_add_forward_looking_columns_to_products',1);
INSERT INTO migrations VALUES(31,'2026_05_27_000006_add_unique_qty_on_product_price_tiers',1);
INSERT INTO migrations VALUES(32,'2026_05_27_000007_add_gsis_registry_credentials_and_kad',1);
INSERT INTO migrations VALUES(33,'2026_05_27_000008_replace_mydata_production_with_mode',1);
INSERT INTO migrations VALUES(34,'2026_05_27_000009_make_mydata_marks_mark_nullable',1);
INSERT INTO migrations VALUES(35,'2026_05_27_000010_add_mydata_type_snapshot_to_invoices',1);
INSERT INTO migrations VALUES(36,'2026_05_27_000011_add_branding_and_mail_columns_to_companies',1);
INSERT INTO migrations VALUES(37,'2026_05_27_000012_create_invoice_mail_log_table',1);
INSERT INTO migrations VALUES(38,'2026_05_27_000013_add_whmcs_api_credentials_to_companies',1);
INSERT INTO migrations VALUES(39,'2026_05_27_000014_add_company_legacy_unique_to_invoices',1);
INSERT INTO migrations VALUES(40,'2026_05_27_000020_create_firebird_import_runs_table',1);
INSERT INTO migrations VALUES(41,'2026_05_28_000001_create_pending_whmcs_invoices_table',1);
INSERT INTO migrations VALUES(42,'2026_05_28_000002_add_whmcs_webhook_secret_to_companies',1);
INSERT INTO migrations VALUES(43,'2026_05_28_000003_add_whmcs_invoice_min_date_to_companies',1);
INSERT INTO migrations VALUES(44,'2026_05_28_000010_add_invoice_id_to_pending_whmcs_invoices',1);
INSERT INTO migrations VALUES(45,'2026_05_28_000011_add_writeback_state_to_pending_whmcs_invoices',1);
INSERT INTO migrations VALUES(46,'2026_05_28_000020_add_money_columns_to_payments',1);
INSERT INTO migrations VALUES(47,'2026_05_28_000021_add_money_cache_columns_to_invoices',1);
INSERT INTO migrations VALUES(48,'2026_05_28_000022_add_local_status_to_invoices',1);
INSERT INTO migrations VALUES(49,'2026_05_28_000023_add_third_party_invoicing_to_whmcs_bridge',1);
INSERT INTO migrations VALUES(50,'2026_05_28_000024_add_whmcs_pending_id_to_invoices',1);
INSERT INTO migrations VALUES(51,'2026_05_28_000025_add_mydata_withholding_and_exemption',1);
INSERT INTO migrations VALUES(52,'2026_05_28_000026_add_whmcs_and_mydata_filing_correctness',1);
INSERT INTO migrations VALUES(53,'2026_05_28_000027_add_auto_email_controls',1);
INSERT INTO migrations VALUES(54,'2026_05_28_000028_add_whmcs_auto_issue_to_companies',1);
INSERT INTO migrations VALUES(55,'2026_05_29_000001_split_mydata_credentials_by_environment',1);
INSERT INTO migrations VALUES(56,'2026_05_30_000001_create_suppliers_table',1);
INSERT INTO migrations VALUES(57,'2026_05_30_000002_create_expenses_table',1);
INSERT INTO migrations VALUES(58,'2026_05_30_000003_create_expense_lines_table',1);
INSERT INTO migrations VALUES(59,'2026_05_30_000004_create_expense_marks_table',1);
INSERT INTO migrations VALUES(60,'2026_05_30_000005_add_classification_to_expenses_table',1);
INSERT INTO migrations VALUES(61,'2026_05_30_000006_add_hold_reason_to_pending_whmcs_invoices',1);
INSERT INTO migrations VALUES(62,'2026_05_31_000001_add_self_declared_to_expenses_table',1);
INSERT INTO migrations VALUES(63,'2026_05_31_000002_create_quotes_table',1);
INSERT INTO migrations VALUES(64,'2026_05_31_000003_create_quote_lines_table',1);
INSERT INTO migrations VALUES(65,'2026_05_31_000004_add_quote_counter_to_companies',1);
INSERT INTO migrations VALUES(66,'2026_05_31_000005_create_quote_mail_logs_table',1);
INSERT INTO migrations VALUES(67,'2026_05_31_201800_add_company_id_to_activity_log_table',1);
INSERT INTO migrations VALUES(68,'2026_06_01_000001_add_legacy_invoiced_to_pending_whmcs_invoices',1);
INSERT INTO migrations VALUES(69,'2026_06_01_000002_add_whmcs_invoice_id_to_invoices',1);
INSERT INTO migrations VALUES(70,'2026_06_01_000003_create_billing_connections_table',1);
INSERT INTO migrations VALUES(71,'2026_06_01_000004_add_source_to_pending_whmcs_invoices',1);
INSERT INTO migrations VALUES(72,'2026_06_01_000005_seed_whmcs_billing_connections',1);
INSERT INTO migrations VALUES(73,'2026_06_02_000001_add_is_favorite_to_pickers',1);
INSERT INTO migrations VALUES(74,'2026_06_02_000001_create_customer_contacts_table',1);
INSERT INTO migrations VALUES(75,'2026_06_02_000001_drop_legacy_mail_print_flags_from_invoices',1);
INSERT INTO migrations VALUES(76,'2026_06_02_000002_create_attachments_table',1);
INSERT INTO migrations VALUES(77,'2026_06_02_000002_create_tags_tables',1);
INSERT INTO migrations VALUES(78,'2026_06_02_000003_add_whmcs_fetch_via_bridge_to_companies',1);
INSERT INTO migrations VALUES(79,'2026_06_02_000003_create_notes_table',1);
INSERT INTO migrations VALUES(80,'2026_06_02_000004_add_source_to_firebird_import_runs',1);
INSERT INTO migrations VALUES(81,'2026_06_03_000001_add_source_to_notes_table',1);
INSERT INTO migrations VALUES(82,'2026_06_03_000001_create_delivery_notes_table',1);
INSERT INTO migrations VALUES(83,'2026_06_03_000002_create_delivery_note_lines_table',1);
INSERT INTO migrations VALUES(84,'2026_06_03_000002_migrate_customer_details_to_notes',1);
INSERT INTO migrations VALUES(85,'2026_06_03_000003_create_delivery_marks_table',1);
INSERT INTO migrations VALUES(86,'2026_06_03_000003_drop_details_from_customers_table',1);
INSERT INTO migrations VALUES(87,'2026_06_03_010000_add_app_authentication_to_users_table',1);
INSERT INTO migrations VALUES(88,'2026_06_03_020000_add_track_stock_to_products',1);
INSERT INTO migrations VALUES(89,'2026_06_03_020001_create_stock_movements_table',1);
INSERT INTO migrations VALUES(90,'2026_06_03_020002_add_invoice_id_to_delivery_notes',1);
INSERT INTO migrations VALUES(91,'2026_06_03_020003_add_reorder_level_to_products',1);
INSERT INTO migrations VALUES(92,'2026_06_03_030000_add_reference_to_payments',1);
INSERT INTO migrations VALUES(93,'2026_06_03_040000_add_transaction_id_to_payments',1);
INSERT INTO migrations VALUES(94,'2026_06_03_050000_create_bank_accounts_table',1);
INSERT INTO migrations VALUES(95,'2026_06_03_050001_add_bank_account_id_to_payments_and_invoices',1);
INSERT INTO migrations VALUES(96,'2026_06_03_060000_add_kind_to_payments',1);
INSERT INTO migrations VALUES(97,'2026_06_03_162018_create_notifications_table',1);
INSERT INTO migrations VALUES(98,'2026_06_04_000001_add_recurring_to_products',1);
INSERT INTO migrations VALUES(99,'2026_06_04_000002_create_product_billing_prices_table',1);
INSERT INTO migrations VALUES(100,'2026_06_04_000003_create_server_groups_table',1);
INSERT INTO migrations VALUES(101,'2026_06_04_000004_create_servers_table',1);
INSERT INTO migrations VALUES(102,'2026_06_04_000005_create_service_contracts_table',1);
INSERT INTO migrations VALUES(103,'2026_06_04_000006_add_service_contract_id_to_invoices',1);
INSERT INTO migrations VALUES(104,'2026_06_05_000001_add_dunning_toggle',1);
INSERT INTO migrations VALUES(105,'2026_06_05_000003_add_einvoice_provider_config_to_companies',1);
INSERT INTO migrations VALUES(106,'2026_06_05_000004_add_provider_columns_to_mydata_marks',1);
INSERT INTO migrations VALUES(107,'2026_06_05_000005_add_mydata_send_item_descr_to_companies',1);
INSERT INTO migrations VALUES(108,'2026_06_05_000010_add_cancellation_mark_to_mydata_marks',1);
INSERT INTO migrations VALUES(109,'2026_06_06_000001_add_converted_service_contract_id_to_quotes',1);
INSERT INTO migrations VALUES(110,'2026_06_06_000001_add_provider_columns_to_delivery_marks',1);
INSERT INTO migrations VALUES(111,'2026_06_09_000001_create_delivery_note_events_table',1);
INSERT INTO migrations VALUES(112,'2026_06_09_000002_change_mark_time_to_time_on_delivery_marks',1);
INSERT INTO migrations VALUES(113,'2026_06_09_000003_create_company_backup_settings_table',1);
INSERT INTO migrations VALUES(114,'2026_06_09_000004_create_company_backup_runs_table',1);
INSERT INTO migrations VALUES(115,'2026_06_09_000005_add_mydata_vat_category_to_vat_categories',1);
INSERT INTO migrations VALUES(116,'2026_06_09_000006_add_additional_taxes_to_invoices',1);
INSERT INTO migrations VALUES(117,'2026_06_10_000001_add_product_linked_taxes',1);
INSERT INTO migrations VALUES(118,'2026_06_10_000002_create_scheduled_task_runs_table',1);
INSERT INTO migrations VALUES(119,'2026_06_10_000003_create_system_settings_table',1);
INSERT INTO migrations VALUES(120,'2026_06_10_000004_add_document_path_to_expenses_table',1);
INSERT INTO migrations VALUES(121,'2026_06_10_000005_add_pdf_language_to_invoices_and_quotes',1);
INSERT INTO migrations VALUES(122,'2026_06_10_000006_add_payable_total_to_invoices',1);
INSERT INTO migrations VALUES(123,'2026_06_11_000001_add_customer_balance_snapshot_to_invoices',1);
INSERT INTO migrations VALUES(124,'2026_06_11_000001_add_whmcs_default_receipt_type_to_companies',1);
INSERT INTO migrations VALUES(125,'2026_06_11_000002_add_show_balance_on_pdf_toggles',1);
INSERT INTO migrations VALUES(126,'2026_06_17_000001_create_ai_assistant_foundation',1);
INSERT INTO migrations VALUES(127,'2026_06_17_000001_create_expense_classification_rules_table',1);
INSERT INTO migrations VALUES(128,'2026_06_17_000002_create_ai_pending_actions_table',1);
INSERT INTO migrations VALUES(129,'2026_06_18_000001_add_english_identity_to_companies',1);
INSERT INTO migrations VALUES(130,'2026_06_18_000002_create_cmr_notes_table',1);
INSERT INTO migrations VALUES(131,'2026_06_18_000003_create_cmr_lines_table',1);
INSERT INTO migrations VALUES(132,'2026_06_19_000001_add_show_on_invoices_to_bank_accounts',1);
INSERT INTO migrations VALUES(133,'2026_06_25_000001_widen_hold_reason_on_pending_whmcs_invoices',1);
INSERT INTO migrations VALUES(134,'2026_07_05_000001_add_original_line_id_to_invoice_lines',1);
INSERT INTO migrations VALUES(135,'2026_07_05_000002_add_gemi_to_companies',1);
INSERT INTO migrations VALUES(136,'2026_07_05_000003_default_company_backup_bucket_to_full',1);
INSERT INTO migrations VALUES(137,'2026_07_06_000001_add_income_class_to_product_categories',1);
INSERT INTO migrations VALUES(138,'2026_07_07_000001_add_mydata_pending_since_to_invoices',1);
INSERT INTO migrations VALUES(139,'2026_07_10_000001_add_batch_to_invoice_mail_log_trigger',1);
INSERT INTO migrations VALUES(140,'2026_07_11_000001_add_send_key_to_invoice_mail_log',1);
INSERT INTO migrations VALUES(141,'2026_07_12_000001_add_mydata_auto_fetch_expenses_to_companies',1);
INSERT INTO migrations VALUES(142,'2026_07_13_000001_add_whmcs_default_unpaid_type_to_companies',1);
INSERT INTO migrations VALUES(143,'2026_07_14_000001_add_whmcs_outbound_payment_push_columns',1);
INSERT INTO migrations VALUES(144,'2026_07_14_000002_add_fb_database_to_firebird_import_runs',1);
INSERT INTO migrations VALUES(145,'2026_08_26_000001_create_update_runs_table',1);
INSERT INTO migrations VALUES(146,'2026_08_26_000002_add_rollback_to_update_runs',1);
INSERT INTO migrations VALUES(147,'2026_08_26_044846_create_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(148,'2026_08_26_050423_create_oauth_auth_codes_table',1);
INSERT INTO migrations VALUES(149,'2026_08_26_050424_create_oauth_access_tokens_table',1);
INSERT INTO migrations VALUES(150,'2026_08_26_050425_create_oauth_refresh_tokens_table',1);
INSERT INTO migrations VALUES(151,'2026_08_26_050426_create_oauth_clients_table',1);
INSERT INTO migrations VALUES(152,'2026_08_26_050427_create_oauth_device_codes_table',1);
INSERT INTO migrations VALUES(153,'2026_09_01_000001_add_recipient_country_to_delivery_notes',1);
INSERT INTO migrations VALUES(154,'2026_09_01_000001_create_leads_table',1);
INSERT INTO migrations VALUES(155,'2026_09_01_000002_create_lead_activities_table',1);
INSERT INTO migrations VALUES(156,'2026_09_02_000001_add_lead_id_to_quotes_table',1);
INSERT INTO migrations VALUES(157,'2026_09_02_000001_widen_invoice_party_snapshot_columns',1);
INSERT INTO migrations VALUES(158,'2026_09_02_000002_add_series_to_numbered_documents',1);
INSERT INTO migrations VALUES(159,'2026_09_02_000002_recompute_leads_last_activity_at',1);
INSERT INTO migrations VALUES(160,'2026_09_02_000003_add_mydata_pending_since_to_delivery_notes',1);
INSERT INTO migrations VALUES(161,'2026_09_02_000004_add_cancellation_mark_to_delivery_marks',1);
INSERT INTO migrations VALUES(162,'2026_09_02_000005_backfill_inverted_provider_cancellation_marks',1);
INSERT INTO migrations VALUES(163,'2026_09_02_000010_add_uid_to_mydata_marks',1);
INSERT INTO migrations VALUES(164,'2026_09_02_000020_add_vat_exemption_category_to_invoice_lines',1);
INSERT INTO migrations VALUES(165,'2026_09_02_000021_backfill_zero_vat_line_exemption',1);
INSERT INTO migrations VALUES(166,'2026_09_03_000001_add_afm_key_unique_to_customers',1);
INSERT INTO migrations VALUES(167,'2026_09_03_000001_add_provider_identity_to_mydata_marks',1);
INSERT INTO migrations VALUES(168,'2026_09_03_000010_add_business_activity_type_to_companies',1);
INSERT INTO migrations VALUES(169,'2026_09_03_000020_add_provider_operational_evidence_to_mydata_marks',1);
INSERT INTO migrations VALUES(170,'2026_09_03_000020_create_whmcs_income_maps_table',1);
INSERT INTO migrations VALUES(171,'2026_09_03_000021_add_income_class_snapshot_to_invoice_lines',1);
INSERT INTO migrations VALUES(172,'2026_09_03_000030_add_mydata_read_env_to_companies',1);
INSERT INTO migrations VALUES(173,'2026_09_03_000030_add_reissued_from_invoice_id_to_invoices',1);
INSERT INTO migrations VALUES(174,'2026_09_03_000030_create_whmcs_payment_maps_table',1);
INSERT INTO migrations VALUES(175,'2026_09_04_000001_allow_provisional_document_numbering',1);
INSERT INTO migrations VALUES(176,'2026_09_04_100000_create_customer_users_table',1);
INSERT INTO migrations VALUES(177,'2026_09_04_110000_create_customer_user_access_table',1);
INSERT INTO migrations VALUES(178,'2026_09_04_120000_create_customer_users_password_reset_tokens_table',1);
INSERT INTO migrations VALUES(179,'2026_09_05_000001_allow_provisional_delivery_note_numbering',1);
INSERT INTO migrations VALUES(180,'2026_09_05_000001_create_payment_gateway_connections_table',1);
INSERT INTO migrations VALUES(181,'2026_09_05_000002_create_payment_intents_table',1);
INSERT INTO migrations VALUES(182,'2026_09_05_000003_add_connection_to_payment_intents_table',1);
INSERT INTO migrations VALUES(183,'2026_09_06_000001_add_needs_invoice_before_payment_to_customers',1);
INSERT INTO migrations VALUES(184,'2026_09_07_000001_add_einvoice_include_customer_email_to_companies',1);
INSERT INTO migrations VALUES(185,'2026_09_08_000001_add_country_code_to_customers_and_suppliers',1);
INSERT INTO migrations VALUES(186,'2026_09_08_000002_add_counterpart_branch_to_invoices',1);
INSERT INTO migrations VALUES(187,'2026_09_09_000001_add_invoice_id_to_payment_intents_table',1);
INSERT INTO migrations VALUES(188,'2026_09_09_000002_add_payment_intent_id_to_payments_table',1);
INSERT INTO migrations VALUES(189,'2026_09_09_000003_create_payment_gateway_events_table',1);
INSERT INTO migrations VALUES(190,'2026_09_09_000004_add_payment_method_id_to_payment_gateway_connections',1);
INSERT INTO migrations VALUES(191,'2026_09_10_000001_add_support_enabled_to_companies',1);
INSERT INTO migrations VALUES(192,'2026_09_10_000002_create_ticket_departments_tables',1);
INSERT INTO migrations VALUES(193,'2026_09_10_000003_create_tickets_tables',1);
INSERT INTO migrations VALUES(194,'2026_09_10_000004_create_canned_replies_tables',1);
INSERT INTO migrations VALUES(195,'2026_09_10_000005_create_ticket_poll_runs_table',1);
INSERT INTO migrations VALUES(196,'2026_09_11_000001_create_ticket_watchers_table',1);
INSERT INTO migrations VALUES(197,'2026_09_12_000001_add_rating_to_tickets_table',1);
INSERT INTO migrations VALUES(198,'2026_09_13_000001_create_ticket_blocked_senders_table',1);
INSERT INTO migrations VALUES(199,'2026_09_14_000001_add_merged_into_id_to_tickets_table',1);
INSERT INTO migrations VALUES(200,'2026_09_15_000001_add_enable_domain_management_to_companies',1);
INSERT INTO migrations VALUES(201,'2026_09_15_000002_create_domain_registrar_connections_table',1);
INSERT INTO migrations VALUES(202,'2026_09_15_000003_create_domain_tlds_table',1);
INSERT INTO migrations VALUES(203,'2026_09_15_000004_create_domain_tld_prices_table',1);
INSERT INTO migrations VALUES(204,'2026_09_15_000005_create_domains_table',1);
INSERT INTO migrations VALUES(205,'2026_09_15_000006_create_domain_nameservers_table',1);
INSERT INTO migrations VALUES(206,'2026_09_15_000007_create_domain_contacts_table',1);
INSERT INTO migrations VALUES(207,'2026_09_16_000001_create_domain_registrar_logs_table',1);
INSERT INTO migrations VALUES(208,'2026_09_17_000001_add_afm_key_parked_to_customers',1);
INSERT INTO migrations VALUES(209,'2026_09_17_000002_add_afm_keep_to_firebird_import_runs',1);
INSERT INTO migrations VALUES(210,'2026_09_18_000001_normalise_einvoice_provider_key',1);
INSERT INTO migrations VALUES(211,'2026_09_19_000001_create_auth_events_table',1);
INSERT INTO migrations VALUES(212,'2026_09_19_000002_add_last_login_to_users_table',1);
INSERT INTO migrations VALUES(213,'2026_09_20_000001_add_return_mark_to_delivery_notes',1);
INSERT INTO migrations VALUES(214,'2026_09_21_000001_add_movement_columns_to_invoices',1);
INSERT INTO migrations VALUES(215,'2026_09_21_000002_add_is_delivery_note_to_invoice_types',1);
INSERT INTO migrations VALUES(216,'2026_09_21_000003_make_delivery_audit_polymorphic',1);
INSERT INTO migrations VALUES(217,'2026_09_21_000004_add_without_digital_transport_tracking_to_invoices',1);
INSERT INTO migrations VALUES(218,'2026_09_21_000005_complete_delivery_events_morph',1);
INSERT INTO migrations VALUES(219,'2026_09_21_000006_normalise_tda_invoice_type_flag',1);
INSERT INTO migrations VALUES(220,'2026_09_21_000007_create_inbound_delivery_notes_table',1);
