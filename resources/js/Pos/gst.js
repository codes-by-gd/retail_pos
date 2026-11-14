/**
 * Live bill preview — an exact mirror of app/Support/Gst/GstCalculator.php.
 * All arithmetic is done in BigInt (paise, milli-quantities, hundredth-percent) with half-up rounding,
 * so the preview matches the server to the paisa. The server still recalculates and is authoritative.
 */

/** "12.5" → 1250n with `scale` decimals, exact (no floating point). */
const fixed = (value, scale) => {
    const s = String(value ?? 0).trim() || '0';
    const neg = s.startsWith('-');
    const [i, f = ''] = s.replace(/^[-+]/, '').split('.');
    const frac = (f + '0'.repeat(scale)).slice(0, scale);
    // half-up on the dropped digit
    let n = BigInt(i || '0') * 10n ** BigInt(scale) + BigInt(frac || '0');
    if (f.length > scale && Number(f[scale]) >= 5) n += 1n;
    return neg ? -n : n;
};
const paise = (v) => fixed(v, 2);
const milli = (v) => fixed(v, 3);
const hundredths = (v) => fixed(v, 2);

/** Round a / b half-up (b > 0). */
const div = (a, b) => (a >= 0n ? (2n * a + b) / (2n * b) : -((2n * -a + b) / (2n * b)));
const min = (a, b) => (a < b ? a : b);

const discount = (base, type, value) => {
    if (!type || base <= 0n) return 0n;
    if (type === 'percent') {
        const pct = min(hundredths(value), 10000n);
        return pct > 0n ? min(div(base * pct, 10000n), base) : 0n;
    }
    const amount = paise(value);
    return amount > 0n ? min(amount, base) : 0n;
};

const toRupees = (p) => Number(p) / 100;

/**
 * @param lines [{ key, unit_price, quantity, price_includes_tax, rate, cess_rate, threshold, rate_above, discount_type, discount_value }]
 * @param billDiscount { type: 'percent'|'amount'|null, value }
 * @returns { lines: [...], totals: {...} } amounts in rupees (Number)
 */
export function calculate(lines, billDiscount, interState, roundOff = true) {
    const rows = lines.map((l) => {
        const qty = milli(l.quantity);
        const gross = div(paise(l.unit_price) * qty, 1000n);
        const lineDiscount = discount(gross, l.discount_type, l.discount_value);
        return { l, qty, gross, lineDiscount, net: gross - lineDiscount };
    });

    const sumNet = rows.reduce((s, r) => s + r.net, 0n);
    const billTotal = discount(sumNet, billDiscount?.type, billDiscount?.value);
    let last = -1;
    rows.forEach((r, i) => r.net > 0n && (last = i));
    let allocated = 0n;
    rows.forEach((r, i) => {
        r.share = billTotal === 0n || r.net <= 0n ? 0n : i === last ? billTotal - allocated : div(billTotal * r.net, sumNet);
        allocated += r.share;
        r.amount = r.net - r.share;
    });

    const t = { subtotal: 0n, line: 0n, bill: 0n, taxable: 0n, cgst: 0n, sgst: 0n, igst: 0n, cess: 0n, lines: 0n };
    const out = rows.map((r) => {
        const { l, qty, amount } = r;
        const inclusive = !!l.price_includes_tax;
        const cessH = hundredths(l.cess_rate ?? 0);
        let rateH = hundredths(l.rate);

        if (l.threshold !== null && l.threshold !== undefined && l.rate_above !== null && l.rate_above !== undefined && qty > 0n) {
            const thr = paise(l.threshold);
            const exceeds = inclusive ? amount * 10_000_000n > thr * qty * (10000n + rateH + cessH) : amount * 1000n > thr * qty;
            if (exceeds) rateH = hundredths(l.rate_above);
        }

        let taxable, gst, cess, lineTotal;
        if (inclusive) {
            taxable = div(amount * 10000n, 10000n + rateH + cessH);
            cess = div(taxable * cessH, 10000n);
            gst = amount - taxable - cess;
            lineTotal = amount;
        } else {
            taxable = amount;
            gst = div(taxable * rateH, 10000n);
            cess = div(taxable * cessH, 10000n);
            lineTotal = taxable + gst + cess;
        }
        const cgst = interState ? 0n : div(gst, 2n);
        const sgst = interState ? 0n : gst - cgst;
        const igst = interState ? gst : 0n;

        t.subtotal += r.gross;
        t.line += r.lineDiscount;
        t.bill += r.share;
        t.taxable += taxable;
        t.cgst += cgst;
        t.sgst += sgst;
        t.igst += igst;
        t.cess += cess;
        t.lines += lineTotal;

        return {
            key: l.key,
            gross: toRupees(r.gross),
            line_discount: toRupees(r.lineDiscount),
            bill_discount_share: toRupees(r.share),
            taxable_value: toRupees(taxable),
            gst_rate: Number(rateH) / 100,
            cgst: toRupees(cgst),
            sgst: toRupees(sgst),
            igst: toRupees(igst),
            cess: toRupees(cess),
            line_total: toRupees(lineTotal),
        };
    });

    const grand = roundOff ? div(t.lines, 100n) * 100n : t.lines;
    return {
        lines: out,
        totals: {
            subtotal: toRupees(t.subtotal),
            line_discount_total: toRupees(t.line),
            bill_discount_total: toRupees(t.bill),
            taxable_total: toRupees(t.taxable),
            cgst_total: toRupees(t.cgst),
            sgst_total: toRupees(t.sgst),
            igst_total: toRupees(t.igst),
            cess_total: toRupees(t.cess),
            tax_total: toRupees(t.cgst + t.sgst + t.igst + t.cess),
            round_off: toRupees(grand - t.lines),
            grand_total: toRupees(grand),
        },
    };
}
