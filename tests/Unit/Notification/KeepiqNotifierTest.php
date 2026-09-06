<?php

/**
 * Unit tests for KeepiqNotifier.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Notification
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

namespace OCA\Keepiq\Tests\Unit\Notification;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Notification\KeepiqNotifier;
use OCA\Keepiq\Service\NotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KeepiqNotifier.
 *
 * This is a secrets vault: a wrong name, link or recipient in a notification is
 * disclosure-adjacent. Every subject branch is therefore pinned on its exact
 * parsed subject, parsed message and link.
 */
class KeepiqNotifierTest extends TestCase {

	/**
	 * Base URL the IURLGenerator double prefixes onto absolute URLs.
	 *
	 * @var string
	 */
	private const BASE_URL = 'https://cloud.example.test';

	/**
	 * Mock L10N factory.
	 *
	 * @var IFactory
	 */
	private IFactory $l10nFactory;

	/**
	 * Mock URL generator.
	 *
	 * @var IURLGenerator
	 */
	private IURLGenerator $url;

	/**
	 * Notifier under test.
	 *
	 * @var KeepiqNotifier
	 */
	private KeepiqNotifier $notifier;

	/**
	 * Values recorded from the notification double during the last prepare().
	 *
	 * @var array<string,mixed>
	 */
	private array $recorded = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->l10nFactory = $this->createMock(originalClassName: IFactory::class);
		$this->url = $this->createMock(originalClassName: IURLGenerator::class);

		$l = $this->createMock(originalClassName: IL10N::class);
		$l->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false) {
					$parameters = [$parameters];
				}

				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);
		$this->l10nFactory->method('get')->willReturn($l);

		$this->url->method('imagePath')->willReturnCallback(
			static fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file
		);
		$this->url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => self::BASE_URL . $path
		);
		$this->url->method('linkToRoute')->willReturnCallback(
			static function (string $route, array $arguments = []): string {
				if ($arguments === []) {
					return '/index.php/apps/keepiq/';
				}

				return '/index.php/settings/admin/' . $arguments['section'];
			}
		);

		$this->notifier = new KeepiqNotifier(l10nFactory: $this->l10nFactory, url: $this->url);
	}//end setUp()

	/**
	 * Build an INotification double that records everything prepare() sets on it.
	 *
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param string|null $app The owning app id, defaulting to Keepiq's
	 *
	 * @return INotification
	 */
	private function notificationDouble(string $subject, array $params = [], ?string $app = null): INotification {
		$this->recorded = [
			'icon' => null,
			'parsedSubject' => null,
			'parsedMessage' => null,
			'link' => null,
			'linkCalls' => 0,
		];

		$notification = $this->createMock(originalClassName: INotification::class);
		$notification->method('getApp')->willReturn($app ?? Application::APP_ID);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);

		$notification->method('setIcon')->willReturnCallback(
			function (string $icon) use ($notification): INotification {
				$this->recorded['icon'] = $icon;
				return $notification;
			}
		);
		$notification->method('setParsedSubject')->willReturnCallback(
			function (string $value) use ($notification): INotification {
				$this->recorded['parsedSubject'] = $value;
				return $notification;
			}
		);
		$notification->method('setParsedMessage')->willReturnCallback(
			function (string $value) use ($notification): INotification {
				$this->recorded['parsedMessage'] = $value;
				return $notification;
			}
		);
		$notification->method('setLink')->willReturnCallback(
			function (string $value) use ($notification): INotification {
				$this->recorded['link'] = $value;
				$this->recorded['linkCalls']++;
				return $notification;
			}
		);

		return $notification;
	}//end notificationDouble()

	/**
	 * Run prepare() against a recording double and return the recorded values.
	 *
	 * @param string $subject The notification subject identifier
	 * @param array<string,mixed> $params The subject parameters
	 * @param string $languageCode The language code to prepare for
	 *
	 * @return array<string,mixed>
	 */
	private function prepareSubject(string $subject, array $params = [], string $languageCode = 'en'): array {
		$notification = $this->notificationDouble(subject: $subject, params: $params);
		$returned = $this->notifier->prepare(notification: $notification, languageCode: $languageCode);

		$this->assertSame(
			expected: $notification,
			actual: $returned,
			message: 'prepare() must return the same notification instance it mutated.'
		);

		return $this->recorded;
	}//end prepareSubject()

	/**
	 * getID(): must be the app id constant, never a literal.
	 *
	 * @return void
	 */
	public function testGetIdIsTheAppIdConstant(): void {
		$this->assertSame(expected: Application::APP_ID, actual: $this->notifier->getID());
		$this->assertSame(expected: 'keepiq', actual: $this->notifier->getID());
	}//end testGetIdIsTheAppIdConstant()

	/**
	 * getName(): human-readable name shown in the notification settings.
	 *
	 * @return void
	 */
	public function testGetNameIsHumanReadable(): void {
		$this->assertSame(expected: 'Keepiq', actual: $this->notifier->getName());
	}//end testGetNameIsHumanReadable()

	/**
	 * prepare(): a notification belonging to another app is rejected.
	 *
	 * @return void
	 */
	public function testPrepareRejectsForeignApp(): void {
		$notification = $this->notificationDouble(
			subject: 'secret_shared',
			params: [],
			app: 'files'
		);

		$this->expectException(exception: UnknownNotificationException::class);
		$this->notifier->prepare(notification: $notification, languageCode: 'en');
	}//end testPrepareRejectsForeignApp()

	/**
	 * prepare(): a subject outside SUBJECT_SETTING_MAP is rejected before any
	 * rendering happens — no icon is set on the way out.
	 *
	 * @return void
	 */
	public function testPrepareRejectsUnknownSubject(): void {
		$notification = $this->notificationDouble(subject: 'mystery_subject');

		try {
			$this->notifier->prepare(notification: $notification, languageCode: 'en');
			$this->fail(message: 'prepare() should reject an unmapped subject.');
		} catch (UnknownNotificationException) {
			$this->assertNull(actual: $this->recorded['icon']);
			$this->assertNull(actual: $this->recorded['parsedSubject']);
		}
	}//end testPrepareRejectsUnknownSubject()

	/**
	 * prepare(): the icon is the app's own app.svg, resolved absolutely and
	 * addressed through Application::APP_ID rather than a literal.
	 *
	 * @return void
	 */
	public function testPrepareSetsAbsoluteAppIcon(): void {
		$recorded = $this->prepareSubject(subject: 'secret_shared');

		$this->assertSame(
			expected: self::BASE_URL . '/apps/' . Application::APP_ID . '/img/app.svg',
			actual: $recorded['icon']
		);
	}//end testPrepareSetsAbsoluteAppIcon()

	/**
	 * prepare(): the requested language is passed through to the L10N factory
	 * together with the app id.
	 *
	 * @return void
	 */
	public function testPrepareResolvesL10nForRequestedLanguage(): void {
		$l10nFactory = $this->createMock(originalClassName: IFactory::class);
		$l10nFactory
			->expects($this->once())
			->method('get')
			->with(Application::APP_ID, 'de')
			->willReturn($this->createMock(originalClassName: IL10N::class));

		$notifier = new KeepiqNotifier(l10nFactory: $l10nFactory, url: $this->url);
		$notifier->prepare(
			notification: $this->notificationDouble(subject: 'secret_shared'),
			languageCode: 'de'
		);
	}//end testPrepareResolvesL10nForRequestedLanguage()

	/**
	 * prepare(): every subject the service is allowed to dispatch must be
	 * renderable. A key in SUBJECT_SETTING_MAP with no renderer would reach the
	 * user as an exception instead of a notification.
	 *
	 * @return void
	 */
	public function testEveryDispatchableSubjectIsRenderable(): void {
		foreach (array_keys(NotificationService::SUBJECT_SETTING_MAP) as $subject) {
			$recorded = $this->prepareSubject(subject: $subject);

			$this->assertNotNull(
				actual: $recorded['parsedSubject'],
				message: 'Subject "' . $subject . '" is dispatchable but has no renderer.'
			);
			$this->assertNotSame(expected: '', actual: $recorded['parsedMessage']);
		}
	}//end testEveryDispatchableSubjectIsRenderable()

	/*
	 * ---------------------------------------------------------------
	 * Sharing subjects
	 * ---------------------------------------------------------------
	 */

	/**
	 * secret_shared: names the sharer and the secret, and deep-links the secret.
	 *
	 * @return void
	 */
	public function testSecretSharedRendersSharerAndSecret(): void {
		$recorded = $this->prepareSubject(
			subject: 'secret_shared',
			params: [
				'secret_name' => 'Prod DB root',
				'shared_by' => 'alice',
				'secret_id' => '42',
			]
		);

		$this->assertSame(expected: 'Secret shared with you', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'alice shared the secret "Prod DB root" with you.',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(
			expected: self::BASE_URL . '/index.php/apps/keepiq/secrets/42',
			actual: $recorded['link']
		);
	}//end testSecretSharedRendersSharerAndSecret()

	/**
	 * secret_shared: missing params fall back to neutral placeholders and, with
	 * no secret_id, no link is attached at all.
	 *
	 * @return void
	 */
	public function testSecretSharedFallsBackToPlaceholdersWithoutLink(): void {
		$recorded = $this->prepareSubject(subject: 'secret_shared');

		$this->assertSame(
			expected: 'a user shared the secret "a secret" with you.',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(expected: 0, actual: $recorded['linkCalls']);
	}//end testSecretSharedFallsBackToPlaceholdersWithoutLink()

	/**
	 * share_request: names the requester and the secret.
	 *
	 * @return void
	 */
	public function testShareRequestRendersRequester(): void {
		$recorded = $this->prepareSubject(
			subject: 'share_request',
			params: [
				'requester' => 'bob',
				'secret_name' => 'API token',
				'secret_id' => '7',
			]
		);

		$this->assertSame(expected: 'Share request', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'bob requested access to the secret "API token".',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(
			expected: self::BASE_URL . '/index.php/apps/keepiq/secrets/7',
			actual: $recorded['link']
		);
	}//end testShareRequestRendersRequester()

	/**
	 * share_request: the requester placeholder is used when unnamed.
	 *
	 * @return void
	 */
	public function testShareRequestFallsBackToPlaceholders(): void {
		$recorded = $this->prepareSubject(subject: 'share_request');

		$this->assertSame(
			expected: 'a user requested access to the secret "a secret".',
			actual: $recorded['parsedMessage']
		);
	}//end testShareRequestFallsBackToPlaceholders()

	/**
	 * share_request_result: an approved result reads as approved.
	 *
	 * @return void
	 */
	public function testShareRequestResultApproved(): void {
		$recorded = $this->prepareSubject(
			subject: 'share_request_result',
			params: [
				'secret_name' => 'SSH key',
				'result' => 'approved',
			]
		);

		$this->assertSame(expected: 'Share request result', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Your share request for "SSH key" was approved.',
			actual: $recorded['parsedMessage']
		);
	}//end testShareRequestResultApproved()

	/**
	 * share_request_result: an explicit denial reads as denied.
	 *
	 * @return void
	 */
	public function testShareRequestResultDenied(): void {
		$recorded = $this->prepareSubject(
			subject: 'share_request_result',
			params: [
				'secret_name' => 'SSH key',
				'result' => 'denied',
			]
		);

		$this->assertSame(
			expected: 'Your share request for "SSH key" was denied.',
			actual: $recorded['parsedMessage']
		);
	}//end testShareRequestResultDenied()

	/**
	 * share_request_result: an absent or unrecognised result must fail closed —
	 * anything that is not literally "approved" reads as denied.
	 *
	 * @return void
	 */
	public function testShareRequestResultDefaultsToDenied(): void {
		$missing = $this->prepareSubject(
			subject: 'share_request_result',
			params: ['secret_name' => 'SSH key']
		);
		$this->assertSame(
			expected: 'Your share request for "SSH key" was denied.',
			actual: $missing['parsedMessage']
		);

		$garbage = $this->prepareSubject(
			subject: 'share_request_result',
			params: [
				'secret_name' => 'SSH key',
				'result' => 'APPROVED',
			]
		);
		$this->assertSame(
			expected: 'Your share request for "SSH key" was denied.',
			actual: $garbage['parsedMessage'],
			message: 'A non-exact result value must fail closed to denied.'
		);
	}//end testShareRequestResultDefaultsToDenied()

	/**
	 * group_member_added: names the group and the secret awaiting approval.
	 *
	 * @return void
	 */
	public function testGroupMemberAddedRendersGroupAndSecret(): void {
		$recorded = $this->prepareSubject(
			subject: 'group_member_added',
			params: [
				'group_id' => 'ops',
				'secret_name' => 'Vault seal',
				'secret_id' => '99',
			]
		);

		$this->assertSame(expected: 'Group member added', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'A new member joined the group "ops" — approve to share "Vault seal".',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(
			expected: self::BASE_URL . '/index.php/apps/keepiq/secrets/99',
			actual: $recorded['link']
		);
	}//end testGroupMemberAddedRendersGroupAndSecret()

	/*
	 * ---------------------------------------------------------------
	 * Secret lifecycle subjects
	 * ---------------------------------------------------------------
	 */

	/**
	 * secret_compromised: flags the secret for migration.
	 *
	 * @return void
	 */
	public function testSecretCompromisedRendersMigrationWarning(): void {
		$recorded = $this->prepareSubject(
			subject: 'secret_compromised',
			params: [
				'secret_name' => 'Leaked token',
				'secret_id' => '5',
			]
		);

		$this->assertSame(expected: 'Secret may be compromised', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Your secret "Leaked token" may be compromised and requires migration.',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(
			expected: self::BASE_URL . '/index.php/apps/keepiq/secrets/5',
			actual: $recorded['link']
		);
	}//end testSecretCompromisedRendersMigrationWarning()

	/**
	 * request_fulfilled: confirms a filled request.
	 *
	 * @return void
	 */
	public function testRequestFulfilledRendersConfirmation(): void {
		$recorded = $this->prepareSubject(
			subject: 'request_fulfilled',
			params: ['secret_name' => 'CI deploy key']
		);

		$this->assertSame(expected: 'Secret request fulfilled', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Your request for "CI deploy key" has been filled in.',
			actual: $recorded['parsedMessage']
		);
	}//end testRequestFulfilledRendersConfirmation()

	/**
	 * secret_expiring: renders the day count as an integer.
	 *
	 * @return void
	 */
	public function testSecretExpiringRendersDayCount(): void {
		$recorded = $this->prepareSubject(
			subject: 'secret_expiring',
			params: [
				'secret_name' => 'TLS key',
				'days_left' => 3,
			]
		);

		$this->assertSame(expected: 'Secret expiring soon', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Your secret "TLS key" expires in 3 day(s). Rotate it before then.',
			actual: $recorded['parsedMessage']
		);
	}//end testSecretExpiringRendersDayCount()

	/**
	 * secret_expiring: a missing or non-numeric day count degrades to zero
	 * rather than rendering a raw placeholder.
	 *
	 * @return void
	 */
	public function testSecretExpiringDefaultsDayCountToZero(): void {
		$recorded = $this->prepareSubject(
			subject: 'secret_expiring',
			params: ['secret_name' => 'TLS key']
		);

		$this->assertSame(
			expected: 'Your secret "TLS key" expires in 0 day(s). Rotate it before then.',
			actual: $recorded['parsedMessage']
		);
	}//end testSecretExpiringDefaultsDayCountToZero()

	/**
	 * secret_rotation_due: reports the overdue rotation flag.
	 *
	 * @return void
	 */
	public function testSecretRotationDueRendersOverdueFlag(): void {
		$recorded = $this->prepareSubject(
			subject: 'secret_rotation_due',
			params: ['secret_name' => 'Legacy password']
		);

		$this->assertSame(expected: 'Secret rotation due', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Your secret "Legacy password" is past its expiry and has been flagged for rotation.',
			actual: $recorded['parsedMessage']
		);
	}//end testSecretRotationDueRendersOverdueFlag()

	/*
	 * ---------------------------------------------------------------
	 * Admin subjects
	 * ---------------------------------------------------------------
	 */

	/**
	 * app_pending: links the admin to the Keepiq admin settings section.
	 *
	 * @return void
	 */
	public function testAppPendingLinksToAdminSection(): void {
		$recorded = $this->prepareSubject(
			subject: 'app_pending',
			params: [
				'app_name' => 'Billing service',
				'registered_by' => 'carol',
			]
		);

		$this->assertSame(expected: 'New application pending approval', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Application "Billing service" was registered by carol and is awaiting approval.',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(
			expected: self::BASE_URL . '/index.php/settings/admin/' . Application::APP_ID,
			actual: $recorded['link']
		);
	}//end testAppPendingLinksToAdminSection()

	/**
	 * app_pending: unnamed applications and registrars fall back to placeholders.
	 *
	 * @return void
	 */
	public function testAppPendingFallsBackToPlaceholders(): void {
		$recorded = $this->prepareSubject(subject: 'app_pending');

		$this->assertSame(
			expected: 'Application "an application" was registered by an external party and is awaiting approval.',
			actual: $recorded['parsedMessage']
		);
	}//end testAppPendingFallsBackToPlaceholders()

	/**
	 * siem_dead_letter: names the failing sink and links to admin settings.
	 *
	 * @return void
	 */
	public function testSiemDeadLetterLinksToAdminSection(): void {
		$recorded = $this->prepareSubject(
			subject: 'siem_dead_letter',
			params: ['sink_name' => 'splunk-primary']
		);

		$this->assertSame(expected: 'SIEM delivery failing', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Audit events for SIEM sink "splunk-primary" could not be delivered and were '
				. 'dead-lettered. Check the sink configuration.',
			actual: $recorded['parsedMessage']
		);
		$this->assertSame(
			expected: self::BASE_URL . '/index.php/settings/admin/' . Application::APP_ID,
			actual: $recorded['link']
		);
	}//end testSiemDeadLetterLinksToAdminSection()

	/*
	 * ---------------------------------------------------------------
	 * Vault access subjects — none of these carry a deep-link
	 * ---------------------------------------------------------------
	 */

	/**
	 * team_folder_shared: names the sharer.
	 *
	 * @return void
	 */
	public function testTeamFolderSharedRendersSharer(): void {
		$recorded = $this->prepareSubject(
			subject: 'team_folder_shared',
			params: ['sharedBy' => 'dave']
		);

		$this->assertSame(expected: 'Team folder shared with you', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'dave shared a team folder with you. Its secrets are now in your vault.',
			actual: $recorded['parsedMessage']
		);
	}//end testTeamFolderSharedRendersSharer()

	/**
	 * team_folder_join_request: names the new member and the group.
	 *
	 * @return void
	 */
	public function testTeamFolderJoinRequestRendersMemberAndGroup(): void {
		$recorded = $this->prepareSubject(
			subject: 'team_folder_join_request',
			params: [
				'newMemberId' => 'erin',
				'groupId' => 'finance',
			]
		);

		$this->assertSame(expected: 'Team folder join request', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'erin joined the group "finance" — approve to share your team folder with them.',
			actual: $recorded['parsedMessage']
		);
	}//end testTeamFolderJoinRequestRendersMemberAndGroup()

	/**
	 * honey_access: a tripwire names the accessor and the channel used.
	 *
	 * @return void
	 */
	public function testHoneyAccessRendersAccessorAndChannel(): void {
		$recorded = $this->prepareSubject(
			subject: 'honey_access',
			params: [
				'accessor' => 'mallory',
				'channel' => 'api',
			]
		);

		$this->assertSame(expected: 'Honey credential accessed', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'A decoy secret was accessed by mallory via api. Review the honey alerts now — '
				. 'this may indicate a compromise.',
			actual: $recorded['parsedMessage']
		);
	}//end testHoneyAccessRendersAccessorAndChannel()

	/**
	 * honey_access: an anonymous tripwire still pages, with placeholders.
	 *
	 * @return void
	 */
	public function testHoneyAccessFallsBackToPlaceholders(): void {
		$recorded = $this->prepareSubject(subject: 'honey_access');

		$this->assertSame(
			expected: 'A decoy secret was accessed by an unknown accessor via unknown channel. '
				. 'Review the honey alerts now — this may indicate a compromise.',
			actual: $recorded['parsedMessage']
		);
	}//end testHoneyAccessFallsBackToPlaceholders()

	/**
	 * certificate_expiring: renders the remaining day count.
	 *
	 * @return void
	 */
	public function testCertificateExpiringRendersDayCount(): void {
		$recorded = $this->prepareSubject(
			subject: 'certificate_expiring',
			params: ['days_left' => 14]
		);

		$this->assertSame(expected: 'Vault certificate expiring soon', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Your vault encryption certificate expires in 14 day(s). Re-issue it from the '
				. 'certificate inventory before it expires.',
			actual: $recorded['parsedMessage']
		);
	}//end testCertificateExpiringRendersDayCount()

	/**
	 * emergency_access_requested: prefers the display name and states the veto
	 * window, so the grantor can act before access is granted.
	 *
	 * @return void
	 */
	public function testEmergencyAccessRequestedPrefersDisplayName(): void {
		$recorded = $this->prepareSubject(
			subject: 'emergency_access_requested',
			params: [
				'grantee_name' => 'Frank Miller',
				'granteeUserId' => 'frank',
				'waitPeriodDays' => 3,
			]
		);

		$this->assertSame(expected: 'Emergency access requested', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Frank Miller requested emergency access to your vault. It will be granted in '
				. '3 day(s) unless you decline.',
			actual: $recorded['parsedMessage']
		);
	}//end testEmergencyAccessRequestedPrefersDisplayName()

	/**
	 * emergency_access_requested: falls back to the user id, then to a neutral
	 * placeholder, and defaults the wait period to seven days.
	 *
	 * @return void
	 */
	public function testEmergencyAccessRequestedFallsBackToUserIdThenPlaceholder(): void {
		$byUserId = $this->prepareSubject(
			subject: 'emergency_access_requested',
			params: ['granteeUserId' => 'frank']
		);
		$this->assertSame(
			expected: 'frank requested emergency access to your vault. It will be granted in '
				. '7 day(s) unless you decline.',
			actual: $byUserId['parsedMessage']
		);

		$anonymous = $this->prepareSubject(subject: 'emergency_access_requested');
		$this->assertSame(
			expected: 'a trusted contact requested emergency access to your vault. It will be granted in '
				. '7 day(s) unless you decline.',
			actual: $anonymous['parsedMessage']
		);
	}//end testEmergencyAccessRequestedFallsBackToUserIdThenPlaceholder()

	/**
	 * emergency_access_accessed: reports that the vault was actually opened.
	 *
	 * @return void
	 */
	public function testEmergencyAccessAccessedRendersGrantee(): void {
		$recorded = $this->prepareSubject(
			subject: 'emergency_access_accessed',
			params: ['grantee_name' => 'Frank Miller']
		);

		$this->assertSame(expected: 'Emergency access used', actual: $recorded['parsedSubject']);
		$this->assertSame(
			expected: 'Frank Miller accessed your vault through emergency access.',
			actual: $recorded['parsedMessage']
		);
	}//end testEmergencyAccessAccessedRendersGrantee()

	/**
	 * The vault-access family deliberately carries no deep-link.
	 *
	 * @return void
	 */
	public function testVaultAccessSubjectsCarryNoLink(): void {
		$subjects = [
			'team_folder_shared',
			'team_folder_join_request',
			'honey_access',
			'certificate_expiring',
			'emergency_access_requested',
			'emergency_access_accessed',
		];

		foreach ($subjects as $subject) {
			$recorded = $this->prepareSubject(subject: $subject, params: ['secret_id' => '1']);

			$this->assertSame(
				expected: 0,
				actual: $recorded['linkCalls'],
				message: 'Subject "' . $subject . '" must not carry a deep-link.'
			);
		}
	}//end testVaultAccessSubjectsCarryNoLink()

	/*
	 * ---------------------------------------------------------------
	 * Link helper edge cases
	 * ---------------------------------------------------------------
	 */

	/**
	 * withSecretLink(): an unroutable dashboard route is swallowed — the link is
	 * optional and must never break the notification itself.
	 *
	 * @return void
	 */
	public function testSecretLinkIsSkippedWhenRouteCannotBeResolved(): void {
		$url = $this->createMock(originalClassName: IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/keepiq/img/app.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => self::BASE_URL . $path
		);
		$url->method('linkToRoute')->willThrowException(new InvalidArgumentException('no such route'));

		$notifier = new KeepiqNotifier(l10nFactory: $this->l10nFactory, url: $url);
		$notification = $this->notificationDouble(
			subject: 'secret_shared',
			params: [
				'secret_name' => 'Prod DB root',
				'secret_id' => '42',
			]
		);

		$notifier->prepare(notification: $notification, languageCode: 'en');

		$this->assertSame(expected: 0, actual: $this->recorded['linkCalls']);
		$this->assertSame(
			expected: 'a user shared the secret "Prod DB root" with you.',
			actual: $this->recorded['parsedMessage'],
			message: 'A missing link must not stop the notification rendering.'
		);
	}//end testSecretLinkIsSkippedWhenRouteCannotBeResolved()

	/**
	 * withSecretLink(): an empty secret_id is treated as absent.
	 *
	 * @return void
	 */
	public function testSecretLinkIsSkippedForEmptySecretId(): void {
		$recorded = $this->prepareSubject(
			subject: 'secret_compromised',
			params: [
				'secret_name' => 'Leaked token',
				'secret_id' => '',
			]
		);

		$this->assertSame(expected: 0, actual: $recorded['linkCalls']);
	}//end testSecretLinkIsSkippedForEmptySecretId()
}//end class
