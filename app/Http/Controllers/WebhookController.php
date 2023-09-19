<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentLog;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function midtransHandler(Request $request) {
        // ambil semua data body dari midtrans
        $data = $request->all();

        // ambil signature key nya
        $signatureKey = $data['signature_key'];

        // ambil data untuk keperluan verifikasi
        $orderId = $data['order_id'];
        $statusCode = $data['status_code'];
        $grossAmount = $data['gross_amount'];
        $serverKey = env('MIDTRANS_SERVER_KEY');

        // buat signature key untuk verifikasi
        $mySignatureKey = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

        // ambil detail transaksi
        $transactionStatus = $data['transaction_status'];
        $type = $data['payment_type'];
        $fraudStatus = $data['fraud_status'];

        // cek apakah signature key valid
        if($signatureKey !== $mySignatureKey) {
            return response()->json([
                'status' => 'error',
                'message' => 'invalid signature'
            ], 400);
        }

        // cek apakah order id valid
        $realOrderId = explode('-', $orderId);

        // cek di database
        $order = Order::find($realOrderId[0]);

        if(!$order) {
            return response()->json([
                'status' => 'error',
                'message' => 'Order ID NOT Found!'
            ], 404);
        }

        // cek apakah order sebelumnya sudah success apa belum
        if($order->status === 'success') {
            // jika sudah success maka tidak boleh ubah status lagi
            return response()->json([
                'status' => 'error',
                'message' => 'operation not permitted!'
            ], 405);
        }

        // handling order status
        if ($transactionStatus == 'capture'){
            if ($fraudStatus == 'challenge'){
                // TODO set transaction status on your database to 'challege'
                // and response with 200 OK
                $order->status = 'challenge';
            } else if($fraudStatus == 'accept') {
                // TODO set transaction status on your database to 'success'
                // and response with 200 OK
                $order->status = 'success';
            }
        } else if ($transactionStatus == 'settlement'){
            // TODO set transaction status on your database to 'success'
            // and response with 200 OK
            $order->status = 'success';
        } else if ($transactionStatus == 'cancel' ||
            $transactionStatus == 'deny' ||
            $transactionStatus == 'expire'){
            // TODO set transaction status on your database to 'failure'
            // and response with 200 OK
            $order->status = 'failure';
        } else if ($transactionStatus == 'pending'){
            // TODO set transaction status on your database to 'pending' / waiting payment
            // and response with 200 OK
            $order->status = 'pending';
        }

        // simpan data ke table payment logs
        $logDataPayment = [
            'status' => $transactionStatus,
            'raw_response' => json_encode($data),
            'order_id' => $realOrderId[0],
            'payment_type' => $type
        ];

        // create data payment log
        PaymentLog::create($logDataPayment);

        // save perubahan status pada table order
        $order->save();

        // jika order success kasih akses premium ke user
        if($order->status === 'success') {
            // call helpers
            createPremiumAccess([
                'user_id' => $order->user_id,
                'course_id' => $order->course_id
            ]);
        }

        return response()->json('ok');
    }
}
