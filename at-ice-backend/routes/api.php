<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePerformanceController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\InventoryAlertController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DeliveryProofController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\BiController;
use App\Http\Controllers\FaceDataController;
use App\Http\Controllers\CustomerPriceController;
use App\Http\Controllers\PickupLogController;
use App\Http\Controllers\IceLoadingController;
use App\Http\Controllers\StockOutRequestController;
use App\Http\Controllers\CustomerPortalController;

//Public
Route::post('/login', [AuthController::class, 'login']);

// Face Descriptors — public, used by AttendanceStation (kiosk has no login/token)
Route::get('face-descriptors', [FaceDataController::class, 'descriptors']);

// Attendance recording — public, used by AttendanceStation (kiosk has no login/token)
Route::post('attendances', [AttendanceController::class, 'store']);

//Protected
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // Change own password (all roles). Throttled: 5 attempts per minute.
    Route::put('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:5,1');
    Route::post('/logout-others',  [AuthController::class, 'logoutOthers'])->middleware('throttle:5,1');

    // ADMIN ROUTES
    Route::middleware('role:admin')->group(function () {

        // Employees — READ ONLY overview for Admin (Name/Position/Role/Shift
        // only, per team decision). Reuses EmployeeController@index() so
        // both Admin's and HR's employee lists stay in sync automatically —
        // same filtered dataset, different route/gate. Full CRUD, Payroll,
        // and Performance are HR-only, see role:hr group below.
        Route::get('employees-summary', [EmployeeController::class, 'index']);

        // Delivery Management (Admin assigns deliveries)
        Route::get('deliveries',             [DeliveryController::class, 'index']);
        Route::post('deliveries',            [DeliveryController::class, 'store']);
        Route::get('deliveries/{id}',        [DeliveryController::class, 'show']);
        Route::put('deliveries/{id}/cancel', [DeliveryController::class, 'cancel']);
        
        Route::get('products-summary', [ProductController::class, 'index']);

        // Delivery Proofs (Admin views)
        Route::get('delivery-proofs',        [DeliveryProofController::class, 'index']);
        Route::get('delivery-proofs/{id}',   [DeliveryProofController::class, 'show']);

        // Receipts (Admin views + manages)
        Route::get('receipts/overdue',       [ReceiptController::class, 'overdue']);
        Route::get('receipts/summary',       [ReceiptController::class, 'summary']);
        Route::get('receipts',               [ReceiptController::class, 'index']);
        Route::get('receipts/{id}',          [ReceiptController::class, 'show']);
        Route::patch('receipts/{id}/paid',   [ReceiptController::class, 'markAsPaid']);

        // CRM
        Route::get('customers',              [CustomerController::class, 'index']);
        Route::post('customers',             [CustomerController::class, 'store']);
        Route::get('customers/{id}',         [CustomerController::class, 'show']);
        Route::put('customers/{id}',         [CustomerController::class, 'update']);
        Route::delete('customers/{id}',      [CustomerController::class, 'destroy']);
        Route::post('customers/{id}/create-account', [CustomerController::class, 'createAccount']);

        Route::get('orders',                 [OrderController::class, 'index']);
        Route::post('orders',                [OrderController::class, 'store']);
        Route::get('orders/{id}',            [OrderController::class, 'show']);
        Route::put('orders/{id}/status',     [OrderController::class, 'updateStatus']);
        Route::put('orders/{id}/cancel',     [OrderController::class, 'cancel']);

        Route::get('payments',               [PaymentController::class, 'index']);
        Route::post('payments',              [PaymentController::class, 'store']);

        // Customer Pricing (Admin sets per-customer unit prices for Bags/Small Sack/Big Sack)
        Route::get('customer-prices',            [CustomerPriceController::class, 'index']);
        Route::get('customer-prices/{customer}', [CustomerPriceController::class, 'show']);
        Route::put('customer-prices/{customer}', [CustomerPriceController::class, 'update']);

        // Pickup Logs (Admin full access — Alexandra/J-Maxine plant pickups, no price/receipt)
        Route::apiResource('pickup-logs', PickupLogController::class)->only(['update', 'destroy']);

        // Stock-Out Requests (Admin approves/rejects/releases — consolidated onto /admin/stock-out-approvals since v29.2)
        Route::put('stock-out-requests/{stockOutRequest}/approve', [StockOutRequestController::class, 'approve']);
        Route::put('stock-out-requests/{stockOutRequest}/reject',  [StockOutRequestController::class, 'reject']);
        Route::put('stock-out-requests/{stockOutRequest}/release', [StockOutRequestController::class, 'release']);

        // BUSINESS INTELLIGENCE 
        Route::get('bi/summary',            [BiController::class, 'summary']);
        Route::get('bi/sales-overview',     [BiController::class, 'salesOverview']);
        Route::get('bi/delivery-stats',     [BiController::class, 'deliveryStats']);
        Route::get('bi/inventory-summary',  [BiController::class, 'inventorySummary']);
        Route::get('bi/attendance-today',   [BiController::class, 'attendanceToday']);
        Route::get('bi/overdue-receipts',   [BiController::class, 'overdueReceipts']);
        Route::get('bi/expenses',           [BiController::class, 'getExpenses']);
        Route::post('bi/expenses',          [BiController::class, 'storeExpense']);
        Route::get('bi/ai-summary', [BiController::class, 'aiSummary']);

        Route::get('/admin/dashboard', function () {
            return response()->json(['message' => 'Welcome Admin!']);
        });
    });

    // HR ROUTES
    Route::middleware('role:hr')->group(function () {

        // Employee Management — full CRUD (create, edit, deactivate)
        Route::apiResource('employees', EmployeeController::class);

        // Payroll — full CRUD, computation happens on the frontend
        // (Sweldong Pinoy-style: SSS/PhilHealth/Pag-IBIG/withholding tax),
        // this endpoint just stores whatever computed values are sent.
        Route::apiResource('payrolls', PayrollController::class);

        // Employee Performance — new (v29.7), HR-only reviews per employee
        Route::apiResource('employee-performance', EmployeePerformanceController::class);

        Route::get('attendances',                        [AttendanceController::class, 'index']);
        Route::put('attendances/{id}',                   [AttendanceController::class, 'update']);
        Route::get('attendances/employee/{employee_id}', [AttendanceController::class, 'getByEmployee']);

        Route::get('leave-requests',             [LeaveRequestController::class, 'index']);
        Route::post('leave-requests',            [LeaveRequestController::class, 'store']);
        Route::get('leave-requests/{id}',        [LeaveRequestController::class, 'show']);
        Route::put('leave-requests/{id}/status', [LeaveRequestController::class, 'updateStatus']);
        Route::delete('leave-requests/{id}',     [LeaveRequestController::class, 'destroy']);

        Route::get('applicants/upcoming',        [ApplicantController::class, 'upcoming']);
        Route::get('applicants',                 [ApplicantController::class, 'index']);
        Route::post('applicants',                [ApplicantController::class, 'store']);
        Route::get('applicants/{id}',            [ApplicantController::class, 'show']);
        Route::put('applicants/{id}',            [ApplicantController::class, 'update']);
        Route::patch('applicants/{id}/status',   [ApplicantController::class, 'updateStatus']);
        Route::delete('applicants/{id}',         [ApplicantController::class, 'destroy']);

        // Face Enrollment (HR manages)
        Route::post('face-enroll',        [FaceDataController::class, 'enroll']);
        Route::get('face-data',           [FaceDataController::class, 'index']);
        Route::put('face-data/{id}',      [FaceDataController::class, 'update']);
        Route::delete('face-data/{id}',   [FaceDataController::class, 'destroy']);
    });

    // EMPLOYEE: CHECKER ROUTES
    Route::middleware('role:employee,checker')->group(function () {

        Route::apiResource('products', ProductController::class);

        Route::get('stock-movements',               [StockMovementController::class, 'index']);
        Route::post('stock-movements',              [StockMovementController::class, 'store']);
        Route::get('stock-movements/{id}',          [StockMovementController::class, 'show']);
        Route::put('stock-movements/{id}/confirm',  [StockMovementController::class, 'confirm']);
        Route::put('stock-movements/{id}/cancel',   [StockMovementController::class, 'cancel']);

        Route::get('inventory-alerts',              [InventoryAlertController::class, 'index']);
        Route::put('inventory-alerts/{id}/resolve', [InventoryAlertController::class, 'resolve']);

        Route::apiResource('suppliers', SupplierController::class);

        // Pickup Logs (Checker can log + view, cannot edit/delete — corrections go through Admin)
        Route::get('pickup-logs',              [PickupLogController::class, 'index']);
        Route::post('pickup-logs',             [PickupLogController::class, 'store']);
        Route::get('pickup-logs/{pickupLog}',  [PickupLogController::class, 'show']);

        // Ice Loading Count (simple count log per delivery — NOT a stock deduction)
        Route::get('ice-loadings',                  [IceLoadingController::class, 'index']);
        Route::post('ice-loadings',                 [IceLoadingController::class, 'store']);
        Route::get('ice-loadings/{iceLoading}',     [IceLoadingController::class, 'show']);
    });

    // EMPLOYEE: SALESMAN ROUTES
    Route::middleware('role:employee,salesman')->group(function () {

        Route::get('my-deliveries',          [DeliveryProofController::class, 'myDeliveries']);
        Route::post('delivery-confirmation', [DeliveryProofController::class, 'confirm']);
        Route::get('customer-prices/{customer}/lookup', [CustomerPriceController::class, 'lookup']);
    });

    // EMPLOYEE: MAINTENANCE ROUTES
    Route::middleware('role:employee,maintenance')->group(function () {

        Route::post('stock-out-requests', [StockOutRequestController::class, 'store']);
    });

    // EMPLOYEE: STOCK-OUT VIEW (shared — Checker and Maintenance can both view)
    Route::middleware('role:employee,checker,maintenance')->group(function () {

        Route::get('stock-out-requests',                   [StockOutRequestController::class, 'index']);
        Route::get('stock-out-requests/{stockOutRequest}',  [StockOutRequestController::class, 'show']);
    });

    // DRIVER ROUTES
    Route::middleware('role:driver')->group(function () {

        Route::get('driver/deliveries',               [DeliveryController::class, 'driverDeliveries']);
        Route::patch('driver/deliveries/{id}/status', [DeliveryController::class, 'updateStatus']);
        Route::get('/driver/dashboard', function () {
            return response()->json(['message' => 'Welcome Driver!']);
        });
    });

    // CUSTOMER ROUTES
    Route::middleware('role:customer')->group(function () {

        // my-orders/my-payments read from Order/Payment, which are dead
        // code as of v29.9 (the real flow is Delivery -> DeliveryProof ->
        // Receipt). Left in place, unused by the portal below, no
        // functional risk in leaving them.
        Route::get('my-orders',   [OrderController::class, 'myOrders']);
        Route::get('my-payments', [PaymentController::class, 'myPayments']);

        // Customer Portal — real endpoints, scoped to the logged-in
        // customer via customers.user_id (see CustomerPortalController).
        Route::get('my-profile',          [CustomerPortalController::class, 'myProfile']);
        Route::get('my-delivery-history', [CustomerPortalController::class, 'myDeliveries']);
        Route::get('my-receipts',        [CustomerPortalController::class, 'myReceipts']);
        Route::get('my-delivery-proofs', [CustomerPortalController::class, 'myDeliveryProofs']);
        Route::get('my-prices',          [CustomerPortalController::class, 'myPrices']);

        Route::get('/customer/dashboard', function () {
            return response()->json(['message' => 'Welcome Customer!']);
        });
    });
});