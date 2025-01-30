<?php

namespace Site\Import;

use Bitrix\Main\Application;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Internals\DiscountCouponTable;
use Bitrix\Sale\Internals\DiscountTable;

class CatalogStockPromoCoupons
{
    private ?array $promoDiscountsPull = null;
    private array $discountsForActivate = [];
    private array $discountsFromFeed = [];
    private string $currentCodeCoupon;
    private array $currentDiscountArray;

    private const DISCOUNT_XML_ID_PREFIX = 'importPromoCouponV2_';

    public function __construct(
        private readonly int    $usersGroupId,
        private readonly string $siteId,
    )
    {
    }

    public function epilogue(): void
    {
        $this->handleCoupons();
        $this->deactivateCoupons();
        $this->activateCoupons();
    }

    public function processingItem(array $item): void
    {
        if (
            !isset($item['PROMO'])
            || !is_string($item['PROMO'])
            || !isset($item['XML_ID'])
            || !is_string($item['XML_ID'])
            || !preg_match('/[a-z0-9_-]+/i', $item['XML_ID'])
        ) {
            return;
        }
        $promoExpl = explode(';', $item['PROMO']);
        foreach ($promoExpl as $promoExplValue) {
            $promoItemExpl = explode('|', $promoExplValue);
            if (count($promoItemExpl) !== 2) {
                continue;
            }
            $promoItemExpl[0] = trim($promoItemExpl[0]);
            $promoItemExpl[1] = trim($promoItemExpl[1]);
            if (
                !preg_match('/[a-z0-9_-]+/i', $promoItemExpl[0])
                || !preg_match('/\d+/', $promoItemExpl[1])
            ) {
                continue;
            }
            $this->processingCoupon(
                $item['XML_ID'],
                $promoItemExpl[0],
                (int)$promoItemExpl[1],
            );
        }
    }

    private function getDiscountXmlId(): string
    {
        $discountArray = $this->getCurrentDiscountArray();
        ksort($discountArray);
        return static::DISCOUNT_XML_ID_PREFIX
            . $this->getCurrentCodeCouponHash()
            . '_' . hash('crc32', var_export($discountArray, true));
    }

    private function getCurrentCodeCouponHash(): string
    {
        return hash('crc32', $this->getCurrentCodeCoupon());
    }

    private function handleCoupons(): void
    {
        $discountsPull = $this->getPromoDiscountsPull();
        $discountsFormFeed = $this->getDiscountsFromFeed();
        foreach ($discountsFormFeed as $codeCoupon => $discountArray) {
            $this->setCurrentCodeCoupon($codeCoupon);
            $this->setCurrentDiscountArray($discountArray);
            $codeCouponHash = $this->getCurrentCodeCouponHash();
            if (!isset($discountsPull[$codeCouponHash])) {
                $this->addDiscount();
            } else {
                $xmlIdDiscount = $this->getDiscountXmlId();
                if ($discountsPull[$codeCouponHash]['XML_ID'] !== $xmlIdDiscount) {
                    $this->updateDiscount();
                } elseif ($discountsPull[$codeCouponHash]['ACTIVE'] !== 'Y') {
                    $discountsForActivate = $this->getDiscountsForActivate();
                    $discountsForActivate[$codeCouponHash] = $discountsPull[$codeCouponHash];
                    $this->setDiscountsForActivate($discountsForActivate);
                }
                unset($discountsPull[$codeCouponHash]);
                $this->setPromoDiscountsPull($discountsPull);
            }
        }
    }

    private function updateDiscount(): void
    {
        $discountsPull = $this->getPromoDiscountsPull();
        $codeCouponHash = $this->getCurrentCodeCouponHash();
        \CSaleDiscount::Update($discountsPull[$codeCouponHash]['ID'], [
            'ACTIVE' => 'Y',
            'XML_ID' => $this->getDiscountXmlId(),
            'ACTIONS' => $this->getDiscountActions(),
        ]);
    }

    private function getDiscountActions(): array
    {
        return array(
            'CLASS_ID' => 'CondGroup',
            'DATA' => array(
                'All' => 'AND',
            ),
            'CHILDREN' => $this->getDiscountActionsChildren(),
        );
    }

    private function getDiscountActionsChildren(): array
    {
        $actions = [];
        foreach ($this->getCurrentDiscountArray() as $medXmlId => $salePercent) {
            $actions[] = array(
                'CLASS_ID' => 'ActSaleBsktGrp',
                'DATA' => array(
                    'Type' => 'Discount',
                    'Value' => $salePercent,
                    'Unit' => 'Perc',
                    'Max' => 0,
                    'All' => 'AND',
                    'True' => 'True',
                ),
                'CHILDREN' => array(array(
                    'CLASS_ID' => 'CondIBXmlID',
                    'DATA' => array(
                        'logic' => 'Equal',
                        'value' => $medXmlId,
                    ),
                ),
                ),
            );
        }
        return $actions;
    }

    private function getAddDiscountParams(): array
    {
        $codeCoupon = $this->getCurrentCodeCoupon();
        return [
            'LID' => $this->getSiteId(),
            'NAME' => "Купон из выгрузки ($codeCoupon)",
            'ACTIVE' => 'Y',
            'USE_COUPONS' => 'Y',
            'XML_ID' => $this->getDiscountXmlId(),
            'CONDITIONS' => array(
                'CLASS_ID' => 'CondGroup',
                'DATA' => array(
                    'All' => 'AND',
                    'True' => 'True',
                ),
                'CHILDREN' => array(),
            ),
            'ACTIONS' => $this->getDiscountActions(),
            'USER_GROUPS' => [$this->getUsersGroupId()]
        ];
    }

    private function addDiscount(): void
    {
        $codeCoupon = $this->getCurrentCodeCoupon();
        if (DiscountCouponsManager::isExist($codeCoupon)) {
            return;
        }
        $connectionDB = Application::getConnection();
        $connectionDB->startTransaction();
        try {
            $newDiscountId = \CSaleDiscount::Add($this->getAddDiscountParams());
            if (
                is_int($newDiscountId)
                && $newDiscountId > 0
            ) {
                $resultDiscountCouponAdd = DiscountCouponTable::add(array(
                    'DISCOUNT_ID' => $newDiscountId,
                    'ACTIVE' => 'Y',
                    'TYPE' => DiscountCouponTable::TYPE_MULTI_ORDER,
                    'COUPON' => $codeCoupon,
                ));
                if ($resultDiscountCouponAdd->isSuccess()) {
                    $resDiscountCouponId = $resultDiscountCouponAdd->getId();
                    if (is_int($resDiscountCouponId) && $resDiscountCouponId > 0) {
                        $connectionDB->commitTransaction();
                        return;
                    }
                }
            }
        } catch (\Exception $e) {
        }
        $connectionDB->rollbackTransaction();
    }

    private function processingCoupon(string $medXmlId, string $codeCoupon, int $salePercent): void
    {
        $discountsFormFeed = $this->getDiscountsFromFeed();
        if (!isset($discountsFormFeed[$codeCoupon][$medXmlId])) {
            $discountsFormFeed[$codeCoupon][$medXmlId] = $salePercent;
            $this->setDiscountsFromFeed($discountsFormFeed);
        }
    }

    private function getPromoDiscountsPull(): array
    {
        if ($this->promoDiscountsPull !== null) {
            return $this->promoDiscountsPull;
        }
        $discounts = DiscountTable::getList([
            'select' => [
                'ID',
                'XML_ID',
                'ACTIVE',
            ],
            'filter' => [
                '=%XML_ID' => static::DISCOUNT_XML_ID_PREFIX . '%',
            ],
        ]);
        $result = [];
        while ($discountItem = $discounts->fetch()) {
            $result[explode('_', $discountItem['XML_ID'])[1]] = $discountItem;
        }
        $this->promoDiscountsPull = $result;
        return $this->promoDiscountsPull;
    }

    private function deactivateCoupons(): void
    {
        $idsForDeactivate = [];
        $discountsPull = $this->getPromoDiscountsPull();
        foreach ($discountsPull as $discountValue) {
            if ($discountValue['ACTIVE'] === 'Y') {
                $idsForDeactivate[] = $discountValue['ID'];
            }
        }
        if ($idsForDeactivate) {
            DiscountTable::updateMulti($idsForDeactivate, [
                'ACTIVE' => 'N',
            ]);
            $idsCouponForDeactivate = $this->getCouponIdsByDiscounts($idsForDeactivate);
            if ($idsCouponForDeactivate) {
                DiscountCouponTable::updateMulti($idsCouponForDeactivate, [
                    'ACTIVE' => 'N',
                ]);
            }
        }
    }

    private function activateCoupons(): void
    {
        $idsForActivate = [];
        $discountsForActivate = $this->getDiscountsForActivate();
        foreach ($discountsForActivate as $discountForActivate) {
            if ($discountForActivate['ACTIVE'] === 'N') {
                $idsForActivate[] = $discountForActivate['ID'];
            }
        }
        if ($idsForActivate) {
            DiscountTable::updateMulti($idsForActivate, [
                'ACTIVE' => 'Y',
            ]);
            $idsCouponForActivate = $this->getCouponIdsByDiscounts($idsForActivate);
            if ($idsCouponForActivate) {
                DiscountCouponTable::updateMulti($idsCouponForActivate, [
                    'ACTIVE' => 'Y',
                ]);
            }
        }
    }

    private function getCouponIdsByDiscounts(array $idsDiscount): array
    {
        $couponIds = DiscountCouponTable::getList([
            'select' => [
                'ID',
            ],
            'filter' => [
                '@DISCOUNT_ID' => $idsDiscount,
            ],
        ]);
        $ids = [];
        while ($couponItem = $couponIds->fetch()) {
            $ids[] = $couponItem['ID'];
        }
        return $ids;
    }

    private function getUsersGroupId(): int
    {
        return $this->usersGroupId;
    }

    private function setPromoDiscountsPull(array $discountsPull): void
    {
        $this->promoDiscountsPull = $discountsPull;
    }

    private function getDiscountsForActivate(): array
    {
        return $this->discountsForActivate;
    }

    private function setDiscountsForActivate(array $discountsForActivate): void
    {
        $this->discountsForActivate = $discountsForActivate;
    }

    private function getDiscountsFromFeed(): array
    {
        return $this->discountsFromFeed;
    }

    private function setDiscountsFromFeed(array $discountsFormFeed): void
    {
        $this->discountsFromFeed = $discountsFormFeed;
    }

    private function getCurrentCodeCoupon(): string
    {
        return $this->currentCodeCoupon;
    }

    private function setCurrentCodeCoupon(string $currentCodeCoupon): void
    {
        $this->currentCodeCoupon = $currentCodeCoupon;
    }

    private function getCurrentDiscountArray(): array
    {
        return $this->currentDiscountArray;
    }

    private function setCurrentDiscountArray(array $currentDiscountArray): void
    {
        $this->currentDiscountArray = $currentDiscountArray;
    }

    private function getSiteId(): string
    {
        return $this->siteId;
    }
}
