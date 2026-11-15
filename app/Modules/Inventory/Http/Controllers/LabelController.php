<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Label designer/printer. Rendering & barcodes happen in the browser (JsBarcode + print CSS). */
class LabelController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate(['variants' => ['nullable', 'array', 'max:500'], 'variants.*' => ['integer'], 'copies' => ['nullable', 'array'], 'copies.*' => ['integer', 'min:0', 'max:1000']]);

        return Inertia::render('Inventory/Labels/Index', [
            'preselected' => array_map('intval', $request->input('variants', [])),
            'copies' => array_map('intval', $request->input('copies', [])),   // variant id → copies (e.g. pieces received)
            'businessName' => settings('business.name'),
        ]);
    }
}
