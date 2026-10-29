(() => {
    const showToast = (message, type = 'warning') => {
        const container = document.getElementById('app-toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast app-toast text-bg-${type}`;
        toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        toast.dataset.bsDelay = '6500';
        const row = document.createElement('div');
        row.className = 'd-flex';
        const body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = `btn-close ${type === 'warning' || type === 'danger' ? 'btn-close-white' : ''} me-2 m-auto`;
        close.dataset.bsDismiss = 'toast';
        close.setAttribute('aria-label', 'Close');
        row.append(body, close);
        toast.append(row);
        container.append(toast);
        bootstrap.Toast.getOrCreateInstance(toast).show();
        toast.addEventListener('hidden.bs.toast', () => toast.remove(), { once: true });
    };
    window.showAppToast = showToast;

    document.querySelectorAll('#app-toast-container .toast').forEach(toast => {
        bootstrap.Toast.getOrCreateInstance(toast).show();
        toast.addEventListener('hidden.bs.toast', () => toast.remove(), { once: true });
    });

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

    const catalog = Array.isArray(window.posCatalog) ? window.posCatalog : [];
    const catalogById = new Map(catalog.map(product => [Number(product.product_id), product]));
    const cart = new Map();
    const searchInput = document.getElementById('pos-product-search');
    const searchResults = document.getElementById('pos-search-results');
    const selectedProductLabel = document.getElementById('pos-selected-product');
    const quantityInput = document.getElementById('pos-quantity');
    const cartBody = document.querySelector('#cart-table tbody');
    const cartInput = document.getElementById('cart-json');
    const discountInput = document.getElementById('pos-discount-rate');
    const money = value => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    let selectedProduct = null;

    function validDiscountRate() {
        if (!discountInput) return 0;
        const value = discountInput.value.trim() === '' ? 0 : Number(discountInput.value);
        const valid = Number.isFinite(value) && value >= 0 && value <= 100;
        discountInput.setCustomValidity(valid ? '' : 'Discount must be between 0 and 100%.');
        return valid ? value : 0;
    }

    function renderCart() {
        if (!cartBody || !cartInput) return;
        cartBody.replaceChildren();
        if (cart.size === 0) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.className = 'text-secondary';
            cell.textContent = 'Your cart is empty.';
            row.append(cell);
            cartBody.append(row);
        }

        let subtotal = 0;
        let itemCount = 0;
        for (const [id, item] of cart) {
            subtotal += item.price * item.quantity;
            itemCount += item.quantity;
            const row = document.createElement('tr');
            const values = [item.name, String(item.quantity), money(item.price), money(item.price * item.quantity)];
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
        const discountRate = validDiscountRate();
        const discount = Math.round(subtotal * discountRate) / 100;
        const rate = Math.max(0, Math.min(Number(window.posTaxRate || 0), 100));
        const tax = Math.round((subtotal - discount) * rate) / 100;
        document.getElementById('pos-cart-count')?.replaceChildren(`${itemCount} item${itemCount === 1 ? '' : 's'}`);
        const subtotalNode = document.getElementById('pos-subtotal');
        const discountNode = document.getElementById('pos-discount-preview');
        const discountRateNode = document.getElementById('pos-discount-rate-preview');
        const taxNode = document.getElementById('pos-tax-preview');
        const totalNode = document.getElementById('pos-total-preview');
        if (subtotalNode) subtotalNode.textContent = money(subtotal);
        if (discountNode) discountNode.textContent = `−${money(discount)}`;
        if (discountRateNode) discountRateNode.textContent = `${discountRate.toFixed(2).replace(/\.?0+$/, '')}%`;
        if (taxNode) taxNode.textContent = money(tax);
        if (totalNode) totalNode.textContent = money(subtotal - discount + tax);
    }

    function updateProductResults() {
        if (!searchInput || !searchResults) return;
        const query = searchInput.value.trim().toLocaleLowerCase();
        const matches = catalog.filter(product => {
            const haystack = [product.name, product.sku, product.barcode, product.model, product.brand]
                .filter(Boolean).join(' ').toLocaleLowerCase();
            return query === '' || haystack.includes(query);
        }).slice(0, 8);
        searchResults.replaceChildren();
        matches.forEach(product => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'pos-search-option';
            option.setAttribute('role', 'option');
            const name = document.createElement('strong');
            name.textContent = `${product.name} · ${product.sku}`;
            const details = document.createElement('small');
            details.textContent = [
                product.barcode ? `Barcode ${product.barcode}` : '',
                product.model || '',
                product.brand || '',
                `${product.stock_quantity} in stock`,
                money(product.selling_price)
            ].filter(Boolean).join(' · ');
            option.append(name, details);
            option.addEventListener('click', () => {
                selectedProduct = product;
                searchInput.value = `${product.sku} · ${product.name}`;
                if (selectedProductLabel) {
                    selectedProductLabel.textContent = `${product.name} · ${product.sku} · ${product.stock_quantity} available · ${money(product.selling_price)}`;
                    selectedProductLabel.classList.remove('text-secondary');
                    selectedProductLabel.classList.add('text-primary');
                }
                searchResults.hidden = true;
                searchInput.setAttribute('aria-expanded', 'false');
                quantityInput?.focus();
            });
            searchResults.append(option);
        });
        searchResults.hidden = matches.length === 0;
        searchInput.setAttribute('aria-expanded', String(matches.length > 0));
    }

    searchInput?.addEventListener('input', () => {
        selectedProduct = null;
        if (selectedProductLabel) {
            selectedProductLabel.textContent = 'Select a search result to add it to the cart.';
            selectedProductLabel.classList.add('text-secondary');
            selectedProductLabel.classList.remove('text-primary');
        }
        updateProductResults();
    });
    searchInput?.addEventListener('focus', updateProductResults);
    document.addEventListener('click', event => {
        if (searchResults && !searchResults.contains(event.target) && event.target !== searchInput) {
            searchResults.hidden = true;
            searchInput?.setAttribute('aria-expanded', 'false');
        }
    });

    document.getElementById('add-to-cart')?.addEventListener('click', () => {
        const quantity = Number.parseInt(quantityInput?.value || '0', 10);
        if (!selectedProduct || !Number.isInteger(quantity) || quantity < 1) {
            showToast('Search for and select an in-stock product, then enter a valid quantity.');
            return;
        }
        const id = Number(selectedProduct.product_id);
        const available = Number(selectedProduct.stock_quantity);
        const existing = cart.get(id)?.quantity || 0;
        if (existing + quantity > available) {
            showToast(`Only ${available} unit(s) are available for ${selectedProduct.name}.`);
            return;
        }
        cart.set(id, {
            name: `${selectedProduct.name} · ${selectedProduct.sku}`,
            price: Number(selectedProduct.selling_price),
            quantity: existing + quantity
        });
        selectedProduct = null;
        if (searchInput) searchInput.value = '';
        if (selectedProductLabel) {
            selectedProductLabel.textContent = 'Item added. Search for another product.';
            selectedProductLabel.classList.add('text-secondary');
            selectedProductLabel.classList.remove('text-primary');
        }
        if (quantityInput) quantityInput.value = '1';
        renderCart();
        searchInput?.focus();
    });
    discountInput?.addEventListener('input', renderCart);

    const purchaseLines = document.getElementById('purchase-lines');
    const purchaseTemplate = document.getElementById('purchase-line-template');
    const purchaseTotal = document.getElementById('purchase-total');
    function updatePurchaseTotal() {
        if (!purchaseLines || !purchaseTotal) return;
        let subtotal = 0;
        purchaseLines.querySelectorAll('.purchase-line').forEach(line => {
            const quantity = Number(line.querySelector('[name="quantity[]"]')?.value || 0);
            const cost = Number(line.querySelector('[name="unit_cost[]"]')?.value || 0);
            subtotal += quantity * cost;
        });
        const rate = Math.max(0, Math.min(Number(window.posTaxRate || 0), 100));
        purchaseTotal.textContent = money(subtotal + Math.round(subtotal * rate) / 100);
    }
    document.getElementById('add-purchase-line')?.addEventListener('click', () => {
        if (!purchaseLines || !purchaseTemplate) return;
        if (purchaseLines.children.length >= 50) {
            showToast('A purchase order can contain at most 50 product lines.');
            return;
        }
        purchaseLines.append(purchaseTemplate.content.cloneNode(true));
        updatePurchaseLineButtons();
        updatePurchaseTotal();
    });
    function updatePurchaseLineButtons() {
        if (!purchaseLines) return;
        const lines = Array.from(purchaseLines.querySelectorAll('.purchase-line'));
        lines.forEach(line => {
            const remove = line.querySelector('.remove-purchase-line');
            if (remove) remove.disabled = lines.length === 1;
        });
    }
    purchaseLines?.addEventListener('input', updatePurchaseTotal);
    purchaseLines?.addEventListener('change', updatePurchaseTotal);
    purchaseLines?.addEventListener('click', event => {
        const remove = event.target.closest('.remove-purchase-line');
        if (!remove || !purchaseLines || purchaseLines.children.length <= 1) return;
        remove.closest('.purchase-line')?.remove();
        updatePurchaseLineButtons();
        updatePurchaseTotal();
    });

    const progressPrefix = 'partsdesk:form-progress:';
    const progressKey = action => `${progressPrefix}${window.location.pathname}:${window.location.search}:${action}`;
    function saveFormProgress(form) {
        const action = form.querySelector('[name="action"]')?.value;
        if (!action || ['login', 'logout', 'notification_mark_read', 'notifications_mark_all'].includes(action)) return;
        const values = [];
        Array.from(form.elements).forEach(element => {
            if (!element.name || element.disabled || ['password', 'password_confirm', 'csrf_token', 'request_key'].includes(element.name)) return;
            if (['button', 'submit', 'reset', 'file'].includes(element.type)) return;
            if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) return;
            values.push({ name: element.name, value: element.value, type: element.type, checked: element.checked });
        });
        try {
            sessionStorage.setItem(progressKey(action), JSON.stringify(values));
        } catch {
            showToast('Could not retain this form in the current browser session.', 'warning');
        }
    }

    if (document.querySelector('#app-toast-container .toast.text-bg-success')) {
        try {
            Object.keys(sessionStorage).filter(key => key.startsWith(progressPrefix)).forEach(key => sessionStorage.removeItem(key));
        } catch {
            showToast('Could not clear saved form data from this browser session.', 'warning');
        }
    }

    if (window.serverHasError && window.serverFailedAction) {
        const key = progressKey(window.serverFailedAction);
        try {
            const values = JSON.parse(sessionStorage.getItem(key) || '[]');
            const form = Array.from(document.forms).find(candidate => candidate.querySelector('[name="action"]')?.value === window.serverFailedAction);
            if (form && Array.isArray(values)) {
                values.forEach(saved => {
                    const fields = Array.from(form.elements).filter(element => element.name === saved.name);
                    const field = fields[0];
                    if (!field) return;
                    if (field.type === 'checkbox' || field.type === 'radio') field.checked = saved.checked;
                    else if (saved.name.endsWith('[]')) {
                        fields.forEach((entry, index) => {
                            if (values.filter(item => item.name === saved.name)[index]) {
                                entry.value = values.filter(item => item.name === saved.name)[index].value;
                            }
                        });
                    } else field.value = saved.value;
                });
                if (window.serverFailedAction === 'sale_checkout' && cartInput?.value) {
                    try {
                        const savedCart = JSON.parse(cartInput.value);
                        savedCart.forEach(line => {
                            const product = catalogById.get(Number(line.product_id));
                            if (product && Number(line.quantity) > 0) {
                                cart.set(Number(product.product_id), {
                                    name: `${product.name} · ${product.sku}`,
                                    price: Number(product.selling_price),
                                    quantity: Number(line.quantity)
                                });
                            }
                        });
                        renderCart();
                    } catch {
                        showToast('The saved cart could not be restored. Please review it before checkout.');
                    }
                }
            }
        } catch {
            showToast('The previous form values could not be restored.', 'warning');
        }
        const modalForAction = {
            product_create: 'productModal',
            supplier_create: 'supplierModal',
            purchase_create: 'purchaseModal',
            profile_update: 'userEditModal'
        }[window.serverFailedAction];
        const modal = modalForAction && document.getElementById(modalForAction);
        if (modal) bootstrap.Modal.getOrCreateInstance(modal).show();
        updatePurchaseTotal();
    }

    let pendingForm = null;
    let pendingSubmitter = null;
    const confirmModal = document.getElementById('confirmActionModal');
    const confirmMessage = document.getElementById('confirmActionMessage');
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const action = form.querySelector('[name="action"]')?.value;

        if (form.id === 'checkout-form' && cart.size === 0) {
            event.preventDefault();
            showToast('Add at least one product before completing the sale.');
            return;
        }

        if (form.id === 'purchase-form') {
            const ids = Array.from(form.querySelectorAll('[name="product_id[]"]')).map(field => field.value);
            if (new Set(ids).size !== ids.length) {
                event.preventDefault();
                showToast('Each product can appear only once on a purchase order.');
                return;
            }
            const invalidCost = Array.from(form.querySelectorAll('[name="unit_cost[]"]')).some(field => {
                const value = Number(field.value);
                return !Number.isFinite(value) || value < 0 || value > 9999999999.99;
            });
            if (invalidCost) {
                event.preventDefault();
                showToast('Check that every purchase cost is a valid amount.');
                return;
            }
        }

        if (form.dataset.confirm && form.dataset.confirmed !== 'true') {
            event.preventDefault();
            saveFormProgress(form);
            pendingForm = form;
            pendingSubmitter = event.submitter || null;
            if (confirmMessage) confirmMessage.textContent = form.dataset.confirm;
            if (confirmModal) bootstrap.Modal.getOrCreateInstance(confirmModal).show();
            return;
        }
        if (form.dataset.confirmed === 'true') delete form.dataset.confirmed;
        saveFormProgress(form);
        if (action === 'sale_checkout') {
            const button = document.getElementById('complete-sale');
            if (button) {
                button.disabled = true;
                button.textContent = 'Processing…';
            }
        }
    });
    document.getElementById('confirmActionContinue')?.addEventListener('click', () => {
        if (!pendingForm) return;
        const form = pendingForm;
        const submitter = pendingSubmitter;
        pendingForm = null;
        pendingSubmitter = null;
        form.dataset.confirmed = 'true';
        bootstrap.Modal.getOrCreateInstance(confirmModal).hide();
        form.requestSubmit(submitter || undefined);
    });
    confirmModal?.addEventListener('hidden.bs.modal', () => {
        pendingForm = null;
        pendingSubmitter = null;
    });

    const stockProductSelect = document.getElementById('stock-product-select');
    const countedStockInput = document.getElementById('stock-counted-stock');
    const adjustmentDelta = document.getElementById('stock-adjustment-delta');
    function updateAdjustmentPreview() {
        if (!stockProductSelect || !countedStockInput || !adjustmentDelta) return;
        const currentStock = stockProductSelect.selectedOptions[0]?.dataset.stock;
        const countedStock = countedStockInput.value;
        if (currentStock === undefined || !/^\d+$/.test(countedStock)) {
            adjustmentDelta.textContent = currentStock !== undefined ? 'Enter the physical count' : 'Select a product';
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

    document.querySelectorAll('[data-bs-target="#editProductModal"]').forEach(button => {
        button.addEventListener('click', () => {
            try {
                const product = JSON.parse(button.dataset.product || '{}');
                document.getElementById('edit-product-id').value = product.product_id || '';
                document.getElementById('edit-product-name').value = product.name || '';
                document.getElementById('edit-product-description').value = product.description || '';
                document.getElementById('edit-product-price').value = product.selling_price || '';
                document.getElementById('edit-product-min').value = product.minimum_stock || 0;
                document.getElementById('edit-product-active').checked = product.is_active === true || product.is_active === 't';
                bootstrap.Modal.getOrCreateInstance(document.getElementById('editProductModal')).show();
            } catch {
                showToast('Product details could not be loaded for editing.', 'danger');
            }
        });
    });

    document.getElementById('userEditModal')?.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        if (!button?.dataset.user) return;
        try {
            const data = JSON.parse(button.dataset.user);
            const form = event.currentTarget.querySelector('form');
            Object.entries(data).forEach(([name, value]) => {
                const field = form.elements[name];
                if (field) field.value = value ?? '';
            });
            form.elements.password.value = '';
        } catch {
            showToast('User details could not be loaded for editing.', 'danger');
            event.preventDefault();
        }
    });

    const exportLinks = Array.from(document.querySelectorAll('a[href*="page=reports"][href*="export="]'));
    if (exportLinks.length) {
        const container = exportLinks[0].parentElement;
        if (container) {
            const dropdown = document.createElement('div');
            dropdown.className = 'dropdown report-export-menu';
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'btn btn-outline-secondary';
            toggle.dataset.bsToggle = 'dropdown';
            toggle.setAttribute('aria-label', 'Choose report export');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.innerHTML = '<span aria-hidden="true">⋮</span> Export';
            const menu = document.createElement('div');
            menu.className = 'dropdown-menu dropdown-menu-end';
            exportLinks.forEach(link => {
                const item = link.cloneNode(true);
                item.className = 'dropdown-item';
                item.textContent = link.textContent.replace(/^Export\s+/i, '');
                menu.append(item);
                link.remove();
            });
            dropdown.append(toggle, menu);
            container.append(dropdown);
        }
    }

    const returnSelect = document.querySelector('select[name="sale_item_id"]');
    const saleIdInput = document.querySelector('input[name="sale_id"]');
    returnSelect?.addEventListener('change', () => {
        const saleId = returnSelect.selectedOptions[0]?.dataset.sale;
        if (saleIdInput && saleId) saleIdInput.value = saleId;
    });

    document.querySelectorAll('.alert-success').forEach(alert => {
        setTimeout(() => { alert.style.transition = 'opacity .4s'; alert.style.opacity = '0'; setTimeout(() => alert.remove(), 450); }, 6000);
    });
})();
