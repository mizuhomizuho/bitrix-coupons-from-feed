# Bitrix coupons from feed

- Allowed coupon characters: A-Za-z0-9_-
- The discount percentage is an integer.
- For a coupon to be added, it must be unique.
- If a coupon is added, it can be deactivated by removing it from the feed.
- Or activated by returning it to the feed.
- A coupon can have multiple products, each with its own percentage.

```php
$parserStock = [[
    'MED_ID' => '8881', // \d+ product XML_ID
    'PROMO' => 'SOME_PRODUCT_COUPON16|15;SOME_PRODUCT_COUPON20|20',
], [
    'MED_ID' => '8882', // \d+ product XML_ID
    'PROMO' => 'SOME_PRODUCT_COUPON16|17',
]];

$promoCouponsInstance = new CatalogStockPromoCoupons(
    (int) $this->arParams['IMPORT']['PROMO']['USERS_GROUP_ID'], // 2
    $this->arParams['IMPORT']['PROMO']['SITE_ID'], // s1
);

foreach($parserStock as $item) {
    $promoCouponsInstance->processingItem($item);
}

$promoCouponsInstance->epilogue();
```

![Screenshots/chrome_9Z2kKCw3RR.png](https://raw.githubusercontent.com/mizuhomizuho/bitrix-coupons-from-feed/master/Screenshots/chrome_9Z2kKCw3RR.png)
![Screenshots/chrome_UMeAELL9SF.png](https://raw.githubusercontent.com/mizuhomizuho/bitrix-coupons-from-feed/master/Screenshots/chrome_UMeAELL9SF.png)