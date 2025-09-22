<?php
namespace App\Http\Controllers;

   use GuzzleHttp\Client;
   use Illuminate\Http\Request;
   use Illuminate\Support\Facades\Log;

   class ProductSyncController extends Controller
   {
       protected $client;
       protected $shopifyApiKey;
       protected $shopifyPassword;
       protected $shopifyShopUrl;
       protected $aliExpressApiKey;

       public function __construct()
       {
           $this->client = new Client();
           $this->shopifyApiKey = env('SHOPIFY_API_KEY');
           $this->shopifyPassword = env('SHOPIFY_API_SECRET');
           $this->shopifyShopUrl = env('SHOPIFY_SHOP_URL');
           $this->aliExpressApiKey = env('ALIEXPRESS_API_KEY');
       }

       public function syncProducts()
       {
           try {
               // Fetch AliExpress products
               $response = $this->client->get('https://api.aliexpress.com/products', [
                   'query' => [
                       'api_key' => $this->aliExpressApiKey,
                       'page' => 1,
                       'limit' => 50
                   ]
               ]);
               $aliProducts = json_decode($response->getBody(), true)['products'] ?? [];

               foreach ($aliProducts as $product) {
                   $shopifyProduct = [
                       'title' => $product['name'] ?? 'Unnamed Product',
                       'body_html' => $product['description'] ?? '',
                       'vendor' => 'AliExpress',
                       'product_type' => $product['category'] ?? 'General',
                       'price' => ($product['price'] ?? 0) * 1.3, // 30% margin
                       'stock' => $product['stock'] ?? 0,
                       'image' => $product['image_url'] ?? '',
                       'sku' => $product['sku'] ?? uniqid('ali_')
                   ];

                   // Check if product exists in Shopify
                   $existing = $this->getProductBySku($shopifyProduct['sku']);
                   if ($existing) {
                       $this->updateProduct($existing['id'], [
                           'title' => $shopifyProduct['title'],
                           'variants' => [
                               [
                                   'price' => $shopifyProduct['price'],
                                   'inventory_quantity' => $shopifyProduct['stock'],
                                   'sku' => $shopifyProduct['sku']
                               ]
                           ]
                       ]);
                   } else {
                       $this->createProduct($shopifyProduct);
                   }
               }
               Log::info('Product sync completed successfully');
               return response()->json(['message' => 'Products synced successfully']);
           } catch (\Exception $e) {
               Log::error('Product sync failed: ' . $e->getMessage());
               return response()->json(['error' => 'Sync failed'], 500);
           }
       }

       protected function createProduct($productData)
       {
           try {
               $response = $this->client->post("https://{$this->shopifyShopUrl}/admin/api/2023-01/products.json", [
                   'auth' => [$this->shopifyApiKey, $this->shopifyPassword],
                   'json' => [
                       'product' => [
                           'title' => $productData['title'],
                           'body_html' => $productData['body_html'],
                           'vendor' => $productData['vendor'],
                           'product_type' => $productData['product_type'],
                           'variants' => [
                               [
                                   'price' => $productData['price'],
                                   'inventory_quantity' => $productData['stock'],
                                   'sku' => $productData['sku']
                               ]
                           ],
                           'images' => [['src' => $productData['image']]]
                       ]
                   ]
               ]);
               return json_decode($response->getBody(), true);
           } catch (\Exception $e) {
               Log::error('Shopify createProduct failed: ' . $e->getMessage());
               return [];
           }
       }

       protected function updateProduct($productId, $data)
       {
           try {
               $response = $this->client->put("https://{$this->shopifyShopUrl}/admin/api/2023-01/products/{$productId}.json", [
                   'auth' => [$this->shopifyApiKey, $this->shopifyPassword],
                   'json' => ['product' => $data]
               ]);
               return json_decode($response->getBody(), true);
           } catch (\Exception $e) {
               Log::error('Shopify updateProduct failed: ' . $e->getMessage());
               return [];
           }
       }

       protected function getProductBySku($sku)
       {
           try {
               $response = $this->client->get("https://{$this->shopifyShopUrl}/admin/api/2023-01/products.json", [
                   'auth' => [$this->shopifyApiKey, $this->shopifyPassword],
                   'query' => ['fields' => 'id,variants']
               ]);
               $products = json_decode($response->getBody(), true)['products'] ?? [];
               return collect($products)->firstWhere('variants.0.sku', $sku);
           } catch (\Exception $e) {
               Log::error('Shopify getProductBySku failed: ' . $e->getMessage());
               return null;
           }
       }
   }