const number = new Intl.NumberFormat('en-US');

/** «شحنة واحدة، شحنتان، 3 شحنات، 11 شحنة» — كما يكتبها الخادم (Arabic::shipments) */
export function shipments(n) {
    if (n === 1) return 'شحنة واحدة';
    if (n === 2) return 'شحنتان';
    return `${number.format(n)} ${n >= 3 && n <= 10 ? 'شحنات' : 'شحنة'}`;
}
