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
namespace Civi\Api4;

/**
 * CiviCRM settings api.
 *
 * Used to read/write persistent setting data from CiviCRM.
 *
 * @see \Civi\Core\SettingsBag
 * @searchable none
 * @since 5.19
 * @package Civi\Api4
 */
class StripeCharge extends Generic\AbstractEntity {

  /**
   * @param bool $checkPermissions
   * @return Action\Setting\Get
   */
  public static function getBalanceTransactionDetails($checkPermissions = TRUE) {
    return (new Action\StripeCharge\GetBalanceTransactionDetails(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * @param bool $checkPermissions
   * @return Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(static::getEntityName(), __FUNCTION__, function() {
      return [
        /*[
          'name' => 'key',
          'description' => 'Long, unique extension identifier',
        ],
        [
          'name' => 'file',
          'description' => 'Short, unique extension identifier',
        ],*/
      ];
    }))->setCheckPermissions($checkPermissions);
  }

}
