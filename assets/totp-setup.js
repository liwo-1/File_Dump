/**
 * FileDump — TOTP QR Code Renderer
 *
 * Reads the otpauth:// URI from the data-uri attribute on #totp-qr
 * and renders a QR code SVG using the qrcode-generator library.
 */
(function () {
    'use strict';

    var el = document.getElementById('totp-qr');
    if (!el || !el.dataset.uri) return;

    var qr = qrcode(0, 'M');
    qr.addData(el.dataset.uri);
    qr.make();
    el.innerHTML = qr.createSvgTag(5, 0);
})();
