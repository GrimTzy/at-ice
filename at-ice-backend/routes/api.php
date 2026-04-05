<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\InventoryAlertController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DeliveryProofController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // HRIS Routes
    Route::apiResource('employees', EmployeeController::class);

    Route::get('attendances', [AttendanceController::class, 'index']);
    Route::post('attendances', [AttendanceController::class, 'store']);
    Route::put('attendances/{id}', [AttendanceController::class, 'update']);
    Route::get('attendances/employee/{employee_id}', [AttendanceController::class, 'getByEmployee']);

    Route::get('leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('leave-requests', [LeaveRequestController::class, 'store']);
    Route::get('leave-requests/{id}', [LeaveRequestController::class, 'show']);
    Route::put('leave-requests/{id}/status', [LeaveRequestController::class, 'updateStatus']);
    Route::delete('leave-requests/{id}', [LeaveRequestController::class, 'destroy']);

    Route::apiResource('payrolls', PayrollController::class);

    // IMS Routes
    Route::apiResource('products', ProductController::class);

    Route::get('stock-movements', [StockMovementController::class, 'index']);
    Route::post('stock-movements', [StockMovementController::class, 'store']);
    Route::get('stock-movements/{id}', [StockMovementController::class, 'show']);
    Route::put('stock-movements/{id}/confirm', [StockMovementController::class, 'confirm']);
    Route::put('stock-movements/{id}/cancel', [StockMovementController::class, 'cancel']);

    Route::get('inventory-alerts', [InventoryAlertController::class, 'index']);
    Route::put('inventory-alerts/{id}/resolve', [InventoryAlertController::class, 'resolve']);

    // SCM Routes
    Route::apiResource('suppliers', SupplierController::class);

    Route::get('deliveries', [DeliveryController::class, 'index']);
    Route::post('deliveries', [DeliveryController::class, 'store']);
    Route::get('deliveries/{id}', [DeliveryController::class, 'show']);
    Route::put('deliveries/{id}/cancel', [DeliveryController::class, 'cancel']);

    Route::post('deliveries/{id}/proof', [DeliveryProofController::class, 'store']);
    Route::get('deliveries/{id}/proof', [DeliveryProofController::class, 'index']);

    // Admin only routes
    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/dashboard', function () {
            return response()->json(['message' => 'Welcome Admin!']);
        });
    });

    // Employee only routes
    Route::middleware('role:employee')->group(function () {
        Route::get('/employee/dashboard', function () {
            return response()->json(['message' => 'Welcome Employee!']);
        });
    });

    // Driver only routes
    Route::middleware('role:driver')->group(function () {
        Route::get('/driver/dashboard', function () {
            return response()->json(['message' => 'Welcome Driver!']);
        });
    });

    // Customer only routes
    Route::middleware('role:customer')->group(function () {
        Route::get('/customer/dashboard', function () {
            return response()->json(['message' => 'Welcome Customer!']);
        });
    });
});