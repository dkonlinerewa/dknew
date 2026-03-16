// ===== assets/js/qr-generator.js =====
function generateQR(elementId, data, size = 150) {
    return new Promise((resolve, reject) => {
        const element = document.getElementById(elementId);
        if (!element) {
            reject('Element not found');
            return;
        }
        
        // Clear previous QR
        element.innerHTML = '';
        
        QRCode.toCanvas(element, JSON.stringify(data), {
            width: size,
            margin: 1,
            color: {
                dark: '#000000',
                light: '#ffffff'
            }
        }, function(error, canvas) {
            if (error) {
                reject(error);
            } else {
                resolve(canvas);
            }
        });
    });
}

function downloadQR(qrData, filename = 'qrcode.png') {
    QRCode.toDataURL(JSON.stringify(qrData), {
        width: 300,
        margin: 2
    }, function(error, url) {
        if (error) {
            console.error(error);
            return;
        }
        
        const link = document.createElement('a');
        link.download = filename;
        link.href = url;
        link.click();
    });
}

function verifyQR(qrData) {
    return fetch('/api/verify_qr.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ data: qrData })
    })
    .then(res => res.json());
}