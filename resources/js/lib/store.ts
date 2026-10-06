const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

/** $1,700.00 */
export function formatMoney(amount: number | string): string {
    return pesos.format(Number(amount));
}

/**
 * Agrega un mensaje prellenado a un enlace de WhatsApp que ya armó el servidor (https://wa.me/52...).
 * El teléfono no se limpia aquí: eso lo hace App\Support\WhatsAppLink.
 */
export function whatsappUrl(baseUrl: string, message?: string): string {
    return message ? `${baseUrl}?text=${encodeURIComponent(message)}` : baseUrl;
}
