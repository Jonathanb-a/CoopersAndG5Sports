const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

/** $1,700.00 */
export function formatMoney(amount: number | string): string {
    return pesos.format(Number(amount));
}

/** Enlace de WhatsApp. Un número de 10 dígitos se toma como mexicano (+52). */
export function whatsappUrl(phone: string, message?: string): string | null {
    const digits = phone.replace(/\D/g, '');

    if (digits.length < 10) {
        return null;
    }

    const international = digits.length === 10 ? `52${digits}` : digits;

    return `https://wa.me/${international}${message ? `?text=${encodeURIComponent(message)}` : ''}`;
}
