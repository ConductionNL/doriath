<?php

/**
 * Keepiq Notifier
 *
 * INotifier implementation that renders Keepiq notification subjects
 * into a localised parsed payload (icon + subject + message + link).
 *
 * @category Notification
 * @package  OCA\Keepiq\Notification
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

namespace OCA\Keepiq\Notification;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders Keepiq notifications.
 *
 * The subject IDs here must match NotificationService::SUBJECT_SETTING_MAP.
 * Each branch builds a short subject line, a longer message line and a
 * deep-link the user clicks to land on the affected secret / queue.
 */
class KeepiqNotifier implements INotifier {
	/**
	 * Constructor for KeepiqNotifier.
	 *
	 * @param IFactory $l10nFactory The L10N factory
	 * @param IURLGenerator $url The URL generator
	 *
	 * @return void
	 */
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}//end __construct()

	/**
	 * Identifier for the manager registration.
	 *
	 * @return string
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()

	/**
	 * Human-readable name used by the Nextcloud notification settings.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Keepiq';
	}//end getName()

	/**
	 * Prepare a notification for display.
	 *
	 * @param INotification $notification The notification instance
	 * @param string $languageCode The user's language code
	 *
	 * @return INotification
	 *
	 * @throws UnknownNotificationException
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$subj = $notification->getSubject();
		if (array_key_exists($subj, NotificationService::SUBJECT_SETTING_MAP) === false) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(app: Application::APP_ID, lang: $languageCode);
		$params = $notification->getSubjectParameters();

		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app.svg')));

		// Each renderer owns one family of subjects and reports whether it
		// recognised this one. The first renderer that claims the subject wins.
		$handled = $this->renderSharingSubject(notification: $notification, subject: $subj, params: $params, l: $l);
		if ($handled === false) {
			$handled = $this->renderSecretLifecycleSubject(notification: $notification, subject: $subj, params: $params, l: $l);
		}

		if ($handled === false) {
			$handled = $this->renderAdminSubject(notification: $notification, subject: $subj, params: $params, l: $l);
		}

		if ($handled === false) {
			$handled = $this->renderVaultAccessSubject(notification: $notification, subject: $subj, params: $params, l: $l);
		}

		if ($handled === false) {
			throw new UnknownNotificationException();
		}

		return $notification;
	}//end prepare()

	/**
	 * Render the sharing-workflow subjects. All of them deep-link to a secret.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderSharingSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'secret_shared':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$sharedBy = (string)($params['shared_by'] ?? $l->t('a user'));
				$notification->setParsedSubject((string)$l->t('Secret shared with you'));
				$notification->setParsedMessage(
					(string)$l->t('%1$s shared the secret "%2$s" with you.', [$sharedBy, $secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'share_request':
				$requester = (string)($params['requester'] ?? $l->t('a user'));
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$notification->setParsedSubject((string)$l->t('Share request'));
				$notification->setParsedMessage(
					(string)$l->t('%1$s requested access to the secret "%2$s".', [$requester, $secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'share_request_result':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$result = (string)($params['result'] ?? 'denied');
				$notification->setParsedSubject((string)$l->t('Share request result'));
				$resultMessage = (string)$l->t('Your share request for "%s" was denied.', [$secretName]);
				if ($result === 'approved') {
					$resultMessage = (string)$l->t('Your share request for "%s" was approved.', [$secretName]);
				}

				$notification->setParsedMessage($resultMessage);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'group_member_added':
				$groupId = (string)($params['group_id'] ?? '');
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$notification->setParsedSubject((string)$l->t('Group member added'));
				$notification->setParsedMessage(
					(string)$l->t('A new member joined the group "%1$s" — approve to share "%2$s".', [$groupId, $secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
		}//end switch

		return false;
	}//end renderSharingSubject()

	/**
	 * Render the secret-lifecycle subjects. All of them deep-link to a secret.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderSecretLifecycleSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'secret_compromised':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$notification->setParsedSubject((string)$l->t('Secret may be compromised'));
				$notification->setParsedMessage(
					(string)$l->t('Your secret "%s" may be compromised and requires migration.', [$secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'request_fulfilled':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$notification->setParsedSubject((string)$l->t('Secret request fulfilled'));
				$notification->setParsedMessage(
					(string)$l->t('Your request for "%s" has been filled in.', [$secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'secret_expiring':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$daysLeft = (int)($params['days_left'] ?? 0);
				$notification->setParsedSubject((string)$l->t('Secret expiring soon'));
				$notification->setParsedMessage(
					(string)$l->t('Your secret "%1$s" expires in %2$d day(s). Rotate it before then.', [$secretName, $daysLeft])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
			case 'secret_rotation_due':
				$secretName = (string)($params['secret_name'] ?? $l->t('a secret'));
				$notification->setParsedSubject((string)$l->t('Secret rotation due'));
				$notification->setParsedMessage(
					(string)$l->t('Your secret "%s" is past its expiry and has been flagged for rotation.', [$secretName])
				);
				$this->withSecretLink(notification: $notification, params: $params);
				return true;
		}//end switch

		return false;
	}//end renderSecretLifecycleSubject()

	/**
	 * Render the administrator-facing subjects. All of them link to the admin section.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderAdminSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'app_pending':
				$appName = (string)($params['app_name'] ?? $l->t('an application'));
				$registeredBy = (string)($params['registered_by'] ?? $l->t('an external party'));
				$notification->setParsedSubject((string)$l->t('New application pending approval'));
				$notification->setParsedMessage(
					(string)$l->t('Application "%1$s" was registered by %2$s and is awaiting approval.', [$appName, $registeredBy])
				);
				$this->withAdminSectionLink(notification: $notification);
				return true;
			case 'siem_dead_letter':
				$sinkName = (string)($params['sink_name'] ?? $l->t('a SIEM sink'));
				$notification->setParsedSubject((string)$l->t('SIEM delivery failing'));
				$notification->setParsedMessage(
					(string)$l->t(
						'Audit events for SIEM sink "%1$s" could not be delivered and were dead-lettered. Check the sink configuration.',
						[$sinkName]
					)
				);
				$this->withAdminSectionLink(notification: $notification);
				return true;
		}//end switch

		return false;
	}//end renderAdminSubject()

	/**
	 * Render the vault-access subjects — team folders, decoys, certificates and
	 * emergency access. None of them carry a deep-link.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param IL10N $l The localisation helper
	 *
	 * @return bool True when this renderer recognised the subject.
	 */
	private function renderVaultAccessSubject(INotification $notification, string $subject, array $params, IL10N $l): bool {
		switch ($subject) {
			case 'team_folder_shared':
				$sharedBy = (string)($params['sharedBy'] ?? $l->t('a user'));
				$notification->setParsedSubject((string)$l->t('Team folder shared with you'));
				$notification->setParsedMessage(
					(string)$l->t('%s shared a team folder with you. Its secrets are now in your vault.', [$sharedBy])
				);
				return true;
			case 'team_folder_join_request':
				$newMemberId = (string)($params['newMemberId'] ?? $l->t('a user'));
				$joinGroupId = (string)($params['groupId'] ?? '');
				$notification->setParsedSubject((string)$l->t('Team folder join request'));
				$notification->setParsedMessage(
					(string)$l->t('%1$s joined the group "%2$s" — approve to share your team folder with them.', [$newMemberId, $joinGroupId])
				);
				return true;
			case 'honey_access':
				$honeyChannel = (string)($params['channel'] ?? $l->t('unknown channel'));
				$honeyAccessor = (string)($params['accessor'] ?? $l->t('an unknown accessor'));
				$notification->setParsedSubject((string)$l->t('Honey credential accessed'));
				$notification->setParsedMessage(
					(string)$l->t(
						'A decoy secret was accessed by %1$s via %2$s. Review the honey alerts now — this may indicate a compromise.',
						[$honeyAccessor, $honeyChannel]
					)
				);
				return true;
			case 'certificate_expiring':
				$certDaysLeft = (int)($params['days_left'] ?? 0);
				$notification->setParsedSubject((string)$l->t('Vault certificate expiring soon'));
				$notification->setParsedMessage(
					(string)$l->t(
						'Your vault encryption certificate expires in %1$d day(s). Re-issue it from the certificate inventory before it expires.',
						[$certDaysLeft]
					)
				);
				return true;
			case 'emergency_access_requested':
				$granteeName = (string)($params['grantee_name'] ?? $params['granteeUserId'] ?? $l->t('a trusted contact'));
				$waitDays = (int)($params['waitPeriodDays'] ?? 7);
				$notification->setParsedSubject((string)$l->t('Emergency access requested'));
				$notification->setParsedMessage(
					(string)$l->t(
						'%1$s requested emergency access to your vault. It will be granted in %2$d day(s) unless you decline.',
						[$granteeName, $waitDays]
					)
				);
				return true;
			case 'emergency_access_accessed':
				$granteeName = (string)($params['grantee_name'] ?? $params['granteeUserId'] ?? $l->t('a trusted contact'));
				$notification->setParsedSubject((string)$l->t('Emergency access used'));
				$notification->setParsedMessage(
					(string)$l->t('%s accessed your vault through emergency access.', [$granteeName])
				);
				return true;
		}//end switch

		return false;
	}//end renderVaultAccessSubject()

	/**
	 * Attach a deep-link to the affected secret, when the params include one.
	 *
	 * @param INotification $notification The notification to mutate
	 * @param array<string,mixed> $params The subject parameters
	 *
	 * @return void
	 */
	private function withSecretLink(INotification $notification, array $params): void {
		$secretId = (string)($params['secret_id'] ?? '');
		if ($secretId === '') {
			return;
		}

		try {
			/* Route names are namespaced by the app id, so this must be built
			   FROM the id rather than spelled out. It read
			   'keepiq.dashboard.page' as a literal, which is correct only
			   for as long as APP_ID happens to equal 'keepiq' — the next
			   rename would move the route and leave the literal behind. The
			   catch below then swallows the resulting exception, so every
			   secret deep-link would quietly stop appearing with nothing
			   logged: exactly the silent-failure shape this app id rename
			   was full of. */
			/* A path, not the retired '#/secrets/' hash form — the SPA's
			   createWebHistory router never reads the fragment. The route is
			   gated, so the click lands on the lock screen with this path as
			   returnUrl and resumes here after unlock. */
			$route = $this->url->linkToRoute(Application::APP_ID . '.dashboard.page')
				. 'secrets/' . $secretId;
			$notification->setLink($this->url->getAbsoluteURL($route));
		} catch (InvalidArgumentException) {
			/* Deliberately swallowed: the link is optional and a notification
			   without one is still useful. Worth knowing that this catch is
			   what made the hardcoded route above dangerous — an unresolvable
			   route produced no link, no error and no log. Deriving the name
			   from APP_ID removes the way that actually happened; if a
			   further failure mode turns up, this is where to add logging,
			   which needs a logger injected into the constructor. */
		}
	}//end withSecretLink()

	/**
	 * Attach a link to the Keepiq section of the Nextcloud admin settings.
	 *
	 * @param INotification $notification The notification to mutate
	 *
	 * @return void
	 */
	private function withAdminSectionLink(INotification $notification): void {
		$notification->setLink(
			$this->url->getAbsoluteURL(
				$this->url->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID])
			)
		);
	}//end withAdminSectionLink()
}//end class
