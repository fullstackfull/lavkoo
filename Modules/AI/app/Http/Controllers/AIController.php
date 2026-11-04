<?php

namespace Modules\AI\app\Http\Controllers;

use App\Http\Controllers\Controller;

class AIController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('ai::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('ai::create');
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('ai::show', ['id' => $id]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('ai::edit', ['id' => $id]);
    }
}
