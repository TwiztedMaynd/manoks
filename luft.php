<?php

require 'vendor/autoload.php'; // Ensure Composer autoload is included

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Cookie\CookieJar;

class CurlHelper
{
    private $client;
    private $cookieJar;

    public function __construct()
    {
        $this->cookieJar = new CookieJar(); // Create a new CookieJar instance

        $this->client = new Client([
            'timeout' => 20,
            'verify' => false,
            'cookies' => $this->cookieJar, // Pass the CookieJar instance
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36'
            ]
        ]);
    }

    public function get($url)
    {
        try {
            $response = $this->client->get($url);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception('cURL error: ' . $e->getMessage());
        }
    }

    public function post($url, $data)
    {
        try {
            $response = $this->client->post($url, [
                'form_params' => $data
            ]);
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            throw new Exception('cURL error: ' . $e->getMessage());
        }
    }

    public function deleteCookieFile()
    {
        // No need for this method anymore as we're using CookieJar
    }
}


class Scraper
{
    private $curlHelper;

    public function __construct()
    {
        $this->curlHelper = new CurlHelper();
    }

    public function extractProductIDs($html)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $productIDs = [];

        $queries = [
            '//a[contains(@href, "add-to-cart=")]/@href',
            '//input[@name="add-to-cart" or @name="product_id"]/@value',
            '//*[@data-product_id or @data-product-id]/@data-product_id | //*[@data-product_id or @data-product-id]/@data-product-id',
            '//form[contains(@action, "add-to-cart=")]/@action',
            '//script[@type="application/ld+json"]'
        ];

        foreach ($queries as $query) {
            foreach ($xpath->query($query) as $node) {
                if ($query === '//script[@type="application/ld+json"]') {
                    $data = json_decode(trim($node->textContent), true);
                    if (isset($data['@type']) && $data['@type'] === 'Product' && isset($data['sku'])) {
                        $productIDs[] = $data['sku'];
                        if (count($productIDs) > 0) {
                            return array_unique($productIDs);
                        }
                    }
                } elseif (preg_match('/add-to-cart=(\d+)/', $node->nodeValue, $matches)) {
                    $productIDs[] = $matches[1];
                    if (count($productIDs) > 0) {
                        return array_unique($productIDs);
                    }
                } else {
                    $productIDs[] = trim($node->nodeValue);
                    if (count($productIDs) > 0) {
                        return array_unique($productIDs);
                    }
                }
            }
        }

        return array_unique(array_filter($productIDs));
    }

    public function extractPaymentMethods($html)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $queries = [
            '//*[@id="payment"]//ul[contains(@class, "wc_payment_methods")]//input[@type="radio"]/@value',
            '//*[@id="payment"]//input[@type="radio" and @name="payment_method"]/@value',
            '//*[contains(@id, "payment")]//input[@type="radio" and @name="payment_method"]/@value',
            '//input[@type="radio" and @name="payment_method"]/@value',

            '//*[@id="payment"]//input[contains(@name, "payment") and @type="radio"]/@value',
            '//*[contains(@id, "payment")]//input[contains(@name, "payment") and @type="radio"]/@value',
            '//input[contains(@name, "payment") and @type="radio"]/@value',
            '//*[contains(@id, "payment")]//input[contains(@name, "method") and @type="radio"]/@value',

            '//*[contains(@class, "wc_payment_methods")]//input[@type="radio" and contains(@name, "payment")]/@value',
            '//*[@id="payment"]//div[contains(@class, "payment_method")]//input[@type="radio"]/@value',
            '//*[contains(@class, "woocommerce-checkout-payment")]//input[@type="radio" and @name]/@value',
            '//*[contains(@class, "woocommerce-payment-methods")]//input[@type="radio" and @name]/@value',

            '//input[@type="radio" and contains(@id, "payment_method")]/@value',
            '//input[@type="radio" and contains(@name, "payment")]/@value',
            '//input[@type="radio" and contains(@class, "payment")]/@value',

        ];

        $methods = [];
        foreach ($queries as $query) {
            foreach ($xpath->query($query) as $method) {
                $value = trim($method->nodeValue);
                if ($value && !in_array($value, ['new', 'true'])) {
                    $methods[] = $value;
                }
            }
        }

        return array_values(array_unique($methods));
    }

    public function detectCaptcha($html)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $captchaIndicators = [
            '//iframe[contains(@src, "recaptcha")]',
            '//div[contains(@class, "g-recaptcha")]',
            '//div[contains(@class, "h-captcha")]',
            '//script[contains(@src, "recaptcha")]',
            '//script[contains(@src, "hcaptcha")]',
            '//noscript[contains(text(), "captcha")]',
            '//input[@name="g-recaptcha-response"]',
            '//input[@name="h-captcha-response"]',
            '//div[@id="px-captcha"]',
            '//div[contains(@class, "captcha")]',
            '//input[contains(@id, "captcha")]',
            '//div[contains(@class, "cf-captcha-container")]',
            '//input[@type="hidden" and @name="cf-turnstile-response"]',
            '//input[@type="hidden" and @name="captcha"]',
        ];

        foreach ($captchaIndicators as $indicator) {
            if ($xpath->query($indicator)->length > 0) {
                return true;
            }
        }

        return false;
    }

    public function addProductToCart($initialUrl, $fullUrl, $productID)
    {
        $ajaxUrl = "$fullUrl/?wc-ajax=add_to_cart";
        $postData = ['product_id' => $productID, 'quantity' => 1];

        $response = $this->curlHelper->post($ajaxUrl, $postData);

        if ($response === false || strpos($response, 'cart') === false) {
            $standardUrl = "$initialUrl/?add-to-cart=$productID";
            $postData = ['add-to-cart' => $productID];

            $response = $this->curlHelper->post($standardUrl, $postData);
        }

        return $response;
    }

    public function getCheckoutPage($checkoutUrl)
    {
        $html = $this->curlHelper->get($checkoutUrl);

        if ($html === false) {
            return false;
        }

        return $html;
    }

    public function scrape($checkUrl)
    {
        $parsedUrl = parse_url($checkUrl);
        $fullUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        $initialUrl = rtrim($checkUrl, '/');

        $html = $this->curlHelper->get($checkUrl);

        if ($html === false) {
            return json_encode(['error' => 'Bad site']);
        }

        $this->curlHelper->deleteCookieFile();

        $response = [];
        $response['captcha'] = $this->detectCaptcha($html) ? 'yes' : 'no';

        $productIDs = $this->extractProductIDs($html);
        $response['productid'] = $productIDs;

        if (empty($productIDs)) {
            $pagesToTry = [
                "$fullUrl/shop/", "$fullUrl/product-category/", "$fullUrl/category/", "$fullUrl/products/",
                "$fullUrl/store/", "$fullUrl/collections/", "$fullUrl/items/", "$fullUrl/catalog/",
                "$fullUrl/products-page/", "$fullUrl/product/", "$fullUrl/our-products/", "$fullUrl/shop-all/",
                "$fullUrl/shop-by-category/", "$fullUrl/all-products/", "$fullUrl/product-list/", "$fullUrl/sale/",
                "$fullUrl/new-arrivals/", "$fullUrl/top-rated/", "$fullUrl/best-sellers/", "$fullUrl/featured/",
                "$fullUrl/brands/", "$fullUrl/vendors/", "$fullUrl/promotions/", "$fullUrl/deals/",
                "$fullUrl/discounts/", "$fullUrl/offers/", "$fullUrl/collections/all/", "$fullUrl/our-range/",
                "$fullUrl/exclusive/", "$fullUrl/seasonal/", "$fullUrl/limited-edition/", "$fullUrl/special-edition/",
                "$fullUrl/catalogue/", "$fullUrl/shop-now/", "$fullUrl/shop-by-brand/", "$fullUrl/shop-by-type/",
                "$fullUrl/shop-by-price/", "$fullUrl/clearance/", "$fullUrl/outlet/", "$fullUrl/promo-items/"
            ];

            foreach ($pagesToTry as $pageUrl) {
                $html = $this->curlHelper->get($pageUrl);

                $productIDs = $this->extractProductIDs($html);
                if (!empty($productIDs)) {
                    $response['productid'] = $productIDs;
                    break;
                }

                $this->curlHelper->deleteCookieFile();
            }
        }

        if (!empty($productIDs)) {
            $productID = reset($productIDs);
            $cartResponse = $this->addProductToCart($initialUrl, $fullUrl, $productID);

            if ($cartResponse !== false) {
                $checkoutUrl = "$fullUrl/checkout/";
                $checkoutPage = $this->getCheckoutPage($checkoutUrl);

                if ($checkoutPage !== false) {
                    $response['captcha'] = $this->detectCaptcha($checkoutPage) ? 'yes' : 'no';
                    $paymentMethods = $this->extractPaymentMethods($checkoutPage);
                    $response['paymentmethod'] = $paymentMethods;
                }
            }
        }

        return json_encode($response);
    }
}

$checkUrl = isset($_GET['check']) ? $_GET['check'] : die('No check URL provided');

$scraper = new Scraper();
$response = $scraper->scrape($checkUrl);

header('Content-Type: application/json');
echo $response;

?>