<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        // Only set current_instance_id if provided in query string
        if ($request->has('instance_id')) {
            $instanceId = $request->input('instance_id');
            Session::put('current_instance_id', $instanceId);
        }
        return view('welcome');
    }

}