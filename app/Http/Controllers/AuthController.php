<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class AuthController extends Controller
{
    public function generateToken(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:8',
            ]);

            $credentials = $request->only('email', 'password');

            if (Auth::attempt($credentials)) {
                $user = Auth::user();
                $token = $user->createToken('api-token')->plainTextToken;
                return response()->json(['token' => $token], 200);
            }

            Log::warning('Token generation failed: Invalid credentials', ['email' => $request->email]);
            return response()->json(['error' => 'Invalid credentials'], 401);
        } catch (\Exception $e) {
            Log::error('Token generation error: ' . $e->getMessage());
            return response()->json(['error' => 'Token generation failed'], 500);
        }
    }
}