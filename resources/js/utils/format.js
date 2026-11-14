const moneyFormatter = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 2,
});

/** ₹1,23,456.00 (Indian digit grouping) */
export const money = (value) => moneyFormatter.format(Number(value ?? 0));

export const number = (value, digits = 0) =>
    Number(value ?? 0).toLocaleString('en-IN', { maximumFractionDigits: digits });

export const date = (value) =>
    value ? new Date(value).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

export const dateTime = (value) =>
    value
        ? new Date(value).toLocaleString('en-IN', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

/** Quantity without trailing zeros: 10 → "10", 2.5 → "2.5", 0.125 → "0.125" */
export const qty = (value) => Number(value ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
