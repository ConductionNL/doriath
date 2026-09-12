<?php

/**
 * Keepiq consolidated schema, replacing the 35 incremental migrations.
 *
 * @category Migration
 * @package  OCA\Keepiq\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Migration;

use Closure;
use InvalidArgumentException;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Consolidated schema for Keepiq.
 *
 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @psalm-type ColumnSpec = array{0: string, 1: string, 2: array<string, mixed>}
 * @psalm-type IndexSpec = array{0: string, 1: list<string>}
 * @psalm-type TableSpec = array{columns: list<ColumnSpec>, primary: list<string>,
 *     indexes: list<IndexSpec>, uniqueIndexes: list<IndexSpec>}
 */
class Version001000Date20260908000000 extends SimpleMigrationStep {
	private const OLD_PREFIX = 'doriath_';

	private const NEW_PREFIX = 'keepiq_';

	/**
	 * Table suffixes shared by the old and new prefixes.
	 *
	 * @var string[]
	 */
	private const TABLES = [
		'app_lease_policies',
		'applications',
		'attachment_grants',
		'attachments',
		'audit_log',
		'ca_certs',
		'certificate_metadata',
		'compliance_reports',
		'dashboard_settings',
		'emergency_contacts',
		'enc_suites',
		'ephemeral_sends',
		'expiry_policies',
		'folders',
		'group_shares',
		'honey_alerts',
		'honey_flags',
		'link_shares',
		'machine_leases',
		'migration_failures',
		'passkey_credentials',
		'rotation_flags',
		'secret_delegations',
		'secret_requests',
		'secret_types',
		'secret_versions',
		'secrets',
		'share_targets',
		'siem_queue',
		'siem_sinks',
		'suite_migr',
		'team_folder_members',
		'team_folders',
	];

	/**
	 * Full target schema, keyed by unprefixed table suffix.
	 *
	 * @var array<string, TableSpec>
	 */
	private const SCHEMA = [
		'app_lease_policies' => [
			'columns' => [
				['application_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['default_ttl_seconds', Types::INTEGER, ['notnull' => false]],
				['max_ttl_seconds', Types::INTEGER, ['notnull' => false]],
				['renewable', Types::BOOLEAN, ['notnull' => false]],
			],
			'primary' => ['application_id'],
			'indexes' => [],
			'uniqueIndexes' => [],
		],
		'applications' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['name', Types::STRING, ['notnull' => true, 'length' => 128]],
				['description', Types::TEXT, ['notnull' => false]],
				['type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'external']],
				['status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']],
				['csr', Types::TEXT, ['notnull' => false]],
				['registered_by', Types::STRING, ['notnull' => false, 'length' => 64]],
				['approved_by', Types::STRING, ['notnull' => false, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['approved_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_app_status_idx', ['status']],
				['keepiq_app_registrant_idx', ['registered_by']],
				['keepiq_app_name_idx', ['name']],
			],
			'uniqueIndexes' => [],
		],
		'attachment_grants' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['attachment_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['recipient_type', Types::STRING, ['notnull' => true, 'length' => 16]],
				['recipient_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['wrapped_file_key', Types::TEXT, ['notnull' => true]],
				['encryption_suite_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['created_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_attg_att_idx', ['attachment_id']],
				['keepiq_attg_secret_idx', ['secret_id']],
				['keepiq_attg_recipient_idx', ['recipient_id']],
			],
			'uniqueIndexes' => [
				['keepiq_attg_copy_uniq', ['attachment_id', 'secret_id']],
			],
		],
		'attachments' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['source_secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['blob_ref', Types::STRING, ['notnull' => true, 'length' => 128]],
				['encrypted_metadata', Types::TEXT, ['notnull' => true]],
				['size_bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]],
				['created_at', Types::DATETIME, ['notnull' => false]],
				['updated_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_att_secret_idx', ['source_secret_id']],
			],
			'uniqueIndexes' => [],
		],
		'audit_log' => [
			'columns' => [
				['id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]],
				['occurred_at', Types::DATETIME, ['notnull' => true]],
				['actor_type', Types::STRING, ['notnull' => true, 'length' => 16]],
				['actor_id', Types::STRING, ['notnull' => false, 'length' => 64]],
				['event_type', Types::STRING, ['notnull' => true, 'length' => 64]],
				['object_type', Types::STRING, ['notnull' => true, 'length' => 32]],
				['object_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['object_name', Types::STRING, ['notnull' => false, 'length' => 255]],
				['metadata', Types::TEXT, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_al_occurred_idx', ['occurred_at']],
				['keepiq_al_actor_idx', ['actor_id']],
				['keepiq_al_object_idx', ['object_type', 'object_id']],
				['keepiq_al_event_idx', ['event_type']],
			],
			'uniqueIndexes' => [],
		],
		'ca_certs' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['type', Types::STRING, ['notnull' => true, 'length' => 20]],
				['certificate', Types::TEXT, ['notnull' => true]],
				['private_key', Types::TEXT, ['notnull' => false]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['expires_at', Types::DATETIME, ['notnull' => true]],
				['is_active', Types::BOOLEAN, ['notnull' => false, 'default' => false]],
				['revoked_at', Types::DATETIME, ['notnull' => false]],
				['successor_id', Types::STRING, ['notnull' => false, 'length' => 36]],
			],
			'primary' => ['id'],
			'indexes' => [],
			'uniqueIndexes' => [],
		],
		'certificate_metadata' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['subject', Types::TEXT, ['notnull' => false]],
				['issuer', Types::TEXT, ['notnull' => false]],
				['serial', Types::STRING, ['notnull' => false, 'length' => 128]],
				['fingerprint_sha256', Types::STRING, ['notnull' => false, 'length' => 128]],
				['not_before', Types::DATETIME, ['notnull' => false]],
				['not_after', Types::DATETIME, ['notnull' => false]],
				['parsed_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_cm_owner', ['owner_id']],
			],
			'uniqueIndexes' => [
				['keepiq_cm_secret', ['secret_id']],
			],
		],
		'compliance_reports' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['generated_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['generated_at', Types::DATETIME, ['notnull' => true]],
				['app_version', Types::STRING, ['notnull' => true, 'length' => 32]],
				['config_snapshot', Types::TEXT, ['notnull' => true]],
				['aggregate', Types::TEXT, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_cr_generated', ['generated_at']],
			],
			'uniqueIndexes' => [],
		],
		'dashboard_settings' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['user_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['setting_key', Types::STRING, ['notnull' => true, 'length' => 64]],
				['setting_value', Types::TEXT, ['notnull' => true]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['updated_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_ds_user_idx', ['user_id']],
			],
			'uniqueIndexes' => [
				['keepiq_ds_user_key_uniq', ['user_id', 'setting_key']],
			],
		],
		'emergency_contacts' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['grantor_user_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['grantee_user_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['access_level', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'view']],
				['wait_period_days', Types::INTEGER, ['notnull' => true, 'default' => 7]],
				['state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'granted']],
				['requested_at', Types::DATETIME, ['notnull' => false]],
				['recovery_envelope', Types::TEXT, ['notnull' => false]],
				['grantor_suite_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['grantee_suite_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['invalidated_reason', Types::STRING, ['notnull' => false, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => false]],
				['updated_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_emc_grantor_idx', ['grantor_user_id']],
				['keepiq_emc_grantee_idx', ['grantee_user_id']],
				['keepiq_emc_state_idx', ['state']],
			],
			'uniqueIndexes' => [
				['keepiq_emc_pair_uniq', ['grantor_user_id', 'grantee_user_id']],
			],
		],
		'enc_suites' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_type', Types::STRING, ['notnull' => true, 'length' => 20]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['certificate', Types::TEXT, ['notnull' => false]],
				['private_key', Types::TEXT, ['notnull' => false]],
				['status', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'active']],
				['revoked_at', Types::DATETIME, ['notnull' => false]],
				['revoked_reason', Types::STRING, ['notnull' => false, 'length' => 255]],
				['revoked_by', Types::STRING, ['notnull' => false, 'length' => 64]],
				['reinstated_at', Types::DATETIME, ['notnull' => false]],
				['reinstated_by', Types::STRING, ['notnull' => false, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['unlock_key_epoch', Types::INTEGER, ['notnull' => true, 'default' => 1]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_es_owner_idx', ['owner_type', 'owner_id']],
			],
			'uniqueIndexes' => [],
		],
		'ephemeral_sends' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['token', Types::STRING, ['notnull' => true, 'length' => 64]],
				['encrypted_payload', Types::TEXT, ['notnull' => true]],
				['payload_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'text']],
				['has_password', Types::BOOLEAN, ['notnull' => false, 'default' => false]],
				['wrapped_key', Types::TEXT, ['notnull' => false]],
				['argon2id_salt', Types::STRING, ['notnull' => false, 'length' => 64]],
				['max_views', Types::INTEGER, ['notnull' => true, 'default' => 1]],
				['view_count', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['expires_at', Types::DATETIME, ['notnull' => false]],
				['failed_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['created_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_es_owner', ['owner_id']],
				['keepiq_es_expires', ['expires_at']],
			],
			'uniqueIndexes' => [
				['keepiq_es_token', ['token']],
			],
		],
		'expiry_policies' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_id', Types::STRING, ['notnull' => false, 'length' => 64]],
				['scope', Types::STRING, ['notnull' => true, 'length' => 16]],
				['scope_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['max_age_days', Types::INTEGER, ['notnull' => false]],
				['reminder_days', Types::TEXT, ['notnull' => false]],
				['created_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => false]],
				['updated_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_ep_owner_idx', ['owner_id']],
			],
			'uniqueIndexes' => [
				['keepiq_ep_scope_uniq', ['owner_id', 'scope', 'scope_id']],
			],
		],
		'folders' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['name', Types::STRING, ['notnull' => true, 'length' => 255]],
				['parent_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['owner_type', Types::STRING, ['notnull' => true, 'length' => 16]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['updated_at', Types::DATETIME, ['notnull' => true]],
				['custom_icon', Types::STRING, ['notnull' => false, 'length' => 64]],
				['custom_color', Types::STRING, ['notnull' => false, 'length' => 64]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_fld_owner_idx', ['owner_type', 'owner_id', 'parent_id']],
				['keepiq_fld_parent_idx', ['parent_id']],
			],
			'uniqueIndexes' => [],
		],
		'group_shares' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['group_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_gs_secret_group_idx', ['secret_id', 'group_id']],
				['keepiq_gs_group_idx', ['group_id']],
			],
			'uniqueIndexes' => [],
		],
		'honey_alerts' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['honey_flag_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['accessor_type', Types::STRING, ['notnull' => true, 'length' => 32]],
				['accessor_id', Types::STRING, ['notnull' => false, 'length' => 128]],
				['channel', Types::STRING, ['notnull' => true, 'length' => 32]],
				['ip', Types::STRING, ['notnull' => false, 'length' => 64]],
				['user_agent', Types::STRING, ['notnull' => false, 'length' => 512]],
				['access_count', Types::INTEGER, ['notnull' => true, 'default' => 1]],
				['accessed_at', Types::DATETIME, ['notnull' => true]],
				['acknowledged_at', Types::DATETIME, ['notnull' => false]],
				['acknowledged_by', Types::STRING, ['notnull' => false, 'length' => 64]],
				['snoozed_until', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_ha_flag', ['honey_flag_id']],
				['keepiq_ha_secret', ['secret_id']],
				['keepiq_ha_ack', ['acknowledged_at']],
			],
			'uniqueIndexes' => [],
		],
		'honey_flags' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['note', Types::TEXT, ['notnull' => false]],
				['created_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_hf_owner', ['owner_id']],
			],
			'uniqueIndexes' => [
				['keepiq_hf_secret', ['secret_id']],
			],
		],
		'link_shares' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['token', Types::STRING, ['notnull' => true, 'length' => 64]],
				['encrypted_secret_snapshot', Types::TEXT, ['notnull' => true]],
				['argon2id_salt', Types::STRING, ['notnull' => true, 'length' => 64]],
				['encryption_suite_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['usage_limit', Types::INTEGER, ['notnull' => true, 'default' => 1]],
				['usage_count', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['failed_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['created_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['expires_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_ls_secret_idx', ['secret_id']],
				['keepiq_ls_creator_idx', ['created_by']],
			],
			'uniqueIndexes' => [
				['keepiq_ls_token_idx', ['token']],
			],
		],
		'machine_leases' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['application_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['scope', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'read']],
				['granted_at', Types::DATETIME, ['notnull' => true]],
				['expires_at', Types::DATETIME, ['notnull' => true]],
				['renewed_count', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['last_renewed_at', Types::DATETIME, ['notnull' => false]],
				['status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']],
				['revoked_at', Types::DATETIME, ['notnull' => false]],
				['revoked_by', Types::STRING, ['notnull' => false, 'length' => 64]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_ml_app_status', ['application_id', 'status']],
				['keepiq_ml_secret', ['secret_id']],
				['keepiq_ml_expires', ['expires_at']],
			],
			'uniqueIndexes' => [],
		],
		'migration_failures' => [
			'columns' => [
				['id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true]],
				['migration_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['store', Types::STRING, ['notnull' => true, 'length' => 32]],
				['record_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['message', Types::STRING, ['notnull' => false, 'length' => 1000]],
				['created_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_mf_migration', ['migration_id']],
				['keepiq_mf_secret', ['migration_id', 'secret_id']],
			],
			'uniqueIndexes' => [
				['keepiq_mf_record', ['migration_id', 'store', 'record_id']],
			],
		],
		'passkey_credentials' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['credential_id', Types::TEXT, ['notnull' => true]],
				['public_key', Types::TEXT, ['notnull' => false]],
				['prf_salt', Types::TEXT, ['notnull' => true]],
				['wrapped_unlock_key', Types::TEXT, ['notnull' => true]],
				['unlock_key_epoch', Types::INTEGER, ['notnull' => true, 'default' => 1]],
				['label', Types::STRING, ['notnull' => false, 'length' => 128]],
				['transports', Types::STRING, ['notnull' => false, 'length' => 128]],
				['aaguid', Types::STRING, ['notnull' => false, 'length' => 64]],
				['status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']],
				['last_used_at', Types::DATETIME, ['notnull' => false]],
				['created_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_pk_owner_status', ['owner_id', 'status']],
				['keepiq_pk_owner', ['owner_id']],
			],
			'uniqueIndexes' => [],
		],
		'rotation_flags' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['reason', Types::STRING, ['notnull' => true, 'length' => 32]],
				['status', Types::STRING, ['notnull' => true, 'length' => 16]],
				['flagged_at', Types::DATETIME, ['notnull' => false]],
				['flagged_by', Types::STRING, ['notnull' => false, 'length' => 64]],
				['resolved_at', Types::DATETIME, ['notnull' => false]],
				['key_updated_at_at_flag', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_rf_status_idx', ['status']],
			],
			'uniqueIndexes' => [
				['keepiq_rf_secret_uniq', ['secret_id']],
			],
		],
		'secret_delegations' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['original_owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['delegated_to', Types::STRING, ['notnull' => true, 'length' => 64]],
				['delegated_at', Types::DATETIME, ['notnull' => true]],
				['initiated_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['is_permanent', Types::BOOLEAN, ['notnull' => false, 'default' => false]],
				['made_permanent_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_sd_secret_idx', ['secret_id']],
				['keepiq_sd_orig_owner_idx', ['original_owner_id']],
				['keepiq_sd_delegate_idx', ['delegated_to']],
			],
			'uniqueIndexes' => [],
		],
		'secret_requests' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['encryption_suite_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['token', Types::STRING, ['notnull' => true, 'length' => 64]],
				['requested_fields', Types::TEXT, ['notnull' => true]],
				['status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']],
				['is_re_request', Types::BOOLEAN, ['notnull' => false, 'default' => false]],
				['expires_at', Types::DATETIME, ['notnull' => false]],
				['created_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['fulfilled_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_sr_secret_idx', ['secret_id']],
				['keepiq_sr_creator_idx', ['created_by']],
				['keepiq_sr_suite_idx', ['encryption_suite_id']],
			],
			'uniqueIndexes' => [
				['keepiq_sr_token_uniq', ['token']],
			],
		],
		'secret_types' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['name', Types::STRING, ['notnull' => true, 'length' => 64]],
				['label', Types::STRING, ['notnull' => true, 'length' => 128]],
				['scope', Types::STRING, ['notnull' => true, 'length' => 16]],
				['owner_id', Types::STRING, ['notnull' => false, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_st_scope_idx', ['scope', 'owner_id']],
			],
			'uniqueIndexes' => [
				['keepiq_st_name_idx', ['name']],
			],
		],
		'secret_versions' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['version_number', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['name', Types::STRING, ['notnull' => true, 'length' => 255]],
				['url', Types::STRING, ['notnull' => false, 'length' => 2048]],
				['key', Types::TEXT, ['notnull' => true, 'default' => '']],
				['login', Types::TEXT, ['notnull' => false]],
				['additional_fields', Types::TEXT, ['notnull' => false]],
				['encryption_suite_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['actor_type', Types::STRING, ['notnull' => true, 'length' => 16]],
				['actor_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_sv_secret_idx', ['secret_id']],
			],
			'uniqueIndexes' => [
				['keepiq_sv_version_uniq', ['secret_id', 'version_number']],
			],
		],
		'secrets' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['name', Types::STRING, ['notnull' => true, 'length' => 255]],
				['url', Types::STRING, ['notnull' => false, 'length' => 2048]],
				['type_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['folder_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['key', Types::TEXT, ['notnull' => true, 'default' => '']],
				['login', Types::TEXT, ['notnull' => false]],
				['additional_fields', Types::TEXT, ['notnull' => false]],
				['encryption_suite_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_type', Types::STRING, ['notnull' => true, 'length' => 16]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['possibly_compromised_at', Types::DATETIME, ['notnull' => false]],
				['migration_error', Types::TEXT, ['notnull' => false]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['updated_at', Types::DATETIME, ['notnull' => true]],
				['key_updated_at', Types::DATETIME, ['notnull' => false]],
				['tombstoned_at', Types::DATETIME, ['notnull' => false]],
				['tombstone_reason', Types::STRING, ['notnull' => false, 'length' => 64]],
				['expires_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_sec_owner_idx', ['owner_type', 'owner_id']],
				['keepiq_sec_folder_idx', ['folder_id']],
				['keepiq_sec_suite_idx', ['encryption_suite_id']],
				['keepiq_sec_type_idx', ['type_id']],
				['keepiq_sec_expires_idx', ['expires_at']],
			],
			'uniqueIndexes' => [],
		],
		'share_targets' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['source_secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['target_user_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['group_share_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['created_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['team_folder_id', Types::STRING, ['notnull' => false, 'length' => 36]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_st_source_idx', ['source_secret_id']],
				['keepiq_st_target_idx', ['target_user_id']],
				['keepiq_st_copy_idx', ['secret_id']],
				['keepiq_st_teamfolder_idx', ['team_folder_id']],
			],
			'uniqueIndexes' => [],
		],
		'siem_queue' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['sink_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['payload', Types::TEXT, ['notnull' => true]],
				['enqueued_at', Types::DATETIME, ['notnull' => true]],
				['status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']],
				['attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['next_attempt_at', Types::DATETIME, ['notnull' => false]],
				['last_error', Types::STRING, ['notnull' => false, 'length' => 512]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_sq_due', ['sink_id', 'status', 'next_attempt_at']],
			],
			'uniqueIndexes' => [],
		],
		'siem_sinks' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['name', Types::STRING, ['notnull' => true, 'length' => 128]],
				['type', Types::STRING, ['notnull' => true, 'length' => 16]],
				['enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]],
				['endpoint', Types::STRING, ['notnull' => true, 'length' => 512]],
				['tls', Types::BOOLEAN, ['notnull' => false, 'default' => true]],
				['hmac_secret_enc', Types::TEXT, ['notnull' => false]],
				['category_filter', Types::TEXT, ['notnull' => false]],
				['queue_cap', Types::INTEGER, ['notnull' => true, 'default' => 1000]],
				['last_delivery_status', Types::STRING, ['notnull' => false, 'length' => 16]],
				['last_success_at', Types::DATETIME, ['notnull' => false]],
				['last_attempt_at', Types::DATETIME, ['notnull' => false]],
				['last_error', Types::STRING, ['notnull' => false, 'length' => 512]],
				['consecutive_failures', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['dropped_count', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['created_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['updated_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_ss_enabled', ['enabled']],
			],
			'uniqueIndexes' => [],
		],
		'suite_migr' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['old_suite_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['new_suite_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['status', Types::STRING, ['notnull' => true, 'length' => 30, 'default' => 'in_progress']],
				['started_at', Types::DATETIME, ['notnull' => true]],
				['completed_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_sm_old_suite_idx', ['old_suite_id']],
				['keepiq_sm_new_suite_idx', ['new_suite_id']],
			],
			'uniqueIndexes' => [],
		],
		'team_folder_members' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['team_folder_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['member_type', Types::STRING, ['notnull' => true, 'length' => 8]],
				['member_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['added_by', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => false]],
				['grade', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'read']],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_tfm_folder_idx', ['team_folder_id']],
				['keepiq_tfm_member_idx', ['member_id']],
			],
			'uniqueIndexes' => [
				['keepiq_tfm_membership_uniq', ['team_folder_id', 'member_type', 'member_id']],
			],
		],
		'team_folders' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['folder_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['created_at', Types::DATETIME, ['notnull' => false]],
				['updated_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_tf_owner_idx', ['owner_id']],
			],
			'uniqueIndexes' => [
				['keepiq_tf_folder_uniq', ['folder_id']],
			],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection Database connection used for the rename.
	 * @param IConfig       $config     System config, read for the table prefix.
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Rename the legacy tables before the schema comparison runs.
	 *
	 * The app id moved from doriath to keepiq, but the physical tables kept the
	 * old prefix. This renames them in place so the data survives; the schema
	 * declared above then matches what a fresh install creates.
	 *
	 * Indexes are deliberately NOT renamed here. The schema comparison that
	 * follows drops any index the target schema does not declare and creates
	 * the ones it does, which reconciles them on every platform. PostgreSQL
	 * sequences and primary-key constraints are not covered by that comparison,
	 * so they are renamed explicitly.
	 *
	 * @param IOutput                   $output        The output interface
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure
	 * @param array<string,mixed>       $options       Migration options
	 *
	 * @return void
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		$renamed = 0;

		foreach (self::TABLES as $suffix) {
			$old = self::OLD_PREFIX.$suffix;
			$new = self::NEW_PREFIX.$suffix;

			if ($schema->hasTable($old) === false || $schema->hasTable($new) === true) {
				continue;
			}

			$this->renameTable(oldName: $old, newName: $new);
			$renamed++;
		}

		if ($renamed > 0) {
			$output->info(sprintf('Renamed %d legacy %s* tables to %s*', $renamed, self::OLD_PREFIX, self::NEW_PREFIX));
		}
	}//end preSchemaChange()

	/**
	 * Rename one table, and on PostgreSQL its sequences and primary key too.
	 *
	 * @param string $oldName Unprefixed current table name
	 * @param string $newName Unprefixed target table name
	 *
	 * @return void
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	private function renameTable(string $oldName, string $newName): void {
		// Identifiers cannot be bound as parameters. Both names come from the
		// private constants above, and this guard keeps that guarantee local.
		if (preg_match('/^[a-z0-9_]+$/', $oldName) !== 1 || preg_match('/^[a-z0-9_]+$/', $newName) !== 1) {
			throw new InvalidArgumentException('Refusing to rename a table with a non-identifier name.');
		}

		$provider = $this->connection->getDatabaseProvider();

		if ($provider === IDBConnection::PLATFORM_MYSQL || $provider === IDBConnection::PLATFORM_MARIADB) {
			$this->connection->executeStatement(sprintf('RENAME TABLE `*PREFIX*%s` TO `*PREFIX*%s`', $oldName, $newName));
			return;
		}

		$this->connection->executeStatement(sprintf('ALTER TABLE "*PREFIX*%s" RENAME TO "*PREFIX*%s"', $oldName, $newName));

		if ($provider !== IDBConnection::PLATFORM_POSTGRES) {
			return;
		}

		$this->renamePostgresArtefacts(oldName: $oldName, newName: $newName);
	}//end renameTable()

	/**
	 * Move the sequences and primary-key constraint a PostgreSQL rename leaves behind.
	 *
	 * @param string $oldName Unprefixed previous table name
	 * @param string $newName Unprefixed current table name
	 *
	 * @return void
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	private function renamePostgresArtefacts(string $oldName, string $newName): void {
		$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');

		$sequences = $this->fetchNames(
			sql: 'SELECT sequencename AS name FROM pg_sequences WHERE schemaname = current_schema() AND sequencename LIKE ?',
			params: [$prefix.$oldName.'\_%']
		);

		foreach ($sequences as $sequence) {
			$target = $prefix.$newName.substr($sequence, strlen($prefix.$oldName));
			$this->connection->executeStatement(sprintf('ALTER SEQUENCE "%s" RENAME TO "%s"', $sequence, $target));
		}

		$constraints = $this->fetchNames(
			sql: 'SELECT conname AS name FROM pg_constraint WHERE conrelid = ?::regclass AND contype = \'p\'',
			params: [$prefix.$newName]
		);

		foreach ($constraints as $constraint) {
			if (str_starts_with($constraint, $prefix.$oldName) === false) {
				continue;
			}

			$target = $prefix.$newName.substr($constraint, strlen($prefix.$oldName));
			$this->connection->executeStatement(
				sprintf('ALTER TABLE "%s" RENAME CONSTRAINT "%s" TO "%s"', $prefix.$newName, $constraint, $target)
			);
		}
	}//end renamePostgresArtefacts()

	/**
	 * Run a query whose single selected column is aliased to "name".
	 *
	 * @param string            $sql    The query to run
	 * @param array<int,string> $params Positional parameters
	 *
	 * @return list<string>
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	private function fetchNames(string $sql, array $params): array {
		$names = [];

		foreach ($this->connection->executeQuery($sql, $params)->fetchAll() as $row) {
			$names[] = (string) $row['name'];
		}

		return $names;
	}//end fetchNames()

	/**
	 * Create any missing table, column or index of the target schema.
	 *
	 * Idempotent by construction: an install that already carries a table keeps
	 * it and gains only what it is missing, so this is safe both on a fresh
	 * install and on one that has just been renamed above.
	 *
	 * @param IOutput                   $output        The output interface
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure
	 * @param array<string,mixed>       $options       Migration options
	 *
	 * @return null|ISchemaWrapper
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		foreach (self::SCHEMA as $suffix => $definition) {
			$this->applyTable(schema: $schema, name: self::NEW_PREFIX.$suffix, definition: $definition);
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Reconcile one table against its declaration.
	 *
	 * @param ISchemaWrapper $schema     The schema being built
	 * @param string         $name       Prefixed table name
	 * @param TableSpec      $definition The declared shape of the table
	 *
	 * @return void
	 *
	 * @spec exclude Schema-only consolidation; behavioural requirements live with the services that read these tables.
	 */
	private function applyTable(ISchemaWrapper $schema, string $name, array $definition): void {
		if ($schema->hasTable($name) === false) {
			$schema->createTable($name);
		}

		$table = $schema->getTable($name);

		foreach ($definition['columns'] as [$column, $type, $columnOptions]) {
			if ($table->hasColumn($column) === true) {
				continue;
			}

			$table->addColumn($column, $type, $columnOptions);
		}

		if ($table->hasPrimaryKey() === false) {
			$table->setPrimaryKey($definition['primary']);
		}

		foreach ($definition['indexes'] as [$index, $columns]) {
			if ($table->hasIndex($index) === true) {
				continue;
			}

			$table->addIndex($columns, $index);
		}

		foreach ($definition['uniqueIndexes'] as [$index, $columns]) {
			if ($table->hasIndex($index) === true) {
				continue;
			}

			$table->addUniqueIndex($columns, $index);
		}
	}//end applyTable()
}//end class
