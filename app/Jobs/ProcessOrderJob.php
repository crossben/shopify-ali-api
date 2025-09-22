<?php

namespace App\Jobs;

use App\Http\Controllers\OrderFulfillmentController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessOrderJob implements ShouldQueue
{
    use Queueable;

    protected $order;
    protected $controller;

    public function __construct($order, OrderFulfillmentController $controller)
    {
        $this->order = $order;
        $this->controller = $controller;
    }

    public function handle()
    {
        try {
            $orderDetails = [
                'product_id' => $this->order['line_items'][0]['product_id'],
                'quantity' => $this->order['line_items'][0]['quantity'],
                'customer_name' => $this->order['customer']['first_name'] . ' ' . $this->order['customer']['last_name'],
                'address' => $this->order['shipping_address']
            ];

            $aliOrder = $this->controller->placeOrder($orderDetails);
            if ($aliOrder['status'] === 'success') {
                $this->controller->updateOrderStatus($this->order['id'], 'fulfilled');
                $tracking = $this->controller->getTrackingInfo($aliOrder['order_id']);
                $this->controller->updateOrderStatus($this->order['id'], 'shipped');
                Http::post('https://your-n8n-domain.com/webhook/notify-customer', [
                    'order_id' => $this->order['id'],
                    'tracking' => $tracking,
                    'customer' => $this->order['customer']
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Order job failed: ' . $e->getMessage());
            throw $e;
        }
    }
}