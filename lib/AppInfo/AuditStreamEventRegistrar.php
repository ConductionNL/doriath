<?php

/**
 * Keepiq audit-stream event registrar
 *
 * Binds every consumer of the typed AuditEvent stream: the append-only audit
 * log, the SIEM forwarder, and the honey-credential tripwire.
 *
 * @category AppInfo
 * @package  OCA\Keepiq\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\AppInfo;

use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Listener\AuditListener;
use OCA\Keepiq\Listener\HoneyTripwireListener;
use OCA\Keepiq\Listener\SiemForwardListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the audit-event fan-out.
 *
 * One dispatched `AuditEvent` reaches three independent consumers. Every one
 * of them is FAIL-SOFT by construction: a record, forward or tripwire failure
 * is logged and never propagates into the audited business operation, so the
 * order they are bound in carries no meaning and adding a fourth consumer
 * cannot break the other three.
 */
final class AuditStreamEventRegistrar {

	/**
	 * The capability-scoped event classes that also feed the audit log.
	 *
	 * These belong to the secret-export-gdpr change (design D2/D5) and are
	 * bound BY NAME so this registration never references a class that a
	 * partially-installed instance does not ship. A string literal is correct
	 * here precisely because the reference must survive the class being
	 * absent — `class_exists()` below is the guard that makes it safe.
	 *
	 * @var array<int, string>
	 */
	private const SCOPED_AUDIT_EVENTS = [
		'OCA\\Keepiq\\Event\\SecretExportedEvent',
		'OCA\\Keepiq\\Event\\GdprExportPerformedEvent',
		'OCA\\Keepiq\\Event\\AccountDataDeletedEvent',
	];

	/**
	 * Bind the audit-stream consumers to the AuditEvent.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-audit-trail/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Add-secret-audit-trail §2.6 — the single AuditListener turns every
		// dispatched AuditEvent into an append-only keepiq_audit_log row. The
		// listener is fail-soft: a record failure is logged at error level and
		// never propagates into the audited business operation.
		$context->registerEventListener(
			event: AuditEvent::class,
			listener: AuditListener::class
		);

		// SIEM audit export §2.1 — a second, independent AuditEvent consumer
		// that enqueues whitelisted payloads for configured SIEM sinks.
		// Fail-soft like AuditListener: a forward failure never propagates
		// into the audited business operation.
		$context->registerEventListener(
			event: AuditEvent::class,
			listener: SiemForwardListener::class
		);

		// Honey credentials §3.1 — the central tripwire on the same typed
		// audit stream: every server-observable secret access (UI, machine
		// API, link, share-copy read) is checked against the honey flags.
		// Fail-soft: a tripwire failure never blocks the observed access.
		$context->registerEventListener(
			event: AuditEvent::class,
			listener: HoneyTripwireListener::class
		);

		foreach (self::SCOPED_AUDIT_EVENTS as $exportEventClass) {
			if (class_exists($exportEventClass) === true) {
				$context->registerEventListener(
					event: $exportEventClass,
					listener: AuditListener::class
				);
			}
		}

	}//end register()
}//end class
