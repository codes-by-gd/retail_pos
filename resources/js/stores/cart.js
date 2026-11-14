import { calculate } from '@/Pos/gst';
import { defineStore } from 'pinia';

let nextKey = 1;

/**
 * The bill being built on the POS screen. Prices/GST shown come from pos/gst.js,
 * which mirrors the server calculator exactly; the server re-checks everything on checkout.
 */
export const useCartStore = defineStore('cart', {
    state: () => ({
        items: [],
        customer: null, // { id, name, phone, price_type, discount_percent, balance, credit_limit, state_code, is_walk_in }
        billDiscount: { type: null, value: 0 },
        heldCartId: null,
        note: '',
        config: { round_off: true, branch_state: null },
    }),

    getters: {
        interState: (s) => !!(s.customer?.state_code && s.config.branch_state && s.customer.state_code !== s.config.branch_state),
        wholesale: (s) => s.customer?.price_type === 'wholesale',

        /** Price a line is charged at before discounts. */
        priceOf() {
            return (item) =>
                item.unit_price !== null && item.unit_price !== undefined && item.unit_price !== ''
                    ? Number(item.unit_price)
                    : this.listPriceOf(item);
        },
        listPriceOf() {
            return (item) => (this.wholesale && item.wholesale_price !== null && item.wholesale_price !== undefined ? item.wholesale_price : item.selling_price);
        },

        preview(state) {
            const lines = state.items.map((i) => ({
                key: i.key,
                unit_price: this.priceOf(i),
                quantity: i.quantity || 0,
                price_includes_tax: i.price_includes_tax,
                rate: i.gst_rate,
                cess_rate: i.gst_cess ?? 0,
                threshold: i.gst_threshold,
                rate_above: i.gst_rate_above,
                discount_type: Number(i.discount_value) > 0 ? i.discount_type : null,
                discount_value: i.discount_value || 0,
            }));
            const bill = Number(state.billDiscount.value) > 0 && state.billDiscount.type ? state.billDiscount : null;
            return calculate(lines, bill, this.interState, state.config.round_off);
        },
        lineByKey() {
            const map = Object.fromEntries(this.preview.lines.map((l) => [l.key, l]));
            return (key) => map[key];
        },
        total() {
            return this.preview.totals.grand_total;
        },
        count: (s) => s.items.reduce((n, i) => n + Number(i.quantity || 0), 0),
        isEmpty: (s) => s.items.length === 0,
    },

    actions: {
        /** Add a scanned / searched item; scanning the same item again adds one more. */
        add(variant, qty = 1) {
            const existing = this.items.find((i) => i.variant_id === variant.id && (i.unit_price === null || i.unit_price === ''));
            if (existing) {
                existing.quantity = Number(existing.quantity) + qty;
                return existing;
            }
            const line = {
                key: nextKey++,
                variant_id: variant.id,
                name: variant.name,
                product_name: variant.product_name,
                variant_name: variant.variant_name,
                sku: variant.sku,
                unit: variant.unit,
                allow_decimal: variant.allow_decimal,
                mrp: variant.mrp,
                selling_price: variant.selling_price,
                wholesale_price: variant.wholesale_price,
                price_includes_tax: variant.price_includes_tax,
                gst_rate: variant.gst_rate,
                gst_cess: variant.gst_cess ?? 0,
                gst_threshold: variant.gst_threshold,
                gst_rate_above: variant.gst_rate_above,
                stock: variant.stock,
                track_stock: variant.track_stock,
                quantity: qty,
                unit_price: null,
                discount_type: 'percent',
                discount_value: 0,
            };
            this.items.push(line);
            return line;
        },
        remove(key) {
            this.items = this.items.filter((i) => i.key !== key);
        },
        setCustomer(customer) {
            this.customer = customer;
            // Group member discount is applied automatically (cashier can still change it with permission).
            const pct = Number(customer?.discount_percent || 0);
            this.billDiscount = pct > 0 ? { type: 'percent', value: pct } : { type: null, value: 0 };
        },
        clear(walkIn) {
            this.items = [];
            this.heldCartId = null;
            this.note = '';
            this.setCustomer(walkIn);
        },

        /** Body for POST /pos/checkout */
        checkoutPayload(payments) {
            return {
                customer_id: this.customer?.is_walk_in ? null : this.customer?.id,
                items: this.items.map((i) => ({
                    variant_id: i.variant_id,
                    quantity: i.quantity,
                    unit_price: i.unit_price === null || i.unit_price === '' ? null : i.unit_price,
                    discount_type: Number(i.discount_value) > 0 ? i.discount_type : null,
                    discount_value: Number(i.discount_value) > 0 ? i.discount_value : null,
                })),
                bill_discount_type: Number(this.billDiscount.value) > 0 ? this.billDiscount.type : null,
                bill_discount_value: Number(this.billDiscount.value) > 0 ? this.billDiscount.value : null,
                payments,
                expected_total: this.total,
                note: this.note || null,
                held_cart_id: this.heldCartId,
            };
        },

        /** Serialisable snapshot for "hold bill". */
        snapshot() {
            return { items: this.items, customer: this.customer, billDiscount: this.billDiscount, note: this.note, total: this.total };
        },
        restore(payload, heldCartId) {
            this.items = (payload.items ?? []).map((i) => ({ ...i, key: nextKey++ }));
            this.customer = payload.customer;
            this.billDiscount = payload.billDiscount ?? { type: null, value: 0 };
            this.note = payload.note ?? '';
            this.heldCartId = heldCartId;
        },
    },
});
