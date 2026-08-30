<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SchemaController extends Controller
{
    public function index(): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        return view('studio.schema.index', ['profile' => $profile, 'schema' => $profile?->schema_stats]);
    }
}
