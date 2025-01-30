# Bitrix discount coupons from the feed

- Allowed coupon characters: A-Za-z0-9_-
- The discount percentage is an integer.
- For a coupon to be added, it must be unique.
- If a coupon is added, it can be deactivated by removing it from the feed.
- Or activated by returning it to the feed.
- A coupon can have multiple products, each with its own percentage.

```php
$parserStock = [[
    'XML_ID' => 'X-0001', // product XML_ID
    'PROMO' => 'SOME-PRODUCT-COUPON|10;SOME-PRODUCT-COUPON-20|20', // COUPON_CODE | SALE_PERCENT
], [
    'XML_ID' => 'Y-0002', // product XML_ID
    'PROMO' => 'SOME-PRODUCT-COUPON|15', // COUPON_CODE | SALE_PERCENT
]];

$promoCouponsInstance = new CatalogStockPromoCoupons(
    USERS_GROUP_ID, // 2
    SITE_ID, // 's1'
);

foreach($parserStock as $item) {
    $promoCouponsInstance->processingItem($item);
}

$promoCouponsInstance->epilogue();
```

![Screenshots/chrome_9Z2kKCw3RR.png](https://raw.githubusercontent.com/mizuhomizuho/bitrix-coupons-from-feed/master/Screenshots/chrome_9Z2kKCw3RR.png)
![Screenshots/chrome_UMeAELL9SF.png](https://raw.githubusercontent.com/mizuhomizuho/bitrix-coupons-from-feed/master/Screenshots/chrome_UMeAELL9SF.png)