<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\PageCache;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\App\State;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQlAbstract;

/**
 * Class CacheTagTest
 */
/**
 * Test the caching works properly for products and categories
 */
class CacheTagTest extends GraphQlAbstract
{
    /**
     * Tests if Magento cache tags and debug headers for products are generated properly
     * @magentoApiDataFixture Magento/Catalog/_files/multiple_products.php
     */
    public function testCacheTagsAndCacheDebugHeaderForProducts()
    {
        $this->markTestSkipped(
            'This test will stay skipped until DEVOPS-4924 is resolved'
        );
        /** @var State $state */
        $state = Bootstrap::getObjectManager()->get(State::class);
        $state->setMode(State::MODE_DEVELOPER);

        $productSku='simple2';
        $query
            = <<<QUERY
 {
           products(filter: {sku: {eq: "{$productSku}"}})
           {
               items {
                   id
                   name
                   sku
               }
           }
       }
QUERY;

        /** cache-debug should be a MISS when product is queried for first time */
        $responseMissHeaders = $this->graphQlQueryForHttpHeaders($query, [], '', []);
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseMissHeaders, $matchesMiss);
        $this->assertEquals('MISS', rtrim($matchesMiss[1], "\r"));

        /** cache-debug should be a HIT for the second round */
        $responseHitHeaders = $this->graphQlQueryForHttpHeaders($query, [], '', []);
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseHitHeaders, $matchesHit);
        $this->assertEquals('HIT', rtrim($matchesHit[1], "\r"));

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
        /** @var Product $product */
        $product =$productRepository->get($productSku, false, null, true);
        /** update the price attribute for the product in test */
        $product->setPrice(15);
        $product->save();
        /** Cache invalidation happens and cache-debug header value is a MISS after product update */
        $responseMissHeaders = $this->graphQlQueryForHttpHeaders($query, [], '', []);
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseMissHeaders, $matchesMiss);
        $this->assertEquals('MISS', rtrim($matchesMiss[1], "\r"));

        /** checks if cache tags for products are correctly displayed in the response header */
        preg_match('/X-Magento-Tags: (.*?)\n/', $responseMissHeaders, $headerCacheTags);
        $actualCacheTags = explode(',', rtrim($headerCacheTags[1], "\r"));
        $expectedCacheTags=['cat_p','cat_p_' . $product->getId(),'FPC'];
        foreach (array_keys($actualCacheTags) as $key) {
            $this->assertEquals($expectedCacheTags[$key], $actualCacheTags[$key]);
        }
    }

    /**
     * Tests if Magento cache tags for categories are generated properly. Also tests the use case for cache invalidation
     *
     * @magentoApiDataFixture Magento/Catalog/_files/categories.php
     */
    public function testCacheTagFromResponseHeaderForCategoriesWithProduct()
    {
        /*$this->markTestSkipped(
            'This test will stay skipped until DEVOPS-4924 is resolved'
        );*/
        $firstProductSku = 'simple-4';
        $secondProductSku = 'simple-5';
        $categoryId ='10';
        $categoryQuery
            = <<<'QUERY'
query GetCategoryQuery($id: Int!, $pageSize: Int!, $currentPage: Int!) {
        category(id: $id) {
            id
            description
            name
            product_count
            products(pageSize: $pageSize, currentPage: $currentPage) {
                items {
                    id
                    name
                    url_key
                }
                total_count
            }
        }
    }
QUERY;
        $variables =[
            'id' => 10,
            'pageSize'=> 10,
            'currentPage' => 1
        ];

        $product1Query
            = <<<QUERY
       {
           products(filter: {sku: {eq: "{$firstProductSku}"}})
           {
               items {
                   id
                   name
                   sku
               }
           }
       }
QUERY;
        $product2Query
            = <<<QUERY
       {
           products(filter: {sku: {eq: "{$secondProductSku}"}})
           {
               items {
                   id
                   name
                   sku
               }
           }
       }
QUERY;

        $responseMissHeaders = $this->graphQlQueryForHttpHeaders($categoryQuery, $variables, '', []);

        /** cache-debug header value should be a MISS when category is loaded first time */
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseMissHeaders, $matchesMiss);
        $this->assertEquals('MISS', rtrim($matchesMiss[1], "\r"));

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
        /** @var Product $firstProduct */
        $firstProduct = $productRepository->get($firstProductSku, false, null, true);
        /** @var Product $secondProduct */
        $secondProduct = $productRepository->get($secondProductSku, false, null, true);

        /** checks to see if the X-Magento-Tags for category is displayed correctly */
        preg_match('/X-Magento-Tags: (.*?)\n/', $responseMissHeaders, $headerCacheTags);
        $actualCacheTags = explode(',', rtrim($headerCacheTags[1], "\r"));
        $expectedCacheTags =
            ['cat_c','cat_c_' . $categoryId,'cat_p','cat_p_' . $firstProduct->getId(),'cat_p_' .$secondProduct->getId(),'FPC'];
        $this->assertEquals($expectedCacheTags, $actualCacheTags);
        // Cach-debug header should be a MISS for product 1 during first load
        $responseHeadersFirstProduct = $this->graphQlQueryForHttpHeaders($product1Query, [], '', []);
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseHeadersFirstProduct, $match);
        $this->assertEquals('MISS', rtrim($match[1], "\r"));

        // Cach-debug header should be a MISS for product 2 during first load
        $responseHeadersSecondProduct = $this->graphQlQueryForHttpHeaders($product2Query, [], '', []);
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseHeadersSecondProduct, $match);
        $this->assertEquals('MISS', rtrim($match[1], "\r"));

        /** cache-debug header value should be MISS after  updating product1 and reloading the category */
        $firstProduct->setPrice(20);
        $firstProduct->save();
        $responseMissHeaders = $this->graphQlQueryForHttpHeaders($categoryQuery, $variables, '', []);
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseMissHeaders, $matchesMiss);
        $this->assertEquals('MISS', rtrim($matchesMiss[1], "\r"));

        /** cache-debug should be a MISS for product 1 after it is updated - cache invalidation */
        $responseHeadersForProd1 = $this->graphQlQueryForHttpHeaders($product1Query, [], '', []);
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseHeadersForProd1, $match);
        $this->assertEquals('MISS', rtrim($match[1], "\r"));

        // Cach-debug header should be a HIT for prod 2 during second load since prod 2 should be fetched from cache
        $responseHeadersSecondProduct = $this->graphQlQueryForHttpHeaders($product2Query, [], '', []);
        preg_match('/X-Magento-Cache-Debug: (.*?)\n/', $responseHeadersSecondProduct, $match);
        $this->assertEquals('HIT', rtrim($match[1], "\r"));
    }
}
