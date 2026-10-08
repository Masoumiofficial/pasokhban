document.getElementById('year').textContent = new Date().getFullYear();
// لینک صفحه محصول راست‌چین را در این یک مقدار عوض کنید.
const PRODUCT_URL = 'https://www.rtl-theme.com/';
document.querySelectorAll('[data-buy]').forEach(a => a.href = PRODUCT_URL);
