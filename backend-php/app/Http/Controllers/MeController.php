<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'username' => 'marcin',
            'id' => 1,
        ]);
    }
}

