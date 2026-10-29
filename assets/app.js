(() => {
    const trend = window.salesTrend;
    const canvas = document.getElementById('salesChart');
    if (canvas && window.Chart && Array.isArray(trend)) {
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: trend.map(item => item.day),
                datasets: [{
                    label: 'Sales',
                    data: trend.map(item => Number(item.total)),
                    fill: true,
                    borderColor: '#526cf0',
                    backgroundColor: 'rgba(82, 108, 240, .09)',
                    pointBackgroundColor: '#526cf0',
                    pointRadius: 3,
                    tension: .35
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#8a96a9', font: { size: 10 } } },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f0f2f6' },
                        ticks: {
                            color: '#8a96a9',
                            font: { size: 10 },
                            callback: value => `₱${Number(value).toLocaleString('en-PH')}`
                        }
                    }
                }
            }
        });
    }

    const cart = new Map();
    const productSelect = document.getElementById('pos-product');
    const quantityInput = document.getElementById('pos-quantity');
    const cartBody = document.querySelector('#cart-table tbody');
    const cartInput = document.getElementById('cart-json');

    function renderCart() {
        if (!cartBody || !cartInput) return;
        cartBody.replaceChildren();
        if (cart.size === 0) {
            const row = document.createElement('tr');
            row.className = 'cart-empty';
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.className = 'text-secondary';
            cell.textContent = 'Your cart is empty.';
            row.append(cell);
            cartBody.append(row);
        }
        for (const [id, item] of cart) {
            const row = document.createElement('tr');
            const values = [item.name, String(item.quantity), `₱${item.price.toFixed(2)}`, `₱${(item.price * item.quantity).toFixed(2)}`];
            for (const value of values) {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.append(cell);
            }
            const removeCell = document.createElement('td');
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.textContent = 'Remove';
            remove.addEventListener('click', () => {
                cart.delete(id);
                renderCart();
            });
            removeCell.append(remove);
            row.append(removeCell);
            cartBody.append(row);
        }
        cartInput.value = JSON.stringify(Array.from(cart, ([product_id, item]) => ({ product_id, quantity: item.quantity })));
    }

    document.getElementById('add-to-cart')?.addEventListener('click', () => {
        const option = productSelect?.selectedOptions[0];
        const quantity = Number.parseInt(quantityInput?.value || '0', 10);
        if (!option?.value || !Number.isInteger(quantity) || quantity < 1) {
            window.alert('Choose an in-stock product and a valid quantity.');
            return;
        }
        const id = Number.parseInt(option.value, 10);
        const available = Number.parseInt(option.dataset.stock || '0', 10);
        const existing = cart.get(id)?.quantity || 0;
        if (existing + quantity > available) {
            window.alert(`Only ${available} unit(s) are available.`);
            return;
        }
        cart.set(id, {
            name: option.dataset.name || option.textContent,
            price: Number(option.dataset.price),
            quantity: existing + quantity
        });
        renderCart();
    });

    document.getElementById('checkout-form')?.addEventListener('submit', event => {
        if (cart.size === 0) {
            event.preventDefault();
            window.alert('Add at least one product before completing the sale.');
            return;
        }
        const button = document.getElementById('complete-sale');
        if (!window.confirm('Complete this sale and deduct stock?')) {
            event.preventDefault();
            return;
        }
        if (button) {
            button.disabled = true;
            button.textContent = 'Processing…';
        }
    });

    document.querySelectorAll('.receive-form').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm('Confirm the delivered quantity and add it to inventory?')) event.preventDefault();
    }));

    const stockProductSelect = document.getElementById('stock-product-select');
    const countedStockInput = document.getElementById('stock-counted-stock');
    const adjustmentDelta = document.getElementById('stock-adjustment-delta');
    function updateAdjustmentPreview() {
        if (!stockProductSelect || !countedStockInput || !adjustmentDelta) return;
        const currentStock = stockProductSelect.selectedOptions[0]?.dataset.stock;
        const countedStock = countedStockInput.value;
        if (!currentStock || !/^\d+$/.test(countedStock)) {
            adjustmentDelta.textContent = currentStock ? 'Enter the physical count' : 'Select a product';
            adjustmentDelta.className = 'adjustment-preview';
            return;
        }
        const difference = BigInt(countedStock) - BigInt(currentStock);
        adjustmentDelta.textContent = difference === 0n
            ? 'No change'
            : `${difference > 0n ? '+' : ''}${difference} unit${difference === 1n || difference === -1n ? '' : 's'}`;
        adjustmentDelta.className = `adjustment-preview ${difference > 0n ? 'increase' : difference < 0n ? 'decrease' : ''}`;
    }
    stockProductSelect?.addEventListener('change', updateAdjustmentPreview);
    countedStockInput?.addEventListener('input', updateAdjustmentPreview);

    const purchaseLines = document.getElementById('purchase-lines');
    const purchaseTemplate = document.getElementById('purchase-line-template');
    document.getElementById('add-purchase-line')?.addEventListener('click', () => {
        if (!purchaseLines || !purchaseTemplate || purchaseLines.children.length >= 50) {
            window.alert('A purchase order can contain at most 50 product lines.');
            return;
        }
        purchaseLines.append(purchaseTemplate.content.cloneNode(true));
        updatePurchaseLineButtons();
    });

    function updatePurchaseLineButtons() {
        if (!purchaseLines) return;
        const lines = Array.from(purchaseLines.querySelectorAll('.purchase-line'));
        lines.forEach(line => {
            const remove = line.querySelector('.remove-purchase-line');
            if (remove) remove.disabled = lines.length === 1;
        });
    }

    purchaseLines?.addEventListener('click', event => {
        const remove = event.target.closest('.remove-purchase-line');
        if (!remove || !purchaseLines || purchaseLines.children.length <= 1) return;
        remove.closest('.purchase-line')?.remove();
        updatePurchaseLineButtons();
    });

    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));

    window.editProduct = button => {
        const product = JSON.parse(button.dataset.product);
        document.getElementById('edit-product-id').value = product.product_id;
        document.getElementById('edit-product-name').value = product.name;
        document.getElementById('edit-product-description').value = product.description || '';
        document.getElementById('edit-product-price').value = product.selling_price;
        document.getElementById('edit-product-min').value = product.minimum_stock;
        document.getElementById('edit-product-active').checked = product.is_active === true || product.is_active === 't';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editProductModal')).show();
    };

    const returnSelect = document.querySelector('select[name="sale_item_id"]');
    const saleIdInput = document.querySelector('input[name="sale_id"]');
    returnSelect?.addEventListener('change', () => {
        const saleId = returnSelect.selectedOptions[0]?.dataset.sale;
        if (saleIdInput && saleId) saleIdInput.value = saleId;
    });
})();
