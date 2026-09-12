<?php

/**
 * Keepiq Application
 *
 * Main application class for the Keepiq Nextcloud app.
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

use OCA\OpenRegister\AppHost\Bootstrap;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Keepiq Nextcloud app.
 *
 * This class is the composition root and little else. The wiring itself lives
 * in single-purpose registrars in this namespace, each of which owns one
 * domain's bindings and can be unit-tested on its own — `Application` cannot
 * be constructed without a Nextcloud DI container, so anything written inline
 * here is unreachable from a test.
 *
 * The registrars are plain collaborators instantiated with `new` rather than
 * resolved from the container: `register()` IS the point at which the
 * container is being populated, so there is nothing to resolve from yet.
 *
 * The AppHost adoption below deliberately stays inline. It is the one piece
 * of wiring that references a class from a SIBLING app, which psalm cannot
 * resolve; moved into a registrar of its own, its `$context`/`$appId`
 * parameters would be reachable only from that unresolvable call and psalm
 * reports both as never referenced (measured).
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'keepiq';

	/**
	 * The app version by which every pre-rename compatibility shim is gone.
	 *
	 * The doriath -> keepiq rename left a handful of published identifiers
	 * carrying the old codename: the assertion audience, the discovery path
	 * and the envelope format name. Each is accepted or announced in parallel
	 * with its replacement so no consumer needs a flag day — but only while
	 * the app is pre-stable. This app has never shipped a stable release, so
	 * there is no released contract to preserve and no reason to carry a dead
	 * codename past 1.0.0; the shims are removed before the first stable
	 * release, not deferred to a future apiVersion.
	 *
	 * `apiVersion` therefore stays at 1 throughout. The "breaking changes
	 * MUST ship as a new apiVersion" rule in the secret-store-api spec binds
	 * from the first stable release onward, which is exactly the point these
	 * shims stop existing.
	 *
	 * @var string
	 */
	public const PRE_STABLE_COMPAT_REMOVED_IN = '1.0.0';

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCA\OpenRegister\AppHost\Bootstrap
	 * is a cross-app static bootstrap entry point in a SIBLING Nextcloud app
	 * that may be absent or unloadable at this point — the call is guarded by
	 * class_exists() and wrapped in a catch(\Throwable) for exactly that
	 * reason. It cannot be injected: this method IS the composition root, so
	 * there is no container to resolve an adapter from yet, and declaring a
	 * typed dependency on a possibly-absent foreign class would 500 every
	 * route (a param type is a class reference the router reflects over).
	 * OpenRegisterAutoloader::register() is static for the same reason.
	 */
	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';

		// Adopt the OpenRegister AppHost engine (ADR-040 / ADR-022). One call wires
		// the boilerplate plumbing the fleet shares: the dashboard/preferences/
		// settings controllers, the settings + action-auth services, the install
		// repair steps, the admin-settings panel + section, the manifest-driven
		// deep-link listener, and the observability controllers (health + metrics).
		//
		// Every registration is a lazy service closure, so a disabled/absent
		// OpenRegister never fatals Nextcloud bootstrap — an aliased route simply
		// surfaces a 5xx and /api/health reports the degraded state.
		//
		// Keepiq then RE-REGISTERS its three domain-divergent plumbing classes
		// after this call so the concrete leaf classes win over the generic
		// aliases (see DomainOverrideRegistrar).
		//
		// LOAD-ORDER HAZARD (measured, not theoretical). OC_App::getEnabledApps()
		// sort()s the app list, and Coordinator::registerApps() walks THAT sorted
		// list calling OC_App::registerAutoloading($appId) and then $app->register()
		// for one app at a time. So every app registers before the PSR-4 prefix of
		// every alphabetically-LATER app exists: `keepiq` < `openregister`, so
		// OCA\OpenRegister\ is not autoloadable at this point on a perfectly
		// healthy instance.
		//
		// Left unguarded, the resulting \Error aborted this ENTIRE register() —
		// every registrar below never ran, and the audit listener recorded ZERO
		// dispatched events. Coordinator::registerApps() catches the Throwable and
		// logs an 'emergency', then `continue`s to the next app, so Keepiq stayed
		// enabled and kept serving requests: nothing in the UI, and nothing in the
		// app itself, reported that half its wiring was missing.
		//
		// OpenRegisterAutoloader::register() puts OpenRegister's prefix on the
		// autoloader ourselves, which is exactly what Nextcloud will do a few
		// iterations later. It never throws; it returns false when OpenRegister is
		// absent, and the class_exists() guard below then skips the AppHost
		// plumbing.
		OpenRegisterAutoloader::register();

		// The class_exists() guard MUST stay in this method: it is also the
		// assertion psalm relies on to accept the Bootstrap::register() call
		// below, and psalm does not carry that narrowing across a call.
		if (class_exists(Bootstrap::class) === true) {
			try {
				Bootstrap::register($context, self::APP_ID, ['namespace' => 'OCA\\Keepiq']);
			} catch (\Throwable) {
				// AppHost present but unloadable: skip the generic plumbing;
				// Keepiq's own listeners and services MUST still register. No
				// logger is resolvable this early, so the skip is silent —
				// /api/health surfaces the degraded AppHost state instead.
			}
		}

		// ORDER MATTERS here: a registerService() for an id the AppHost engine
		// already aliased only wins when it runs after that call.
		(new DomainOverrideRegistrar())->register(context: $context);

		// Domain event wiring, one registrar per trigger family. Each is
		// independent: a listener graph can be extended without touching the
		// other two, and none of them can abort the others.
		(new SuiteLifecycleEventRegistrar())->register(context: $context);
		(new UserLifecycleEventRegistrar())->register(context: $context);
		(new AuditStreamEventRegistrar())->register(context: $context);

		// Nextcloud's own extension points: unified search, notifications and
		// the JWT-Bearer request middleware.
		(new PlatformIntegrationRegistrar())->register(context: $context);

		// Domain repair steps (BootstrapCertificateAuthority, InitializeSettings,
		// SeedSecretTypes, the Seed* development data steps) are registered via
		// info.xml <repair-steps>. InitializeSettings is the Keepiq concrete
		// re-registered above (domain default-config seeding); the rest are
		// crypto/seed domain steps owned by the app.
	}//end register()

	/**
	 * Boot the application.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $context is mandated by
	 *   OCP\AppFramework\Bootstrap\IBootstrap::boot(), which this class implements.
	 *   All wiring happens in register(); there is nothing to do at boot time, but
	 *   the method and its parameter cannot be dropped from the interface.
	 */
	public function boot(IBootContext $context): void {
	}//end boot()
}//end class
