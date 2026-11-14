<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Attribute;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBarcode;
use App\Modules\Inventory\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('products.manage');
    }

    protected function prepareForValidation(): void
    {
        // Barcodes arrive as a comma/space separated string per variant.
        $variants = collect($this->input('variants', []))->map(function ($v) {
            $codes = $v['barcodes'] ?? [];
            if (is_string($codes)) {
                $codes = preg_split('/[\s,]+/', $codes, -1, PREG_SPLIT_NO_EMPTY);
            }

            return [...$v, 'barcodes' => array_values(array_unique($codes)), 'sku' => isset($v['sku']) ? trim((string) $v['sku']) : null];
        })->all();

        $this->merge(['variants' => $variants]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'article_no' => ['nullable', 'string', 'max:50'],
            'material' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::in([Product::TYPE_SINGLE, Product::TYPE_VARIABLE])],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'unit_id' => ['required', Rule::exists('units', 'id')],
            'tax_rate_id' => ['required', Rule::exists('tax_rates', 'id')],
            'hsn_code' => ['nullable', 'regex:/^\d{4,8}$/'],
            'price_includes_tax' => ['required', 'boolean'],
            'track_stock' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'image' => ['nullable', 'image', 'max:2048'],
            'remove_image' => ['nullable', 'boolean'],

            'variants' => ['required', 'array', 'min:1', 'max:200'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.options' => [Rule::requiredIf($this->input('type') === Product::TYPE_VARIABLE), 'nullable', 'array'],
            'variants.*.options.*' => ['nullable', 'string', 'max:50'],
            'variants.*.sku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-_.\/]+$/'],
            'variants.*.barcodes' => ['array', 'max:10'],
            'variants.*.barcodes.*' => ['string', 'max:50', 'regex:/^[A-Za-z0-9\-]+$/'],
            'variants.*.purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.mrp' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.reorder_level' => ['nullable', 'numeric', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.opening_stock' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'variants.*.options' => 'size / colour',
            'variants.*.sku' => 'SKU',
            'variants.*.barcodes.*' => 'barcode',
            'variants.*.purchase_price' => 'purchase price',
            'variants.*.selling_price' => 'selling price',
            'variants.*.mrp' => 'MRP',
            'hsn_code' => 'HSN code',
            'tax_rate_id' => 'GST rate',
            'unit_id' => 'unit',
        ];
    }

    public function messages(): array
    {
        return ['hsn_code.regex' => 'HSN code must be 4 to 8 digits.'];
    }

    /** Same attributes on every variant, a value for each, and no duplicate combinations. */
    private function validateOptions(Validator $validator, array $variants): void
    {
        $known = Attribute::pluck('id')->map(fn ($id) => (string) $id)->all();
        $expected = null;
        $combos = [];

        foreach ($variants as $i => $v) {
            $options = $v['options'] ?? [];
            $keys = array_map('strval', array_keys($options));
            sort($keys);

            if (! $keys) {
                $validator->errors()->add("variants.$i.options", 'Choose the size / colour of this variant.');

                continue;
            }
            if (array_diff($keys, $known)) {
                $validator->errors()->add("variants.$i.options", 'Unknown attribute.');

                continue;
            }
            if ($expected === null) {
                $expected = $keys;
            } elseif ($keys !== $expected) {
                $validator->errors()->add('variants', 'All variants must use the same attributes (e.g. Colour and Size).');

                return;
            }
            if (in_array('', array_map(fn ($x) => trim((string) $x), $options), true)) {
                $validator->errors()->add("variants.$i.options", 'Fill in every attribute of this variant.');

                continue;
            }

            ksort($options);
            $combo = mb_strtolower(implode('|', array_map('trim', $options)));
            if (in_array($combo, $combos, true)) {
                $validator->errors()->add("variants.$i.options", 'This size / colour combination is repeated.');
            }
            $combos[] = $combo;
        }
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $product = $this->route('product');
            $variants = $this->input('variants');
            $ownIds = $product ? $product->variants()->withTrashed()->pluck('id')->all() : [];

            if ($this->input('type') === Product::TYPE_SINGLE && count($variants) !== 1) {
                $validator->errors()->add('variants', 'A single product has exactly one set of prices.');
            }

            if ($this->input('type') === Product::TYPE_VARIABLE) {
                $this->validateOptions($validator, $variants);
            }

            $seenSkus = $seenCodes = [];
            foreach ($variants as $i => $v) {
                if (! empty($v['id']) && ! in_array((int) $v['id'], $ownIds, true)) {
                    $validator->errors()->add("variants.$i.id", 'Unknown variant.');
                }
                if (! empty($v['mrp']) && (float) $v['selling_price'] > (float) $v['mrp']) {
                    $validator->errors()->add("variants.$i.selling_price", 'Selling price cannot be above MRP.');
                }

                if ($v['sku']) {
                    if (in_array(strtolower($v['sku']), $seenSkus, true)) {
                        $validator->errors()->add("variants.$i.sku", 'SKU is repeated in this form.');
                    }
                    $seenSkus[] = strtolower($v['sku']);

                    $taken = ProductVariant::withTrashed()->where('sku', $v['sku'])
                        ->when(! empty($v['id']), fn ($q) => $q->where('id', '!=', $v['id']))->exists();
                    if ($taken) {
                        $validator->errors()->add("variants.$i.sku", "SKU {$v['sku']} is already used.");
                    }
                }

                foreach ($v['barcodes'] as $code) {
                    if (in_array($code, $seenCodes, true)) {
                        $validator->errors()->add("variants.$i.barcodes", "Barcode {$code} is repeated in this form.");
                    }
                    $seenCodes[] = $code;

                    $owner = ProductBarcode::where('barcode', $code)->value('product_variant_id');
                    if ($owner && (empty($v['id']) || (int) $owner !== (int) $v['id'])) {
                        $validator->errors()->add("variants.$i.barcodes", "Barcode {$code} already belongs to another item.");
                    }
                }
            }
        }];
    }
}
