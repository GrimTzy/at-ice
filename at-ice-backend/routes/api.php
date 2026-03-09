<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

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