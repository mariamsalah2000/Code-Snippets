<?php

namespace App\Http\Controllers;

use Excel;
use Exception;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Pickup;
use App\Models\Refund;
use App\Models\Prepare;
use App\Models\Employee;
use App\Models\BlackList;
use App\Models\Warehouse;
use App\Models\ShippingFee;
use App\Models\UserHistory;
use App\Imports\StockImport;
use App\Models\CashRegister;
use App\Models\OrderHistory;
use App\Models\PendingOrder;
use App\Models\ReturnDetail;
use App\Models\ReturnPickup;
use App\Traits\RequestTrait;
use Illuminate\Http\Request;
use App\Models\BranchVariant;
use App\Models\ResyncedOrder;
use App\Models\ReturnedOrder;
use App\Models\ShortageOrder;
use App\Models\TicketHistory;
use App\Models\CancelledOrder;
use App\Models\ProductVariant;
use App\Imports\ProductsImport;
use App\Models\InventoryDetail;
use App\Models\Order as order2;
use App\Jobs\Shopify\Sync\Order;
use App\Models\InventoryTransfer;
use App\Models\OrderConfirmation;
use App\Jobs\Shopify\Sync\Product;
use App\Models\PrepareProductList;
use App\Models\ReturnsTransaction;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\FulfillOrder;
use App\Jobs\Shopify\Sync\Customer;
use App\Jobs\Shopify\Sync\OneOrder;
use App\Models\Product as Product2;
use App\Models\ShippingTransaction;
use Illuminate\Support\Facades\Log;
use App\Exports\ShippingSheetExport;
use App\Http\Controllers\Controller;
use App\Jobs\Shopify\Sync\Locations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Exports\FailedProductsExport;
use App\Models\Customer as Customer2;
use Illuminate\Support\Facades\Cache;
use App\Models\BranchStockTransaction;
use App\Models\CashRegisterTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Session;
use App\Models\ShippingTransactionDetail;
use Illuminate\Support\Facades\Validator;
use App\Imports\ShippingTransactionImport;
use App\Jobs\Shopify\Sync\WarehouseProduct;
use App\Http\Resources\PosProductCollection;
use App\Jobs\Shopify\Sync\OrderFulfillments;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\WarehouseProduct as WarehouseProduct2;

class ShopifyController extends Controller {

    use RequestTrait;

    public function __construct() {
        $this->middleware('auth');
    }

    public function orders(Request $request) {
        
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        $payment_status = '';
        $prepare_users_list = [];
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $orders = $store->getOrders()->where('channel','online')->where('fulfillment_status', 'processing');
        if ($request->search) {
            $sort_search = $request->search;
            $orders = $orders->where('name', $sort_search)
                ->orWhere('order_number', $sort_search);
        }
        if($date !=null)
        {
            $orders = $orders->whereDate('created_at_date', '=',$date);
        }

        if($request->delivery_status)
        {
            $delivery_status = $request->delivery_status;
            $orders = $orders->where('fulfillment_status', $delivery_status);
        }

        $orders = $orders->orderBy('table_id', 'asc')
                        ->paginate(15)->appends($request->query());


        $prepare_users = User::where('role_id', '5')->get();
        if(count($prepare_users)) {
            foreach ($prepare_users as $key => $prepare) {

                $prepare_users_list['id'][$key] = $prepare->id;
                $prepare_users_list['name'][$key] = $prepare->name;
            }
        }
        return view('orders.index', compact('orders','prepare_users_list','date','sort_search','delivery_status','payment_status'));
    }


    
    public function blacklist(){
        $blacklist = BlackList::all();
        return view('preparation.blacklist' , compact('blacklist'));
    }
    public function blacklist_create(){
        return view('preparation.blacklist-create');
    }
    public function blacklist_store(Request $request){
        $item = new BlackList();
        $item->name = $request->name;
        $item->phone = $request->phone;
        $item->note = $request->note;
        $item->save();
        return redirect()->back()->with('success','Added To BlackList');
    }
    public function blacklist_edit(Request $request , $id){
        $item = BlackList::findOrFail($id);
        return view('preparation.blacklist-edit' , compact('item'));
    }
    public function blacklist_update(Request $request , $id){
        $item = BlackList::findOrFail($id);
        $item->name = $request->name;
        $item->phone = $request->phone;
        $item->note = $request->note;
        $item->save();
        return redirect()->back()->with('success','Updated Successfully');
    }
    public function blacklist_destroy($id){
        $item = BlackList::findOrFail($id);
        $item->delete();
        return redirect()->back()->with('success','Deleted Successfully');
    }


    public function shipping_transactions(Request $request)
    {
        $search = $request->search;
        $sort_by = $request->sort_by;
        $status = $request->status;
        $date = $request->date;

        $transactions = ShippingTransaction::with('details')->when($search,fn($q)=>$q->where('transaction_number','like','%'.$search.'%')->orWhere('shipment_number','like','%'.$search.'%'))->when($status,fn($q)=>$q->where('status','like','%'.$search.'%'))->when($date,fn($q)=>$q->whereDate('created_at',$date))
        ->when($sort_by,function($q)use($sort_by){
            if($sort_by == "success1")
                return $q->orderBy('success_orders','ASC');
            elseif($sort_by == "success2")
                return $q->orderBy('success_orders','DESC');
            elseif($sort_by == "failed1")
                return $q->orderBy('failed_orders','ASC');
            elseif($sort_by == "failed2")
                return $q->orderBy('failed_orders','DESC');
        })->orderBy('created_at','DESC')->paginate(20);
        return view('finance.shipment_transactions')->with(get_defined_vars());
    }

    public function upload_shipping_transaction(Request $request)
    {
        $file = $request->file('sheet');
        $rows = Excel::toCollection(new ShippingTransactionImport, $file)->first();
        
        $fileName = $file->getClientOriginalName();
        $file->move(public_path('uploads'), $fileName);
        $publicPath = asset('uploads/' . $fileName);

        $total_orders = $rows->count();
        $total_cod = $rows->sum('cod');
        $total_shipping = $rows->sum('shipping');
        $total_net = $total_cod - $total_shipping;

        $trx = new ShippingTransaction();
        $trx->shipment_number = $request->shipping_number;
        $trx->transaction_number = "sh-" . rand(10000000, 99999999);
        $trx->total_orders = $total_orders;
        $trx->total_cod = $total_cod;
        $trx->total_shipping = $total_shipping;
        $trx->total_net = $total_net;
        $trx->note = $request->note;
        $trx->sheet = $publicPath;
        $trx->user_id = auth()->id();
        $trx->created_at = now();
        $trx->updated_at = now();
        $trx->save();

        foreach($rows as $key=>$row)
        {
            $row = array_values($row->toArray());
            if (!$row[0])
                continue;
            $row_id = str_replace( 'Lvs','',$row[0]);
            $row_id = str_replace( 'lvs','',$row_id);
            
            $order = order2::where('order_number',$row_id)->orWhere('name',$row_id)->first();
            if(!$order){
                $detail = new ShippingTransactionDetail();
                $detail->transaction_id = $trx->id;
                $detail->order_id = $row[0];
                $detail->cod = $row[8];
                $detail->order_price = $row[8];
                $detail->shipping = $row[9];
                $detail->net = $row[10];
                $detail->order_shipping = $row[9];
                $detail->status = "failed";
                $detail->reason = "Order Not Found";
                $detail->created_at = now();
                $detail->updated_at = now();
                $detail->save();
            }
            else{

                $shipping = $row[9];
                if(isset($order->shipping_lines) && is_array($order->shipping_lines) && isset($order->shipping_lines[0]) && isset($order->shipping_lines[0]['price']) )
                {
                    $shipping = $order->shipping_lines[0]['price'];
                }
                $detail = new ShippingTransactionDetail();
                $detail->transaction_id = $trx->id;
                $detail->order_id = $row[0];
                $detail->cod = $row[8];
                $detail->order_price = $order->total_price;
                $detail->shipping = $row[9];
                $detail->order_shipping = $shipping;
                $detail->net = $row[10];
                $detail->reason = $row[5];
                $detail->created_at = now();
                $detail->updated_at = now();

                if($row[8] == 0)
                {
                    if(is_array($order->payment_gateway_names) && isset($order->payment_gateway_names[0]) && ($order->payment_gateway_names[0]  == "fawrypay (pay by card or at any fawry location)" || $order->payment_gateway_names[0]  == "Paymob"))
                    {
                        $detail->status = "success";
                        $detail->order_status = "delivered";
                        $detail->reason = "Paid By Visa";
                    }
                    else{
                        $returns = ReturnedOrder::where('order_number',$order->order_number)->where('status','Returned')->sum('amount');
                        if($returns == $order->total_price)
                        {
                            $detail->status = "success";
                            $detail->reason = "Returned";
                            $detail->order_status = "returned";
                        }
                        else{
                            $detail->status = "failed";
                            $detail->reason = "Zero COD";
                        }
                    }
                }
                else if($row[8] == $order->total_price)
                {
                    $detail->status = "success";
                    $detail->reason = "Delivered";
                    $detail->order_status = "delivered";
                }
                else{
                    $returns = ReturnedOrder::where('order_number',$order->order_number)->where('status','Returned')->sum('amount');
                    if($returns == ($order->total_price - $row[8]))
                    {
                        $detail->status = "success";
                        $detail->reason = "Returned";
                        $detail->order_status = "returned";
                    }
                    else{
                        $detail->status = "failed";
                        $detail->reason = "Invalid COD";
                    }
                    
                }
                $detail->save();
            }
            
        }

        $trx->success_orders = $trx->details->where('status','success')->count();
        $trx->failed_orders = $trx->details->where('status','failed')->count();
        $trx->save();

        if(auth()->user()->role->name == "Shipping")
            return redirect()->route('shipping_trx.index')->with('success','Shipping Transaction Uploaded Successfully');

        return redirect()->route('shipping_trx.show',$trx->id)->with('success','Shipping Transaction Uploaded Successfully');

    }

    public function show_shipping_transaction($id)
    {
        $trx = ShippingTransaction::findOrFail($id);
        $trx->shipping_status = "in_progress";
        $trx->save();
        $details = $trx->details;
        return view('finance.show_shipment_transaction',compact('details','trx'));

    }

    public function approve_shipping_transaction(Request $request)
    {
        $detail = ShippingTransactionDetail::find($request->id);
        if($detail)
        {
            $transaction = ShippingTransaction::find($detail->transaction_id);
            
            if($detail->transaction->status == "close" || $detail->transaction->status == "closed"){
                $row_id = str_replace( 'Lvs','',$detail->order_id);
                $row_id = str_replace( 'lvs','',$row_id);
                $order = order2::where('order_number',$row_id)->first();
                if($order)
                {
                    if($order->fulfillment_status == "shipped")
                    {
                        if($request->status == "Returned")
                        {
                            $detail->status = "success";
                            $detail->order_status = "returned";
                            $detail->reason = $request->reason;
                            $detail->save();

                            $transaction->success_orders +=1;
                            $transaction->save();
                            return redirect()->back()->with('success','Status Updated Successfully');
                        }
                        $payload = [
                            'transaction' => [
                                'kind' => 'capture', // 'capture' will mark it as paid
                                'amount' => null, // Leaving null will capture the entire amount
                                'currency' => 'EGP', // Use your store's currency
                            ]
                        ];
                        $user = Auth::user();
                        $store = $user->getShopifyStore;
                        $headers = getShopifyHeadersForStore($store, 'POST');
                        $endpoint = getShopifyURLForStore('orders/'.$order->id.'/transactions.json', $store);
                        $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers,$payload);
                        if (isset($response) && isset($response['statusCode']) && $response['statusCode'] === 200) {
                            $detail->status = "success";
                            $detail->order_status = "delivered";
                            $detail->reason = $request->reason;
                            $detail->save();

                            $transaction->success_orders +=1;
                            $transaction->save();
                            return redirect()->back()->with('success','Status Updated Successfully');

                        }
                        else
                        {
                            $reason = "Payment API Failed";
                        }
                    }else{
                        $reason = "Order not Shipped";
                    }
                }
                else{
                    $reason = "Order not Found";
                }
                $detail->reason = $request->reason;
                $detail->save();
                return redirect()->back()->with('error',$reason);

            }
            else{
                $detail->status = "success";
                $detail->order_status = $request->status;
                $detail->reason = $request->reason;
                $detail->save();

                $transaction->success_orders +=1;
                $transaction->save();
                return redirect()->back();
            }
        }
        

        return response()->json(['status' => true, 'message' => '']);
    }

    public function check_shipping_transaction($id)
    {
        $trx = ShippingTransaction::findOrFail($id);
        foreach($trx->details->where('status','failed') as $detail)
        {
            $row_id = str_replace( 'Lvs','',$detail->order_id);
            $row_id = str_replace( 'lvs','',$row_id);
            $order = order2::where('order_number',$row_id)->first();

            $returns = ReturnedOrder::where('order_number',$order->order_number)->where('status','Returned')->sum('amount');
            if($returns > 0) {
                if($returns == $order->total_price || $returns == ($order->total_price - $detail->cod)) {
                    $detail->status = "success";
                    $detail->reason = "Return Received";
                    $detail->order_status = "returned";
                    $detail->save();
                    $trx->success_orders += 1;
                    $trx->save();
                }
                
            }
            elseif($order->total_price == $detail->cod)
            {
                $detail->status = "success";
                $detail->reason = "Delivered";
                $detail->order_status = "delivered";
                $detail->save();
                $trx->success_orders += 1;
                $trx->save();
            }
        }
        return redirect()->back()->with('success','Orders Checked Successfully');
    }

    public function export_success_transaction($id)
    {
        $trx = ShippingTransaction::findOrFail($id);
        return Excel::download(new ShippingSheetExport($trx->details->where('status','success')), 'success_shipments.xlsx');
    }
    public function export_failed_transaction($id)
    {
        $trx = ShippingTransaction::findOrFail($id);
        return Excel::download(new ShippingSheetExport($trx->details->where('status','failed')), 'failed_shipments.xlsx');
    }

    public function submit_shipping_transaction(request $request)
    {
        $transaction = ShippingTransaction::find($request->trx_id);
        if($transaction)
        {
            $details = $transaction->details->where('status','success');
            foreach($details as $detail)
            {
                $row_id = str_replace( 'Lvs','',$detail->order_id);
                $row_id = str_replace( 'lvs','',$row_id);
                $order = order2::where('order_number',$row_id)->first();
                if($order)
                {
                    if($order->fulfillment_status == "shipped" && $detail->order_status == "delivered")
                    {
                        $payload = [
                            'order' => [
                                'id' => $order->id,
                                'financial_status' => 'Paid',  // Mark the order as paid
                            ]
                        ];
                        $user = Auth::user();
                        $store = $user->getShopifyStore;
                        $headers = getShopifyHeadersForStore($store, 'PUT');
                        $endpoint = getShopifyURLForStore('orders/'.$order->id.'.json', $store);
                        $response = $this->makeAnAPICallToShopify('PUT', $endpoint, null, $headers,$payload);
                        
                        if (isset($response) && isset($response['statusCode']) && $response['statusCode'] === 200) {
                            $order->fulfillment_status = "delivered";
                            $prepare = Prepare::where('order_id',$order->id)->first();
                            $prepare->delivery_status = "delivered";
                            $order->save();
                            $prepare->save();

                            $add_History_sale = new OrderHistory();
                            $add_History_sale->order_id = $order->id;
                            $add_History_sale->user_id = Auth::user()->id;
                            $add_History_sale->action = "Delivered";
                            $add_History_sale->created_at = now();
                            $add_History_sale->updated_at = now();
                            $add_History_sale->note = " Order Has Been Delivered By Shipping Company";

                            $add_History_sale->save();
                        }
                        else{
                            dd($response);
                            $detail->status = "failed";
                            $detail->reason = "Shopify API Failed";
                            $detail->save();
                        }
                    }
                    elseif( $detail->order_status == "returned"){
                        $returns = ReturnedOrder::where('order_id',$order->id)->where('status','Returned')->sum('amount');
                        if($returns == $order->total_price)
                        {
                            $order->fulfillment_status = "returned";
                            $order->prepare->delivery_status = "returned";
                            $order->prepare->save();
                            $order->save();
                        }
                        else if($returns == ($order->total_price - $detail->cod)){
                            $order->fulfillment_status = "delivered";
                            $order->prepare->delivery_status = "delivered";
                            $order->prepare->save();
                            $order->save();
                        }
                        else{
                            $detail->status = "failed";
                            $detail->reason = "Invalid COD";
                            $detail->save();
                        }
                    }
                    else{
                        $detail->status = "failed";
                        $detail->reason = "Order is not Shipped";
                        $detail->save();
                    }
                    
                }
                else{
                    $detail->status = "failed";
                    $detail->reason = "Order Not Found";
                    $detail->save();
                }
            }
            $transaction->status = "closed";
            $transaction->shipping_status = "done";
            $transaction->save();
        }
        $transaction->success_orders = $transaction->details->where('status','success')->count();
        $transaction->failed_orders = $transaction->details->where('status','failed')->count();
        $transaction->save();
        return redirect()->route('shipping_trx.index')->with('success','Transaction Closed Successfully');
    }

    public function downloads($id)
    {
        $pickup = Pickup::where('pickup_id',$id)->first();
        if(!$pickup)
        $pickup = ReturnPickup::where('pickup_id',$id)->first();

        $orders = order2::where('pickup_id',$id)->pluck('id')->toArray();
        foreach($orders as $order)
        {
            $history = OrderHistory::where('order_id',$order)->where('action','Picked-Up')->first();
            if($history)
                continue;
            $add_History_sale = new OrderHistory();
            $add_History_sale->order_id = $order;
            $add_History_sale->user_id = Auth::user()->id;
            $add_History_sale->action = "Picked-Up";
            $add_History_sale->created_at = now();
            $add_History_sale->updated_at = now();
            $add_History_sale->note = "Sheet Has Been Downloaded By Shipping Company";

            $add_History_sale->save();
        }
        if(auth()->user()->role->name == "Shipping")
        {
            $pickup->downloaded_at_shipping = now();
            $pickup->save();
            $path = $pickup->file_name;
        }
        elseif(auth()->user()->role->name == "Finance"){
            $pickup->downloaded_at_finance = now();
            $pickup->save();
            $path = $pickup->file_accounting_name;
        }
        else{
            $pickup->downloaded_at_prepare = now();
            $pickup->save();
            $path = $pickup->file_name;
        }
        
        
        return redirect()->away(asset('/download/'.$path));

    }


    

    public function warehouse_products(Request $request)
    {
        $user = Auth::user();
        $warehouse = $user->warehouse_id;
        $products = WarehouseProduct2::where('warehouse_id', $user->warehouse_id)->orderBy("created_at", 'desc')->paginate(20)->appends($request->query());
        return view('products.warehouse', compact('products'));

    }

    public function syncWarehouseProducts()
    {
        try {
            $user = Auth::user();
            $warehouse = $user->warehouse_id;
            $store = $user->getShopifyStore;
            WarehouseProduct::dispatch($user, $store, $warehouse);
            return back()->with('success', 'Product sync successful');
        } catch(Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error :'.$e->getMessage().' '.$e->getLine()]);
        }
    }

    public function limsProductSearch(Request $request)
    {
        $lims_product_data = ProductVariant::where('inventory_quantity', '>', 0);
        if (!$lims_product_data)
            return null;

        if ($request->keyword != null) {
            $product_code = explode(" ",$request['keyword']);
            $lims_product_data = $lims_product_data->where('sku', 'like', '%'.$product_code[0].'%')->orWhereHas('product', function ($q) use($product_code) {
                return $q->where('title', 'like', '%' . $product_code[0] . '%');
            });
        }

        $stocks = new PosProductCollection($lims_product_data->paginate(16));
        $stocks->appends(['keyword' =>  $request->keyword]);
        return $stocks;
    }

    public function get_order_summary(Request $request){
        $data = $request->all();
        if($request->address && !empty($request->address))
        {
            $address=Address::findOrFail((int)$request->address);
            $data['phone']=$address->phone;
            $data['address']=$address->address;
            $data['country'] = Country::find($address->country_id)->name;
            $data['state'] = State::find($address->state_id)->name;
            $data['city'] = City::find($address->city_id)->name;
            $data['postal_code'] = $address->postal_code;
            $data['shipping_fees'] = City::find($address->city_id)->cost;
        }else {
            $user = User::where([['user_type','customer'],['phone',$request->phone]])->first();
            $data['address'] = $user->address;
            $data['country'] = Country::find($user->country)->name;
            $data['state'] = State::find($user->state)->name;
            $data['city'] = City::find($user->city)->name;
            $data['postal_code'] = $user->postal_code;
            $data['phone'] = $user->phone;
            $data['shipping_fees'] = City::find($user->city)->cost;

        }
        $request->session()->put('pos.address', $data);
        return view('pos.order_summary');
    }

    public function store_order(Request $request)
    {
        $coupon = null;
        $discount = 0;
        if($request->coupon_code)
        {
            $coupon = Coupon::where('name',$request->coupon_code)->whereDate('start_date','<=',now()->toDateString())->whereDate('end_date','>=',now()->toDateString())->first();
            if($coupon)
            {
                if ($coupon->type == "percentage")
                    $discount = $coupon->amount / 100 * 1;
                else
                    $discount = $coupon->amount;
            }
            else
                $discount = Session::get('pos.discount',0);
            if (!$discount)
                $discount = 0;
        }
           

        $user = Auth::user();
        $store = $user->getShopifyStore;
        $headers = getShopifyHeadersForStore($store, 'POST');
        $endpoint = getShopifyURLForStore('orders.json', $store);
        $line_items = [];
        $customer = Customer2::where('phone_number', $request->get_customer_sale)->first();
        if(!str_contains($customer->phone_number, '+2'))
        {
            $customer->phone_number = "+2".$customer->phone_number;
            $customer->save();
        }
        
        $data = [];
        $discount_codes = [];
        if($coupon)
        {
            $discount_codes = [
                ['code' => $coupon->name,
                'amount' => $discount,
                'type' => $coupon->type,]
            ];
        }
        $ship = Session::get('pos.shipping', 0);
        if ($request->shipping == "zero")
            $ship = 0;
        foreach($request->session()->get('pos.cart') as $key=>$item)
        {
            $product = ProductVariant::where('sku',$item['stock_id'])->first();
            $line_items[] = [
                'variant_id'=>$product->id,
                'quantity'=>$item['quantity'],
            ] ;
        }
        // $request->source = "Point of Sale";
        $payload['order'] = [
            'line_items' => $line_items,
            'total_tax' =>0.0,
            'currency' => "EGP",
            'source_name' => $request->source,
            'note' => $request->shipping_note,
            'customer' => [
                'id' => $customer->shopify_id,
            ],
            'billing_address' => [
                "first_name" => $customer->name,
                "last_name" => "",
                "name" => $customer->name,
                "address1" => $customer->address,
                "phone" => $customer->phone_number,
                "city" => $customer->city,
                "province" => $customer->state,
                "country" => "Egypt",
                'zip' => "123"
            ],
            'shipping_address' => [
                "first_name" => $customer->name,
                "last_name" => "",
                "name" => $customer->name,
                "address1" => $customer->address,
                "phone" => $customer->phone_number,
                "city" => $customer->city,
                "province" => $customer->state,
                "country" => "Egypt",
                "zip" => "123"
            ],
            'shipping_lines' => [
                [
                    'title' => 'Standard Shipping', // Title of the shipping method
                    'price' => $ship, // Shipping fee in the shop's currency
                    'code' => 'Standard',
                    'source' => 'Custom',
                    'requested_fulfillment_service_id' => null,
                    'delivery_category' => null,
                    'carrier_identifier' => null,
                ]
            ],
            'financial_status' => "pending",
            'fulfillment_status' => "unfulfilled",
            'phone' => $customer->phone_number,
            'email' =>$customer->email,
            "inventory_behavior" => "decrement_ignoring_policy",
            'discount_codes' => $discount_codes
        ];
        $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers,$payload);
        if ($response['statusCode'] === 201 || $response['statusCode'] === 200) {
            if(isset($response['body']['order']))
            {
                $order_id = $response['body']['order']['id'];
                OneOrder::dispatchNow($user,$store,$order_id);
                            $user_id = auth()->user()->id;
                
                $order = order2::find($order_id);
                if (!$order)
                    $order = Sale::find($order_id);
                $order->source_name = $request->source;
                $order->channel = "POS";
                $order->created_by = $user->id;
                $order->prepare_note = $request->prepare_note;
                if(isset($request->transaction_id) || $request->hasFile('trx_img'))
                {
                    if($request->hasFile('trx_img'))
                    {
                        $file = $request->file('trx_img');
                        $fileName = $file->getClientOriginalName();
                        $file->move(public_path('uploads'), $fileName);           
                        // Get the public path to the uploaded file
                        $publicPath = asset('uploads/' . $fileName);
                        $order->transaction_id = $publicPath;
                    }
                    else
                        $order->transaction_id = $request->transaction_id;
                    $order->paid_by = $request->paid_by;
                    $order->fulfillment_status = "Pending";
                }
                $order->save();
                if ($order) {
                    
                    //prepare
                    $add_History_sale = new OrderHistory();
                    $add_History_sale->order_id = $order->id;
                    $add_History_sale->user_id = Auth::user()->id;
                    $add_History_sale->action = "POS";
                    $add_History_sale->created_at = now();
                    $add_History_sale->updated_at = now();
                    $add_History_sale->note = " Order Has Been Created From POS By : <strong>" . auth()->user()->name . "</strong>";

                    $add_History_sale->save();
                }
            }
            return redirect()->back()->with('success', 'Order Created Successfully with Number #'.$order->order_number);
        }
            return redirect()->back()->with('error', 'Something Went Wrong');
                
    } 

    public function update_order(Request $request)
    {
        $coupon = null;
        $user = Auth::user();
        $old = order2::find($request->order_id);
        $store = $user->getShopifyStore;
        $headers = getShopifyHeadersForStore($store, 'POST');
        $endpoint = getShopifyURLForStore('orders.json', $store);
        $line_items = [];
        $customer = Customer2::where('id', $request->customer_id_ajax)->first();
        if(!str_contains($customer->phone_number, '+2'))
        {
            $customer->phone_number = "+2".$customer->phone_number;
            $customer->save();
        }
        

        //dd($customer->phone_number);
        $data = [];
        $discount_codes = [];
        $ship = Session::get('pos.shipping', 0);
        
        if (!$ship || $request->shipping == "zero")
            $ship = 0;
        foreach($request->session()->get('pos.cart') as $key=>$item)
        {
            $product = ProductVariant::where('sku',$item['stock_id'])->first();
            $line_items[] = [
                'variant_id'=>$product->id,
                'quantity'=>$item['quantity'],
            ] ;
        }
        $payload['order'] = [
            'name' => $old->name,
            'order_number' => $old->order_number,
            'line_items' => $line_items,
            'total_tax' =>0.0,
            'currency' => "EGP",
            'source_name' => $old->source_name,
            'note' => $request->shipping_note,
            'customer' => [
                'id' => $customer->shopify_id,
            ],
            'billing_address' => [
                "first_name" => $customer->name,
                "last_name" => "",
                "name" => $customer->name,
                "address1" => $customer->address,
                "phone" => $customer->phone_number,
                "city" => $customer->city,
                "province" => $customer->state,
                "country" => $customer->country,
                'zip' => "123"
            ],
            'shipping_address' => [
                "first_name" => $customer->name,
                "last_name" => "",
                "name" => $customer->name,
                "address1" => $customer->address,
                "phone" => $customer->phone_number,
                "city" => $customer->city,
                "province" => $customer->state,
                "country" => $customer->country,
                "zip" => "123"
            ],
            'shipping_lines' => [
                [
                    'title' => 'Standard Shipping', // Title of the shipping method
                    'price' => $ship, // Shipping fee in the shop's currency
                    'code' => 'Standard',
                    'source' => 'Custom',
                    'requested_fulfillment_service_id' => null,
                    'delivery_category' => null,
                    'carrier_identifier' => null,
                ]
            ],
            'financial_status' => "pending",
            'fulfillment_status' => "unfulfilled",
            'phone' => $customer->phone_number,
            'email' =>$customer->email,
            "inventory_behavior" => "decrement_ignoring_policy",
            'discount_codes' => $discount_codes
        ];

        $payload1 = [
                'reason' => 'OTHER',
                'staffNote' => $request->note
            ];
        $api_endpoint = 'orders/'.$old->id.'/cancel.json';
        $endpoint1 = getShopifyURLForStore($api_endpoint, $store);
        $headers = getShopifyHeadersForStore($store);
        $responseCreate = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
        if ($responseCreate['statusCode'] === 201 || $responseCreate['statusCode'] === 200) {
            if (isset($responseCreate['body']['order'])) {
                if (isset($responseCreate['body']['order'])) {

                    $order_id = $responseCreate['body']['order']['id'];
                    OneOrder::dispatchNow($user, $store, $order_id);
                    $user_id = auth()->user()->id;

                    $order = order2::find($order_id);
                    if (!$order)
                        $order = Sale::find($order_id);
                    $order->source_name = $request->source;
                    $order->channel = "POS";
                    $order->order_number = str_replace('#','',$order->name);
                    $order->created_by = $user->id;
                    $order->prepare_note = $request->prepare_note;
                    if(isset($request->transaction_id) || $request->hasFile('trx_img'))
                    {
                        if($request->hasFile('trx_img'))
                        {
                            $file = $request->file('trx_img');
                            $fileName = $file->getClientOriginalName();
                            $file->move(public_path('uploads'), $fileName);           
                            // Get the public path to the uploaded file
                            $publicPath = asset('uploads/' . $fileName);
                            $order->transaction_id = $publicPath;
                        }
                        else
                            $order->transaction_id = $request->transaction_id;
                        $order->paid_by = $request->paid_by;
                        $order->fulfillment_status = "Pending";
                    }
                    $order->save();
                    if ($order) {

                        $old_history = OrderHistory::where('order_id',$old->id)->get();
                        foreach($old_history as $oldH)
                        {
                            $oldH->order_id = $order->id;
                            $oldH->save();
                        }
                        //prepare
                        $add_History_sale = new OrderHistory();
                        $add_History_sale->order_id = $order->id;
                        $add_History_sale->user_id = Auth::user()->id;
                        $add_History_sale->action = "POS";
                        $add_History_sale->created_at = now();
                        $add_History_sale->updated_at = now();
                        $add_History_sale->note = " Order Has Been Updated From POS By : <strong>" . auth()->user()->name . "</strong>";

                        $add_History_sale->save();
                        if(isset($request->transaction_id))
                        {
                            $add_History_sale = new OrderHistory();
                            $add_History_sale->order_id = $order->id;
                            $add_History_sale->user_id = Auth::user()->id;
                            $add_History_sale->action = "Payment Added";
                            $add_History_sale->created_at = now();
                            $add_History_sale->updated_at = now();
                            $add_History_sale->note = " Order is Marked as Paid From POS By : <strong>" . auth()->user()->name . "</strong>";

                            $add_History_sale->save();
                        }
                    }
                    $responseCancel = $this->makeAnAPICallToShopify('POST', $endpoint1, null, $headers, $payload1);
                    if ($responseCancel['statusCode'] === 201 || $responseCancel['statusCode'] === 200) {
                        $prepare = Prepare::where('order_id', $old->id)->first();
                        if ($prepare) {

                            $products = PrepareProductList::where('order_id', $old->id)->get();
                            foreach ($products as $product) {
                                $product->delete();
                            }
                            $prepare->delete();
                        } 
                        $old->delete();
                        return redirect()->route('sales.all')->with('success', 'Order Updated Successfully');
                    }
                    else{dd($responseCreate);
                        return redirect()->route('sales.all')->with('error',$responseCreate['body']);
                    }

                }
            }
            dd($responseCreate);
        }
        return redirect()->route('sales.all')->with('error', 'Something Went Wrong');
                
    } 


    public function searchCouponCode(Request $request)
    {
        if($request->code)
        {
            $coupon = Coupon::where('name',$request->code)->whereDate('start_date','<=',now()->toDateString())->whereDate('end_date','>=',now()->toDateString())->first();
            if($coupon) {
                Session::put('pos.discount',$coupon->amount);
                return array('success' => 1, 'message' => '', 'view' => view('pos.cart')->render());
            }
            else
            return response()->json(['amount'=>'']);
        }
    }

    public function update_coupon(Request $request)
    {
        $coupon = Coupon::findOrFail($request->coupon_id);
        if($coupon)
        {
            $coupon->name = $request->name;
            $coupon->start_date = $request->start_date;
            $coupon->end_date=$request->end_date;
            $coupon->amount=$request->amount;
            $coupon->save();
        }
        return redirect()->route('coupons');
    }
    public function create_coupon(Request $request)
    {
        $coupon = new Coupon();
        $coupon->name = $request->name;
        $coupon->start_date = $request->start_date;
        $coupon->end_date=$request->end_date;
        $coupon->amount=$request->amount;
        $coupon->save();
        return redirect()->route('coupons');
    }

    public function coupons(Request $request)
    {
        $search = null;
        $coupons = Coupon::orderBy('created_at','DESC');
        if($request->search)
        {
            $search = $request->search;
            $coupons =$coupons->where('name',$search);
        }
        $coupons = $coupons->simplePaginate(15);

        return view('orders.coupons', compact('coupons', 'search'));
    }
    

    public function confirmedReturns(Request $request)
    {
        $search = null;
        $daterange = null;
        $returns = ReturnedOrder::where('status','returned');
        if($request->search){
            $search = $request->search;
            $returns = $returns->where('order_number', $search)->orWhere('return_number', $search);
        }
        if($request->daterange)
        {
            $daterange = $request->daterange;
            $date = explode(' - ', $daterange);
            $startDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[0])->format('Y-m-d');
            $endDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[1])->format('Y-m-d');
            $returns = $returns->whereDate('created_at', '>=' ,$startDate)->whereDate('created_at', '<=' ,$endDate);
        }
        $returns_count = $returns->count();
        $returns = $returns->orderBy('created_at', 'DESC')->paginate(20);
        return view('returns.confirmed',compact('returns','returns_count','daterange','search'));


    }
    public function confirmReturns(Request $request)
    {
        $order_number = $request->order_number;
        $return = ReturnedOrder::where('order_number', $order_number)->orWhere('return_number',$order_number)->first();
        if($return)
        {
            if($return->status == "Returned")
            {
                return redirect()->back()->with('error', 'Return Already Confirmed');
            }
            else
            {
                $details = ReturnDetail::where('return_id', $return->id)->get();
                return view('returns.details', compact('details', 'return'));
            }
        }
        return redirect()->back()->with('error', 'Return Not Found');

    }
    public function postConfirmReturns(Request $request)
    {
        $order_number = $request->order_number;
        $return_id = $request->return_id;
        $return = ReturnedOrder::find($return_id);
        $items = [];
        $return_details = $request->detail_id;
        if(isset($request->all) && $request->all == "all")
        {
            $return_details = $return->details->pluck('id')->toArray();
        }
        if($return)
        {
            
            $user = Auth::user();
            $store = $user->getShopifyStore;
            $api_endpoint = 'graphql.json';
            
            $endpoint = getShopifyURLForStore($api_endpoint, $store);
            $headers = getShopifyHeadersForStore($store);
            
            $payload = [
                'query' => 'mutation closeReturn($id: ID!) {
                                returnClose(id: $id) {
                                    return {
                                        id
                                    }
                                    userErrors {
                                        field
                                        message
                                    }
                                }
                            }',
                'variables' => [
                    'id' => $return->return_id,  // Should be passed as-is, "gid://shopify/Return/5801738532"
                ],
            ];
            $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
            
            if ($response['statusCode'] === 201 || $response['statusCode'] === 200) {
                foreach($return_details as $key=>$id)
                {
                    $detail = ReturnDetail::find($id);
                    if($detail){
                        $status = $request->status[$key];
                        $detail->status = $status;
                        $detail->save();

                        $history = new OrderHistory();
                        $history->order_id = $return->order_id;
                        $history->user_id = Auth::user()->id;
                        $history->action = $status;
                        $history->item = $detail->product->product_name;
                        $history->created_at = now();
                        $history->updated_at = now();
                        $history->note = " A Return Item Status Has Been Changed to: <strong>" .$status."</strong> By : <strong>" . auth()->user()->name ."</strong>";
                        $history->save();
                    }
                }
                
                $not_received = ReturnDetail::where('return_id', $return_id)->where('status', '!=', 'received')->count();
                $order_items = PrepareProductList::where('order_id', $return->order_id)->count();
                $received = ReturnDetail::where('return_id', $return_id)->where('status', 'received')->count();
                if($not_received == 0)
                {
                    $return->status = "Returned";
                    $return->save();
                    $history = new OrderHistory();
                    $history->order_id = $return->order_id;
                    $history->user_id = Auth::user()->id;
                    $history->action = "Returned";
                    $history->created_at = now();
                    $history->updated_at = now();
                    $history->note = " A Return Has Been Confirmed On This Order By : <strong>" . auth()->user()->name ."</strong>";
                    $history->save();
                    if($received == $order_items)
                    {
                        $order = order2::where('order_number',$order_number)->first();
                        $order->fulfillment_status = "returned";
                        $order->save();
                        $prepare = Prepare::where('order_id',$order->id)->first();
                        $prepare->delivery_status = "returned";
                        $prepare->save();

                        $history = new OrderHistory();
                        $history->order_id = $return->order_id;
                        $history->user_id = Auth::user()->id;
                        $history->action = "Returned";
                        $history->created_at = now();
                        $history->updated_at = now();
                        $history->note = "Order Has Been Fully Returned By : <strong>" . auth()->user()->name ."</strong>";
                        $history->save();
                    }
                    else{
                        $order = order2::where('order_number',$order_number)->first();
                        $order->fulfillment_status = "partial_return";
                        $order->save();
                        $order->prepare->delivery_status = "partial_return";
                        $order->prepare->save();
                    }
                    return redirect()->route('returns.confirmed')->with('success', 'Status Updated Successfully');

                }
                else{
                    return redirect()->route('returns.confirmed')->with('success', 'Status Updated Successfully');
                }
            }
            
        }
        return redirect()->route('returns.confirmed')->with('error', 'Return Not Found');

    }

    public function locations(Request $request)
    {
        $warehouses = Warehouse::orderBy("created_at",'DESC');
        $search = null;
        if($request->search)
        {
            $warehouses = $warehouses->where('name','LIKE','%'.$request->search.'%')->orWhere('address1','LIKE','%'.$request->search.'%');
        }
        $warehouses = $warehouses->paginate(15);
        return view('products.locations',compact('warehouses','search'));
    }

    public function hold_products(Request $request)
    {
        if(isset($request->button) && $request->button == "update")
        {
            $non_null_status = array_filter($request->product_status, function($value) {
            return !is_null($value);
            });
            if($non_null_status&&count($non_null_status)>0) {
                return $this->updateProductStatus($non_null_status);
            }
        }
        $date = $request->date;
        $hold_date = null;
        $delivery_status = null;
        $search = null;
        $products = PrepareProductList::whereHas('prepare', function ($q) {
            return $q->where('delivery_status', 'hold');
        })->where('product_status', '!=', 'prepared');

        if($date !=null)
        {
            $products = $products->whereDate('created_at', '=',$date);
        
        }

        if($request->hold_date)
        {
            $hold_date = $request->hold_date;
            $products = $products->whereDate('updated_at', '=',$date);
        }
        if($request->search)
        {
            $search = $request->search;
            $products = $products->where('product_status', $search)->orWhere('product_sku', $search)->orWhere('product_name', $search);
        }

        if($request->delivery_status)
        {
            $delivery_status = $request->delivery_status;
            $products = $products->where('product_status', $delivery_status);
        }
        if(isset($request->button) && $request->button == "export")
        {
            return $this->export_hold_products($products->get());
        }

        $branches = DB::table('branch_variants')
        ->join('branches', 'branch_variants.branch_id', '=', 'branches.id')
        ->orderBy(DB::raw('branches.order'), 'asc')
        ->orderBy(DB::raw('branch_variants.qty'), 'desc')
        ->get();
        $products_count = $products->count();
        
        $products_all = $products->get();

        $naProducts = $products_all->where('product_status','NA');
        
        foreach($naProducts as $na)
        {
            $variant = ProductVariant::where(
                'sku',
                $na->product_sku,
            )->first();
            
            if($variant && count($branches->where('variant_id',$variant->id)) > 0)
            {
                if(count($branches->where('variant_id',$variant->id)->where('qty','>',0)) > 0)
                {
                    $na->product_status = "hold";
                }
                else{
                    $na->product_status = "shortage";
                    $shortage_order = ShortageOrder::where('order_id',$na->order_id)->first();
                        if($shortage_order)
                        {
                            $shortage_order->shortage_items += 1;
                            $shortage_order->shortage_price += $na->price;
                            $shortage_order->save();
                        }
                        else{
                            $shortage_order = new ShortageOrder();
                            $shortage_order->order_id = $na->order_id;
                            $shortage_order->assign_to = $na->prepare->user->id;
                            $shortage_order->shortage_items = 1;
                            $shortage_order->total_items = $na->prepare->products->count();
                            $shortage_order->total_price = $na->prepare->products->sum('price');
                            $shortage_order->shortage_price = $na->price;
                            $shortage_order->hold_date = now();
                            $shortage_order->created_at = $na->order->created_at_date;
                            $shortage_order->updated_at = now();
                            $shortage_order->save();
                        }
                }
                $na->save();
            }
        }


        $products = $products->orderBy('created_at','desc')->where('product_status','!=','shortage')->paginate(15)->appends($request->query());
        $orders = Prepare::where('delivery_status', 'hold')->count();
        $statuses = Branch::orderBy('order', 'ASC')->whereHas('variants', function ($q) {
            return $q->where('qty', '>', 0);
        })->pluck('name')->toArray();
        return view('reports.hold_products', compact('products','search','branches','statuses','products_all','products_count','orders','date','hold_date','delivery_status'));
    }

    public function shortage_report(Request $request)
    {
        $date = $request->date;
        $hold_date = null;
        $delivery_status = null;
        $search = null;
        $orders = ShortageOrder::orderBy('created_at','desc');
        if($date !=null)
        {
            $orders = $orders->whereDate('created_at', '=',$date);
        
        }

        if($request->hold_date)
        {
            $hold_date = $request->hold_date;
            $orders = $orders->whereDate('hold_date', '=',$date);
        }
        if($request->search)
        {
            $search = $request->search;
            $orders = $orders->whereHas('order',function($query)use($search){where('order_number', $search);});
        }
        $orders_count = $orders->count();
        $orders_amount = $orders->sum('shortage_price');
        $orders = $orders->paginate(15)->appends($request->query());
        
        return view('reports.shortage_products', compact('orders','search','orders_count','date','hold_date','delivery_status'));

    }

    public function make_call($order_id){
        $shortageOrder = ShortageOrder::find($order_id);
        if($shortageOrder)
        {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            $order = order2::where('id',$shortageOrder->order_id)->first();
            if(!$order)
            $order = Sale::where('id',$shortageOrder->order_id)->first();

            if(!$order)
                return redirect()->back()->with('error','Original Order Not Found');

            $product_images = $store->getProductImagesForOrder($order);

            $prepare = Prepare::where('order_id', $order->id)->first();

            $prepare_products = PrepareProductList::where('prepare_id', $prepare->id)->first();
            $refunds = Refund::where('order_name', $order->name)->pluck('line_item_id')->toArray();
            $returns = [];
            $return = ReturnedOrder::where('order_number', $order->order_number)->pluck('id')->toArray();
            if ($return)
                $returns = ReturnDetail::whereIn('return_id', $return)->pluck('line_item_id')->toArray();
            return view('shortage.make_call', [
                'product_images' => $product_images,
                'order' => $order,
                'prepare_products' => $prepare_products,
                'prepare'=>$prepare,
                'refunds'=>$refunds,
                'returns'=>$returns,
                'shortage' => $shortageOrder
            ]);
        }
        return redirect()->back()->with('error','Shortage Order Not Found');
    }

    public function staff_products_report(Request $request)
    {
        $users = User::orderBy('created_at', 'DESC')->get();
        $histories = UserHistory::where('action','!=','prepared')->orderBy('created_at', 'desc')->with('product');
        $user_id = $request->user_id;
        $daterange = $request->daterange;
        $action = $request->action;
        if ($request->user_id)
            $histories = $histories->where('user_id', $user_id);
        if($action)
            $histories = $histories->where('action',$action);
        if($daterange)
        {
            $date = explode(' - ', $daterange);
            $startDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[0])->format('Y-m-d');
            $endDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[1])->format('Y-m-d');
            $histories = $histories->whereDate('created_at', '>=' ,$startDate)->whereDate('created_at', '<=' ,$endDate); 
        }
        $history_count = $histories->count();
        $history_sum = 0;
        $histories = $histories->paginate(20)->appends($request->query());
        return view('reports.staff_products', compact('history_sum','histories','history_count','users', 'user_id', 'daterange', 'action'));
    }

    public function updateProductStatus($stats)
    {
        foreach($stats as $key=>$status)
        {
            if($status)
            {
                $variant = PrepareProductList::find($key);
                if($variant)
                {
                    $variant->product_status = $status;
                    $variant->save();
                    $history = new UserHistory();
                    $history->user_id = auth()->id();
                    $history->product_id = $variant->id;
                    $history->order_id = $variant->order_id;
                    $history->action = $status;
                    $history->note = "Product has been marked ".$status;
                    $history->created_at = now();
                    $history->updated_at = now();
                    $history->save();
                }
            }
        }
        return redirect()->back()->with('success', 'Statuses Updated Successfully');
    }

    public function reviewNAProduct($id)
    {
        $product = PrepareProductList::find($id);
        if($product)
        {
            $history = new UserHistory();
            $history->user_id = auth()->id();
            $history->product_id = $product->id;
            $history->order_id = $product->order_id;
            $history->action = "reviewed";
            $history->note = "Product has been marked Reviewed";
            $history->created_at = now();
            $history->updated_at = now();
            $history->save();
            return redirect()->back()->with('success', 'NA Product Marked as Reviewed Successfully');
        }
        return redirect()->back()->with('error', 'Product Not Found');
    }

    public function export_hold_products($productss)
    {
        $csvData=array('Order Number ,Product Name, Item SKU ,Variation ID, Product Status ,Qty ,Variant,Product Image, Assigned to, Assigned at');
        
        if ($productss) {
            foreach ($productss as $key => $product) {
                $product = PrepareProductList::where('id',$product->id)->first();
                $order = order2::findOrFail($product->order_id);
                if($product)
                {
                    $csvData[]=  
                    ($order?$order->order_number:"-") . ',' 
                    .$product->product_name . ','
                    .$product->product_sku . ','
                    . $product->product_id  . ','
                    . $product->product_status  . ','
                    . $product->order_qty  . ','
                    . $product->variation_id  . ','
                    . $product->variant_image  . ','
                    . (isset($product->prepare->user->name)?$product->prepare->user->name:"")  . ','
                    . $product->created_at  . ','
                    ;
                }
            }
            $filename= 'hold-products-' . date('Ymd').'-'.date('his'). ".xlsx";
            $file_path= public_path().'/download/'.$filename;

            $file = fopen($file_path, "w+");
            foreach ($csvData as $cellData){
                fputcsv($file, explode(',', $cellData));
            }
            fclose($file);

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Csv');

            $objPHPExcel = $reader->load($file_path);
            $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, 'Csv');
            $filenamexlsx= 'hold-products' . '-' .date('Ymd').'-'.date('his'). ".Csv";
            $file_pathxlsx= public_path().'/download/'. $filenamexlsx;

            $objWriter->save($file_pathxlsx);
            return response()->download($file_pathxlsx, $filenamexlsx);

        }
        return redirect()->back();
    }

    public function export_staff_report($prepare_users_list)
    {
        $csvData=array('Name,Total Orders,New Orders,Prepared Orders,Hold Orders,Fulfilled Orders,Shipped Orders');
        foreach($prepare_users_list['name'] as $key => $user)
        {
            $csvData[]=   $prepare_users_list['name'][$key] . ','
                . $prepare_users_list['all'][$key]  . ','
                . $prepare_users_list['new'][$key]  . ','
                . $prepare_users_list['prepared'][$key]  . ','
                . $prepare_users_list['hold'][$key]  . ','
                . $prepare_users_list['fulfilled'][$key]  . ','
                . $prepare_users_list['shipped'][$key]  . ','
                ;
        }
        $filename= 'staff-report-' . date('Ymd').'-'.date('his'). ".xlsx";


        $file_path= public_path().'/download/'.$filename;

        $file = fopen($file_path, "w+");
        foreach ($csvData as $cellData){
            fputcsv($file, explode(',', $cellData));
        }
        fclose($file);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Csv');

        $objPHPExcel = $reader->load($file_path);
        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, 'Csv');
        $filenamexlsx= 'staff-report-' . date('Ymd').'-'.date('his'). ".csv";
        $file_pathxlsx= public_path().'/download/'. $filenamexlsx;

        $objWriter->save($file_pathxlsx);
        //dd($file_pathxlsx);
        return response()->download($file_pathxlsx, $filenamexlsx);
        
    }

    public function all_orders(Request $request) {
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        $payment_status = '';
        $prepare_users_list = [];
        $daterange = null;

        $user = Auth::user();
        $store = $user->getShopifyStore;

        $orders = $store->getPrepares();
        
        $all_orders = Order2::select('fulfillment_status', \DB::raw('count(*) as total'))
        ->where('store_id', 1)
        ->groupBy('fulfillment_status')
        ->pluck('total', 'fulfillment_status');
        
        $all_prepares = $orders->get();
        $orders = $orders->where('type','!=', 'pos');
        

        if ($request->search) {
            $sort_search = $request->search;
            $orders = $orders->where('delivery_status', 'like', '%' . $sort_search . '%')
                ->orWhereHas('order', function ($q)use($sort_search) {
                    return $q->where('name', 'like', '%' . $sort_search . '%')
                    ->orWhere('phone', 'like', '%' . $sort_search . '%')
                    ->orWhere('order_number', 'like', '%' . $sort_search . '%')
                    ->orWhere('shipping_address', 'like', '%' . $sort_search . '%')
                    ->orWhere('payment_details', 'like', '%' . $sort_search . '%')
                    ->orWhere('email', 'like', '%' . $sort_search . '%');
                });
        }

        if($request->delivery_status) {
            $delivery_status = $request->delivery_status;
            $orders = $orders->where('delivery_status', $delivery_status);
            
        }
        if($request->prepare_emp) {
            $orders = $orders->where('assign_to', $request->prepare_emp);
            
        }

        if($date !=null)
        {
            $orders = $orders->whereDate('created_at', '=',$date);
        
        }
        if($request->daterange)
        {
            $daterange = $request->daterange;
            $dater = explode(' - ', $daterange);
            $startDate = \Carbon\Carbon::createFromFormat('m/d/Y', $dater[0])->format('Y-m-d');
            $endDate = \Carbon\Carbon::createFromFormat('m/d/Y', $dater[1])->format('Y-m-d');
            $orders = $orders->whereDate('created_at', '>=' ,$startDate)->whereDate('created_at', '<=' ,$endDate);
        }

        $orders = $orders->orderBy('order_id', 'desc')
                        ->paginate(15)->appends($request->query());

        $prepare_users = User::where('role_id', '5')->get();
        if(count($prepare_users)) {
            foreach ($prepare_users as $key => $prepare) {

                $prepare_users_list['id'][$key] = $prepare->id;
                $prepare_users_list['name'][$key] = $prepare->name;
            }
        }
        
        return view('preparation.all', compact('orders','daterange','all_prepares','all_orders','prepare_users_list','date','sort_search','delivery_status','payment_status'));
    }

    public function search_order(Request $request)
    {
        $search = $request->search;
        $order=null;
        $order = order2::where('order_number', $search)->orWhere('phone', $search)->first();
        return view('orders.search',compact('order','search'));
    }

    public function all_sales(Request $request) {
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        $payment_status = '';
        $prepare_users_list = [];
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $orders = $store->getOrders()->where('channel','POS')->where('fulfillment_status', 'processing');
        if ($request->search) {
            $sort_search = $request->search;
            $orders = $orders->where('name', 'like', '%' . $sort_search . '%')
                ->orWhere('id', 'like', '%' . $sort_search . '%')
                ->orWhere('fulfillment_status', 'like', '%' . $sort_search . '%');
        }
        if($date !=null)
        {
            $orders = $orders->whereDate('created_at_date', '=',$date);
        }

        if($request->delivery_status)
        {
            $delivery_status = $request->delivery_status;
            $orders = $orders->where('fulfillment_status', $delivery_status);
        }

        $orders = $orders->orderBy('table_id', 'asc')
                        ->paginate(15)->appends($request->query());


        $prepare_users = User::where('role_id', '5')->get();
        if(count($prepare_users)) {
            foreach ($prepare_users as $key => $prepare) {

                $prepare_users_list['id'][$key] = $prepare->id;
                $prepare_users_list['name'][$key] = $prepare->name;
            }
        }
        return view('orders.pos_orders', compact('orders','prepare_users_list','date','sort_search','delivery_status','payment_status'));
    }

    public function new_orders() {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        if($user->id == 1 || $user->id == 2)
        $orders = $store->getPrepares()->where('delivery_status','distributed')->orderBy('table_id', 'desc')
                ->paginate(15);
        else
        $orders = $store->getPrepares()->where('delivery_status','distributed')
                ->where('assign_to',$user->id)->orderBy('table_id', 'asc')
                ->paginate(15);
        return view('preparation.new', ['orders' => $orders]);
    }

    public function hold_orders() {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        if($user->id == 1 || $user->id == 2)
        $orders = $store->getPrepares()->where('delivery_status','hold')->orderBy('table_id', 'desc')
                ->paginate(15);
        else
        $orders = $store->getPrepares()->where('delivery_status','hold')
                ->where('assign_to',$user->id)->orderBy('table_id', 'desc')
                ->paginate(15);
        return view('preparation.hold', ['orders' => $orders]);
    }

    public function staff_report(Request $request)
    {
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        $prepare_users_list = [];
        $daterange = null;

        $user = Auth::user();
        $store = $user->getShopifyStore;
        $orders = $store->getPrepares();

        if($request->delivery_status) {
            $delivery_status = $request->delivery_status;
            $orders = $orders->where('delivery_status', $delivery_status);
            
        }
        if ($request->search) {
            $sort_search = $request->search;
            $orders = $orders->where('delivery_status', 'like', '%' . $sort_search . '%')
                ->orWhereHas('user', function ($q)use($sort_search) {
                    return $q->where('name', 'like', '%' . $sort_search . '%');
                });
        }
        if($request->daterange)
        {
            $daterange = $request->daterange;
            $date = explode(' - ', $daterange);
            $startDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[0])->format('Y-m-d');
            $endDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[1])->format('Y-m-d');
            $orders = $orders->whereDate('created_at', '>=' ,$startDate)->whereDate('created_at', '<=' ,$endDate);
        }
        
        $all_prepares = $orders->get();

        $prepare_users = User::where('role_id', '5')->get();
        if(count($prepare_users)) {
            foreach ($prepare_users as $key => $prepare) {

                $prepare_users_list['id'][$key] = $prepare->id;
                $prepare_users_list['name'][$key] = $prepare->name;
                $prepare_users_list['all'][$key] = $all_prepares->where('assign_to',$prepare->id)->count();
                $prepare_users_list['hold'][$key] = $all_prepares->where('assign_to',$prepare->id)->where('delivery_status','hold')->count();
                $prepare_users_list['prepared'][$key] = $all_prepares->where('assign_to',$prepare->id)->where('delivery_status','prepared')->count();
                $prepare_users_list['new'][$key] = $all_prepares->where('assign_to',$prepare->id)->where('delivery_status','distributed')->count();
                $prepare_users_list['shipped'][$key] = $all_prepares->where('assign_to',$prepare->id)->where('delivery_status','shipped')->count();
                $prepare_users_list['fulfilled'][$key] = $all_prepares->where('assign_to',$prepare->id)->where('delivery_status','fulfilled')->count();
                
            }
        }
        if(isset($request->action) && $request->action == "export")
        {
            return $this->export_staff_report($prepare_users_list);
        }
        return view('reports.staff', compact('prepare_users_list','daterange', 'delivery_status', 'all_prepares', 'sort_search'));
    }

    public function upload_branches_stock(Request $request)
    {
        Cache::forget('message');
        if($request->hasFile('sheet')){
            $import = new StockImport();
            Excel::import($import, request()->file('sheet'));
            
            $file = $request->file('sheet');
            $fileName = $file->getClientOriginalName();
            $file->move(public_path('uploads'), $fileName);

            Cache::forget('message');            
            // Get the public path to the uploaded file
            $publicPath = asset('uploads/' . $fileName);
            if(isset($import->message))
                return redirect()->back()->with('errors', $import->message);
            if(isset($import->trx_id))
            return redirect()->route('branches_stock.transactions')->with('success', 'Stock Updated Successfully');

        }

        return redirect()->back();
    }
    
    public function getProgress()
    {
        // Fetch the progress message from the session
        $message = Cache::get('message', 'Processing Data, It May Takes a few Minutes..');
        //echo($message);
        return response()->json(['message' => $message]);
    }

    public function branchStockTransactions(Request $request)
    {
        $transactions = BranchStockTransaction::orderBy('created_at','desc')->get();
        return view('reports.branch_transactions',compact('transactions'));
    }

    

    public function cash_register_report(Request $request) {

        ini_set('max_execution_time', 180);
        $data = $request->all();
        $start_date = $data['start_date'] ?? Carbon::now()->startOfDay();
        $end_date = $data['end_date'] ?? Carbon::now()->endOfDay();
        $warehouse_id = $data['warehouse_id'];

        if($warehouse_id == 0) {
            $warehouse_auth = Warehouse::find(Auth::user()->warehouse_id);

            if ($warehouse_auth == null) {
                $warehouse_id = 0;
                $warehouse_val = '>=';
            }else {
                $warehouse_id = $warehouse_auth->id;
                $warehouse_val = '=';
            }

        }else {
            $warehouse_id = $data['warehouse_id'];
            $warehouse_val = '=';

        }
        $registers = cashRegister::select('cash_registers.*')
            -> where('cash_registers.warehouse_id',$warehouse_val , $warehouse_id)->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)->get();
        $registers_sum = DB::table('cash_registers')
            ->select(DB::raw('SUM(total_sales_amount) AS totalSales') ,
                     DB::raw('SUM(register_close_amount) AS TotalClose'),
                     DB::raw('SUM(total_cash) AS TotalCash'),
                     DB::raw('SUM(total_card_slips) AS TotalCredit'))
            -> where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)->get();


        $cash_all_amount = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.pay_method','cash')
            ->where('cash_register_transactions.transaction_type','sell')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->sum('amount');

        $refund_cash_all_amount = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','refund')
            ->where('cash_register_transactions.pay_method','cash')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->sum('amount');

        $credit_all_amount = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','sell')
            ->where('cash_register_transactions.pay_method','Credit Card')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->sum('amount');

        $refund_credit_all_amount = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','refund')
            ->where('cash_register_transactions.pay_method','Credit Card')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->sum('amount');

        $online_all_amount = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','online_refund')
            ->where('cash_register_transactions.pay_method','Cash')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->sum('amount');

        $refund_online_all_amount = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','online_refund')
            ->where('cash_register_transactions.pay_method','Cash')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->count('amount');

        $cash_negative_amount = DB::table('cash_registers')
            ->select('cash_registers.*')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_registers.close_status','negative')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->sum('close_status_amount');

        $cash_positive_amount = DB::table('cash_registers')
            ->select('cash_registers.*')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_registers.close_status','positive')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->sum('close_status_amount');

        $cash_all_register_amount = DB::table('cash_registers')
            ->select('cash_registers.*')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->sum('register_close_amount');

        $all_sales_register_count = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.sale_id','!=' ,'null')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->count('sale_id');

        $all_sales_register_item = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*','sales.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->join('sales','sales.id', '=', 'cash_register_transactions.sale_id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.sale_id','!=' ,'null')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->sum('sales.item');

        $all_sales_register_qty = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*','sales.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->join('sales','sales.id', '=', 'cash_register_transactions.sale_id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.sale_id','!=' ,'null')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->sum('sales.total_qty');


        $all_cash_register_count = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','=' ,'sell')
            ->where('cash_register_transactions.pay_method','=' ,'Cash')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->count('sale_id');

        $all_cash_register_refund_count = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','=' ,'refund')
            ->where('cash_register_transactions.pay_method','=' ,'Cash')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->count('sale_id');


        $all_cash_register_refund_item = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*','sales.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->join('sales','sales.id', '=', 'cash_register_transactions.sale_id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','=' ,'refund')
            ->where('cash_register_transactions.pay_method','=' ,'Cash')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->sum('sales.item');


        $all_cash_register_refund_qty = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*','sales.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->join('sales','sales.id', '=', 'cash_register_transactions.sale_id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','=' ,'refund')
            ->where('cash_register_transactions.pay_method','=' ,'Cash')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->sum('sales.total_qty');

        $all_credit_register_count = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','=' ,'sell')
            ->where('cash_register_transactions.pay_method','=' ,'Credit Card')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->count('sale_id');

        $all_credit_register_refund_count = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','=' ,'refund')
            ->where('cash_register_transactions.pay_method','=' ,'Credit Card')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->count('sale_id');

        $all_credit_register_refund_item = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*','sales.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->join('sales','sales.id', '=', 'cash_register_transactions.sale_id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','=' ,'refund')
            ->where('cash_register_transactions.pay_method','=' ,'Credit Card')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->sum('sales.item');

        $all_credit_register_refund_qty = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*','sales.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->join('sales','sales.id', '=', 'cash_register_transactions.sale_id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.transaction_type','=' ,'refund')
            ->where('cash_register_transactions.pay_method','=' ,'Credit Card')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->sum('sales.total_qty');

        $all_sales_register_user__count = DB::table('cash_registers')
            ->select('cash_registers.*','cash_register_transactions.*')
            ->join('cash_register_transactions','cash_register_transactions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_registers.warehouse_id',$warehouse_val ,$warehouse_id)
            ->where('cash_register_transactions.sale_id','!=' ,'null')
            ->whereDate('cash_registers.created_at', '>=' , $start_date)->whereDate('cash_registers.created_at', '<=' , $end_date)
            ->get()->count('sale_id');


        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        $lims_user_list = User::where('is_active', true)->get();



        return view('reports.cash_registers_report',
            compact('registers','lims_warehouse_list','lims_user_list','registers_sum','start_date','end_date',
                'warehouse_id','cash_all_amount','refund_cash_all_amount','credit_all_amount','refund_credit_all_amount',
                'cash_negative_amount','cash_positive_amount','cash_all_register_amount',
                'all_sales_register_count', 'all_cash_register_count' ,'all_cash_register_refund_count' ,
                'all_credit_register_count','all_credit_register_refund_count','all_sales_register_item','all_sales_register_qty',
            'all_cash_register_refund_item',
                'online_all_amount','refund_online_all_amount','all_cash_register_refund_qty','all_credit_register_refund_item','all_credit_register_refund_qty'));
    }

    
    public function pos()
    {
        Session::forget('pos.shipping');
        Session::forget('customer');
        Session::forget('pos.cart');
        Session::forget('pos.discount');
        return view('pos.index');
    }


    
    public function getProduct($id)
    {
        $lims_product_warehouse_data = WarehouseProduct2::where([
            ['warehouse_id', $id],['inventory_quantity','>',0]
        ])->get();
        $product_code = [];
        $product_name = [];
        $product_qty = [];
        $product_data = [];
        $product_type = [];
        $product_id = [];
        $product_list = [];
        $qty_list = [];
        //product without variant
        foreach ($lims_product_warehouse_data as $product_warehouse)
        {
            $product_qty[] = $product_warehouse->inventory_quantity;
            $product_code[] =  $product_warehouse->sku;
            $product_name[] = $product_warehouse->title;
            $product_id[] = $product_warehouse->product_id;
        }
        $product_data = [$product_code, $product_name, $product_qty, $product_id];
        return $product_data;
    }

    public function posEdit($id)
    {
        Session::forget('pos.discount');
        Session::forget('pos.shipping');
        Session::forget('customer');
        Session::forget('pos.cart');
        $user = Auth::user();
        $warehouse_id = $user->warehouse_id;
        $user_id = auth()->user()->id;
        $order = order2::find($id);
        $customer = json_decode($order->customer);
        $db_customer = Customer2::where('shopify_id', $customer->id)->first();
        if(!$db_customer)
        {
            $new_customer = new Customer2;
            $new_customer->name = $customer->first_name . " " . $customer->last_name;
            $new_customer->shopify_id = $customer->id;
            $new_customer->email = $customer->email;
            $new_customer->city = $customer->default_address->city;
            $new_customer->state = $customer->default_address->province;
            $new_customer->country = $customer->default_address->country;
            $new_customer->address = $customer->default_address->address1;
            $new_customer->phone_number = $customer->default_address->phone ?? $customer->phone ?? $order->phone ?? null;
            $new_customer->customer_group_id = 1;
            $new_customer->created_at = now();
            $new_customer->updated_at = now();
            $new_customer->save();
            $db_customer = $new_customer;
        }
        
        $customer = $customer->id;
        Session::put('customer',$customer);

        $cart = collect();
        foreach($order->line_items as $key=>$item)
        {
            $data = array();
            $product_variant = ProductVariant::select('id','price', 'title', 'barcode', 'image_id', 'sku')->where('sku',$item['sku'])->first();
            $prod = Product2::find($item['product_id']);
            $image = asset("assets/img/logo.png");
            $images = null;
            if ($prod) {
                $images = collect(json_decode($prod->images));
            }
            if(isset($product_variant->image_id))
            {
                            
                if($images) {
                    $product_img2 = $images->where('id', $product_variant->image_id)->first();
                    if ($product_img2 && $product_img2->src != null && $product_img2->src != '') {
                        $image = $product_img2->src;
                    }
                    else{
                        $product_img2 = $images->first();
                        $image = $product_img2->src;
                    }
                }
            }
            elseif($images)
            {
                $product_img2 = $images->first();
                if ($product_img2 && $product_img2->src != null && $product_img2->src != '') {
                    $image = $product_img2->src;
                }
            }
            $data['stock_id'] = $item['sku'];
            $data['id'] = $item['product_id'];
            $data['variant'] = $item['name'];
            $data['quantity'] = $item['fulfillable_quantity'];
            $data['image'] = $image;
            $data['price'] = $product_variant->price;
            $data['tax'] = 0;
            $cart->push($data);
        }
        if(isset($order->shipping_lines) && is_array($order->shipping_lines) && isset($order->shipping_lines[0]) && isset($order->shipping_lines[0]['price']) )
        {
            $shipping = $order->shipping_lines[0]['price'];
        }
        else{
            $ship = ShippingFee::where('city',$db_customer->state)->first();
            if ($ship)
                $shipping = $ship->cost;
            else
                $shipping = 0;
        } 
        Session::put('pos.shipping',$shipping);
        Session::put('pos.cart', $cart);
        
        return view('pos.edit',compact('order'));
    }
    public function searchPos(Request $request)
    {
        $products = ProductVariant::join('products','product_variants.product_id', '=', 'products.id')->where('inventory_quantity', '>' ,'0')->select('products.*','product_variants.id as stock_id','product_variants.title','product_variants.price as stock_price', 'product_variants.inventory_quantity as stock_qty', 'product_variants.image_id as stock_image' ,'product_variants.sku as stock_sku')->orderBy('products.created_at', 'desc');

        foreach($products as $product)
        {

            $images = null;
            if ($product) {
                $images = collect(json_decode($product->images));
            }
            if(isset($product->stock_image))
            {
                            
                if($images) {
                    $product_img2 = $images->where('id', $product->stock_image)->first();
                    if ($product_img2 && $product_img2->src != null && $product_img2->src != '') {
                        $image = $product_img2->src;
                    }
                }
            }
            elseif($images)
            {
                $product_img2 = $images->first();
                if ($product_img2 && $product_img2->src != null && $product_img2->src != '') {
                    $image = $product_img2->src;
                }
            }
            $product->stock_image = $image;
        }
        if($request->category != null){
            $arr = explode('-', $request->category);
            if($arr[0] == 'category'){
                $category_ids = CategoryUtility::children_ids($arr[1]);
                $category_ids[] = $arr[1];
                $products = $products->whereIn('products.category_id', $category_ids);
            }
        }


        if($request->brand != null){
            $products = $products->where('products.brand_id', $request->brand);
        }

        if ($request->keyword != null) {

            $products = $products->where('products.name', 'like', '%'.$request->keyword.'%')
                ->orWhere('products.barcode', $request->keyword)
                ->orWhere('sku', 'like', '%'.$request->keyword.'%');
        }



        $stocks = new PosProductCollection($products->paginate(16));
        $stocks->appends(['keyword' =>  $request->keyword,'category' => $request->category, 'brand' => $request->brand] );
        return $stocks;
    }

    public function addToCart(Request $request)
    {
        // dd($request);
        $stock = ProductVariant::where('sku',$request->stock_id)->first();
        $product = $stock->product;

        $image = asset("assets/img/logo.png");
        if(isset($stock->image_id))
        {
                        
            if($product) {
                $images = collect(json_decode($product->images));

                $product_img2 = $images->where('id', $stock->image_id)->first();
                if ($product_img2 && $product_img2->src != null && $product_img2->src != '') {
                    $image = $product_img2->src;
                }
                else{
                    $product_img2 = $images->first();
                    $image = $product_img2->src;
                }
            }
        }

        $data = array();
        $data['stock_id'] = $request->stock_id;
        $data['id'] = $product->id;
        $data['variant'] = $stock->title;
        $data['quantity'] = 1;
        $data['image'] = $image;

        if($stock->inventory_quantity == 0){
            return array('success' => 0, 'message' => trans("This product doesn't have enough stock "), 'view' => view('pos.cart')->render());
        }

        $tax = 0;
        $price = $stock->price;

        $data['price'] = $price;
        $data['tax'] = $tax;

        if($request->session()->has('pos.cart')){
            $foundInCart = false;
            $cart = collect();

            foreach ($request->session()->get('pos.cart') as $key => $cartItem){
                if($cartItem['id'] == $product->id && $cartItem['stock_id'] == $stock->sku){
                    $foundInCart = true;
                    $loop_product = product2::find($cartItem['id']);
                    $product_stock = $stock;

                    if($product_stock->inventory_quantity >= ($cartItem['quantity'] + 1)){
                        $cartItem['quantity'] += 1;
                    }else{
                        return array('success' => 0, 'message' => trans("This product doesn't have more stock."), 'view' => view('pos.cart')->render());
                    }
                }
                $cart->push($cartItem);
            }

            if (!$foundInCart) {
                $cart->push($data);
            }
            $request->session()->put('pos.cart', $cart);
        }
        else{
            $cart = collect([$data]);
            $request->session()->put('pos.cart', $cart);
        }

        $request->session()->put('pos.cart', $cart);

        return array('success' => 1, 'message' => '', 'view' => view('pos.cart')->render());
    }


    public function decreaseCart(Request $request)
    {
        // dd($request);
        $stock = ProductVariant::where('sku',$request->stock_id)->first();
        $product = $stock->product;

        if($request->session()->has('pos.cart')){
            $cart = collect();

            foreach ($request->session()->get('pos.cart') as $key => $cartItem){
                if($cartItem['id'] == $product->id && $cartItem['stock_id'] == $stock->sku){
                    $cartItem['quantity'] -= 1;
                    if($cartItem['quantity'] == 0)
                    {
                        $cart->forget($key);
                    }
                    break;
                }
                $cart->push($cartItem);
            }
            $request->session()->put('pos.cart', $cart);
        }

        return array('success' => 1, 'message' => '', 'view' => view('pos.cart')->render());
    }

    public function updateQuantity(Request $request)
    {
        $cart = $request->session()->get('pos.cart', collect([]));
        $cart = $cart->map(function ($object, $key) use ($request) {
            if($key == $request->key){
                $product = product2::find($object['id']);
                $product_stock = ProductVariant::where('sku', $object['stock_id'])->first();

                if($product_stock->inventory_quantity >= $request->quantity){
                    $object['quantity'] = $request->quantity;
                }else{
                    return array('success' => 0, 'message' => translate("This product doesn't have more stock."), 'view' => view('pos.cart')->render());
                }
            }
            return $object;
        });
        $request->session()->put('pos.cart', $cart);

        return array('success' => 1, 'message' => '', 'view' => view('pos.cart')->render());
    }

    //removes from Cart
    public function removeFromCart(Request $request)
    {
        if(Session::has('pos.cart')){
            $cart = Session::get('pos.cart', collect([]));
            $cart->forget($request->key);
            Session::put('pos.cart', $cart);

            $request->session()->put('pos.cart', $cart);
        }

        return view('pos.cart');
    }

    public function countOpenedRegister()
    {
        $user_id = auth()->user()->id;
        $count =  CashRegister::where('user_id', $user_id)
                                ->where('status', 'open')
                                ->count();
        return $count;
    }

    public function stock_report(Request $request)
    {
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        $prepare_users_list = [];
        $daterange = null;

        $user = Auth::user();
        $store = $user->getShopifyStore;

        $mostSellingProducts = PrepareProductList::select('product_name', 'variant_image','product_sku', 'price', DB::raw('COUNT(*) as total'))
            ->groupBy('product_name', 'variant_image','product_sku', 'price')
            ->orderBy('total', 'desc');
            

        if ($request->search) {
            $sort_search = $request->search;
            $mostSellingProducts = $mostSellingProducts->where('product_name', 'like', '%' . $sort_search . '%');
        }
        if($request->daterange)
        {
            $daterange = $request->daterange;
            $date = explode(' - ', $daterange);
            $startDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[0])->format('Y-m-d');
            $endDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[1])->format('Y-m-d');
            $orders = $mostSellingProducts->whereDate('created_at', '>=' ,$startDate)->whereDate('created_at', '<=' ,$endDate);
        }
        
        $mostSellingProducts = $mostSellingProducts->paginate(15)->appends($request->query());

        return view('reports.stock', compact('mostSellingProducts','daterange', 'delivery_status', 'sort_search'));
    }
    
    public function return_order(Request $request)
    {
        $return = new ReturnedOrder();
        $order = order2::where('order_number', $request->order_number)->orWhere('name', $request->order_number)->first();
        if(!$order)
        $order = Sale::where('order_number', $request->order_number)->orWhere('name', $request->order_number)->first();
        
        if($order) {
            if($order->note=="Point of Sale" || $order->note=="Point of Sale")
            $return->type = "pos";
            else
            $return->type = "online";
            $return->order_id = $order->id;
            $return->order_number = $order->order_number;
            $return->note =$request->note;
            $return->shipping_on = $request->shipping_on;
            $return->status = "In Progress";
            $return->user_id = Auth::user()->id;
            $return->created_at = now();
            $return->updated_at = now();
            $return->save();
            $return->return_number = 1000+$return->id;
            $return->save();
            $qty = 0;
            $amount = 0;
            $line_items = [];

            $user = Auth::user();
            $store = $user->getShopifyStore;

            $payload = $this->getFulfillmentItemForReturn($return->order_id);

            $api_endpoint = 'graphql.json';
            

            $endpoint = getShopifyURLForStore($api_endpoint, $store);
            $headers = getShopifyHeadersForStore($store);
            
            $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
            $items = [];
            if($response['statusCode'] === 201 || $response['statusCode'] === 200)
            {
                if (isset($response['body']['data']['returnableFulfillments']['edges'])) {
                    foreach($response['body']['data']['returnableFulfillments']['edges'] as $edge)
                    {
                        if(isset($edge['node']['returnableFulfillmentLineItems']['edges']))
                        {
                            foreach($edge['node']['returnableFulfillmentLineItems']['edges'] as $mini)
                            {
                                 $items[] = $mini;
                            }
                        }
                    }
                   
                }
            }
            $return_items = $request->items;
            if(isset($request->all) && $request->all == "all")
            {
                $return_items = $order->prepare->products->pluck('product_id')->toArray();
            }
            foreach($return_items as $key=>$item) {
                if (!$item)
                    continue;
                $detail = new ReturnDetail();
                $detail->return_id = $return->id;
                $detail->line_item_id = $item;
                $detail->qty = $request->qty[$key];
                $detail->amount = $request->amount[$key];
                $detail->reason = $request->reason[$key];
                $detail->prepare_product_id = $request->prepare_product_id[$key];
                $detail->created_at = now();
                $detail->updated_at = now();
                $detail->save();
                $qty += $request->qty[$key];
                $amount += $request->amount[$key];

                if(isset($items[$key]))
                {
                    $line_items[] = '
                    {
                    fulfillmentLineItemId: "'.$items[$key]['node']['fulfillmentLineItem']['id'].'",
                    quantity: '.$detail->qty.',
                    returnReason: '.$detail->reason.'
                    returnReasonNote: "'.$return->note.'"
                    }
                    ';
                }
                

            }

            $return->qty = $qty;
            $return->amount = $amount;
            $return->save();

            $payload = $this->createReturnMutation($return->order_id,$line_items);
            $api_endpoint = 'graphql.json';
            

            $endpoint = getShopifyURLForStore($api_endpoint, $store);
            $headers = getShopifyHeadersForStore($store);
            
            $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
            
            if($response['statusCode'] === 201 || $response['statusCode'] === 200)
            {
                if(isset($response['body']['data']['returnCreate']['return']))
                {
                    $return->return_id = $response['body']['data']['returnCreate']['return']['id'];
                    $return->save();
                    return redirect()->route('orders.returned')->with('success','Return Created Successfully');

                }
                return redirect()->route('orders.returned')->with('success','Something Went Wrong');

            }
        }
        return redirect()->route('orders.returned')->with('error','Order Not Found');
    }

    public function returned_products_report(Request $request){

        $date = $request->date;
        $sort_search = null;
        $reason = null;
        $payment_status = '';
        $prepare_users_list = [];
        $paginate_num = 0;
        $orders = ReturnedOrder::orderBy('created_at','desc');
        $order_ids = $orders->pluck('order_id')->toArray();
        $orders = $orders->pluck('id')->toArray();
        $daterange = null;
        
        $returns = ReturnDetail::whereIn('return_id', $orders)->orderBy('created_at','desc');
            
        if($request->daterange)
        {
            $daterange = $request->daterange;
            $date = explode(' - ', $daterange);
            $startDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[0])->format('Y-m-d');
            $endDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[1])->format('Y-m-d');
            $returns = $returns->whereDate('created_at', '>=' ,$startDate)->whereDate('created_at', '<=' ,$endDate);
        }
        

        if($request->reason)
        {
            $reason = $request->reason;
            $returns = $returns->where('reason','like', '%'.$reason.'%');
        }
        $returned_orders = $returns;
        $returns = $returns->pluck('line_item_id')->toArray();   
        if ($request->paginate) {
            $paginate_num = $request->paginate;
        }else {
            $paginate_num = 15;
        }
        $data = [];
        $products = PrepareProductList::orderBy('created_at', 'desc');
        if($request->search)
        {
            $sort_search = $request->search;
            $products = $products->where('product_name', 'like', '%' . $sort_search . '%')->orWhereHas('order', function ($q)use($sort_search) {
                $q->where('order_number', 'like', '%' . $sort_search . '%');
            });
        }
        $products = $products->get();
        foreach($returned_orders->get() as $key=>$return) 
        {
            $products2 = $products->where('product_id',$return->line_item_id)->where('order_id',$return->return->order_id);
            
            foreach($products2 as $product)
            {
                $data[] = [
                    'id' => $product->id,
                    'product_name' => $product->product_name,
                    'product_img' => $product->variant_image,
                    'old_qty' => $product->order_qty,
                    'returned_qty' => $return->qty,
                    'return_number' => $return->return->return_number,
                    'order_id' => $return->return->order_number,
                    'reason' => $return->reason,
                    'amount' => $return->amount,
                    'created_at' => $return->created_at,
                ];
            }
            
        }
        $data = collect($data);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        // Number of items per page
        $perPage = 15;

        // Slice the items for the current page
        $currentPageItems = $data->slice(($currentPage - 1) * $perPage, $perPage)->values();

        // Create a LengthAwarePaginator instance
        $dataa = new LengthAwarePaginator(
            $currentPageItems, // The items for the current page
            $data->count(), // Total number of items
            $perPage, // Items per page
            $currentPage, // Current page
            ['path' => request()->url(), 'query' => request()->query()] // Keep the query parameters
        );
        //$dataa = $data->paginate(15);
        $returns_count = $data->count();
        $returns_amount = $data->sum('amount');


        return view('reports.returned_products', compact('data','returns_amount','dataa','returns_count','daterange','reason','sort_search'));
    
    }
    public function confirmOrders(Request $request)
    {
        $sort_search = null;
        $orders = order2::whereHas('confirmation', function ($q) {
            return $q->where('status', '!=', 'confirmed');
        });
        if($request->search)
        {
            $sort_search = $request->search;
            $orders = $orders->where('name', 'like', '%' . $sort_search . '%')
                    ->orWhere('phone', 'like', '%' . $sort_search . '%')
                    ->orWhere('order_number', 'like', '%' . $sort_search . '%')
                    ->orWhere('shipping_address', 'like', '%' . $sort_search . '%')
                    ->orWhere('email', 'like', '%' . $sort_search . '%');
        }
        $orders = $orders->paginate(15)->appends($request->query());
        return view('orders.confirm', compact('orders', 'sort_search'));
    }
    public function returned_orders_report(Request $request){
        $returns = ReturnedOrder::orderBy('created_at','desc');
        $date = $request->date;
        $sort_search = null;
        $paginate_num = 0;
        $delivery_status = null;
        $orders_count = ReturnedOrder::count();

        if ($request->paginate) {
            $paginate_num = $request->paginate;
        }else {
            $paginate_num = 15;
        }

        if($request->delivery_status)
        {
            $delivery_status = $request->delivery_status;
            $returns = $returns->where('status', $delivery_status);
        }

        if ($request->search) {
            $sort_search = $request->search;
            $returns = $returns->where('order_number', 'like', '%' . $sort_search . '%')
                ->orWhere('return_number', 'like', '%' . $sort_search . '%')
                ->orWhere('status', 'like', '%' . $sort_search . '%')
                ->orWhereHas('order', function ($q)use($sort_search) {
                    return $q->where('name', 'like', '%' . $sort_search . '%')
                    ->orWhere('phone', 'like', '%' . $sort_search . '%')
                    ->orWhere('shipping_address', 'like', '%' . $sort_search . '%')
                    ->orWhere('email', 'like', '%' . $sort_search . '%');
                });
        }
        if($date !=null)
        {
            $returns = $returns->whereDate('created_at', '=',$date);
        
        }
        $returns_count = $returns->count();
        $returns_amount = $returns->sum('amount');
        $returns_all = $returns->get();
        $returns = $returns->paginate($paginate_num)->appends($request->query());
        return view('reports.returned_orders', compact('returns_all','orders_count','returns_count','returns_amount','returns','delivery_status', 'date', 'sort_search'));
    
    }
    public function returned_orders(Request $request){

        $returns = ReturnedOrder::where('shipping_status',null)->orderBy('created_at','desc');
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        $payment_status = '';
        $prepare_users_list = [];
        $paginate_num = 0;
        $orders_count = ReturnedOrder::where('shipping_status',null)->count();

        $prepare_users = User::where('role_id', '4')->get();
        if(count($prepare_users)) {
            foreach ($prepare_users as $key => $prepare) {

                $prepare_users_list['id'][$key] = $prepare->id;
                $prepare_users_list['name'][$key] = $prepare->name;
            }
        }

        if ($request->paginate) {
            $paginate_num = $request->paginate;
        }else {
            $paginate_num = 15;
        }
        
        if($request->delivery_status)
        {
            $delivery_status = $request->delivery_status;
            $returns = $returns->where('status', $delivery_status);
        }

        if ($request->search) {
            $sort_search = $request->search;
            $returns = $returns->where('order_number', 'like', '%' . $sort_search . '%')
                ->orWhere('return_number', 'like', '%' . $sort_search . '%')
                ->orWhere('status', 'like', '%' . $sort_search . '%')
                ->orWhereHas('order', function ($q)use($sort_search) {
                    return $q->where('name', 'like', '%' . $sort_search . '%')
                    ->orWhere('phone', 'like', '%' . $sort_search . '%')
                    ->orWhere('shipping_address', 'like', '%' . $sort_search . '%')
                    ->orWhere('email', 'like', '%' . $sort_search . '%');
                });
        }
        if($date !=null)
        {
            $returns = $returns->whereDate('created_at', '=',$date);
        
        }
        $returns = $returns->paginate($paginate_num)->appends($request->query());
        return view('preparation.returned_orders', compact('orders_count','prepare_users_list','returns', 'delivery_status', 'date', 'sort_search'));
    }

    public function returnsTransaction(Request $request)
    {
        $trx = ReturnsTransaction::orderBy('created_at','desc')->get();
        return view('returns.transactions', compact('trx'));
    }

    public function uploadReturnsSheet(Request $request)
    {
        if($request->hasFile('sheet')){
            $import = new ReturnsImport();
            Excel::import($import, request()->file('sheet'));
            
            $file = $request->file('sheet');
            $fileName = $file->getClientOriginalName();
            $file->move(public_path('uploads'), $fileName);

            // Get the public path to the uploaded file
            $publicPath = asset('uploads/' . $fileName);
            if(isset($import->message))
                return redirect()->route('inventories.index')->with('errors', $import->message);
            if(isset($import->transaction_id)){
                $transaction = ReturnsTransaction::where('id', $import->transaction_id)->first();
                if($transaction)
                {
                    $transaction->sheet = $publicPath;
                    $transaction->note = $request->note;
                    $transaction->save();
                    return redirect()->route('returns.trx')->with('success', 'Returns Uploaded Successfully');
                }
            }
        }

        return back();
    }

    
    public function import_inventory(Request $request)
    {
        if($request->hasFile('sheet')){
            $user = Auth::user();
            $store = $user->getShopifyStore;
            $endpoint = getShopifyURLForStore('inventory_levels.json?location_ids=95353602340&limit=250', $store);
            $headers = getShopifyHeadersForStore($store);
            $hasMorePages = true;
            $products = [];
            while ($hasMorePages) {
                $response = Http::withHeaders($headers)->get($endpoint);
                if ($response->successful()) {
                    // Access the inventory levels within the response body
                    $body = $response->json();
                    $products = array_merge($products, $body['inventory_levels']);

                    // Handle pagination
                    $linkHeader = $response->header('Link');
                    if ($linkHeader) {
                        $nextPageLink = $this->parseNextPageLink($linkHeader);
                        if ($nextPageLink) {
                            $endpoint = $nextPageLink;
                        } else {
                            $hasMorePages = false;
                        }
                    } else {
                        $hasMorePages = false;
                    }
                } else {
                    $hasMorePages = false;
                }
            }
            $products = collect($products)->pluck('inventory_item_id');

            // $import = new ProductsImport();
            // Excel::import($import, request()->file('sheet'));
            
            $file = $request->file('sheet');
            $rows = Excel::toCollection(new ProductsImport, $file)->first();
            $fileName = $file->getClientOriginalName();
            $file->move(public_path('uploads'), $fileName);
            $publicPath = asset('uploads/' . $fileName);

            $failed = [];
            $success = [];
            $qty = [];
            $failed_qty = [];
            $inventory_items = [];
            $success_titles = [];
            $failed_titles = [];
            $note = $request->note;
            foreach ($rows as $row) {
                
                if(isset($row['title']) && isset($row['available']) && isset($row['sku']))
                {

                    $sku = $row['sku'];
                    $inventory_item_id = $sku;
                    $qty_before = 0;
                    $qty_after = 0;

                    $product = Product2::where('title', $row['title'])->first();
                    if($product)
                    {
                        
                        $inventory = ProductVariant::where('sku', (string)$sku)->first();
                        if ($inventory)
                        {
                            
                            if(in_array($inventory['inventory_item_id'],$products->all()))
                            {
                                $success[] = $row['sku'];
                                $qty[] = $row['available'];
                                $success_titles[] = $row['title'];
                                $inventory_items[] = $inventory['inventory_item_id'];
                                $inventory_item_id = $inventory['inventory_item_id'];
                            }
                            else{
                                $failed[] = $row['sku'];
                                $failed_titles[] = $row['title'];
                                $failed_qty[] = $row['available'];
                            }
                            
                        }
                            
                        else{
                            $failed[] = $row['sku'];
                            $failed_titles[] = $row['title'];
                            $failed_qty[] = $row['available'];
                        }
                    }
                    else{
                        $failed[] = $row['sku'];
                        $failed_titles[] = $row['title'];
                        $failed_qty[] = $row['available'];
                    }
                }
                else{
                    $this->message = "One Or More Columns is Missing";
                    return [$this->transfer_id, $this->message];
                }
            }
            $failedProductsData = [];
            foreach ($failed as $index => $sku) {
                $failedProductsData[] = [
                    
                    'Title' => $failed_titles[$index] ?? 'Unknown Title',
                    'Sku' => $sku,
                    'available' => $failed_qty[$index]
                ];
            }
            $publicPath = "";
            // Check if there are any failed products
            if (!empty($failedProductsData)) {
                $fileName = 'failed_products.xlsx';
                $filePath = public_path() .'/inventories/'. $fileName;
                
                // Store the Excel file in the public folder
                Excel::store(new FailedProductsExport($failedProductsData), $filePath);
                
                // Get the public URL for downloading
                $publicPath = asset('uploads/' . $fileName);
                
            }
            return view('transfers.review', compact('success', 'failed', 'success_titles','publicPath', 'failed_titles', 'qty', 'inventory_items','publicPath','note'));
            
        }

        return back();
    }
    private function parseNextPageLink($linkHeader)
    {
        $links = explode(',', $linkHeader);
        foreach ($links as $link) {
            [$urlPart, $relPart] = explode(';', $link);
            $url = trim($urlPart, '<> ');
            $rel = trim(explode('=', $relPart)[1], '" ');

            if ($rel === 'next') {
                return $url;
            }
        }

        return null;
    }

    public function import_inventory_post(Request $request)
    {
        $errors = 0;
        $success = json_decode($request->success);
        $qtys = json_decode($request->qty);

        $titles = json_decode($request->success_titles);
        $inventory_items = json_decode($request->inventory_items);
        $transfer = new InventoryTransfer();
        $transfer->user_id = Auth::user()->id;
        $transfer->qty = array_sum($qtys);
        $transfer->items = count($success);
        $transfer->ref = "tr-" . rand(10000000, 99999999);
        $transfer->created_at = now();
        $transfer->updated_at = now();
        $transfer->sheet = $request->publicPath;
        $transfer->note = $request->note;
        $transfer->save();
        collect($success)->chunk(100)->each(function ($chunkedRows) use ($errors,$success, $qtys, $titles, $inventory_items,$transfer) {
            foreach($chunkedRows as $key=>$sku)
            {
                $user = Auth::user();
                $store = $user->getShopifyStore;
                $endpoint = getShopifyURLForStore('inventory_levels/adjust.json', $store);
                $headers = getShopifyHeadersForStore($store);
                $inventory_item_id = $inventory_items[$key];
                $qty = $qtys[$key];
                $payload = ["location_id" => 95353602340, "inventory_item_id" => $inventory_item_id, "available_adjustment" =>(int)$qty];
                $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
                
                if($response['statusCode'] == 200 && $response['body']['inventory_level'] != null) {
                    $qty_before = $response['body']['inventory_level']['available'] - (int)$qty;
                    $qty_after = $response['body']['inventory_level']['available'];

                    $option1 = "";
                    $option2 = "";

                    $detail = new InventoryDetail();
                    $detail->transfer_id = $transfer->id;
                    $detail->line_item_id = $sku;
                    $detail->qty_before = $qty_before;
                    $detail->qty_after = $qty_after;
                    $detail->variation = $option1."-". $option2;
                    $detail->product_name = $titles[$key];
                    $detail->created_at = now();
                    $detail->updated_at = now();
                    $detail->save();
                }
                else {
                    $detail = new InventoryDetail();
                    $detail->transfer_id = $transfer->id;
                    $detail->line_item_id = $sku;
                    $detail->qty_before = 0;
                    $detail->qty_after = 0;
                    $detail->variation = "";
                    $detail->product_name = $titles[$key];
                    $detail->created_at = now();
                    $detail->updated_at = now();
                    $detail->status = "failed";
                    $detail->save();
                    $errors+=1;
                    
                }
            }
            sleep(0.5);
                
        });
        if($errors==0)
        return redirect()->route('inventories.index')->with('success', 'Inventory Updated Successfully');
        else
        return redirect()->route('inventories.index')->with('error', 'There were errors in '.$errors.' products');
    }

    public function inventory_transfers(Request $request)
    {
        $date = $request->date;
        $transfers = InventoryTransfer::orderBy('created_at', 'desc');
        if($date !=null)
        {
            $transfers = $transfers->whereDate('created_at', '=',$date);
        
        }
        $transfers = $transfers->paginate(15);
        return view('transfers.index', compact('transfers', 'date'));

    }

    public function show_inventory_transfers($id)
    {
        $transfer = InventoryTransfer::find($id);
        if($transfer)
        {
            $details = InventoryDetail::where('transfer_id',$transfer->id)->orderBy('created_at','desc')->get();
            return view('transfers.show', compact('details','transfer'));
        }
        return redirect()->back()->with('error', 'Transfer Not Found');

    }

    public function prepareOrder($id)
    {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $order = order2::where('order_number','like','%'. $id.'%')->first();
        if(!$order)
        $order = Sale::where('order_number','like','%'. $id.'%')->first();

        if ($user->role_id == 6 && $order->fulfillment_status != "shipped")
            return redirect()->back()->with('error',"Youre not permitted to view this page");

        $product_images = $store->getProductImagesForOrder($order);

        $prepare = Prepare::where('order_id', $order->id)->first();

        $prepare_products = PrepareProductList::where('prepare_id', $prepare->id)->first();
        $refunds = Refund::where('order_name', $order->name)->pluck('line_item_id')->toArray();
        $returns = [];
        $return = ReturnedOrder::where('order_number', $order->order_number)->pluck('id')->toArray();
        if ($return)
            $returns = ReturnDetail::whereIn('return_id', $return)->pluck('line_item_id')->toArray();
        return view('preparation.prepare', [
            'order_currency' => getCurrencySymbol($order->currency),
            'product_images' => $product_images,
            'order' => $order,
            'prepare_products' => $prepare_products,
            'prepare'=>$prepare,
            'refunds'=>$refunds,
            'returns'=>$returns,
        ]);
    }


    public function validateAssignOrders($id)
    {
        $order = order2::find($id);
        $check_order_status_error = [];
        if ($order != null) {
            if($order->fulfillment_status == "shipped" || $order->fulfillment_status == "fulfilled") {
                $check_order_status_error[] = $order->id;
            }
        }
        return $check_order_status_error;
    }

    public function assignOrders($id,$prepare_emp)
    {
        $prepare_employee = User::find($prepare_emp);
        $order = order2::find($id);
        
        $user = Auth::user();
        $store = $user->getShopifyStore;
        if ($order != null) {

                try {
                    $order = order2::where('id',$id)->first();
                    $order->status = "3";
                    $order->fulfillment_status = "distributed";
                    $order->save();

                    // Add TO Prepare
                    //firstornew
                    $add_to_prepare = Prepare::where('order_id',$order->id)->first();
                    if($add_to_prepare){
                        $add_to_prepare->delete();
                        $add_History_sale = new OrderHistory();
                        $add_History_sale->order_id = $order->id;
                        $add_History_sale->user_id = Auth::user()->id;
                        $add_History_sale->action = "ReAssign";
                        $add_History_sale->created_at = now();
                        $add_History_sale->updated_at = now();
                        $add_History_sale->note = " Order Has Been ReAssigned By : <strong>" . auth()->user()->name . "</strong> To : <strong>" . $prepare_employee->name ."</strong>";
                    }
                    else{
                        $add_History_sale = new OrderHistory();
                        $add_History_sale->order_id = $order->id;
                        $add_History_sale->user_id = Auth::user()->id;
                        $add_History_sale->action = "Assign";
                        $add_History_sale->created_at = now();
                        $add_History_sale->updated_at = now();
                        $add_History_sale->note = " Order Has Been Assigned By : <strong>" . auth()->user()->name . "</strong> To : <strong>" . $prepare_employee->name ."</strong>";


                    }
                    $add_History_sale->save();
                    $add_to_prepare = new Prepare();
                    $add_to_prepare->order_id  = $order->id;
                    $add_to_prepare->store_id  = $order->store_id;
                    $add_to_prepare->table_id  = $order->table_id;
                    $add_to_prepare->assign_by  = Auth::user()->id;
                    $add_to_prepare->assign_to  = $prepare_emp;
                    $add_to_prepare->status  = "3";
                    $add_to_prepare->delivery_status  = "distributed";
                    $add_to_prepare->sale_created_at  = $order->created_at_date;
                    $add_to_prepare->created_at  = now();
                    $add_to_prepare->updated_at  = now();
                    $add_to_prepare->save();
                    $prepare_product = PrepareProductList::where('order_id',$order->id)->delete();
                    $product_images = $store->getProductImagesForOrder($order);
                
                    foreach($order->line_items as $item)
                    {
                        $product_img = "";
                        $prepare_product = new PrepareProductList();
                        if(isset($item['product_id']) && $item['product_id'] != null)
                        {
                            if(isset($product_images[$item['product_id']]))
                            {
                                $product_imgs = is_array($product_images[$item['product_id']]) ? $product_images[$item['product_id']] : json_decode(str_replace('\\','/',$product_images[$item['product_id']]),true);
                                if ($product_imgs && !is_array($product_imgs))
                                    $product_imgs = $product_imgs->toArray();

                                $product_img = is_array($product_imgs) && isset($product_imgs[0]) && isset($product_imgs[0]['src']) ? $product_imgs[0]['src'] : null;
                            }
                        
                            $product = Product2::find($item['product_id']);
                        }
                        else
                        {
                            $product = Product2::where('variants','like','%'.$item['sku'].'%')->first();
                        }
                        if($product) {

                            $variants = collect(json_decode($product->variants));
                            $variant = $variants->where('id',$item['variant_id'])->first();
                            $images = collect(json_decode($product->images));
                            if(!$variant)
                            {
                                $variant = $variants->where('sku',$item['sku'])->first();
                            }

                            if($variant)
                            {
                                $product_img2 = $images->where('id', $variant->image_id)->first();
                                if ($product_img2 && $product_img2->src != null && $product_img2->src != '')
                                    $product_img = $product_img2->src;
                            }
                        }
                        



                        $prepare_product->order_id = $order->id;
                        $prepare_product->table_id = $order->table_id;
                        $prepare_product->store_id = $order->store_id;
                        $prepare_product->prepare_id = $add_to_prepare->id;
                        $prepare_product->user_id = Auth::user()->id;
                        $prepare_product->product_id = $item['id'];
                        $prepare_product->product_sku = $item['sku'];
                        $prepare_product->variation_id = $item['variant_title'];
                        $prepare_product->variant_image = $product_img;
                        $prepare_product->order_qty = $item['quantity'];
                        $prepare_product->product_status= $item['fulfillment_status']??"unfulfilled";
                        $prepare_product->prepared_qty = 0;
                        $prepare_product->needed_qty = $item['quantity'];
                        $prepare_product->product_name = $item['title'];
                        $prepare_product->price = $item['price'];
                        $prepare_product->created_at = now();
                        $prepare_product->updated_at = now();
                        $prepare_product->save();

                    }

                } catch (\Exception $e) {
                dd($e);
                }
            
            $order->save();
            return redirect()->back()->with('success','Orders has been Assigned successfully');
        } else {
            return redirect()->back()->with('error','Something went wrong');
        }
        return back();

    }

    public function bulk_order_assign(Request $request)
    {

        $check_order_status = [];
        $auth_user = Auth::user()->id;
        if ($request->id) {
            foreach ($request->id as $key => $order_id) {
                if($this->validateAssignOrders($order_id) != null) {
                    $check_order_status[] = $this->validateAssignOrders($order_id)[0];
                }
            }
        }

        if ($check_order_status != null) {

            foreach ($check_order_status as $error_orders) {
                return redirect()->back()->with('success',"Order Number: " . $error_orders . ' Not A Processing');
            }
            return 1;
        } else {
            if ($request->id) {
                foreach ($request->id as $order_id) {
                    // $order = order2::findOrFail($order_id);
                    $this->assignOrders($order_id,$request->prepare_emp);


                    //Add Order History
                }
            }
        }

    }

    public function showOrder($id) {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $order = $store->getOrders()->where('table_id', $id)->first();
        //dd($order);
        if($order->getFulfillmentOrderDataInfo()->doesntExist())
            OrderFulfillments::dispatch($user, $store, $order);
        $product_images = $store->getProductImagesForOrder($order);
        $refunds = Refund::where('order_name', $order->name)->pluck('line_item_id')->toArray();
        return view('orders.show', [
            'order_currency' => getCurrencySymbol($order->currency),
            'product_images' => $product_images,
            'order' => $order,
            'refunds' => $refunds,
        ]);
    }

    public function createReturnMutation($order_id,$line_items)
    {
        $fulfillmentV2Mutation = 'returnCreate (

            returnInput: {
            orderId: "gid://shopify/Order/'.$order_id.'",
            returnLineItems: ['.implode(',',$line_items).'],
            requestedAt: "2022-05-04T00:00:00Z",
            	notifyCustomer: false,
        }
        )
        {
            return {
            id
            }
            userErrors {
            field
            message
            }
        }';
        $mutation = 'mutation returnCreateMutation{ '.$fulfillmentV2Mutation.' }';
        // dd($mutation);
        return ['query' => $mutation];

    }
    public function getFulfillmentItemForReturn($order_id)
    {
        $query = '
        
        query returnableFulfillmentsQuery {
            returnableFulfillments(orderId: "gid://shopify/Order/'.$order_id.'", first: 10) {
                edges {
                node {
                    id
                    fulfillment {
                    id
                    }
                    returnableFulfillmentLineItems(first: 10) {
                    edges {
                        node {
                        fulfillmentLineItem {
                            id
                        }
                        quantity
                        }
                    }
                    }
                }
                }
            }
            }

        ';
        return ['query' => $query];
    }

    private function getFulfillmentLineItem($posted_data, $order) {
        try {
            $search = (int) $posted_data['lineItemId'];
            $fulfillment_orders = $order->getFulfillmentOrderDataInfo;

            foreach($fulfillment_orders as $fulfillment_order) {
                $line_items = $fulfillment_order->line_items;
                foreach($line_items as $item) {
                    if($item['line_item_id'] === $search){
                        return $fulfillment_order;
                    }// Found it!
                }
            }

        } catch(Exception $e) {
            return null;
        }
    }

    private function getPayloadForFulfillment($line_items, $request) {
        return [
            'fulfillment' => [
                'message' => $request['message'],
                'notify_customer' => $request['notify_customer'] === 'on',
                'tracking_info' => [
                    'number' => $request['number'],
                    'url' => $request['tracking_url'],
                    'company' => $request['shipping_company']
                ],
                'line_items_by_fulfillment_order' => $this->getFulfillmentOrderArray($line_items, $request)
            ]
        ];
    }

    public function markAsPaidMutation($order_id)
    {
        $mutation = 'mutation orderMarkAsPaid($input: OrderMarkAsPaidInput!) {
        orderMarkAsPaid(input: $input) {
            order {
            id
            note
            email
            totalPrice
            }
            userErrors {
            field
            message
            }
        }
        }';
        $variables = [
            'input' => [
                'id' => "gid://shopify/Order/".$order_id,
            ]
        ];
        return ['query' => $mutation,'variables' => $variables];

    }

    private function getFulfillmentOrderArray($line_items, $request) {
        $temp_payload = [];
        $search = (int) $request['lineItemId'];
        foreach($line_items as $line_item)
            if($line_item['line_item_id'] === $search)
                $temp_payload[] = [
                    'fulfillment_order_id' => $line_item['fulfillment_order_id'],
                    'fulfillment_order_line_items' => [[
                        'id' => $line_item['id'],
                        'quantity' => (int) $request['no_of_packages']
                    ]]
                ];
        return $temp_payload;
    }

    private function checkIfCanBeFulfilledDirectly($fulfillment_order) {
        return in_array('request_fulfillment', $fulfillment_order->supported_actions);
    }

    private function getLineItemsByFulifllmentOrderPayload($line_items, $request) {
        $search = (int) $request['lineItemId'];
        $id = $line_items[0]['fulfillment_order_id'];
        $items = "";
        foreach($line_items as $line_item)
            if($line_item['line_item_id'] === $search){
                $items = $items . 'fulfillmentOrderLineItems: { id: "gid://shopify/FulfillmentOrderLineItem/' . $line_item['id'] . '", quantity: ' . (int) $request['no_of_packages'] . ' }';
            }
        return implode(',', [
                    'fulfillmentOrderId: "gid://shopify/FulfillmentOrder/'.$id.'"',
                    $items
                ]);
    }

    private function getLineItemsByHoldFulifllmentOrderPayload($line_items, $request) {
        $search = (int) $request['lineItemId'];
        foreach($line_items as $line_item)
            if($line_item['line_item_id'] === $search)
                return implode(',', [
                    'id: "gid://shopify/FulfillmentOrder/'.$line_item['fulfillment_order_id'].'"',
                    'quantity:'. 1 ,
                ]);
    }

    private function getGraphQLPayloadForFulfillment($line_items, $request) {
        $temp = [];
        $temp[] = 'notifyCustomer: '.($request['notify_customer'] === 'on' ? 'true':'false');
        $temp[] = 'trackingInfo: { company: "'.$request['shipping_company'].'", number: "'.$request['number'].'", url: "'.$request['tracking_url'].'"}';
        $temp[] = 'lineItemsByFulfillmentOrder: [{ '.$this->getLineItemsByFulifllmentOrderPayload($line_items, $request).' }]';
        return implode(',', $temp);
    }

    private function getGraphQLPayloadForHoldFulfillment($line_items, $request) {
        $temp = [];
        $temp[] = 'notifyMerchant: false';
        $temp[] = 'reason: INVENTORY_OUT_OF_STOCK';
        $temp[] = 'reasonNotes: "Waiting on new shipment"';
        return implode(',', $temp);
    }

    private function getFulfillmentV2PayloadForFulfillment($line_items, $request) {
        $fulfillmentV2Mutation = 'fulfillmentCreateV2 (fulfillment: {'.$this->getGraphQLPayloadForFulfillment($line_items, $request).'}) {
            fulfillment { id }
            userErrors { field message }
        }';
        $mutation = 'mutation MarkAsFulfilledSubmit{ '.$fulfillmentV2Mutation.' }';
        return ['query' => $mutation];
    }

    private function getFulfillmentV2PayloadForHoldFulfillment($line_items, $request) {

        $fulfillmentHoldMutation = 'fulfillmentOrderHold (fulfillmentHold: {'.$this->getGraphQLPayloadForHoldFulfillment($line_items, $request).'}
        ,id:"gid://shopify/FulfillmentOrder/'. $line_items[0]['fulfillment_order_id']. '") {
            userErrors { field message }
        },
        ';
        $mutations = 'mutation fulfillmentOrderHold{ '.$fulfillmentHoldMutation.' }';
        return ['query' => $mutations];
    }

    public function review_order($id)
    {
        $order = order2::where('id', $id)->first();
        $prepare = Prepare::where('order_id', $id)->first();
        $order_currency=getCurrencySymbol($order->currency);
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $product_images = $store->getProductImagesForOrder($order);
        $refunds = Refund::where('order_name', $order->name)->pluck('line_item_id')->toArray();

        return view('preparation.review', compact('order','prepare','order_currency','product_images','refunds'));
    }

    public function reviewed_orders(Request $request)
    {
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        $payment_status = '';
        $prepare_users_list = [];
        $paginate_num = 0;
        $orders_count = order2::where('fulfillment_status', 'fulfilled')->count();
        $orders = order2::where('fulfillment_status', 'fulfilled')->orderBy('id', 'desc');

        $prepare_users = User::where('role_id', '4')->get();
        if(count($prepare_users)) {
            foreach ($prepare_users as $key => $prepare) {

                $prepare_users_list['id'][$key] = $prepare->id;
                $prepare_users_list['name'][$key] = $prepare->name;
            }
        }

        if ($request->paginate) {
            $paginate_num = $request->paginate;
        }else {
            $paginate_num = 15;
        }

         if ($request->search) {
            $sort_search = $request->search;
            $orders = $orders->where('code', 'like', '%' . $sort_search . '%')
                ->orWhere('id', 'like', '%' . $sort_search . '%')
                ->orWhere('fulfillment_status', 'like', '%' . $sort_search . '%');
        }
        if($date !=null)
        {
            $orders = $orders->whereDate('created_at', '=',$date);
        
        }

        $orders = $orders->paginate($paginate_num)->appends($request->query());
        return view('preparation.new_review_orders_list', compact('orders','delivery_status','prepare_users_list', 'sort_search', 'orders_count', 'date'));
    }

    public function review_post(Request $request)
    {
        $data = $request->all();
        $auth_id = auth()->user()->id;
        $auth_user = auth()->user()->name;
        $user = Auth::user();
        $store = $user->getShopifyStore;

        $order = order2::where('order_number', $request['order_id'])->first();
        $prepare = Prepare::where('order_id', $order->id)->first();
        $refunds = Refund::where('order_name', $order->name)->pluck('line_item_id')->toArray();

        if($order->getFulfillmentOrderDataInfo()->doesntExist()){
            OrderFulfillments::dispatch($user, $store, $order);
        }

        if($order)
        {
            $order->fulfillment_status = "reviewed";
            $order->status = 8;
            $order->save();
            $data['name'] = $order->name;
        }
        if($prepare)
        {
            $prepare->delivery_status = 'reviewed';
            $prepare->save();
        }

        $data['quantity'] = 0;
        $data['order_id'] = str_replace('#','',$order->name);
        if (is_array($order->payment_gateway_names) && isset($order->payment_gateway_names[0]) && ($order->payment_gateway_names[0]  == "fawrypay (pay by card or at any fawry location)" || $order->payment_gateway_names[0]  == "Paymob"))
            $data['total'] = 0;
        else
            $data['total'] = $order->total_price;

        
        $shipping_cost = 0;
        foreach ($order['shipping_lines'] as $ship) {
            $shipping_cost = $ship['price'];
        }
        $posted_data2 = [];
        foreach ($request['line_item_id'] as $key => $item) {
            
            $prepare_product = PrepareProductList::where('product_id', $item)->where('order_id', $order->id)->first();
            if(!in_array($prepare_product->product_id , $refunds))
                $data['quantity']+= $prepare_product->order_qty;


            $posted_data['lineItemId'] = $item;
            $posted_data['number']= "Lvs-" . $request['order_id'] ;
            $posted_data['shipping_company']= "Best Express";
            $posted_data['no_of_packages']= $prepare_product->order_qty;
            $posted_data['message']= "Ship To Customer";
            $posted_data['tracking_url']= "https://track.bestexpresseg.com/" . "Lvs-" . $request['order_id'];
            $posted_data['notify_customer']= "off";
            $posted_data['order_id']= $request['order_id'];
            $posted_data2[] = $posted_data;

            $fulfillment_order = $this->getFulfillmentLineItem($posted_data, $order);
            $payload = null;

            if($fulfillment_order !== null) {
                if(!in_array($item,$refunds))
                {
                    $check = $this->checkIfCanBeFulfilledDirectly($fulfillment_order);
                    if(!$check) {

                        if ($request["product_status"][$key] == 'prepared') {
                            $payload = $this->getFulfillmentV2PayloadForFulfillment($fulfillment_order->line_items, $posted_data);
                        }
                        $api_endpoint = 'graphql.json';
                    } else {
                        if($store->hasRegisteredForFulfillmentService())
                        $payload = $this->getPayloadForFulfillment($fulfillment_order->line_items, $request);
                        $api_endpoint = 'fulfillments.json';
                    }

                    $endpoint = getShopifyURLForStore($api_endpoint, $store);
                    $headers = getShopifyHeadersForStore($store);
                            //dd("hi",$api_endpoint, $headers, $endpoint,$payload);

                    $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
                    //dump($response);
                }
                

            }

        }
// dd("hi");
        OneOrder::dispatch($user, $store, $order->id);
        if ($prepare->delivery_status != "fulfilled")
        {
            $prepare->delivery_status = 'fulfilled';
            $prepare->save();
        }
        
        if($order)
        {
            $order->fulfillment_status = "fulfilled";
            $order->status = 10;
            $order->save();
        }
            
        
            
        if(isset($response))
        {
            Log::info('Response for fulfillment');
            Log::info(json_encode($response));
        }



        $add_History_sale = new OrderHistory();
        $add_History_sale->order_id = $order->id;
        $add_History_sale->user_id = Auth::user()->id;
        $add_History_sale->action = "Fulfilled";
        $add_History_sale->note = '<strong>' . auth()->user()->name .  " </strong> Has Change Order To Fulfilled<strong>";
        $add_History_sale->created_at = now();
        $add_History_sale->updated_at = now();
        $add_History_sale->save();

        return view('preparation.invoice_order', compact('data','refunds','shipping_cost','order','prepare','auth_user'));

    }

    public function cancelled_Orders(Request $request)
    {
        $daterange = null;
        $sort_search = null;
        $reason = null;
        $paginate_num = 0;

        $orders = CancelledOrder::orderBy('created_at', 'desc');
        if($request->daterange)
        {
            $daterange = $request->daterange;
            $date = explode(' - ', $daterange);
            $startDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[0])->format('Y-m-d');
            $endDate = \Carbon\Carbon::createFromFormat('m/d/Y', $date[1])->format('Y-m-d');
            $orders = $orders->whereDate('created_at', '>=' ,$startDate)->whereDate('created_at', '<=' ,$endDate);
        }

        if($request->search)
        {
            $sort_search = $request->search;
            $orders = $orders->where('reason', 'like', '%' . $sort_search . '%')->orWhere('note', 'like', '%' . $sort_search . '%')->orWhereHas('order', function ($q) use ($sort_search) {
                $q->where('order_number', 'like', '%' . $sort_search . '%');
            });
        }

        if($request->reason)
        {
            $reason = $request->reason;
            $orders = $orders->where('reason', 'like', '%' . $reason . '%');
        }
        if ($request->paginate) {
            $paginate_num = $request->paginate;
        }else {
            $paginate_num = 15;
        }
        $orders_count = $orders->count();
        $orderss = $orders;
        $orders_new = $orders;
        $orders_amount = order2::whereIn('id', $orderss->pluck('order_id')->toArray())->sum('total_price');
        $orders = $orders->paginate($paginate_num);
        return view('reports.cancelled', compact('orders','orders_new','orders_amount','orders_count','sort_search','daterange','reason','paginate_num'));
    }

    public function bulk_order_shipped(Request $request)
    {
        $sales = array();
        $key = 1;
        $auth_user = Auth::user()->id;
        $sale_id = $request['id'];

        $csvData=array('AWB,Name,Addr1,Addr2,Phone,Mobile,City,zone,Contents,Weight,Peices,Shipping Cost,Special Instructions,Contact Person,COD,AWBxAWB');
        $csvAccountingData=array('AWB,Name,Addr1,Mobile,City,zone,subtotal,shipping,total,Payment Method,Payment Reference,Special Instructions,Shipping Note');

        foreach ($sale_id as $id) {

            $sale_data = order2::findorfail($id);
            $sale_prepare_data = Prepare::where('order_id',$id)->first();
            $order_details = $sale_data->shipping_address;

            $address = preg_replace( "/\r|\n/", "", $order_details['address1'] );
            $address = str_replace(['-','/','"',"_",'.',','],'',$address);

            $address2 = preg_replace( "/\r|\n/", "", $order_details['address2'] );
            $address2 = str_replace(['-','/','"',"_",'.',','],'',$address2);

            if (is_array($sale_data->payment_gateway_names) && isset($sale_data->payment_gateway_names[0]) && ($sale_data->payment_gateway_names[0]  == "fawrypay (pay by card or at any fawry location)" || $sale_data->payment_gateway_names[0]  == "Paymob"))
            {   
                $total = 0;
                $payment_method = $sale_data->payment_gateway_names[0];
                $note = $sale_data->note;

            }
            elseif($sale_data->financial_status == "paid")
            {
                $total = 0;
                $payment_method = $sale_data->paid_by;
                $note = $sale_data->note;
            }
            else{
                $total = $sale_data->total_price;
                $payment_method = "Cash On Delivery";
                $note = $sale_data->note;
            }
                

            $order_number = "Lvs" . $sale_data->order_number;


            $sale_data->carrier_id = $request['shipping_company'];
            $sale_data->fulfillment_status = 'shipped';
            $sale_data->status = '10';
            $sale_data->save();

            if(isset($sale_prepare_data))
            {
                $sale_prepare_data->delivery_status = 'shipped';
                $sale_prepare_data->status = '10';
                $sale_prepare_data->save();
            }

            


            if($request['shipping_company'] == 1) {
                $shipping_company = 1;
                $company_name = "Best Express";
            }else {
                $shipping_company = 2;
                $company_name = "Sprint";
            }

            $shipping_cost = 0;
            foreach ($sale_data['shipping_lines'] as $ship) {
                $shipping_cost = $ship['price'];
            }


            $add_History_sale = new OrderHistory();
            $add_History_sale->order_id = $sale_data->id;
            $add_History_sale->user_id = $auth_user;
            $add_History_sale->action = "Shipped";
            $add_History_sale->note = '<strong>' . auth()->user()->name .  " </strong> Has Change Order To Shipped By : <strong>" . $company_name . "</strong> ";
            $add_History_sale->created_at = now();
            $add_History_sale->updated_at = now();
            $add_History_sale->save();

        $sales[] =  $sale_data;

            $csvData[]=   $order_number . ','
                . $order_details['name']  . ','
                . $address  . ','
                . $address2  . ','
                . $order_details['phone']  . ','
                . $order_details['phone']  . ','
                . $order_details['province']  . ','
                . $order_details['city']  . ','
                . '1' . ','
                . '1' . ','
                . '1' . ','
                . $shipping_cost . ','
                . $note . ','
                . ' ' . ','
                . $total .  ','
                ;


            $total_account = $sale_data->total_price;


            $csvAccountingData[] = $order_number . ','
                . $order_details['name'] . ','
                . $address . ','
                . $order_details['phone'] . ','
                . $order_details['province'] . ','
                . $order_details['city'] . ','
                . $sale_data->subtotal_price  . ','
                . $shipping_cost . ','
                . $total_account . ','
                . $payment_method  . ','
                . ($sale_data->transaction_id??"-")  . ','
                . $sale_data->note . ','
                . ',';



        }
        $filename= 'pickup-' . date('Ymd').'-'.date('his'). ".xlsx";


        $file_path= public_path().'/download/'.$filename;

        $file = fopen($file_path, "w+");
        foreach ($csvData as $cellData){
            fputcsv($file, explode(',', $cellData));
        }
        fclose($file);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Csv');

        $objPHPExcel = $reader->load($file_path);
        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, 'Xlsx');
        $filenamexlsx= 'pickup-' . date('Ymd').'-'.date('his'). ".xlsx";
        $file_pathxlsx= public_path().'/download/'. $filenamexlsx;

        $objWriter->save($file_pathxlsx);

        //Acount
        $accountFileName= 'pickup-accounting' . date('Ymd').'-'.date('his'). ".xlsx";
        $account_file_path= public_path().'/download/'.$accountFileName;
        $accountFile = fopen($account_file_path, "w+");
        foreach ($csvAccountingData as $cellDataAccount){
            fputcsv($accountFile, explode(',', $cellDataAccount));
        }
        fclose($accountFile);

        $reader_account = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Csv');

        $objPHPExcelAccount = $reader_account->load($account_file_path);
        $objWriterAccount = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcelAccount, 'Xlsx');

        $file_pathxlsxAccount= public_path().'/download/'. $accountFileName;

        $objWriterAccount->save($file_pathxlsxAccount);


        //  create a new collection instance from the array
        $size = count(collect($sale_id));
        $totalData = $size;
        $totalFiltered = $totalData;

        $create_pickup = new Pickup();
        $create_pickup->pickup_id = date('Ymd'). date('his');
        $create_pickup->user_id = Auth::user()->id;
        $create_pickup->shipment_count = $totalData;
        $create_pickup->company_id = $shipping_company;
        $create_pickup->file_name = $filenamexlsx;
        $create_pickup->file_accounting_name = $accountFileName;
        $create_pickup->created_at = now();
        $create_pickup->updated_at = now();
        $create_pickup->save();
        
        foreach($sales as $sale_data)
        {
            $sale_data->pickup_id = $create_pickup->pickup_id;
            $sale_data->save();
        }
        

        $data = [ 'message' => 'Pickup Created  With total ' . $totalData . 'Shipments '];
        return response()->json($data);

    }

    public function bulk_returns_shipped(Request $request)
    {
        $sales = array();
        $key = 1;
        $auth_user = Auth::user()->id;
        $sale_id = $request['id'];

        $csvData=array('AWB,Name,Addr1,Addr2,Phone,Mobile,City,zone,Contents,Weight,Peices,Special Instructions,Ref,Contact Person,COD,AWBxAWB');
        $csvAccountingData=array('AWB,Order Number,Name,Addr1,Mobile,City,zone,shipping cost, shipping on,total,Return Reason,Return Note,Special Instructions');

        foreach ($sale_id as $id) {

            $sale_data = ReturnedOrder::findorfail($id);
            $old_order = order2::where('id',$sale_data->order_id)->first();
            $order_details = $old_order->shipping_address;

            $address = preg_replace( "/\r|\n/", "", $order_details['address1'] );
            $address = str_replace(['-','/','"',"_",'.',','],'',$address);
                

            $order_number = "Lvs" . $sale_data->order_number;
            $return_number = "Lvr" . $sale_data->return_number;

            if($request['shipping_company'] == 1) {
                $shipping_company = 1;
                $company_name = "Best Express";
            }else {
                $shipping_company = 2;
                $company_name = "Sprint";
            }

            $shipping_cost = 0;
            foreach ($old_order['shipping_lines'] as $ship) {
                $shipping_cost = $ship['price'];
            }

            if($sale_data->shipping_on == "client")
            {
                $total = ($sale_data->amount + $shipping_cost) * -1;
            } else
                $total = $sale_data->amount * -1;


            // $add_History_sale = new OrderHistory();
            // $add_History_sale->order_id = $sale_data->id;
            // $add_History_sale->user_id = $auth_user;
            // $add_History_sale->action = "Returned";
            // $add_History_sale->note = '<strong>' . auth()->user()->name .  " </strong> Has Done return By : <strong>" . $company_name . "</strong> ";
            // $add_History_sale->created_at = now();
            // $add_History_sale->updated_at = now();
            // $add_History_sale->save();

        $sales[] =  $sale_data;

            $csvData[]=   $return_number . ','
                . $order_details['name']  . ','
                . $address  . ','
                . $order_details['address2']  . ','
                . $order_details['phone']  . ','
                . $order_details['phone']  . ','
                . $order_details['province']  . ','
                . $order_details['city']  . ','
                . '1' . ','
                . '1' . ','
                . '1' . ','
                . $sale_data->note . ','
                . ' ' . ','
                . ' ' . ','
                . $total .  ','
                .'1'.','
                ;


            $csvAccountingData[] = 
                $return_number . ','
                .$order_number . ','
                . $order_details['name'] . ','
                . $address . ','
                . $order_details['phone'] . ','
                . $order_details['province'] . ','
                . $order_details['city'] . ','
                . $shipping_cost . ','
                . $sale_data->shipping_on . ','
                . $total  . ','
                . $sale_data->reason . ','
                . $sale_data->note . ','
                . ',';

            $sale_data->shipping_status = "Shipped";
            $sale_data->save();

        }
        $filename= 'return-pickup-' . date('Ymd').'-'.date('his'). ".xlsx";


        $file_path= public_path().'/download/'.$filename;

        $file = fopen($file_path, "w+");
        foreach ($csvData as $cellData){
            fputcsv($file, explode(',', $cellData));
        }
        fclose($file);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Csv');

        $objPHPExcel = $reader->load($file_path);
        $objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, 'Xlsx');
        $filenamexlsx= 'return-pickup-' . date('Ymd').'-'.date('his'). ".xlsx";
        $file_pathxlsx= public_path().'/download/'. $filenamexlsx;

        $objWriter->save($file_pathxlsx);

        //Acount
        $accountFileName= 'return-pickup-accounting' . date('Ymd').'-'.date('his'). ".xlsx";
        $account_file_path= public_path().'/download/'.$accountFileName;
        $accountFile = fopen($account_file_path, "w+");
        foreach ($csvAccountingData as $cellDataAccount){
            fputcsv($accountFile, explode(',', $cellDataAccount));
        }
        fclose($accountFile);

        $reader_account = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Csv');

        $objPHPExcelAccount = $reader_account->load($account_file_path);
        $objWriterAccount = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcelAccount, 'Xlsx');

        $file_pathxlsxAccount= public_path().'/download/'. $accountFileName;

        $objWriterAccount->save($file_pathxlsxAccount);


        //  create a new collection instance from the array
        $size = count(collect($sale_id));
        $totalData = $size;
        $totalFiltered = $totalData;

        $create_pickup = new ReturnPickup();
        $create_pickup->pickup_id = date('Ymd'). date('his');
        $create_pickup->user_id = Auth::user()->id;
        $create_pickup->shipment_count = $totalData;
        $create_pickup->company_id = $shipping_company;
        $create_pickup->file_name = $filenamexlsx;
        $create_pickup->file_accounting_name = $accountFileName;
        $create_pickup->created_at = now();
        $create_pickup->updated_at = now();
        $create_pickup->save();

        foreach($sales as $sale_data)
        {
            $sale_data->pickup_id = $create_pickup->pickup_id;
            $sale_data->save();
        }


        $data = [ 'message' => 'Return Pickup Created  With total ' . $totalData . 'Shipments '];
        return response()->json($data);

    }

    //OLD Update Payment Status
    // public function update_payment_status(Request $request,$id=null)
    // {
    //     $user = Auth::user();
    //     $store = $user->getShopifyStore;
    //     $headers = getShopifyHeadersForStore($store, 'PUT');
    //     if($id)
    //     {
    //         $pending = PendingOrder::find($id);
            
    //         if($pending)
    //         {
    //             $endpoint = getShopifyURLForStore('orders/'.$id.'.json', $store);
    //             $payload['order'] = [
    //                 'payment_gateway_names' =>'["Cash on Delivery (COD)"]',
    //                 'note' => "Payment Changed to Cash on Delivery (COD)"
    //             ];
    //             $response1 = $this->makeAnAPICallToShopify('PUT', $endpoint, null, $headers,$payload);
    //             if ($response1['statusCode'] === 201 || $response1['statusCode'] === 200) {
                    
    //                 if (isset($response1['body'])) {

    //                     $pending->payment_gateway_names = '["Cash on Delivery (COD)"]';
    //                     $pending->note = "Payment Changed to Cash on Delivery (COD)";
    //                     $pending->financial_status = "paid";
    //                     $pending->save();
    //                     $order = order2::where('id',$pending->id)->first();
    //                     if (!$order)
    //                         $order = new order2();
    //                     foreach($pending->toArray() as $key=>$value) {
    //                         if ($key == "table_id")
    //                             continue;
    //                         $order->$key = $value;
    //                     }
    //                     $order->save();
    //                     return redirect()->back()->with('success', 'Order Financial Status Changed Successfully');
    //                 }
    //             }
    //         }
            
    //     }
    //     else if(isset($request->status) && $request->status == "fawry")
    //     {
    //         $order = order2::find($request->order_id);
    //         $pending = PendingOrder::where('id',$request->order_id)->first();
    //         $endpoint = getShopifyURLForStore('orders/'.$request->order_id.'.json', $store);
    //         if($pending)
    //         {
    //             $payload['order'] = [
    //                 'financial_status' => "paid",
    //                 'payment_gateway_names' =>'["fawrypay (pay by card or at any fawry location)"]',
    //                 'note' => "transaction id : ".$request->trx
    //             ];
    //             $response1 = $this->makeAnAPICallToShopify('PUT', $endpoint, null, $headers,$payload);

    //             $payload = $this->markAsPaidMutation($request->order_id);
    //             $api_endpoint = 'graphql.json';
                

    //             $endpoint = getShopifyURLForStore($api_endpoint, $store);
    //             $headers = getShopifyHeadersForStore($store);
                
    //             $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
    //             if ($response1['statusCode'] === 201 || $response1['statusCode'] === 200 && $response['statusCode'] === 201 || $response['statusCode'] === 200) {
                    
    //                 if (isset($response['body']) && isset($response['body']['data'])) {

    //                     $pending->financial_status = "paid";
    //                     //$pending->payment_gateway_names[0] = "fawrypay (pay by card or at any fawry location)";
    //                     $pending->transaction_id = $request->trx;
    //                     $pending->save();
    //                     OneOrder::dispatchNow($user, $store, $request->order_id);
    //                     return redirect()->back()->with('success', 'Order Financial Status Changed Successfully');
    //                 }
    //             }
    //         }
    //     }
    //     return redirect()->back()->with('error', "Something Went Wrong!");
    // }

    public function update_payment_status($id=null,$status = null){
        if($id)
        {
            $order = order2::find($id);
            if($order)
            {
                try {
                    DB::beginTransaction();
                    $order->fulfillment_status = "processing";
                    if ($status == "paid") {
                        $order->financial_status = "paid";
                        $order->payment_gateway_names = '["fawrypay (pay by card or at any Paymob location)"]';
                    } elseif ($status == "cash") {

                        $order->financial_status = "pending";
                        $order->payment_gateway_names = '["fawrypay (pay by card or at any Paymob location)"]';
                    }
                    $order->save();

                    DB::commit();
                    return redirect()->back()->with('success', "Payment Status Updated Successfully");
                }
                catch(\Exception $e)
                {
                    DB::rollBack();
                    return redirect()->back()->with('error', $e->getMessage());
                }
            }
            return redirect()->back()->with('error', "Something Went Wrong!");
        }
        return redirect()->back()->with('error', "Something Went Wrong!");
    }

    public function pickups(Request $request)
    {
    
        // $orders = [19851];
        // foreach($orders as $order)
        // {
        //     $order_db = order2::where('order_number',$order)->first();
        //     if($order_db)
        //     {
        //         $order_db->total_price = $order_db->subtotal_price;
        //         $shipping = $order_db['shipping_lines'];
        //         $shipping[0]['price'] = 0;
        //         $order_db['shipping_lines'] = $shipping;
        //         $order_db->save();
                
        //     }
        // }
        $user = Auth::user();
        $store = $user->getShopifyStore;
        OneOrder::dispatchNow($user, $store,6001677435172);
        $shipping = null;
        $date = $request->date;
        $pickups = Pickup::orderBy('created_at', 'desc');
        $shipping_companies = ['BestExpress','Sprint'];

        if ($date != null) {
            $pickups = $pickups->whereDate('created_at', '=', $date);
        }
        if($request->shipping)
        {
            $shipping = $request->shipping;
            $pickups = $pickups->where('company_id', '=', $shipping);
        }
        $pickups = $pickups->paginate(15)->appends($request->query());
        
        return view('pickups.index', compact('pickups','shipping_companies','shipping','date'));
    }

    public function return_pickups(Request $request)
    {
        $shipping = null;
        $date = $request->date;
        $pickups = ReturnPickup::orderBy('created_at', 'desc');
        $shipping_companies = ['BestExpress','Sprint'];

        if ($date != null) {
            $pickups = $pickups->whereDate('created_at', '=', $date);
        }
        if($request->shipping)
        {
            $shipping = $request->shipping;
            $pickups = $pickups->where('company_id', '=', $shipping);
        }
        $pickups = $pickups->paginate(15)->appends($request->query());
        
        return view('pickups.returns', compact('pickups','shipping_companies','shipping','date'));
    }

    public function generate_invoice($id)
    {
        $order = order2::find($id);
        if(!$order)
        $order = Sale::find($id);
        $refunds = Refund::where('order_name', $order->name)->pluck('line_item_id')->toArray();
        $order_details = PrepareProductList::where('order_id', $id)->where('table_id', $order->table_id)->whereNotIn('product_id',$refunds)->get();
        $auth_user = Auth::user()->id;
        $order_shipping_address = $order->shipping_address;
        $shipping_cost = 0;
        foreach ($order['shipping_lines'] as $ship) {
            $shipping_cost = $ship['price'];
        }
        if (is_array($order->payment_gateway_names) && isset($order->payment_gateway_names[0]) && ($order->payment_gateway_names[0] == "fawrypay (pay by card or at any fawry location)" || $order->payment_gateway_names[0] == "Paymob"))
            $total = 0;
        else
            $total = $order->total_price;

        return view('preparation.single_invoice_order', compact('total','order','shipping_cost', 'order_shipping_address','auth_user','order_details'));
    }

    public function generate_return_invoice($id)
    {
        $return = ReturnedOrder::findOrFail($id);
        $auth_user = Auth::user()->id;
        $order = order2::findOrFail($return->order_id);
        $order_shipping_address = $order->shipping_address;
        $shipping_cost = 0;
        foreach ($order['shipping_lines'] as $ship) {
            $shipping_cost = $ship['price'];
        }
        if($return->shipping_on == "client")
        {
            $total = ($return->amount + $shipping_cost) * -1;
        } else
            $total = $return->amount * -1;

        
        return view('preparation.return_single_invoice_order', compact('total','return','shipping_cost', 'order_shipping_address','auth_user'));
    }

    public function order_history($id)
    {
        $order = order2::findOrFail($id);

        $order_history = OrderHistory::where('order_id',$id)->get();
        $order_shipping_address = $order->shipping_address;
        $ticket_history = TicketHistory::where('order_id',$order->order_number)->get();

        return view('preparation.order_history', compact('order', 'order_history','ticket_history','order_shipping_address'));
    }

    public function update_delivery_status(Request $request)
    {
        if($request->status == 'cancelled') {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            $order = order2::findOrFail($request->order_id);
            $order->fulfillment_status = $request->status;
            $order->status = '14';


            $order->save();
            $get_prepare_order = Prepare::where('order_id',$order->id)->first();

            if($get_prepare_order){

                $get_prepare_order->delete();
                $get_prepare_order_details = PrepareProductList::where('order_id',$order->id)->get();
                if(count($get_prepare_order_details)){
                    foreach ($get_prepare_order_details as $detail) {
                        $detail_record = PrepareProductList::find($detail->id);
                        $detail_record->delete();
                    }
                }

            }
            $payload = [
                'reason' => 'OTHER',
                'staffNote' => $request->note
            ];
            $api_endpoint = 'orders/'.$order->id.'/cancel.json';
            $endpoint = getShopifyURLForStore($api_endpoint, $store);
            $headers = getShopifyHeadersForStore($store);
            $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);

            if($response['statusCode'] === 201 || $response['statusCode'] === 200)
            {
                Log::info('Response for Cancel Order');
                Log::info(json_encode($response));

                $add_History_sale = new OrderHistory();
                $add_History_sale->order_id = $order->id;
                $add_History_sale->user_id = auth()->user()->id;
                $add_History_sale->action = "Cancel";
                $add_History_sale->note = " Order Has Been Cancelled By : <strong>" . auth()->user()->name ."</strong>";
                $add_History_sale->created_at = now();
                $add_History_sale->updated_at = now();
                $add_History_sale->save();

                $cancel_order = new CancelledOrder();
                $cancel_order->order_id = $order->id;
                $cancel_order->user_id = auth()->user()->id;
                $cancel_order->reason = $request->reason;
                $cancel_order->note = $request->note;
                $cancel_order->created_at = now();
                $cancel_order->updated_at = now();
                $cancel_order->save();
                return redirect()->route('prepare.cancelled-orders')->with('success','Order Cancelled Successfully');
            }
            else{
                return redirect()->route('prepare.cancelled-orders')->with('error','Something went wrong');

            }
        }
    }
    public function update_confirmation_status(Request $request)
    {
            $user = Auth::user();
            $order = order2::findOrFail($request->order_id);
            if ($request->status == "confirmed")
            {$order->confirm_status = "confirmed";
            $order->save();}

            $confirm = OrderConfirmation::where('order_id',$order->id)->first();
            $confirm->status = $request->status;
            $confirm->note = $request->note;
            $confirm->extra_data = $request->extra_data;
            $confirm->save();

            $add_History_sale = new OrderHistory();
            $add_History_sale->order_id = $order->id;
            $add_History_sale->user_id = Auth::user()->id;
            $add_History_sale->action = $request->status;
            $add_History_sale->note = $request->note;
            $add_History_sale->created_at = now();
            $add_History_sale->updated_at = now();
            $add_History_sale->save();

            return redirect()->route('orders.confirm')->with('success','Confirmation Status Changes Successfully');

            
    }

    public function fulfillOrderItems(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_status.*' => 'required',]
            , [
                'product_status.*.required' => 'This field is required.',
            ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        $request = $request->all();
        $posted_data = [];
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $order = $store->getOrders()->where('table_id', (int) $request['order_id'])->first();
        $prepare = $store->getPrepares()->where('table_id',(int) $request['order_id'])->first();
        if ($prepare->delivery_status == "hold")
            $old_page = "prepare.hold";
        else
            $old_page = "prepare.new";
        $prepare->delivery_status = "prepared";
        $order->fulfillment_status = "prepared";
        foreach($request['line_item_id'] as $key => $item)
        {
            $prepare_product = PrepareProductList::where('product_id', $item)->where('order_id', $order->id)->first();

            if($request['product_status'][$key] == 'prepared')
            {
                $prepare_product->product_status = 'prepared';
                $history = new UserHistory();
                $history->user_id = auth()->id();
                $history->product_id = $prepare_product->id;
                $history->order_id = $prepare_product->order_id;
                $history->action = "Prepared";
                $history->note = "Product has been marked "."Prepared";
                $history->created_at = now();
                $history->updated_at = now();
                $history->save();
            }
            else{
                $product_variant = ProductVariant::where('sku', $prepare_product->product_sku)->first();
                if($product_variant)
                {
                    $branches = BranchVariant::where('variant_id',$product_variant->id) // still eager load the branch relationship
                    ->get();
                    if(count($branches) == 0)
                    {
                        $prepare_product->product_status = "NA";
                        $history = new UserHistory();
                        $history->user_id = auth()->id();
                        $history->product_id = $prepare_product->id;
                        $history->order_id = $prepare_product->order_id;
                        $history->action = "NA";
                        $history->note = "Product has been marked NA";
                        $history->created_at = now();
                        $history->updated_at = now();
                        $history->save();
                    }
                    elseif(count($branches->where('qty','>',0)) == 0)
                    {
                        $prepare_product->product_status = "shortage";

                        $history = new UserHistory();
                        $history->user_id = auth()->id();
                        $history->product_id = $prepare_product->id;
                        $history->order_id = $prepare_product->order_id;
                        $history->action = "shortage";
                        $history->note = "Product has been marked Shortage";
                        $history->created_at = $prepare_product->order->created_at_date;
                        $history->updated_at = now();
                        $history->save();

                        $shortage_order = ShortageOrder::where('order_id',$prepare_product->order_id)->first();
                        if($shortage_order)
                        {
                            $shortage_order->shortage_items += 1;
                            $shortage_order->shortage_price += $prepare_product->price;
                            $shortage_order->save();
                        }
                        else{
                            $shortage_order = new ShortageOrder();
                            $shortage_order->order_id = $prepare_product->order_id;
                            $shortage_order->assign_to = $prepare_product->prepare->user->id;
                            $shortage_order->shortage_items = 1;
                            $shortage_order->shortage_price = $prepare_product->price;
                            $shortage_order->total_items = $prepare->products->count();
                            $shortage_order->total_price = $prepare->products->sum('price');
                            $shortage_order->hold_date = now();
                            $shortage_order->created_at = $prepare_product->order->created_at_date;
                            $shortage_order->updated_at = now();
                            $shortage_order->save();
                        }
                        

                    }
                    else{
                        $prepare_product->product_status = $request['product_status'][$key];

                        $history = new UserHistory();
                        $history->user_id = auth()->id();
                        $history->product_id = $prepare_product->id;
                        $history->order_id = $prepare_product->order_id;
                        $history->action = $request['product_status'][$key];
                        $history->note = "Product has been marked ".$request['product_status'][$key];
                        $history->created_at = now();
                        $history->updated_at = now();
                        $history->save();
                    }
                }
                else{
                    $prepare_product->product_status = $request['product_status'][$key];
                    $history = new UserHistory();
                    $history->user_id = auth()->id();
                    $history->product_id = $prepare_product->id;
                    $history->order_id = $prepare_product->order_id;
                    $history->action = $request['product_status'][$key];
                    $history->note = "Product has been marked ".$request['product_status'][$key];
                    $history->created_at = now();
                    $history->updated_at = now();
                    $history->save();
                }
                
                $prepare->delivery_status = "hold";
                $prepare->updated_at = now();
                $order->fulfillment_status = "hold";
            }
            $prepare_product->save();
        }
        $order->save();
        $prepare->save();

        if($prepare->delivery_status == "prepared")
        {
            $add_History_sale = new OrderHistory();
            $add_History_sale->order_id = $order->id;
            $add_History_sale->user_id = Auth::user()->id;
            $add_History_sale->action = "Prepared";
            $add_History_sale->note = '<strong>' . auth()->user()->name .  " </strong> Has Change Order To Prepared";
            $add_History_sale->created_at = now();
            $add_History_sale->updated_at = now();
            $add_History_sale->save();
        }
        else{
            $prepare_product = PrepareProductList::whereIn('product_id', $request['line_item_id'])->where('order_id', $order->id)->get();
            foreach($prepare_product as $prod){
                $add_History_sale = new OrderHistory();
                $add_History_sale->order_id = $order->id;
                $add_History_sale->user_id = Auth::user()->id;
                $add_History_sale->action = $prod->product_status;
                $add_History_sale->item = $prod->product_name;
                $add_History_sale->note = '<strong>' . auth()->user()->name .  " </strong> Has Change Order To <strong>".$prod->product_status."</strong>";
                $add_History_sale->created_at = now();
                $add_History_sale->updated_at = now();
                $add_History_sale->save();
            }
        }

        if(Auth::user()->role_id == 5)
        {
            return redirect()->route($old_page)->with('success', 'Order Has been Fulfilled');
        }
            

        return redirect()->route('prepare.all')->with('success', 'Order Has been Fulfilled');
    }

    public function resync_order(Request $request)
    {
        $id = $request->order_id;
        $old = order2::where('name',$id)->orWhere('order_number',$id)->first();
        $order_id = null;
        $resync = null;
        if($old)
        {
            $order_id = $old->id;
            $prepare = Prepare::where('order_id', $old->id)->first();
            
            $resync = new ResyncedOrder();
            $resync->order_id = $old->name;
            $resync->old_status = $old->fulfillment_status;
            $resync->reason = $request->reason;
            $resync->assign_to = $prepare?$prepare->assign_to:null;
            $resync->status = "pending";
            $resync->synced_by = Auth::user()->id;
            $resync->old_total = $old->total_price;
            $resync->created_at = now();
            $resync->updated_at = now();
            $resync->save();

            $old->delete();
            
            
        }
    
        $user = Auth::user();
        $store = $user->getShopifyStore;
        OneOrder::dispatchNow($user, $store, $order_id);
        if($resync)
        {
            $new = order2::where('name',$id)->orWhere('order_number',$id)->first();
            if (!$new)
                dd($resync, order2::where('name', $id)->first());
            else {
                $resync->new_total = $new->total_price;
                $resync->status = "success";
                $resync->save();
                if($prepare)
                {

                    $products = PrepareProductList::where('order_id',$old->id)->get();
                    foreach($products as $product)
                    {
                        $product->delete();
                    }
                }
                if($prepare)
                $prepare->delete();
            }

            $add_History_sale = new OrderHistory();
            $add_History_sale->order_id = $new->id;
            $add_History_sale->user_id = Auth::user()->id;
            $add_History_sale->action = "Edited";
            $add_History_sale->created_at = now();
            $add_History_sale->updated_at = now();
            $add_History_sale->note = " Order Has Been Edited and Re-Synced By : <strong>" . auth()->user()->name ."</strong>";
            $add_History_sale->save();

        }

        
        return redirect()->route('prepare.resynced-orders')->with('success','Order Re-Synced Successfully');


    }
    public function searchReadyOrders(Request $request)
    {
        
        $orders = isset($request->orders) ? $request->orders : [];
        $orderNumber = $request->input('order_number');
        $number = str_replace('Lvs','',$orderNumber);
        $number = str_replace('lvs','',$number);
        $number = str_replace(' ','',$number);
        $order = order2::where('fulfillment_status','fulfilled')->whereNotIn('order_number',$orders)->where('order_number', (int)$number)->first();
        
        if ($order) {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            $api_endpoint = 'orders/'.$order->id.'.json';
            $endpoint = getShopifyURLForStore($api_endpoint, $store);
            $headers = getShopifyHeadersForStore($store);
            $response = $this->makeAnAPICallToShopify('GET', $endpoint, null, $headers);
            // $refunds = Refund::where('order_id',$order->id)->pluck('line_item_id')->toArray();
            // $line_items = collect($order->line_items)->whereNotIn('id',$refunds);
            if ($response['statusCode'] === 201 || $response['statusCode'] === 200 && isset($response['body']['order'])) {
                $payload = $response['body']['order'];
                $total = isset($payload['current_total_price']) ? $payload['current_total_price'] : $payload['total_price'];
                if(isset($payload['fulfillment_status']) && $payload['fulfillment_status'] == "fulfilled" && count($payload['line_items']) == count($order->line_items) && $total == $order->total_price)
                {
                    $html = view('preparation.reviewed_template', compact('order','store'))->render();
                    return response()->json([
                        'status' => 'success',
                        'html' => $html
                    ]);
                }
            }
            return response()->json([
                'status' => 'error',
                'message' => 'Order Mismatch, Please Re-Sync It First'
            ]);
            
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Order not found'
            ]);
        }
    }

    public function resynced_orders(Request $request)
    {
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        
        $orders = ResyncedOrder::orderBy('created_at','desc');
        
        if($request->delivery_status)
        {
            $delivery_status = $request->delivery_status;
            $orders = $orders->where('old_status', $delivery_status);
        }
        if($date !=null)
        {
            $orders = $orders->whereDate('created_at', '=',$date);
        }

        if ($request->search) {
            $sort_search = $request->search;
            $orders = $orders->where('order_id', 'like', '%' . $sort_search . '%')
                ->orWhere('reason', 'like', '%' . $sort_search . '%')
                ->orWhere('old_status', 'like', '%' . $sort_search . '%');
        }
        $orders = $orders->paginate(15)->appends($request->query());
        return view('orders.resynced_orders', compact('orders','date','delivery_status','sort_search'));

    }

    public function fulfillOrder(FulfillOrder $request) {
        try {
            $sendAndAcceptresponse = null;
            $request = $request->all();
            $user = Auth::user();
            $store = $user->getShopifyStore;
            $order = $store->getOrders()->where('table_id', (int) $request['order_id'])->first();
            $fulfillment_order = $this->getFulfillmentLineItem($request, $order);

            if($fulfillment_order !== null) {
                $check = $this->checkIfCanBeFulfilledDirectly($fulfillment_order);
                if($check) {
                    $payload = $this->getFulfillmentV2PayloadForFulfillment($fulfillment_order->line_items, $request);
                    $api_endpoint = 'graphql.json';
                } else {
                    if($store->hasRegisteredForFulfillmentService())
                        $sendAndAcceptresponse = $this->sendAndAcceptFulfillmentRequests($store, $fulfillment_order);
                    $payload = $this->getPayloadForFulfillment($fulfillment_order->line_items, $request);
                    $api_endpoint = 'fulfillments.json';
                }

                $endpoint = getShopifyURLForStore($api_endpoint, $store);
                $headers = getShopifyHeadersForStore($store);
                $response = $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);

                if($response['statusCode'] === 201 || $response['statusCode'] === 200)
                    OneOrder::dispatch($user, $store, $order->id);

                Log::info('Response for fulfillment');
                Log::info(json_encode($response));
                return response()->json(['response' => $response, 'sendAndAcceptresponse' => $sendAndAcceptresponse ?? null]);
            }
            return response()->json(['status' => false]);
        } catch(Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage().' '.$e->getLine()]);
        }
    }

    private function sendAndAcceptFulfillmentRequests($store, $fulfillment_order) {
        try {
            $responses = [];
            $responses[] = $this->callFulfillmentRequestEndpoint($store, $fulfillment_order);
            $responses[] = $this->callAcceptRequestEndpoint($store, $fulfillment_order);
            return ['status' => true, 'message' => 'Done', 'responses' => $responses];
        } catch(Exception $e) {
            return ['status' => false, 'error' => $e->getMessage().' '.$e->getLine()];
        }
    }

    private function callFulfillmentRequestEndpoint($store, $fulfillment_order) {
        $endpoint = getShopifyURLForStore('fulfillment_orders/'.$fulfillment_order->id.'/fulfillment_request.json', $store);
        $headers = getShopifyHeadersForStore($store);
        $payload = [
            'fulfillment_request' => [
                'message' => 'Please fulfill ASAP'
            ]
        ];
        return $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
    }

    private function callAcceptRequestEndpoint($store, $fulfillment_order) {
        $endpoint = getShopifyURLForStore('fulfillment_orders/'.$fulfillment_order->id.'/fulfillment_request/accept.json', $store);
        $headers = getShopifyHeadersForStore($store);
        $payload = [
            'fulfillment_request' => [
                'message' => 'Accepted the request on '.date('F d, Y')
            ]
        ];
        return $this->makeAnAPICallToShopify('POST', $endpoint, null, $headers, $payload);
    }

    public function products()
    {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $products = $store->getProducts()
                          ->select(['table_id', 'title', 'product_type', 'vendor', 'created_at', 'tags'])
                          ->orderBy('created_at', 'desc')
                          ->get();
        return view('products.index', ['products' => $products]);
    }
    public function product_variants()
    {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $products = $store->getVariants()
                          ->select(['image_id','inventory_quantity', 'title', 'product_id', 'price', 'created_at', 'sku','barcode'])
                          ->orderBy('created_at', 'desc')
                          ->paginate(15);
        return view('products.variants', ['products' => $products]);
    }
    

    public function syncProducts() {
        try {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            Product::dispatch($user, $store);
            return back()->with('success', 'Product sync successful');
        } catch(Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error :'.$e->getMessage().' '.$e->getLine()]);
        }
    }

    public function syncCustomers() {
        try {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            Customer::dispatch($user, $store);
            return back()->with('success', 'Customer sync successful');
        } catch(Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error :'.$e->getMessage().' '.$e->getLine()]);
        }
    }

    //Sync orders for Store using either GraphQL or REST API
    public function syncOrders() {
        try {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            //Order::dispatch($user, $store, 'GraphQL'); //For using GraphQL API
            Order::dispatch($user, $store,"&fulfillment_status=unfulfilled"); //For using REST API
            return back()->with('success', 'Order sync successful');
        } catch(Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error :'.$e->getMessage().' '.$e->getLine()]);
        }
    }


    //Old Pending Payment Orders
    // public function pending_payment_orders(Request $request) {
    //     $date = $request->date;
    //     $sort_search = null;
    //     $delivery_status = null;
    //     $payment_status = '';
    //     $prepare_users_list = [];
    //     $user = Auth::user();
    //     $store = $user->getShopifyStore;
    //     $orders = $store->getPendings()->whereNull('transaction_id')->where('financial_status',"!=","paid");
    //     if ($request->search) {
    //         $sort_search = $request->search;
    //         $orders = $orders->where('name', 'like', '%' . $sort_search . '%')
    //             ->orWhere('id', 'like', '%' . $sort_search . '%')
    //             ->orWhere('fulfillment_status', 'like', '%' . $sort_search . '%');
    //     }
    //     if($date !=null)
    //     {
    //         $orders = $orders->whereDate('created_at_date', '=',$date);
    //     }

    //     if($request->delivery_status)
    //     {
    //         $delivery_status = $request->delivery_status;
    //         $orders = $orders->where('fulfillment_status', $delivery_status);
    //     }

    //     $orders = $orders->orderBy('table_id', 'asc')
    //                     ->paginate(15)->appends($request->query());


    //     $prepare_users = User::where('role_id', '5')->get();
    //     if(count($prepare_users)) {
    //         foreach ($prepare_users as $key => $prepare) {

    //             $prepare_users_list['id'][$key] = $prepare->id;
    //             $prepare_users_list['name'][$key] = $prepare->name;
    //         }
    //     }
    //     return view('orders.pending', compact('orders','prepare_users_list','date','sort_search','delivery_status','payment_status'));
    // }
    public function pending_payment_orders(Request $request) {
        $date = $request->date;
        $sort_search = null;
        $delivery_status = null;
        $payment_status = '';
        $prepare_users_list = [];
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $orders = $store->getOrders()->whereNotNull('transaction_id')->where('fulfillment_status',"Pending")->where('financial_status',"pending");
        if ($request->search) {
            $sort_search = $request->search;
            $orders = $orders->where('name', 'like', '%' . $sort_search . '%')
                ->orWhere('id', 'like', '%' . $sort_search . '%')
                ->orWhere('fulfillment_status', 'like', '%' . $sort_search . '%');
        }
        if($date !=null)
        {
            $orders = $orders->whereDate('created_at_date', '=',$date);
        }

        if($request->delivery_status)
        {
            $delivery_status = $request->delivery_status;
            $orders = $orders->where('fulfillment_status', $delivery_status);
        }

        $orders = $orders->orderBy('table_id', 'asc')
                        ->paginate(15)->appends($request->query());


        $prepare_users = User::where('role_id', '5')->get();
        if(count($prepare_users)) {
            foreach ($prepare_users as $key => $prepare) {

                $prepare_users_list['id'][$key] = $prepare->id;
                $prepare_users_list['name'][$key] = $prepare->name;
            }
        }
        return view('orders.pending', compact('orders','prepare_users_list','date','sort_search','delivery_status','payment_status'));
    }
    public function sync_pending_payment_orders(){
        try {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            //Order::dispatch($user, $store, 'GraphQL'); //For using GraphQL API
            Order::dispatch($user, $store,"&financial_status=pending&fulfillment_status=unfulfilled",'pending_orders'); //For using REST API
            return back()->with('success', 'Order sync successful');
        } catch(Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error :'.$e->getMessage().' '.$e->getLine()]);
        }
    }

    public function acceptCharge(Request $request) {
        try {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            $charge_id = $request->charge_id;
            $user_id = $request->user_id;
            $endpoint = getShopifyURLForStore('application_charges/'.$charge_id.'.json', $store);
            $headers = getShopifyHeadersForStore($store);
            $response = $this->makeAnAPICallToShopify('GET', $endpoint, null, $headers);
            if($response['statusCode'] === 200) {
                $body = $response['body']['application_charge'];
                if($body['status'] === 'active') {
                    return redirect()->route('members.create')->with('success', 'Sub user created!');
                }
            }
            User::where('id', $user_id)->delete();
            return redirect()->route('members.create')->with('error', 'Some problem occurred while processing the transaction. Please try again.');
        } catch(Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error :'.$e->getMessage().' '.$e->getLine()]);
        }
    }

    public function customers() {
        return view('customers.index');
    }

    public function list(Request $request) {
        try {
            if($request->ajax()) {
                $request = $request->all();
                $store = Auth::user()->getShopifyStore; //Take the auth user's shopify store
                $customers = $store->getCustomers(); //Load the relationship (Query builder)
                $customers = $customers->select(['first_name', 'last_name', 'email', 'phone', 'created_at']); //Select columns
                if(isset($request['search']) && isset($request['search']['value']))
                    $customers = $this->filterCustomers($customers, $request); //Filter customers based on the search term
                $count = $customers->count(); //Take the total count returned so far
                $limit = $request['length'];
                $offset = $request['start'];
                $customers = $customers->offset($offset)->limit($limit); //LIMIT and OFFSET logic for MySQL
                if(isset($request['order']) && isset($request['order'][0]))
                    $customers = $this->orderCustomers($customers, $request); //Order customers based on the column
                $data = [];
                $query = $customers->toSql(); //For debugging the SQL query generated so far
                $rows = $customers->get(); //Fetch from DB by using get() function
                if($rows !== null)
                    foreach ($rows as $key => $item)
                        $data[] = array_merge(
                                        ['#' => $key + 1], //To show the first column, NOTE: Do not show the table_id column to the viewer
                                        $item->toArray()
                                );
                return response()->json([
                    "draw" => intval(request()->query('draw')),
                    "recordsTotal"    => intval($count),
                    "recordsFiltered" => intval($count),
                    "data" => $data,
                    "debug" => [
                        "request" => $request,
                        "sqlQuery" => $query
                    ]
                ], 200);
            }

        } catch(Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage().' '.$e->getLine()], 500);
        }
    }

    //Returns a Query builders after setting the logic for ordering customers by specified column
    public function orderCustomers($customers, $request) {
        $column = $request['order'][0]['column'];
        $dir = $request['order'][0]['dir'];
        $db_column = null;
        switch($column) {
            case 0: $db_column = 'table_id'; break;
            case 1: $db_column = 'first_name'; break;
            case 2: $db_column = 'email'; break;
            case 3: $db_column = 'phone'; break;
            case 4: $db_column = 'created_at'; break;
            default: $db_column = 'table_id';
        }
        return $customers->orderBy($db_column, $dir);
    }

    //Returns a Query builder after setting the logic for filtering customers by the search term
    public function filterCustomers($customers, $request) {
        $term = $request['search']['value'];
        return $customers->where(function ($query) use ($term) {
            $query->where(
                        DB::raw("CONCAT(`first_name`, ' ', `last_name`)"), 'LIKE', "%".$term."%"
                    )
                  ->orWhere('email', 'LIKE', '%'.$term.'%')
                  ->orWhere('phone', 'LIKE', '%'.$term.'%');
        });
    }

    public function syncLocations() {
        try {
            $user = Auth::user();
            $store = $user->getShopifyStore;
            Locations::dispatch($user, $store);
            return back()->with('success', 'Locations synced successfully');
        } catch(Exception $e) {
            dd($e->getMessage().' '.$e->getLine());
        }
    }

    public function syncOrder($id) {
        $user = Auth::user();
        $store = $user->getShopifyStore;
        $order = $store->getOrders()->where('table_id', $id)->select('id')->first();
        OneOrder::dispatchNow($user, $store, $order->id);
        return redirect()->route('shopify.order.show', $id)->with('success', 'Order synced!');
    }
    //PREPARATION

    //Users
    public function all_users(Request $request)
    {
        $search = null;
        $users = User::orderBy('created_at','DESC');
        if($request->search)
        {
            $search = $request->search;
            $users = $users->where('name', 'like', '%' . $search . '%')->orWhere('email', 'like', '%' . $search . '%')->orWhere('phone', 'like', '%' . $search . '%');
        }
        $users = $users->paginate(15)->appends($request->query());
        return view('users.all',compact('users','search'));
    }

    public function create_user()
    {
        $roles = Role::select('name','id')->get();
        return view('users.create',compact('roles'));
    }
}
