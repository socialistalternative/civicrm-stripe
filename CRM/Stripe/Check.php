<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use CRM_Stripe_ExtensionUtil as E;

/**
 * Class CRM_Stripe_Check
 */
class CRM_Stripe_Check {

  /**
   * @var string
   */
  const API_VERSION = '2022-11-15';
  const API_MIN_VERSION = '2019-12-03';

  /**
   * @var string
   */
  const MIN_VERSION_MJWSHARED = '1.2.10';
  const MIN_VERSION_FIREWALL = '1.5.6';

  /**
   * @var array
   */
  private $messages;

  /**
   * constructor.
   *
   * @param $messages
   */
  public function __construct($messages) {
    $this->messages = $messages;
  }

  /**
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  public function checkRequirements() {
    $this->checkExtensionMjwshared();
    $this->checkExtensionFirewall();
    $this->checkUpgradeMessages();
    $this->checkWebhooks();
    $this->checkFailedPaymentIntents();
    return $this->messages;
  }

  /**
   * @param string $extensionName
   * @param string $minVersion
   * @param string $actualVersion
   */
  private function requireExtensionMinVersion($extensionName, $minVersion, $actualVersion) {
    $actualVersionModified = $actualVersion;
    if (substr($actualVersion, -4) === '-dev') {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . $extensionName . E::SHORT_NAME . '_requirements_dev',
        E::ts('You are using a development version of %1 extension.',
          [1 => $extensionName]),
        E::ts('%1: Development version', [1 => $extensionName]),
        \Psr\Log\LogLevel::WARNING,
        'fa-code'
      );
      $this->messages[] = $message;
      $actualVersionModified = substr($actualVersion, 0, -4);
    }

    if (version_compare($actualVersionModified, $minVersion) === -1) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . $extensionName . E::SHORT_NAME . '_requirements',
        E::ts('The %1 extension requires the %2 extension version %3 or greater but your system has version %4.',
          [
            1 => ucfirst(E::SHORT_NAME),
            2 => $extensionName,
            3 => $minVersion,
            4 => $actualVersion
          ]),
        E::ts('%1: Missing Requirements', [1 => ucfirst(E::SHORT_NAME)]),
        \Psr\Log\LogLevel::ERROR,
        'fa-exclamation-triangle'
      );
      $message->addAction(
        E::ts('Upgrade now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $this->messages[] = $message;
    }
  }

  /**
   * @throws \CiviCRM_API3_Exception
   */
  private function checkExtensionMjwshared() {
    // mjwshared: required. Requires min version
    $extensionName = 'mjwshared';
    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => $extensionName,
    ]);

    if (empty($extensions['count']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . E::SHORT_NAME . '_requirements',
        E::ts('The <em>%1</em> extension requires the <em>Payment Shared</em> extension which is not installed. See <a href="%2" target="_blank">details</a> for more information.',
          [
            1 => ucfirst(E::SHORT_NAME),
            2 => 'https://civicrm.org/extensions/mjwshared',
          ]
        ),
        E::ts('%1: Missing Requirements', [1 => ucfirst(E::SHORT_NAME)]),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $this->messages[] = $message;
      return;
    }
    if (isset($extensions['id']) && $extensions['values'][$extensions['id']]['status'] === 'installed') {
      $this->requireExtensionMinVersion($extensionName, self::MIN_VERSION_MJWSHARED, $extensions['values'][$extensions['id']]['version']);
    }
  }

  /**
   * @throws \CiviCRM_API3_Exception
   */
  private function checkExtensionFirewall() {
    $extensionName = 'firewall';

    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => $extensionName,
    ]);

    if (empty($extensions['count']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . 'stripe_recommended',
        E::ts('If you are using Stripe to accept payments on public forms (eg. contribution/event registration forms) it is required that you install the <strong><a href="https://lab.civicrm.org/extensions/firewall">firewall</a></strong> extension.
        Some sites have become targets for spammers who use the payment endpoint to try and test credit cards by submitting invalid payments to your Stripe account.'),
        E::ts('Required Extension: firewall'),
        \Psr\Log\LogLevel::ERROR,
        'fa-lightbulb-o'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $this->messages[] = $message;
    }
    if (isset($extensions['id']) && $extensions['values'][$extensions['id']]['status'] === 'installed') {
      $this->requireExtensionMinVersion($extensionName, CRM_Stripe_Check::MIN_VERSION_FIREWALL, $extensions['values'][$extensions['id']]['version']);
    }
  }

  /**
   * Display messages on version upgrade
   *
   * @param array $messages
   */
  private function checkUpgradeMessages() {
    // @todo: When we release 6.7 we need to think about if this should be displayed or not (consider install/upgrade).
    if ((bool) \Civi::settings()->get('stripe_upgrade66message')) {
      $message = new CRM_Utils_Check_Message(
        __FUNCTION__ . 'stripe_upgrade66message',
        E::ts('Thankyou for upgrading to Stripe 6.6. You MUST check your configuration to make sure it will continue working:
        <ul>
          <li><strong>Access AJAX API</strong> permission is required for all users that make payments using Stripe (including the anonymous user).
          You need to check that all users making Stripe payments have the CiviCRM permission <strong>Access AJAX API</strong> otherwise payments will fail.</li>
          <li>Billing address fields are now disabled by default (if you require additional fields collect them via a profile).
          If you require the billing address fields you can enable them in <em>Administer->CiviContribute->Stripe Settings->Disable billing address fields</em>.
          They will be removed in a future version - if this is a problem please contact <a href="https://mjw.pt/support">MJW</a> and let us know why!</li>
          <li><a href="https://docs.civicrm.org/mjwshared/en/latest/refunds/">Refund UI</a> is now enabled by default.</li>
        </ul>'
        ),
        E::ts('Stripe: You need to check some settings now that you have upgraded to 6.6'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-money'
      );
      $message->addAction(
        E::ts('I\'ve read this'),
        E::ts('I\'ve read this and made any necessary configuration changes - don\'t show me this upgrade message any
      more'),
        'api3',
        ['Setting', 'create', ['stripe_upgrade66message' => 0]]
      );
      $this->messages[] = $message;
    }
  }

  private function checkWebhooks() {
    // If we didn't install mjwshared yet check requirements but don't crash when checking webhooks
    if (trait_exists('CRM_Mjwshared_WebhookTrait')) {
      $webhooks = new CRM_Stripe_Webhook();
      $webhooks->check($this->messages);
    }
  }

  /**
   * Try to detect if a client is being spammed / credit card fraud.
   */
  private function checkFailedPaymentIntents() {
    // Check for a high volume of failed/pending contributions
    $count = CRM_Core_DAO::singleValueQuery('SELECT count(*)
      FROM civicrm_stripe_paymentintent
      WHERE status = "failed"
        AND TIMESTAMPDIFF(minute, created_date, NOW()) < 60
      ORDER BY id DESC
      LIMIT 1000');

    if ($count > 20) {
      $message = new CRM_Utils_Check_Message(
        'stripe_paymentintentspam',
        E::ts('%1 failed Stripe Payment Intents in the past hour. Please check the logs. They are problably hitting the CiviCRM REST API.', [1 => $count]),
        E::ts('Stripe - High rate of failed contributions'),
        \Psr\Log\LogLevel::CRITICAL,
        'fa-check'
      );
      $this->messages[] = $message;
    }
    else {
      $message = new CRM_Utils_Check_Message(
        'stripe_paymentintentspam',
        E::ts('%1 failed Stripe Payment Intents in the past hour.', [1 => $count]) . ' ' . E::ts('We monitor this in case someone malicious is testing stolen credit cards on public contribution forms.'),
        E::ts('Stripe - Failed Stripe Payment Intents'),
        \Psr\Log\LogLevel::INFO,
        'fa-check'
      );
      $this->messages[] = $message;
    }
  }

}
