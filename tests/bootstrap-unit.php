<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * `lib/base.php` from a source tree that was never installed still declares
 * `OC` and builds `\OC::$server` before it throws "Not installed". That server
 * cannot be undone (`OC::$server` is a typed static), so from then on every
 * `\OC::$server->get()` in the code under test hits a container that knows
 * none of this app's registrations and autowires from scratch; constructor
 * cycles then recurse until memory runs out (19 GB on one openregister test,
 * 2026-09-08). So the decision has to be made BEFORE base.php is loaded, and
 * the only cheap signal is config/config.php: it must declare
 * `installed => true` and, as this bootstrap always required, a database.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true` and a database.
 */
function keepiq_nc_root_is_installed(string $ncRoot): bool
{
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it in a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	if (($config['installed'] ?? false) !== true) {
		return false;
	}

	return isset($config['dbtype']) || isset($config['dbhost']);
}

// The Nextcloud root this checkout sits under (apps-extra/keepiq/), or null
// when there is none or it is only a bare source tree. Decided ONCE, up here,
// so that lib/base.php is never loaded from a tree that cannot finish booting.
$keepiqNcRoot = null;
$keepiqNcCandidate = dirname(__DIR__, 3);
if (is_file($keepiqNcCandidate . '/lib/base.php') === true) {
	if (keepiq_nc_root_is_installed($keepiqNcCandidate) === true) {
		$keepiqNcRoot = $keepiqNcCandidate;
	} else {
		fwrite(
			STDERR,
			sprintf(
				"[keepiq/tests/bootstrap-unit] Nextcloud tree at %s is not installed (config/config.php lacks installed => true or a database); "
				. "skipping lib/base.php and running in pure-unit mode.\n",
				$keepiqNcCandidate
			)
		);
	}
}

// Bootstrap Nextcloud only when an INSTALLED instance is present: inside the
// Docker container the full environment (including \OC::$server) is available.
$ncLoaded = false;
if ($keepiqNcRoot !== null) {
	try {
		require_once $keepiqNcRoot . '/lib/base.php';
		$ncLoaded = true;
	} catch (\Throwable $e) {
		// The tree IS installed, so the dangerous case this guard exists for
		// (loading a bare source tree) did not happen. base.php still failed
		// part-way.
		//
		// This does NOT abort. `OC::$server` is a typed static, so a half-built
		// container cannot be unset, and aborting was tried: it turned all six
		// PHPUnit legs red on a suite that passes (humaniq, 2026-09-08). The
		// runaway this guard exists for needs an autowiring lookup to reach the
		// poisoned container, this app has none in lib, and phpunit.xml's 2G cap
		// bounds one anyway.
		//
		// So: say plainly that the container is unreliable, and let the pure unit
		// tests run. A container-bound test failing loudly is the intended outcome.
		fwrite(
			STDERR,
			sprintf(
				"[keepiq/tests/bootstrap-unit] Nextcloud at %s could not finish booting (%s).\n"
				. "  \\OC::\$server now holds a HALF-BUILT container and cannot be unset. Pure unit tests\n"
				. "  continue; anything resolving a service from that container is UNVERIFIED by this run.\n",
				$keepiqNcRoot,
				$e->getMessage()
			)
		);
	}
}

// If Nextcloud could not be loaded, register OCP stubs for pure unit tests.
if ($ncLoaded === false && $autoloader instanceof \Composer\Autoload\ClassLoader) {
	$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
	$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');
}

// Register Test\ namespace for NC test classes.
$serverTestsLib = __DIR__ . '/../../../tests/lib/';
if (is_dir($serverTestsLib)) {
	$loader = new \Composer\Autoload\ClassLoader();
	$loader->addPsr4('Test\\', $serverTestsLib);
	$loader->register(true);
}

// Stub Doctrine\DBAL\ParameterType for unit tests that mock IDBConnection or
// IQueryBuilder. The real class lives in doctrine/dbal which is provided by
// Nextcloud at runtime but not part of keepiq's composer dev deps.
if (class_exists('Doctrine\\DBAL\\ParameterType') === false) {
	eval(
		'namespace Doctrine\\DBAL; '
		. 'enum ParameterType: int { '
		. 'case NULL = 0; '
		. 'case INTEGER = 1; '
		. 'case STRING = 2; '
		. 'case LARGE_OBJECT = 3; '
		. 'case BOOLEAN = 5; '
		. 'case BINARY = 6; '
		. 'case ASCII = 7; '
		. '}'
	);
}
