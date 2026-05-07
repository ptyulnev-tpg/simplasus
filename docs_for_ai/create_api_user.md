Introduction:

This SQL script helps you generate API users for merchants in the middleware_merchants_user table. It doesn’t directly create the users. Instead, it produces ready-to-run INSERT statements that you can review and then execute.

The script automatically:

Creates a consistent API username and email based on the merchant name (removing spaces and special characters, converting to lowercase).

Skips merchants that already have an API user with the same prefix.

Puts a placeholder (Password (hashed)) in the password field, which you should replace with a proper password hash before running the inserts.

Think of it as a helper that builds the SQL for you. You just set the prefix, pick your merchants, and then execute the generated statements.

-- Feel free to change
SET @api_prefix = 'PREFIX';
SET @password   = 'Password (hashed)';
SET @merchant_filter_list = '123,456,789'; -- comma seperated list of ids. set to NULL for all merchants

-- No need for changes here.
SET @user_prefix        = CONCAT('API-', @api_prefix, '-');
SET @email_local_prefix = CONCAT('noreply+', @user_prefix);
SET @email_domain       = '@the-platform-group.com';
SET @telefon          = '';
SET @role_ids         = '4';

SET @logins        = 1;
SET @account_owner = 0;
SET @account_id    = 0;
SET @join_date     = 0;
SET @lastlogin     = 1701380568;
SET @failed_logins = 3;
SET @locked_until  = 0;
SET @created       = 0;
SET @modified      = 0;
SET @active        = 1;
SET @is_deleted    = 0;
SET @picture       = '';
SET @fb_uid        = '';
SET @language      = 'de';

SET @insert_head   = 'INSERT INTO `middleware_merchants_user` (`merchant_id`, `user`, `password`,`telefon`, `email`, `role_ids`, `logins`, `account_owner`, `account_id`, `join_date`, `lastlogin`, `failed_logins`, `locked_until`, `created`, `modified`, `active`, `is_deleted`, `picture`, `fb_uid`, `language`) VALUES (';

WITH b AS (
SELECT
dr.UMLFIRMA AS merchant_id,
REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(LOWER(dr.firma)),' ',''),'+',''),'-',''),'.',''),',',''),'&',''),'ö','oe'),'ü','ue'),'ä','ae') AS slug
FROM tpg_b2b.data_retailer dr
)
SELECT
CONCAT(
@insert_head,
QUOTE(b.merchant_id), ',',
QUOTE(CONCAT(@user_prefix, b.slug)), ',',
QUOTE(@password), ',',
QUOTE(@telefon), ', ',
QUOTE(CONCAT(@email_local_prefix, b.slug, @email_domain)), ',',
QUOTE(@role_ids), ',',
@logins, ', ', @account_owner, ', ', @account_id, ', ',
@join_date, ', ', @lastlogin, ', ', @failed_logins, ', ', @locked_until, ', ',
@created, ', ', @modified, ', ',
@active, ', ', @is_deleted, ', ',
QUOTE(@picture), ', ', QUOTE(@fb_uid), ', ', QUOTE(@language),
');'
) AS sqlscript
FROM b
WHERE (@merchant_filter_list IS NULL OR FIND_IN_SET(b.merchant_id, @merchant_filter_list))
AND NOT EXISTS (
SELECT 1
FROM tpg_erp.middleware_merchants_user mmu
WHERE mmu.merchant_id = b.merchant_id
AND mmu.`user` LIKE CONCAT(@user_prefix, '%')
);

### PW: unhashed Password (not neccessary)


What happens after running the SQL:

Once you run the SQL script above, it generates INSERT statements for the middleware_merchants_user table. Each generated statement will look like this:

INSERT INTO `middleware_merchants_user` (`merchant_id`, `user`, `password`, `telefon`,
`email`, `role_ids`, `logins`, `account_owner`, `account_id`, `join_date`,
`lastlogin`, `failed_logins`, `locked_until`, `created`, `modified`, `active`,
`is_deleted`, `picture`, `fb_uid`, `language`)
VALUES ('MERCHANT_ID','API-PREFIX-MerchantName','Password (hashed)','',
'noreply+API-PREFIX-MerchantName@the-platform-group.com','4',1, 0, 0, 0,
1701380568, 3, 0, 0, 0, 1, 0, '', '', 'de');



Notes:

MERCHANT_ID will be replaced by the actual merchant ID from your tpg_b2b.data_retailer table.

API-PREFIX-MerchantName is generated automatically using the prefix you set (@api_prefix) and a sanitized version of the merchant name (lowercased, special characters removed).

The password field is a placeholder (Password (hashed)); you need to replace it with a proper hashed password before generating the INSERTs or at least before executing the generated INSERTs.

All other fields (logins, active, language, etc.) are pre-set according to the default values in the script.
