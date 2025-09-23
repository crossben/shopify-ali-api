<?php
namespace App\Http\Controllers;

use App\Jobs\ProcessOrderJob;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderFulfillmentController extends Controller
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

    public function processOrders()
    {
        try {
            $orders = $this->getOrders();
            foreach ($orders as $order) {
                dispatch(new ProcessOrderJob($order, $this));
            }
            return response()->json(['message' => 'Orders queued for processing']);
        } catch (\Exception $e) {
            Log::error('Order processing failed: ' . $e->getMessage());
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    public function handleWebhook(Request $request)
    {
        try {
            $hmac = $request->header('X-Shopify-Hmac-Sha256');
            $data = $request->getContent();
            $calculatedHmac = base64_encode(hash_hmac('sha256', $data, env('SHOPIFY_API_SECRET'), true));
            if (!hash_equals($hmac, $calculatedHmac)) {
                Log::error('Invalid Shopify webhook HMAC');
                return response()->json(['error' => 'Invalid webhook'], 401);
            }

            $order = $request->all();
            dispatch(new ProcessOrderJob($order, $this));
            Http::post('https://n8n.debcouture.com/webhook-test/order-created', $order);
            return response()->json(['status' => 'received']);
        } catch (\Exception $e) {
            Log::error('Webhook handling failed: ' . $e->getMessage());
            return response()->json(['error' => 'Webhook failed'], 500);
        }
    }

    public function getOrders($params = ['status' => 'open'])
    {
        try {
            $response = $this->client->get("https://{$this->shopifyShopUrl}/admin/api/2023-01/orders.json", [
                'auth' => [$this->shopifyApiKey, $this->shopifyPassword],
                'query' => $params
            ]);
            return json_decode($response->getBody(), true)['orders'] ?? [];
        } catch (\Exception $e) {
            Log::error('Shopify getOrders failed: ' . $e->getMessage());
            return [];
        }
    }

    public function updateOrderStatus($orderId, $status)
    {
        try {
            $response = $this->client->put("https://{$this->shopifyShopUrl}/admin/api/2023-01/orders/{$orderId}.json", [
                'auth' => [$this->shopifyApiKey, $this->shopifyPassword],
                'json' => [
                    'order' => ['id' => $orderId, 'fulfillment_status' => $status]
                ]
            ]);
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('Shopify updateOrderStatus failed: ' . $e->getMessage());
            return [];
        }
    }

    public function placeOrder($orderDetails)
    {
        try {
            $response = $this->client->post('https://api.aliexpress.com/order', [
                'json' => [
                    'api_key' => $this->aliExpressApiKey,
                    'order_details' => $orderDetails
                ]
            ]);
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('AliExpress placeOrder failed: ' . $e->getMessage());
            return ['status' => 'error'];
        }
    }

    public function getTrackingInfo($orderId)
    {
        try {
            $response = $this->client->get("https://api.aliexpress.com/tracking/{$orderId}", [
                'query' => ['api_key' => $this->aliExpressApiKey]
            ]);
            return json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            Log::error('AliExpress getTrackingInfo failed: ' . $e->getMessage());
            return [];
        }
    }
}