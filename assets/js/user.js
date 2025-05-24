/**
 AmezPrice User JavaScript
 **/

// Utility Functions
const debounce = (func, wait) => {
    let timeout;
    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
};

const showToast = (message, type = 'success') => {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
};

const showSpinner = (element) => {
    element.classList.add('loading');
    element.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
};

const hideSpinner = (element, originalContent) => {
    element.classList.remove('loading');
    element.innerHTML = originalContent;
};

// Popup Management
function showPopup(id, content) {
    const popup = document.getElementById(id);
    const popupContent = popup.querySelector('.popup-content');
    const overlay = document.querySelector('.popup-overlay');
    
    popupContent.innerHTML = content;
    popup.style.display = 'block';
    overlay.style.display = 'block';
    popup.focus();
    
    // Accessibility
    popup.setAttribute('aria-modal', 'true');
    popup.setAttribute('role', 'dialog');
}

function hidePopup(id) {
    const popup = document.getElementById(id);
    const overlay = document.querySelector('.popup-overlay');
    
    popup.style.display = 'none';
    overlay.style.display = 'none';
    
    // Accessibility
    popup.removeAttribute('aria-modal');
    popup.removeAttribute('role');
}

// Favorite Toggle
async function toggleFavorite(productId, isFavorite) {
    const heart = event.target;
    const originalContent = heart.outerHTML;
    showSpinner(heart);

    try {
        // Check if alerts are active
        const alertResponse = await fetch('/user/check_alerts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ product_id: productId })
        });
        const alertResult = await alertResponse.json();

        if (alertResult.status === 'success' && alertResult.alerts_active && isFavorite) {
            showPopup('error-popup', `<h3>Error</h3><p>Cannot remove from favorites while alerts are active. Please disable alerts first.</p>`);
            hideSpinner(heart, originalContent);
            return;
        }

        const response = await fetch('/user/toggle_favorite.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ product_id: productId, is_favorite: !isFavorite })
        });
        const result = await response.json();

        if (result.status === 'success') {
            heart.classList.toggle('favorite');
            heart.style.color = isFavorite ? '#ccc' : '#ff0000';
            showToast(isFavorite ? 'Removed from favorites' : 'Added to favorites');
            trackInteraction('favorite', productId, !isFavorite);
            
            // Remove row from favorites table if unfavorited
            if (isFavorite && window.location.pathname.includes('favorites')) {
                const row = heart.closest('tr');
                if (row) row.remove();
            }
        } else {
            showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
        }
    } catch (error) {
        handleNetworkError(error);
    } finally {
        hideSpinner(heart, originalContent);
    }
}

// Alert Toggle
async function toggleAlert(productId, type, element) {
    const isOn = element.classList.contains('on');
    const originalContent = element.innerHTML;
    showSpinner(element);

    try {
        const response = await fetch('/user/toggle_alert.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ product_id: productId, type, enabled: !isOn })
        });
        const result = await response.json();

        if (result.status === 'success') {
            element.classList.toggle('on');
            showToast(`${type.charAt(0).toUpperCase() + type.slice(1)} alert ${isOn ? 'disabled' : 'enabled'}`);
            trackInteraction('alert_toggle', productId, { type, enabled: !isOn });
        } else if (result.message.includes('permission')) {
            showPopup('permission-popup', `<h3>Permission Required</h3><p>Please enable notifications in your browser settings.</p>`);
        } else {
            showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
        }
    } catch (error) {
        handleNetworkError(error);
    } finally {
        hideSpinner(element, originalContent);
    }
}

// Delete Product Confirmation
function confirmDeleteProduct(productId) {
    showPopup('delete-product-popup', `
        <h3>Confirm Deletion</h3>
        <p>Are you sure you want to remove this product from your list?</p>
        <button class="btn btn-primary" onclick="deleteProduct('${productId}')">Yes</button>
        <button class="btn btn-secondary" onclick="hidePopup('delete-product-popup')">No</button>
    `);
}

async function deleteProduct(productId) {
    try {
        const response = await fetch('/user/remove_product.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ product_id: productId })
        });
        const result = await response.json();

        if (result.status === 'success') {
            const row = document.querySelector(`tr[data-product-id="${productId}"]`);
            if (row) row.remove();
            showToast('Product removed');
            trackInteraction('remove_product', productId);
            hidePopup('delete-product-popup');
        } else {
            showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
        }
    } catch (error) {
        handleNetworkError(error);
    }
}

// Delete Account Confirmation
function confirmDeleteAccount() {
    showPopup('delete-account-popup', `
        <h3>Confirm Account Deletion</h3>
        <p>This action will permanently delete your account and all associated data. Are you sure?</p>
        <button class="btn btn-primary" onclick="requestDeleteAccountOtp()">Yes</button>
        <button class="btn btn-secondary" onclick="hidePopup('delete-account-popup')">No</button>
    `);
}

async function requestDeleteAccountOtp() {
    try {
        const response = await fetch('/user/account.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ action: 'request_otp' })
        });
        const result = await response.json();

        if (result.status === 'success') {
            showPopup('otp-popup', `
                <h3>Enter OTP</h3>
                <p>OTP sent to your email.</p>
                <input type="text" id="otp-input" placeholder="Enter OTP">
                <button class="btn btn-primary" onclick="verifyDeleteAccountOtp()">Submit</button>
                <button class="btn btn-secondary" onclick="hidePopup('otp-popup')">Cancel</button>
                <p id="resend-timer" style="display: none;">Resend in <span id="timer">30</span> seconds</p>
                <a href="#" id="resend-otp" style="display: none;" onclick="requestDeleteAccountOtp()">Resend OTP</a>
            `);
            startResendTimer();
        } else {
            showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
        }
    } catch (error) {
        handleNetworkError(error);
    }
}

async function verifyDeleteAccountOtp() {
    const otp = document.getElementById('otp-input').value;
    try {
        const response = await fetch('/user/account.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ action: 'verify_otp', otp })
        });
        const result = await response.json();

        if (result.status === 'success') {
            window.location.href = result.redirect;
        } else {
            showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
        }
    } catch (error) {
        handleNetworkError(error);
    }
}

function startResendTimer() {
    let timeLeft = 30;
    const timerEl = document.getElementById('timer');
    const resendEl = document.getElementById('resend-otp');
    const timerContainer = document.getElementById('resend-timer');
    timerContainer.style.display = 'block';
    resendEl.style.display = 'none';

    const interval = setInterval(() => {
        timeLeft--;
        timerEl.textContent = timeLeft;
        if (timeLeft <= 0) {
            clearInterval(interval);
            timerContainer.style.display = 'none';
            resendEl.style.display = 'block';
        }
    }, 1000);
}

// Table Sorting
function sortTable(column, table) {
    const th = table.querySelector(`th[data-column="${column}"]`);
    const isAscending = th.classList.contains('asc');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    rows.sort((a, b) => {
        const aValue = a.querySelector(`td:nth-child(${th.cellIndex + 1})`).textContent.trim();
        const bValue = b.querySelector(`td:nth-child(${th.cellIndex + 1})`).textContent.trim();
        
        if (column.includes('Price')) {
            return isAscending 
                ? parseFloat(aValue.replace(/[^0-9.-]+/g, '')) - parseFloat(bValue.replace(/[^0-9.-]+/g, '')) 
                : parseFloat(bValue.replace(/[^0-9.-]+/g, '')) - parseFloat(aValue.replace(/[^0-9.-]+/g, ''));
        }
        
        return isAscending 
            ? aValue.localeCompare(bValue) 
            : bValue.localeCompare(aValue);
    });

    th.classList.toggle('asc', !isAscending);
    th.classList.toggle('desc', isAscending);
    tbody.innerHTML = '';
    rows.forEach(row => tbody.appendChild(row));

    // Save sort preference
    localStorage.setItem('tableSort', JSON.stringify({ column, ascending: !isAscending }));
    trackInteraction('sort_table', null, { column, ascending: !isAscending });
}

// Search Form
async function handleSearch(event) {
    event.preventDefault();
    const form = event.target;
    const input = form.querySelector('.search-input');
    const url = input.value.trim();

    if (!url) {
        showPopup('error-popup', `<h3>Error</h3><p>Please enter a valid product URL</p>`);
        return;
    }

    try {
        const response = await fetch('/search/search.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ url })
        });
        const result = await response.json();

        if (result.status === 'success') {
            showPopup('search-preview-popup', `
                <h3>Search Result</h3>
                <img src="${result.image_path}" alt="${result.name}" style="max-width: 100%; border-radius: 8px;">
                <p><strong>${result.name}</strong></p>
                <p>Current Price: ₹${result.current_price.toLocaleString('en-IN')}</p>
                <a href="${result.website_url}" class="btn btn-primary">View Price History</a>
            `);
            trackInteraction('search', null, { url, product_name: result.name });
            
            // Save search history
            const searchHistory = JSON.parse(localStorage.getItem('searchHistory') || '[]');
            searchHistory.unshift({ url, name: result.name, timestamp: Date.now() });
            if (searchHistory.length > 10) searchHistory.pop();
            localStorage.setItem('searchHistory', JSON.stringify(searchHistory));
        } else {
            showPopup('error-popup', `<h3>Error</h3><p>${result.message}</p>`);
        }
    } catch (error) {
        handleNetworkError(error);
    }
}

// Quick View
function showQuickView(event, productId) {
    const row = event.target.closest('tr');
    const name = row.querySelector('.product-name').textContent;
    const price = row.querySelector('td:nth-child(3)').textContent;
    const img = row.querySelector('img').src;

    const quickView = document.createElement('div');
    quickView.className = 'quick-view';
    quickView.innerHTML = `
        <img src="${img}" alt="${name}" style="max-width: 100px; border-radius: 8px;">
        <p><strong>${name}</strong></p>
        <p>${price}</p>
    `;
    row.appendChild(quickView);

    setTimeout(() => quickView.remove(), 3000);
}

// Bulk Favorite Removal (Favorites Page Only)
function toggleBulkSelection() {
    const checkboxes = document.querySelectorAll('.bulk-checkbox');
    const selectAll = document.getElementById('select-all');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
}

async function confirmBulkFavoriteRemoval() {
    const selected = Array.from(document.querySelectorAll('.bulk-checkbox:checked')).map(cb => cb.dataset.productId);
    if (!selected.length) {
        showPopup('error-popup', `<h3>Error</h3><p>Please select at least one product</p>`);
        return;
    }

    // Check if any selected product has active alerts
    for (const productId of selected) {
        const response = await fetch('/user/check_alerts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ product_id: productId })
        });
        const result = await response.json();

        if (result.status === 'success' && result.alerts_active) {
            showPopup('error-popup', `<h3>Error</h3><p>Cannot remove favorites with active alerts. Please disable alerts first.</p>`);
            return;
        }
    }

    showPopup('delete-product-popup', `
        <h3>Confirm Bulk Favorite Removal</h3>
        <p>Are you sure you want to remove ${selected.length} favorites?</p>
        <button class="btn btn-primary" onclick="bulkRemoveFavorites(${JSON.stringify(selected)})">Yes</button>
        <button class="btn btn-secondary" onclick="hidePopup('delete-product-popup')">No</button>
    `);
}

async function bulkRemoveFavorites(productIds) {
    for (const productId of productIds) {
        try {
            const response = await fetch('/user/toggle_favorite.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ product_id: productId, is_favorite: false })
            });
            const result = await response.json();

            if (result.status === 'success') {
                const row = document.querySelector(`tr[data-product-id="${productId}"]`);
                if (row) row.remove();
                trackInteraction('favorite', productId, false);
            }
        } catch (error) {
            console.error('Error removing favorite:', error);
        }
    }
    showToast(`${productIds.length} favorites removed`);
    hidePopup('delete-product-popup');
}

// Analytics Tracking
function trackInteraction(type, productId = null, details = {}) {
    fetch('/user/track_interaction.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ type, product_id: productId, details })
    }).catch(error => console.error('Error tracking interaction:', error));
}

// Network Error Handling
function handleNetworkError(error) {
    console.error('Network error:', error);
    if (error.message.includes('401') || error.message.includes('403')) {
        showPopup('error-popup', `<h3>Session Expired</h3><p>Please log in again.</p>`);
        setTimeout(() => window.location.href = '/auth/login.php', 3000);
    } else {
        showPopup('error-popup', `<h3>Error</h3><p>Network error. Please try again.</p>`);
    }
}

// Lazy Loading for Tables
function lazyLoadTable() {
    const table = document.querySelector('.user-table');
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: '100px' });

    rows.forEach(row => {
        row.classList.add('lazy');
        observer.observe(row);
    });
}

// Event Listeners
document.addEventListener('DOMContentLoaded', () => {
    // Initialize lazy loading
    lazyLoadTable();

    // Restore sort preference
    const sortPref = JSON.parse(localStorage.getItem('tableSort'));
    if (sortPref) {
        const table = document.querySelector('.user-table');
        if (table) sortTable(sortPref.column, table);
    }

    // Event delegation for dynamic elements
    document.addEventListener('click', (event) => {
        const target = event.target;

        // Toggle favorite
        if (target.classList.contains('fa-heart')) {
            const productId = target.closest('tr').dataset.productId;
            const isFavorite = target.classList.contains('favorite');
            toggleFavorite(productId, isFavorite);
        }

        // Toggle alert
        if (target.closest('.toggle')) {
            const toggle = target.closest('.toggle');
            const productId = toggle.dataset.productId;
            const type = toggle.dataset.type;
            toggleAlert(productId, type, toggle);
        }

        // Sort table
        if (target.classList.contains('sortable')) {
            const column = target.dataset.column;
            const table = target.closest('table');
            sortTable(column, table);
        }

        // Quick view
        if (target.closest('.product-name')) {
            const productId = target.closest('tr').dataset.productId;
            showQuickView(event, productId);
        }

        // Bulk selection
        if (target.id === 'select-all') {
            toggleBulkSelection();
        }
    });

    // Search form
    const searchForm = document.querySelector('.search-form');
    if (searchForm) {
        searchForm.addEventListener('submit', handleSearch);
        const searchInput = searchForm.querySelector('.search-input');
        searchInput.addEventListener('input', debounce(async (e) => {
            const url = e.target.value.trim();
            if (url) {
                try {
                    const response = await fetch('/search/search.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({ url })
                    });
                    const result = await response.json();
                    if (result.status === 'success') {
                        showPopup('search-preview-popup', `
                            <h3>Live Preview</h3>
                            <img src="${result.image_path}" alt="${result.name}" style="max-width: 100%; border-radius: 8px;">
                            <p><strong>${result.name}</strong></p>
                            <p>Current Price: ₹${result.current_price.toLocaleString('en-IN')}</p>
                        `);
                    }
                } catch (error) {
                    console.error('Search preview error:', error);
                }
            }
        }, 500));
    }

    // Add bulk favorite removal UI (favorites page only)
    if (window.location.pathname.includes('favorites')) {
        const table = document.querySelector('.user-table');
        if (table) {
            const thead = table.querySelector('thead tr');
            const th = document.createElement('th');
            th.innerHTML = `<input type="checkbox" id="select-all">`;
            thead.insertBefore(th, thead.firstChild);

            const tbody = table.querySelector('tbody');
            tbody.querySelectorAll('tr').forEach(row => {
                const td = document.createElement('td');
                td.innerHTML = `<input type="checkbox" class="bulk-checkbox" data-product-id="${row.dataset.productId}">`;
                row.insertBefore(td, row.firstChild);
            });

            const bulkActions = document.createElement('div');
            bulkActions.className = 'bulk-actions';
            bulkActions.innerHTML = `
                <button class="btn btn-delete" onclick="confirmBulkFavoriteRemoval()">Remove Selected Favorites</button>
            `;
            table.parentElement.insertBefore(bulkActions, table);
        }
    }
});

// Accessibility Enhancements
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        const openPopup = document.querySelector('.popup[style*="block"]');
        if (openPopup) hidePopup(openPopup.id);
    }
});