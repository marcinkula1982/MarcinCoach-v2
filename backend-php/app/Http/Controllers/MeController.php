<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        return response()->json([
            'username' => (string) $request->header('x-username', ''),
            'id' => $userId,
        ]);
    }
}

