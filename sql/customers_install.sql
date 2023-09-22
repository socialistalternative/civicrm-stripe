-- /*******************************************************
-- *
-- * Create new tables
-- *
-- *******************************************************/

-- /*******************************************************
-- *
-- * civicrm_stripe_customers
-- *
-- * Stripe Customers
-- *
-- *******************************************************/
CREATE TABLE `civicrm_stripe_customers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID',
  `customer_id` varchar(255) COMMENT 'Stripe Customer ID',
  `contact_id` int unsigned COMMENT 'FK to Contact',
  `processor_id` int unsigned COMMENT 'ID from civicrm_payment_processor',
  `currency` varchar(3) DEFAULT NULL COMMENT '3 character string, value from Stripe customer.',
  PRIMARY KEY (`id`),
  UNIQUE INDEX `customer_id`(customer_id),
  CONSTRAINT FK_civicrm_stripe_customers_contact_id FOREIGN KEY (`contact_id`) REFERENCES `civicrm_contact`(`id`) ON DELETE CASCADE
)
ENGINE=InnoDB;
