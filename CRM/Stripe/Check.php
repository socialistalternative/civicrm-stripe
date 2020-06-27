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
  const API_VERSION = '2020-03-02';
  const API_MIN_VERSION = '2019-12-03';

  /**
   * @var string
   */
  const MIN_VERSION_MJWSHARED = '0.8';
  const MIN_VERSION_SWEETALERT = '1.2';

  public static function checkRequirements(&$messages) {
    self::checkExtensionMjwshared($messages);
    self::checkExtensionFirewall($messages);
    self::checkExtensionSweetalert($messages);
  }

  /**
   * @param array $messages
   *
   * @throws \CiviCRM_API3_Exception
   */
  private static function checkExtensionMjwshared(&$messages) {
    // mjwshared: required. Requires min version
    $extensionName = 'mjwshared';

    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => $extensionName,
    ]);

    if (empty($extensions['id']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $message = new CRM_Utils_Check_Message(
        'stripe_requirements',
        E::ts('The Stripe extension requires the mjwshared extension which is not installed (https://lab.civicrm.org/extensions/mjwshared).'),
        E::ts('Stripe: Missing Requirements'),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $messages[] = $message;
    }
    if ($extensions['values'][$extensions['id']]['status'] === 'installed') {
      self::requireExtensionMinVersion($messages, $extensionName, CRM_Stripe_Check::MIN_VERSION_MJWSHARED, $extensions['values'][$extensions['id']]['version']);
    }
  }

  /**
   * @param array $messages
   *
   * @throws \CiviCRM_API3_Exception
   */
  private static function checkExtensionFirewall(&$messages) {
    $extensionName = 'firewall';

    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => $extensionName,
    ]);

    if (empty($extensions['id']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $message = new CRM_Utils_Check_Message(
        'stripe_recommended',
        E::ts('If you are using Stripe to accept payments on public forms (eg. contribution/event registration forms) it is recommended that you install the <strong><a href="https://lab.civicrm.org/extensions/firewall">firewall</a></strong> extension.
        Some sites have become targets for spammers who use the payment endpoint to try and test credit cards by submitting invalid payments to your Stripe account.'),
        E::ts('Recommended Extension: firewall'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-lightbulb-o'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $messages[] = $message;
    }
  }

  /**
   * @param array $messages
   *
   * @throws \CiviCRM_API3_Exception
   */
  private static function checkExtensionSweetalert(&$messages) {
    // sweetalert: recommended. If installed requires min version
    $extensionName = 'sweetalert';
    $extensions = civicrm_api3('Extension', 'get', [
      'full_name' => $extensionName,
    ]);

    if (empty($extensions['id']) || ($extensions['values'][$extensions['id']]['status'] !== 'installed')) {
      $message = new CRM_Utils_Check_Message(
        'stripe_recommended',
        E::ts('It is recommended that you install the <strong><a href="https://civicrm.org/extensions/sweetalert">sweetalert</a></strong> extension.
        This allows the stripe extension to show useful messages to the user when processing payment.
        If this is not installed it will fallback to the browser "alert" message but you will
        not see some messages (such as <em>we are pre-authorizing your card</em> and <em>please wait</em>) and the feedback to the user will not be as helpful.'),
        E::ts('Recommended Extension: sweetalert'),
        \Psr\Log\LogLevel::NOTICE,
        'fa-lightbulb-o'
      );
      $message->addAction(
        E::ts('Install now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $messages[] = $message;
    }
    if ($extensions['values'][$extensions['id']]['status'] === 'installed') {
      self::requireExtensionMinVersion($messages, $extensionName, CRM_Stripe_Check::MIN_VERSION_SWEETALERT, $extensions['values'][$extensions['id']]['version']);
    }
  }

  /**
   * @param array $messages
   * @param string $extensionName
   * @param string $minVersion
   * @param string $actualVersion
   */
  private static function requireExtensionMinVersion(&$messages, $extensionName, $minVersion, $actualVersion) {
    if (version_compare($actualVersion, $minVersion) === -1) {
      $message = new CRM_Utils_Check_Message(
        'stripe_requirements',
        E::ts('The Stripe extension requires the %1 extension version %2 or greater but your system has version %3.',
          [
            1 => $extensionName,
            2 => $minVersion,
            3 => $actualVersion
          ]),
        E::ts('Stripe: Missing Requirements'),
        \Psr\Log\LogLevel::ERROR,
        'fa-money'
      );
      $message->addAction(
        E::ts('Upgrade now'),
        NULL,
        'href',
        ['path' => 'civicrm/admin/extensions', 'query' => ['action' => 'update', 'id' => $extensionName, 'key' => $extensionName]]
      );
      $messages[] = $message;
    }
  }

}
